<?php

namespace App\Http\Controllers;

use App\Models\ClinicNote;
use App\Models\Consultation;
use App\Models\FeedingAttendance;
use App\Models\HealthAssessment;
use App\Models\HealthConsentForm;
use App\Models\MedicalCertificate;
use App\Models\StudentHealthRecord;
use App\Support\PriorityHealthRule;
use App\Support\RequestMemo;
use App\Support\SchemaCache;
use App\Support\StudentMedicalDocuments;
use App\Support\StudentRosterSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StudentHealthRecordController extends Controller
{
    /**
     * Per-request memo for this page's repeated reads.
     *
     * The adviser dashboard builds two things from the same underlying data —
     * the overview panel and the My Students table — and each used to fetch the
     * learners, their consent forms, and their assessments for itself. Against
     * a hosted database, where a round trip costs the better part of a second,
     * that doubling was enough to push the page past PHP's execution limit and
     * fail it outright.
     *
     * @template TValue
     *
     * @param  \Closure(): TValue  $resolve
     * @return TValue
     */
    private function memo(string $key, \Closure $resolve): mixed
    {
        return RequestMemo::remember($key, $resolve);
    }

    public function classAdviserDashboard(Request $request): View|RedirectResponse
    {
        if ($request->session()->get('active_role') !== 'class_adviser') {
            $roleRoutes = [
                'school_nurse' => 'dashboard.school-nurse',
                'clinic_staff' => 'dashboard.clinic-staff',
                'school_head' => 'dashboard.school-head',
                'feeding_coor' => 'dashboard.feedingcor-dashboard',
                'nutricor' => 'dashboard.nutricor-dashboard',
                'system_admin' => 'dashboard.system-admin',
            ];
            $role = (string) $request->session()->get('active_role', '');
            $route = $roleRoutes[$role] ?? 'dashboard.school-nurse';

            return redirect()->route($route);
        }

        $this->stripLegacyDemoRows($request);
        $this->ensureAssignedSchoolName($request);

        // Rebuild the roster from the database so adviser-entered students
        // survive session expiry, re-login, and server restarts.
        StudentRosterSync::syncToSession($request);

        $records = collect();

        if (SchemaCache::hasTable('student_health_records')) {
            // Shares the roster sync's read of the same rows; sorting a handful
            // of already-loaded models costs nothing next to a second query.
            $records = StudentHealthRecord::currentYearForInstitution(
                $request->session()->get('active_institution_id')
            )->sortByDesc('updated_at')->values();
        }

        $todayCount = $records
            ->filter(fn (StudentHealthRecord $record) => optional($record->updated_at)?->isToday())
            ->count();

        $avgBmi = $records->avg('bmi_value') ?: 0;

        $flaggedCount = $records
            ->filter(function (StudentHealthRecord $record): bool {
                $status = strtolower((string) $record->nutritional_status);

                return str_contains($status, 'wast');
            })
            ->count();

        // Any uploaded document counts — a certificate filed against a
        // condition and one uploaded straight from the student profile alike.
        $lrnsWithCertificates = [];
        if (SchemaCache::hasTable('medical_certificates')) {
            $lrnsWithCertificates = array_flip(
                MedicalCertificate::query()
                    ->when(
                        $request->session()->get('active_institution_id'),
                        fn ($q, $id) => $q->where('institution_id', $id)
                    )
                    ->whereNotNull('student_lrn')
                    ->pluck('student_lrn')
                    ->unique()
                    ->toArray()
            );
        }

        return view('adviser-dashboard.class-adviser', [
            'records' => $records,
            'stats' => [
                'encoded_today' => $todayCount,
                'avg_bmi' => number_format((float) $avgBmi, 1),
                'flagged' => $flaggedCount,
            ],
            'lrnsWithCertificates' => $lrnsWithCertificates,
            'overview' => $this->buildAdviserOverview($request),
            'rosterMeta' => $this->buildRosterMeta($request),
        ]);
    }

    /**
     * Full-page, read-only profile for one learner — reached from the View
     * Profile action on My Students. Keeps the shared adviser sidebar/topbar
     * rather than a modal overlay.
     */
    public function studentProfile(Request $request, string $lrn): View|RedirectResponse
    {
        if ($request->session()->get('active_role') !== 'class_adviser') {
            return redirect()->route('dashboard.class-adviser');
        }

        StudentRosterSync::syncToSession($request);

        $record = collect($request->session()->get('school_health_card_records', []))
            ->first(fn ($row) => (string) ($row['lrn'] ?? '') === $lrn);

        if ($record === null) {
            return redirect()->route('dashboard.class-adviser', ['tab' => 'saved'])
                ->with('error', 'Student not found in your assigned class.');
        }

        return view('adviser-dashboard.student-profile', [
            'prototypeRecord' => $record,
            'meta' => $this->buildRosterMeta($request)[$lrn] ?? [
                'has_assessment' => false,
                'consent' => 'pending',
                'at_risk' => false,
                'consent_detail' => [],
                'feeding' => [],
                'documents' => collect(),
                'consultations' => collect(),
                'clinic_notes' => collect(),
            ],
        ]);
    }

    /**
     * Per-learner profile/consent state for the My Students table, keyed by
     * LRN. consent_choice is encrypted, so consent forms are fetched by the
     * plain student_lrn column and classified in PHP.
     *
     * @return array<string, array{has_assessment: bool, consent: string, at_risk: bool}>
     */
    private function buildRosterMeta(Request $request): array
    {
        $institutionId = $request->session()->get('active_institution_id');

        $roster = collect($request->session()->get('school_health_card_records', []));

        $lrns = $roster
            ->pluck('lrn')
            ->filter()
            ->map(fn ($lrn) => (string) $lrn)
            ->unique()
            ->values();

        if ($lrns->isEmpty()) {
            return [];
        }

        $shRecords = $this->recordsByLrn($lrns, $institutionId);
        $assessments = $this->assessmentsForRecords($shRecords);
        $consentForms = $this->consentsByLrn($lrns, $institutionId);

        // Medical Documents tab — documents are keyed by LRN + institution on
        // the document row itself, so this is an exact match, unlike consultations.
        $documentsByLrn = StudentMedicalDocuments::forLearners($lrns, $institutionId);

        // Consultation Log tab — the consultations table has no student_id/LRN
        // column (clinic staff log a free-text name), so matches are best-effort
        // by decrypted name + grade/section, not a guaranteed exact link.
        $consultationsByLrn = $this->matchConsultationsToRoster($roster, $lrns, $institutionId);

        // Clinic Notes tab — read-only for the adviser; the nurse and clinic
        // staff are the only roles that may write them.
        $clinicNotesByLrn = $this->clinicNotesForRoster($lrns, $institutionId);

        $meta = [];
        foreach ($lrns as $lrn) {
            $shRecord = $shRecords->get($lrn);
            $assessment = $shRecord ? $assessments->get($shRecord->id) : null;
            $consent = $consentForms->get($lrn);

            $meta[$lrn] = [
                'has_assessment' => $assessment !== null,
                'consent' => $this->classifyConsentStatus($consent),
                'at_risk' => (bool) ($shRecord?->is_at_risk),
                // Read-only summaries for the Consent and Feeding Status tabs
                // of the student profile.
                'consent_detail' => [
                    'status' => $consent
                        ? (HealthConsentForm::statusBadges()[$consent->status]['label'] ?? $consent->status)
                        : null,
                    'choice' => $consent ? $this->consentChoiceLabel($consent->consent_choice) : null,
                    'signed_at' => $consent?->signed_at?->toDateString(),
                    'reviewed_at' => $consent?->reviewed_at?->toDateString(),
                ],
                'feeding' => [
                    'baseline_status' => $shRecord?->baseline_nutritional_status,
                    'endline_status' => $shRecord?->endline_nutritional_status,
                    'sessions' => (int) ($shRecord?->attendance_sessions_count ?? 0),
                ],
                'assessment_date' => $assessment?->date_of_assessment?->toDateString(),
                'documents' => $documentsByLrn->get($lrn, collect())->values(),
                'consultations' => $consultationsByLrn->get($lrn, collect())->values(),
                'clinic_notes' => $clinicNotesByLrn->get($lrn, collect())->values(),
            ];
        }

        return $meta;
    }

    /**
     * Best-effort match of clinic consultation records to this adviser's
     * roster. The consultations table has no student_id/LRN column — it only
     * ever captured a free-text name typed by clinic staff — so this compares
     * decrypted, normalised names rather than an exact foreign key. Two
     * students with very similar names could theoretically collide; callers
     * must present this as "matched by name", not as a verified link.
     *
     * @param  Collection<int, string>  $lrns
     * @return Collection<string, Collection<int, array>>
     */
    private function matchConsultationsToRoster(Collection $roster, Collection $lrns, ?int $institutionId): Collection
    {
        if (! SchemaCache::hasTable('consultations') || $lrns->isEmpty()) {
            return collect();
        }

        $consultations = Consultation::query()
            ->when($institutionId, fn ($q, $id) => $q->where('institution_id', $id))
            ->orderByDesc('consulted_at')
            ->get();

        if ($consultations->isEmpty()) {
            return collect();
        }

        $students = $roster
            ->filter(fn ($row) => in_array((string) ($row['lrn'] ?? ''), $lrns->all(), true))
            ->map(function ($row) {
                return [
                    'lrn' => (string) ($row['lrn'] ?? ''),
                    'last' => $this->normaliseNameForMatching((string) ($row['last_name'] ?? '')),
                    'first' => $this->normaliseNameForMatching((string) ($row['first_name'] ?? '')),
                ];
            })
            ->filter(fn ($s) => $s['last'] !== '' && $s['first'] !== '')
            ->values();

        $byLrn = collect();
        foreach ($consultations as $consultation) {
            $normalisedName = $this->normaliseNameForMatching((string) $consultation->student_name);

            $match = $students->first(
                fn ($s) => str_contains($normalisedName, $s['last']) && str_contains($normalisedName, $s['first'])
            );

            if ($match === null) {
                continue;
            }

            $entry = [
                'consulted_at' => $consultation->consulted_at?->toDateTimeString(),
                'consulted_at_label' => $consultation->consulted_at?->format('M j, Y \a\t g:i A'),
                'grade_section' => $consultation->grade_section,
                'condition' => $consultation->condition,
                'treatment_given' => $consultation->treatment_given,
                'status' => $this->consultationStatusLabel($consultation->status),
            ];

            $byLrn->put($match['lrn'], $byLrn->get($match['lrn'], collect())->push($entry));
        }

        return $byLrn;
    }

    /**
     * Clinic notes for this class's learners, newest first and keyed by LRN.
     * student_lrn / institution_id are the plain lookup columns; the note and
     * its author are encrypted, so they are only read back here in PHP.
     *
     * @param  Collection<int, string>  $lrns
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    private function clinicNotesForRoster(Collection $lrns, ?int $institutionId): Collection
    {
        if (! SchemaCache::hasTable('clinic_notes') || $lrns->isEmpty()) {
            return collect();
        }

        return ClinicNote::query()
            ->whereIn('student_lrn', $lrns)
            ->when($institutionId, fn ($q, $id) => $q->where('institution_id', $id))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('student_lrn')
            ->map(fn (Collection $notes) => $notes->map(fn (ClinicNote $note) => [
                'recorded_at' => $note->created_at?->format('M j, Y \a\t g:i A'),
                'author' => (string) $note->author_name,
                'note' => (string) $note->note,
                'follow_up_date' => $note->follow_up_date?->format('M j, Y'),
            ])->values());
    }

    /** "treated"/"referred" as the clinic log itself labels them. */
    private function consultationStatusLabel(?string $status): ?string
    {
        return match ($status) {
            'treated' => 'Treated',
            'referred' => 'Referred',
            default => $status,
        };
    }

    private function normaliseNameForMatching(string $name): string
    {
        $stripped = preg_replace('/[^a-z\s]/', ' ', strtolower($name)) ?? '';

        return trim(preg_replace('/\s+/', ' ', $stripped) ?? '');
    }

    private function consentChoiceLabel(?string $choice): ?string
    {
        return match ($choice) {
            HealthConsentForm::CONSENT_ALL => 'Consented to all health services',
            HealthConsentForm::CONSENT_SPECIFIC => 'Consented with exceptions',
            HealthConsentForm::CONSENT_DENY => 'Consent declined',
            default => null,
        };
    }

    /**
     * A consent form only counts as answered once the parent has signed it —
     * drafts and sent-but-unsigned forms are still pending.
     */
    private function classifyConsentStatus(?HealthConsentForm $form): string
    {
        if ($form === null) {
            return 'pending';
        }

        $answered = [HealthConsentForm::STATUS_SIGNED, HealthConsentForm::STATUS_REVIEWED];
        if (! in_array($form->status, $answered, true)) {
            return 'pending';
        }

        return match ($form->consent_choice) {
            HealthConsentForm::CONSENT_DENY => 'declined',
            HealthConsentForm::CONSENT_SPECIFIC => 'partial',
            default => 'approved',
        };
    }

    /**
     * Redesigned dashboard data: real per-class totals, a "needs attention"
     * list (consent declined/pending, no health profile, at-risk), and a
     * recent-activity feed — all derived from this adviser's own roster
     * (session, scoped to their assigned grade/section) joined against the
     * DB-backed consent and assessment records.
     */
    private function buildAdviserOverview(Request $request): array
    {
        $ctx = $this->loadAdviserContext($request);
        $roster = $ctx['roster'];

        $empty = [
            'grade_section' => $ctx['grade_section'],
            'total' => 0,
            'complete' => 0,
            'pending' => 0,
            'needs_followup' => 0,
            'priority' => 0,
            'priority_students' => collect(),
            'recent_activity' => collect(),
            'activity_stamp' => $this->activityStamp($request),
        ];

        if ($ctx['lrns']->isEmpty()) {
            return $empty;
        }

        $shRecords = $ctx['records'];
        $consentForms = $ctx['consents'];
        $assessments = $ctx['assessments'];

        $complete = 0;
        $needsFollowup = 0;
        $priorityStudents = collect();

        foreach ($roster as $row) {
            $lrn = (string) ($row['lrn'] ?? '');
            if ($lrn === '') {
                continue;
            }

            $shRecord = $shRecords->get($lrn);
            $assessment = $shRecord ? $assessments->get($shRecord->id) : null;
            $consent = $consentForms->get($lrn);

            $hasAssessment = $assessment !== null;
            if ($hasAssessment) {
                $complete++;
            }

            $atRisk = (bool) ($shRecord?->is_at_risk);
            $consentDenied = $consent && $consent->consent_choice === HealthConsentForm::CONSENT_DENY;
            $consentPending = $consent && $consent->status === HealthConsentForm::STATUS_SENT;

            // Chronic or life-threatening condition on the adviser's own
            // assessment. Derived on every read — see PriorityHealthRule.
            $priorityReasons = PriorityHealthRule::reasonsFor($assessment);
            $isPriority = $priorityReasons !== [];

            if ($consentDenied || $atRisk || $isPriority) {
                $needsFollowup++;
            }

            if ($isPriority) {
                $priorityStudents->push([
                    'lrn' => $lrn,
                    'name' => $this->rosterDisplayName($row, $shRecord),
                    'section' => trim(($row['grade_level'] ?? '').'-'.($row['section'] ?? ''), '-'),
                    'reasons' => $priorityReasons,
                    // A learner carrying several conditions is the more
                    // urgent one, so the table leads with them.
                    'reason_count' => count($priorityReasons),
                    'updated_at' => $shRecord?->updated_at ?? $consent?->updated_at,
                ]);
            }
        }

        return [
            'grade_section' => $ctx['grade_section'],
            'total' => $roster->count(),
            'complete' => $complete,
            'pending' => max(0, $roster->count() - $complete),
            'needs_followup' => $needsFollowup,
            'priority' => $priorityStudents->count(),
            // Most conditions first, then most recently touched.
            'priority_students' => $priorityStudents
                ->sortByDesc('updated_at')
                ->sortByDesc('reason_count')
                ->values(),
            'recent_activity' => $this->buildRecentActivity($ctx),
            'activity_stamp' => $this->activityStamp($request),
        ];
    }

    /**
     * The adviser's own roster (session copy, already DB-synced and scoped to
     * their assigned grade/section) joined against the DB-backed records,
     * consent forms and health assessments. Shared by the dashboard overview
     * and the live activity feed so both always read the same data.
     *
     * @return array{
     *     grade_section: string,
     *     institution_id: mixed,
     *     roster: Collection,
     *     lrns: Collection,
     *     records: Collection,
     *     consents: Collection,
     *     assessments: Collection,
     * }
     */
    private function loadAdviserContext(Request $request): array
    {
        $grade = (string) $request->session()->get('assigned_grade_level', '');
        $section = (string) $request->session()->get('assigned_section', '');
        $institutionId = $request->session()->get('active_institution_id');

        $roster = collect($request->session()->get('school_health_card_records', []))
            ->filter(function ($row) use ($grade, $section) {
                if ($grade === '' || $section === '') {
                    return true;
                }

                return (string) ($row['grade_level'] ?? '') === $grade
                    && strcasecmp(trim((string) ($row['section'] ?? '')), trim($section)) === 0;
            })
            ->unique(fn ($row) => (string) ($row['lrn'] ?? ''))
            ->values();

        $lrns = $roster->pluck('lrn')->filter()->map(fn ($lrn) => (string) $lrn)->values();

        $ctx = [
            'grade_section' => trim("{$grade} / {$section}", ' /') ?: 'Not Assigned',
            'institution_id' => $institutionId,
            'roster' => $roster,
            'lrns' => $lrns,
            'records' => collect(),
            'consents' => collect(),
            'assessments' => collect(),
        ];

        if ($lrns->isEmpty()) {
            return $ctx;
        }

        $ctx['records'] = $this->recordsByLrn($lrns, $institutionId);
        $ctx['consents'] = $this->consentsByLrn($lrns, $institutionId);
        $ctx['assessments'] = $this->assessmentsForRecords($ctx['records']);

        return $ctx;
    }

    /**
     * The learners behind a set of LRNs, keyed by LRN. Memoized per request —
     * the overview and the roster table both ask for the same set.
     */
    private function recordsByLrn(Collection $lrns, mixed $institutionId): Collection
    {
        if ($lrns->isEmpty() || ! SchemaCache::hasTable('student_health_records')) {
            return collect();
        }

        return $this->memo('records:'.$institutionId.':'.md5($lrns->sort()->implode(',')), fn () => StudentHealthRecord::query()
            ->when($institutionId, fn ($q, $id) => $q->where('institution_id', $id))
            ->whereIn('student_id', $lrns)
            ->forCurrentSchoolYear()
            ->get()
            ->keyBy('student_id'));
    }

    /** This year's consent forms for a set of LRNs, keyed by LRN. */
    private function consentsByLrn(Collection $lrns, mixed $institutionId): Collection
    {
        if ($lrns->isEmpty() || ! SchemaCache::hasTable('health_consent_forms')) {
            return collect();
        }

        return $this->memo('consents:'.$institutionId.':'.md5($lrns->sort()->implode(',')), fn () => HealthConsentForm::where('school_year', HealthConsentForm::currentSchoolYear())
            ->when($institutionId, fn ($q, $id) => $q->where('institution_id', $id))
            ->whereIn('student_lrn', $lrns)
            ->get()
            ->keyBy('student_lrn'));
    }

    /** This year's health assessments for a set of records, keyed by record id. */
    private function assessmentsForRecords(Collection $records): Collection
    {
        if ($records->isEmpty() || ! SchemaCache::hasTable('health_assessments')) {
            return collect();
        }

        $ids = $records->pluck('id')->sort()->values();

        return $this->memo('assessments:'.md5($ids->implode(',')), fn () => HealthAssessment::whereIn('student_health_record_id', $ids)
            ->where('school_year', HealthAssessment::currentSchoolYear())
            ->get()
            ->keyBy('student_health_record_id'));
    }

    /**
     * "LastName, FirstName M." from the roster row, falling back to the
     * decrypted DB name and finally the LRN, so a legacy or half-filled row
     * never renders as a bare comma.
     *
     * @param  array<string, mixed>  $row
     */
    private function rosterDisplayName(array $row, ?StudentHealthRecord $record = null): string
    {
        $last = trim((string) ($row['last_name'] ?? ''));
        $first = trim((string) ($row['first_name'] ?? ''));
        $middle = trim((string) ($row['middle_name'] ?? ''));
        $middleInitial = $middle !== '' ? ' '.strtoupper(mb_substr($middle, 0, 1)).'.' : '';

        if ($last !== '' || $first !== '') {
            return trim(trim($last.', '.$first.$middleInitial, ', '));
        }

        $dbName = trim((string) ($record?->student_name ?? ''));

        return $dbName !== '' ? $dbName : (string) ($row['lrn'] ?? 'Unknown learner');
    }

    /**
     * The Recent Activity feed: every dated event on this adviser's own class,
     * newest first. Each event is keyed by its source row + kind, so polling
     * for updates can never duplicate an entry that is already on screen.
     *
     * @param  array<string, mixed>  $ctx  from loadAdviserContext()
     * @return Collection<int, array<string, mixed>>
     */
    private function buildRecentActivity(array $ctx, int $limit = 6): Collection
    {
        if ($ctx['lrns']->isEmpty()) {
            return collect();
        }

        $activity = collect();
        $names = [];

        foreach ($ctx['roster'] as $row) {
            $lrn = (string) ($row['lrn'] ?? '');
            if ($lrn === '') {
                continue;
            }

            $record = $ctx['records']->get($lrn);
            $assessment = $record ? $ctx['assessments']->get($record->id) : null;
            $consent = $ctx['consents']->get($lrn);
            $name = $this->rosterDisplayName($row, $record);
            $names[$lrn] = $name;

            if ($record?->created_at) {
                $activity->push([
                    'id' => "student-{$record->id}",
                    'icon' => 'student',
                    'badge' => 'STUDENT',
                    'text' => "{$name} was enrolled in your class",
                    'at' => $record->created_at,
                ]);
            }

            if ($assessment?->created_at) {
                $activity->push([
                    'id' => "assessment-{$assessment->id}",
                    'icon' => 'profile',
                    'badge' => 'PROFILE',
                    'text' => "Health profile completed for {$name}",
                    'at' => $assessment->created_at,
                ]);

                // A later edit is its own event; the one-minute grace keeps the
                // insert's own updated_at from doubling the "completed" entry.
                if ($assessment->updated_at?->gt($assessment->created_at->copy()->addMinute())) {
                    $activity->push([
                        'id' => "assessment-updated-{$assessment->id}",
                        'icon' => 'profile',
                        'badge' => 'PROFILE',
                        'text' => "Health profile updated for {$name}",
                        'at' => $assessment->updated_at,
                    ]);
                }
            }

            if ($consent?->sent_at) {
                $activity->push([
                    'id' => "consent-sent-{$consent->id}",
                    'icon' => 'consent',
                    'badge' => 'CONSENT',
                    'text' => "Consent form sent to the guardian of {$name}",
                    'at' => $consent->sent_at,
                ]);
            }

            // Keyed off signed_at alone: the form moves on to "reviewed" once
            // the adviser opens it, and the parent's response must stay in the
            // feed after that.
            if ($consent?->signed_at) {
                $declined = $consent->consent_choice === HealthConsentForm::CONSENT_DENY;
                $activity->push([
                    'id' => "consent-signed-{$consent->id}",
                    'icon' => $declined ? 'declined' : 'consent',
                    'badge' => 'CONSENT',
                    'text' => 'Consent form '.($declined ? 'declined' : 'signed')." by guardian of {$name}",
                    'at' => $consent->signed_at,
                ]);
            }

            if ($consent?->reviewed_at) {
                $activity->push([
                    'id' => "consent-reviewed-{$consent->id}",
                    'icon' => 'consent',
                    'badge' => 'CONSENT',
                    'text' => "Consent form reviewed for {$name}",
                    'at' => $consent->reviewed_at,
                ]);
            }
        }

        foreach ($this->recentCertificates($ctx, $limit) as $certificate) {
            $lrn = (string) $certificate->student_lrn;
            $activity->push([
                'id' => "certificate-{$certificate->id}",
                'icon' => 'certificate',
                'badge' => 'MED CERT',
                'text' => 'Medical certificate uploaded for '.($names[$lrn] ?? $lrn),
                'at' => $certificate->created_at,
            ]);
        }

        return $activity
            ->filter(fn (array $event) => $event['at'] !== null)
            ->unique('id')
            ->sortByDesc('at')
            ->values()
            ->take($limit);
    }

    /**
     * Medical documents filed for this class's learners — both certificates
     * attached to a condition and documents uploaded straight from the student
     * profile. Scoped by the document's own student_lrn / institution_id, the
     * plain lookup columns.
     *
     * @param  array<string, mixed>  $ctx  from loadAdviserContext()
     * @return Collection<int, MedicalCertificate>
     */
    private function recentCertificates(array $ctx, int $limit): Collection
    {
        if (! SchemaCache::hasTable('medical_certificates')) {
            return collect();
        }

        return MedicalCertificate::query()
            ->whereIn('student_lrn', $ctx['lrns'])
            ->when($ctx['institution_id'], fn ($q, $id) => $q->where('institution_id', $id))
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Live Recent Activity feed for the adviser's dashboard panel. Returns the
     * same events the page rendered, so a poll only ever refreshes what is
     * already shown — it never widens access beyond the adviser's own class.
     */
    public function activityFeed(Request $request): JsonResponse
    {
        if ($request->session()->get('active_role') !== 'class_adviser') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        StudentRosterSync::syncToSession($request);
        $ctx = $this->loadAdviserContext($request);

        $items = $this->buildRecentActivity($ctx)
            ->map(fn (array $event) => [
                'id' => $event['id'],
                'icon' => $event['icon'],
                'badge' => $event['badge'],
                'text' => $event['text'],
                'at' => $event['at']->toIso8601String(),
                'ago' => $event['at']->diffForHumans(),
            ])
            ->values();

        return response()->json([
            'stamp' => $this->activityStamp($request),
            'items' => $items,
        ]);
    }

    /**
     * A cheap change signal for the activity panel: max timestamp + row count
     * across the tables the feed is built from. It carries no personal
     * information, so the panel can poll it often; the feed itself is only
     * re-fetched (and audited) when this value actually changes.
     */
    public function activityPulse(Request $request): JsonResponse
    {
        if ($request->session()->get('active_role') !== 'class_adviser') {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json(['stamp' => $this->activityStamp($request)]);
    }

    private function activityStamp(Request $request): string
    {
        $institutionId = $request->session()->get('active_institution_id');

        // One stamp costs four aggregate queries, and the overview asks for it
        // on both its empty and its populated path — compute it once per request.
        return $this->memo('stamp:'.$institutionId, function () use ($institutionId): string {
            $parts = [];

            foreach (['student_health_records', 'health_consent_forms', 'health_assessments', 'medical_certificates'] as $table) {
                if (! SchemaCache::hasTable($table)) {
                    $parts[] = '-';

                    continue;
                }

                $query = DB::table($table);
                // health_assessments and medical_certificates inherit their school
                // scope from the parent record, so only the owning tables filter.
                if ($institutionId && SchemaCache::hasColumn($table, 'institution_id')) {
                    $query->where('institution_id', $institutionId);
                }

                $row = $query->selectRaw('COUNT(*) as row_count, MAX(updated_at) as last_touched')->first();
                $parts[] = ((int) ($row->row_count ?? 0)).'@'.((string) ($row->last_touched ?? ''));
            }

            return md5(implode('|', $parts));
        });
    }

    /**
     * Read-only feeding/nutrition status for the adviser's own class —
     * nutritional status, at-risk flag, and feeding-program attendance
     * pulled from student_health_records. Advisers don't manage the feeding
     * program (that's the Feeding Coordinator/Nurse), this is a summary view.
     */
    public function feedingStatus(Request $request): View|RedirectResponse
    {
        if ($request->session()->get('active_role') !== 'class_adviser') {
            return redirect()->route('dashboard.class-adviser');
        }

        // The roster is rebuilt from the database first so the page still
        // lists the class after a session expiry, re-login, or restart.
        StudentRosterSync::syncToSession($request);

        $grade = (string) $request->session()->get('assigned_grade_level', '');
        $section = (string) $request->session()->get('assigned_section', '');
        $institutionId = $request->session()->get('active_institution_id');

        $lrns = collect($request->session()->get('school_health_card_records', []))
            ->filter(function ($row) use ($grade, $section) {
                if ($grade === '' || $section === '') {
                    return true;
                }

                return (string) ($row['grade_level'] ?? '') === $grade
                    && strcasecmp(trim((string) ($row['section'] ?? '')), trim($section)) === 0;
            })
            ->pluck('lrn')
            ->filter()
            ->unique()
            ->values();

        $records = collect();
        if (SchemaCache::hasTable('student_health_records') && $lrns->isNotEmpty()) {
            $records = StudentHealthRecord::query()
                ->when($institutionId, fn ($q, $id) => $q->where('institution_id', $id))
                ->whereIn('student_id', $lrns)
                ->forCurrentSchoolYear()
                ->get();
        }

        // attendance_sessions_count holds sessions *attended*; the denominator
        // is how many sessions were recorded for that learner.
        $sessionTotals = collect();
        if ($records->isNotEmpty() && SchemaCache::hasTable('feeding_attendances')) {
            $sessionTotals = FeedingAttendance::query()
                ->whereIn('student_health_record_id', $records->pluck('id')->all())
                ->selectRaw('student_health_record_id, COUNT(*) as total_sessions')
                ->groupBy('student_health_record_id')
                ->pluck('total_sessions', 'student_health_record_id');
        }

        $students = $records
            ->map(function (StudentHealthRecord $record) use ($sessionTotals): array {
                $status = trim((string) $record->nutritional_status);
                $statusKey = $this->nutritionStatusKey($status);
                // The feeding program targets undernourished learners only;
                // eligibility is derived from BMI-for-age, never tagged by hand.
                $eligible = in_array($statusKey, ['wasted', 'severely-wasted', 'underweight'], true);

                $attended = max(0, (int) $record->attendance_sessions_count);
                $sessions = max(0, (int) ($sessionTotals[$record->id] ?? 0));
                $rate = $sessions > 0 ? (int) round(($attended / $sessions) * 100) : 0;

                $hasBaseline = $record->baseline_recorded_at !== null
                    || trim((string) $record->baseline_weight_kg) !== '';
                $hasEndline = $record->endline_recorded_at !== null
                    || trim((string) $record->endline_weight_kg) !== '';

                $program = match (true) {
                    ! $eligible => 'not-enrolled',
                    $hasEndline => 'completed',
                    default => 'ongoing',
                };

                $assessment = match (true) {
                    $hasBaseline && $hasEndline => 'complete',
                    $hasBaseline => 'pending',
                    default => 'none',
                };

                $bmi = trim((string) $record->bmi_value);
                if ($bmi === '') {
                    $bmi = trim((string) $record->baseline_bmi_value);
                }

                $weight = trim((string) $record->weight);
                if ($weight === '') {
                    $weight = trim((string) $record->baseline_weight_kg);
                }

                return [
                    'lrn' => (string) $record->student_id,
                    'name' => trim((string) $record->student_name),
                    'weight' => $weight,
                    'bmi' => $bmi,
                    'status' => $status,
                    'status_key' => $statusKey,
                    'eligible' => $eligible,
                    'program' => $program,
                    'attended' => $attended,
                    'sessions' => $sessions,
                    'rate' => $rate,
                    'at_risk' => (bool) $record->is_at_risk,
                    'assessment' => $assessment,
                ];
            })
            // student_name is encrypted at rest, so ordering happens here in
            // PHP rather than in an ORDER BY the database cannot read.
            ->sortBy(fn (array $row) => mb_strtolower($row['name']), SORT_NATURAL)
            ->values();

        $totalSessions = $students->sum('sessions');
        $totalAttended = $students->sum('attended');

        return view('adviser-dashboard.feeding-status', [
            'students' => $students,
            'gradeSection' => trim("{$grade} / {$section}", ' /') ?: 'Not Assigned',
            'schoolYear' => StudentHealthRecord::currentSchoolYear(),
            'stats' => [
                'total' => $students->count(),
                'normal' => $students->where('status_key', 'normal')->count(),
                'wasted' => $students->where('status_key', 'wasted')->count(),
                'severely_wasted' => $students->where('status_key', 'severely-wasted')->count(),
                'at_risk' => $students->where('at_risk', true)->count(),
                'enrolled' => $students->where('eligible', true)->count(),
                'ongoing' => $students->where('program', 'ongoing')->count(),
                'completed' => $students->where('program', 'completed')->count(),
                'attendance_rate' => $totalSessions > 0
                    ? (int) round(($totalAttended / $totalSessions) * 100)
                    : 0,
            ],
        ]);
    }

    /**
     * Slug for a DepEd BMI-for-age classification, used for badge tone and the
     * client-side status filter. Order matters: "severely wasted" must be
     * matched before the plain "wasted" it contains.
     */
    private function nutritionStatusKey(string $status): string
    {
        $status = strtolower(trim($status));

        return match (true) {
            $status === '' => 'not-assessed',
            str_contains($status, 'severely wasted') => 'severely-wasted',
            str_contains($status, 'wasted') => 'wasted',
            str_contains($status, 'underweight') => 'underweight',
            str_contains($status, 'obese') => 'obese',
            str_contains($status, 'overweight') => 'overweight',
            str_contains($status, 'normal') => 'normal',
            default => 'other',
        };
    }

    /**
     * Remove any leftover built-in demo student rows that older sessions may
     * still carry, so they don't reappear after the demo accounts were removed.
     */
    private function stripLegacyDemoRows(Request $request): void
    {
        $demoLrns = ['100234560201', '100234560202', '100234560203'];
        $existing = $request->session()->get('school_health_card_records', []);
        $cleaned = collect($existing)
            ->reject(fn (array $r) => in_array((string) ($r['lrn'] ?? ''), $demoLrns, true))
            ->values()
            ->all();

        if (count($cleaned) !== count($existing)) {
            $request->session()->put('school_health_card_records', $cleaned);
        }
    }

    /**
     * Ensure assigned_school_name is always populated in the session.
     * Sessions created before this key was introduced will not have it, so we
     * recover it from active_school_name or the accounts table on first access.
     */
    private function ensureAssignedSchoolName(Request $request): void
    {
        if ($request->session()->get('assigned_school_name') !== null) {
            return;
        }

        $schoolName = $request->session()->get('active_school_name');

        if ($schoolName === null && SchemaCache::hasTable('accounts')) {
            $username = strtolower((string) $request->session()->get('active_username', ''));
            if ($username !== '') {
                $account = DB::table('accounts')
                    ->whereRaw('LOWER(TRIM(username)) = ?', [$username])
                    ->when($request->session()->get('active_institution_id'), fn ($q, $id) => $q->where('institution_id', $id))
                    ->first();
                $schoolName = $account?->school_name ?? null;
            }
        }

        if ($schoolName !== null) {
            $request->session()->put('assigned_school_name', $schoolName);
        }
    }

    /**
     * Every school year on file for one student, oldest first — proves
     * grade promotion preserves history instead of overwriting it.
     * Allowed roles: class_adviser (own class only), clinic_staff, school_nurse.
     */
    public function history(Request $request): JsonResponse
    {
        $activeRole = (string) $request->session()->get('active_role', '');

        abort_unless(
            in_array($activeRole, ['class_adviser', 'clinic_staff', 'school_nurse'], true),
            403,
            'Access denied.'
        );

        $lrn = (string) $request->query('lrn', '');
        if ($lrn === '' || ! SchemaCache::hasTable('student_health_records')) {
            return response()->json(['years' => []]);
        }

        $records = StudentHealthRecord::where('student_id', $lrn)
            ->when(
                $request->session()->get('active_institution_id'),
                fn ($q, $id) => $q->where('institution_id', $id)
            )
            ->orderBy('school_year')
            ->get();

        if ($activeRole === 'class_adviser') {
            $grade = (string) $request->session()->get('assigned_grade_level', '');
            $section = (string) $request->session()->get('assigned_section', '');
            $expected = trim("{$grade} / {$section}");
            $current = $records->firstWhere('school_year', StudentHealthRecord::currentSchoolYear());
            if ($grade === '' || $section === '' || $current === null || $current->section !== $expected) {
                return response()->json(['years' => []]);
            }
        }

        return response()->json([
            'years' => $records->map(fn (StudentHealthRecord $r) => [
                'school_year' => $r->school_year,
                'section' => $r->section,
                'is_current' => $r->school_year === StudentHealthRecord::currentSchoolYear(),
                'baseline' => [
                    'height_cm' => $r->baseline_height_cm,
                    'weight_kg' => $r->baseline_weight_kg,
                    'bmi' => $r->baseline_bmi_value,
                    'status' => $r->baseline_nutritional_status,
                    'recorded_at' => optional($r->baseline_recorded_at)->format('M d, Y'),
                ],
                'endline' => [
                    'height_cm' => $r->endline_height_cm,
                    'weight_kg' => $r->endline_weight_kg,
                    'bmi' => $r->endline_bmi_value,
                    'status' => $r->endline_nutritional_status,
                    'recorded_at' => optional($r->endline_recorded_at)->format('M d, Y'),
                ],
            ])->values(),
        ]);
    }

    public function storeBaseline(Request $request): RedirectResponse
    {
        if (! $this->canRecordMeasurements($request)) {
            return redirect()->route('login')->with('error', 'You are not allowed to record baseline data.');
        }

        $validated = $request->validate([
            'student_name' => ['required', 'string', 'max:255'],
            'student_id' => ['required', 'string', 'max:100'],
            'school_name' => ['nullable', 'string', 'max:255'],
            'section' => ['required', 'string', 'max:255'],
            'age' => ['required', 'integer', 'min:2', 'max:25'],
            'height_cm' => ['required', 'numeric', 'min:50', 'max:250'],
            'weight_kg' => ['required', 'numeric', 'min:5', 'max:300'],
            'recorded_at' => ['nullable', 'date'],
        ]);

        $bmi = $this->computeBmi((float) $validated['height_cm'], (float) $validated['weight_kg']);
        $status = $this->classifyStatus($bmi, (int) $validated['age']);
        $recordedAt = $validated['recorded_at'] ?? now()->toDateString();

        $institutionId = $request->session()->get('active_institution_id');

        StudentHealthRecord::query()->updateOrCreate(
            [
                'student_id' => $validated['student_id'],
                'institution_id' => $institutionId,
                'school_year' => StudentHealthRecord::currentSchoolYear(),
            ],
            [
                'institution_id' => $institutionId,
                'student_name' => $validated['student_name'],
                'school_name' => $validated['school_name'] ?? session('active_school_name'),
                'section' => $validated['section'],
                'weight' => (float) $validated['weight_kg'],
                'bmi_value' => $bmi,
                'nutritional_status' => $status,
                'baseline_age' => (int) $validated['age'],
                'baseline_height_cm' => (float) $validated['height_cm'],
                'baseline_weight_kg' => (float) $validated['weight_kg'],
                'baseline_bmi_value' => $bmi,
                'baseline_nutritional_status' => $status,
                'baseline_recorded_at' => $recordedAt,
            ]
        );

        return back()->with('success', 'Baseline record saved. BMI and nutritional status were computed automatically.');
    }

    public function storeEndline(Request $request, StudentHealthRecord $record): RedirectResponse
    {
        if (! $this->canRecordMeasurements($request)) {
            return redirect()->route('login')->with('error', 'You are not allowed to record endline data.');
        }

        // Child records inherit school scope from their parent — never serve or
        // write one belonging to another institution.
        $institutionId = $request->session()->get('active_institution_id');
        if ($institutionId && (int) $record->institution_id !== (int) $institutionId) {
            abort(403);
        }

        // Endline is only meaningful once a baseline exists for the same
        // student and program cycle (school year).
        if ($record->baseline_bmi_value === null) {
            return back()->with('error', 'Record the baseline measurement first — endline cannot be entered without a baseline.');
        }

        $validated = $request->validate([
            'age' => ['required', 'integer', 'min:2', 'max:25'],
            'height_cm' => ['required', 'numeric', 'min:50', 'max:250'],
            'weight_kg' => ['required', 'numeric', 'min:5', 'max:300'],
            'recorded_at' => ['nullable', 'date'],
        ]);

        $bmi = $this->computeBmi((float) $validated['height_cm'], (float) $validated['weight_kg']);
        $status = $this->classifyStatus($bmi, (int) $validated['age']);
        $recordedAt = $validated['recorded_at'] ?? now()->toDateString();

        $record->update([
            'weight' => (float) $validated['weight_kg'],
            'bmi_value' => $bmi,
            'nutritional_status' => $status,
            'endline_age' => (int) $validated['age'],
            'endline_height_cm' => (float) $validated['height_cm'],
            'endline_weight_kg' => (float) $validated['weight_kg'],
            'endline_bmi_value' => $bmi,
            'endline_nutritional_status' => $status,
            'endline_recorded_at' => $recordedAt,
        ]);

        return back()->with('success', 'Endline record saved. BMI comparison is now available.');
    }

    /**
     * One learner, one key. Names and sections reach this page from two places
     * — a decrypted database column and a session roster row assembled from
     * separate fields — so they agree on the learner without agreeing on the
     * spacing or the case.
     */
    private function learnerKey(string $name, string $section): string
    {
        $normalize = fn (string $value): string => strtolower(
            preg_replace('/\s+/', ' ', trim(str_replace(['.', ','], ' ', $value))) ?? ''
        );

        return $normalize($name).'|'.$normalize($section);
    }

    public function feedingHealthRecords(Request $request): View
    {
        $records = collect();

        if (SchemaCache::hasTable('student_health_records')) {
            $q = StudentHealthRecord::query();
            $institutionId = $request->session()->get('active_institution_id');
            if ($institutionId) {
                $q->where('institution_id', $institutionId);
            }
            // student_name is encrypted at rest, so sorting happens in PHP.
            $records = $q->forCurrentSchoolYear()->get()->sortBy([
                ['section', 'asc'],
                ['student_name', 'asc'],
            ])->values();
        }

        $sessionAtRiskRecords = collect($request->session()->get('school_health_card_records', []))
            ->filter(function (array $row): bool {
                $status = strtolower((string) ($row['nutritional_status_bmi_for_age'] ?? ''));

                return str_contains($status, 'wasted') || str_contains($status, 'underweight');
            })
            ->map(function (array $row): object {
                $middle = trim((string) ($row['middle_name'] ?? ''));
                $middleInitial = $middle !== '' ? (' '.strtoupper(substr($middle, 0, 1)).'.') : '';
                $fullName = trim((string) ($row['last_name'] ?? '').', '.(string) ($row['first_name'] ?? '').$middleInitial);

                $baselineStatus = (string) ($row['nutritional_status_bmi_for_age'] ?? '');
                $baselineBmi = is_numeric($row['bmi_value'] ?? null) ? (float) $row['bmi_value'] : null;

                $endlineBmiRaw = data_get($row, 'endline_snapshot.bmi_value');
                if (! is_numeric($endlineBmiRaw)) {
                    $endlineWeight = data_get($row, 'endline_snapshot.weight_kg');
                    $heightCm = $row['height_cm'] ?? null;
                    if (is_numeric($endlineWeight) && is_numeric($heightCm) && (float) $heightCm > 0) {
                        $heightMeters = ((float) $heightCm) / 100;
                        $endlineBmiRaw = round(((float) $endlineWeight) / ($heightMeters * $heightMeters), 2);
                    }
                }

                return (object) [
                    'lrn' => trim((string) ($row['lrn'] ?? '')),
                    'student_name' => $fullName !== '' ? $fullName : ((string) ($row['first_name'] ?? 'Unknown Student')),
                    'section' => trim((string) ($row['grade_level'] ?? '').' / '.(string) ($row['section'] ?? '')),
                    'baseline_bmi_value' => $baselineBmi,
                    'baseline_nutritional_status' => $baselineStatus,
                    'endline_bmi_value' => is_numeric($endlineBmiRaw) ? (float) $endlineBmiRaw : null,
                    'endline_nutritional_status' => data_get($row, 'endline_snapshot.nutritional_status_bmi'),
                    'nutritional_status' => $baselineStatus,
                ];
            });

        // The session roster is a working copy of these same rows — StudentRosterSync
        // rebuilds it from the database — so appending it wholesale drew every
        // learner twice. It is kept only as a fallback for a learner the adviser
        // has entered but whose row this query cannot see, and a learner the
        // database already accounts for is dropped from it here.
        $lrnsOnFile = $records
            ->pluck('student_id')
            ->map(fn ($lrn): string => trim((string) $lrn))
            ->filter()
            ->flip();

        $namesOnFile = $records
            ->map(fn (StudentHealthRecord $record): string => $this->learnerKey(
                (string) $record->student_name,
                (string) $record->section
            ))
            ->flip();

        $records = $records
            ->concat($sessionAtRiskRecords->reject(function (object $row) use ($lrnsOnFile, $namesOnFile): bool {
                // The LRN is the learner's identity; the name and section are
                // the fallback for a roster row saved before LRNs were kept.
                if ($row->lrn !== '' && $lrnsOnFile->has($row->lrn)) {
                    return true;
                }

                return $namesOnFile->has($this->learnerKey((string) $row->student_name, (string) $row->section));
            }))
            ->values();

        // `section` holds "Grade 7 / Section A" (see StudentRosterSync). Split it
        // so grade level and section can be filtered independently, and flatten
        // both sources to one shape the view can read without special-casing.
        $records = $records->map(function ($record): object {
            $raw = trim((string) ($record->section ?? ''));
            $parts = array_map('trim', explode('/', $raw, 2));
            $grade = ($parts[0] ?? '') !== '' ? $parts[0] : 'Unassigned';
            $section = ($parts[1] ?? '') !== '' ? $parts[1] : 'Unassigned';

            return (object) [
                'student_name' => $record->student_name,
                'grade_level' => $grade,
                'section_name' => $section,
                'section' => $raw !== '' ? $raw : 'Unassigned',
                'baseline_bmi_value' => $record->baseline_bmi_value,
                'baseline_nutritional_status' => $record->baseline_nutritional_status,
                'endline_bmi_value' => $record->endline_bmi_value,
                'endline_nutritional_status' => $record->endline_nutritional_status,
                'nutritional_status' => $record->nutritional_status,
            ];
        });

        // Grade options always come from the unfiltered set, so a filter can
        // never hide its own way out. Section options narrow to the chosen
        // grade — offering Grade 8's sections while Grade 7 is selected would
        // only ever return nothing.
        $gradeLevels = $records->pluck('grade_level')->unique()->sort(SORT_NATURAL)->values();

        $gradeFilter = trim((string) $request->query('grade_level', ''));
        if (! $gradeLevels->contains($gradeFilter)) {
            $gradeFilter = '';
        }

        $sections = $records
            ->when($gradeFilter !== '', fn ($rows) => $rows->where('grade_level', $gradeFilter))
            ->pluck('section_name')->unique()->sort(SORT_NATURAL)->values();

        $sectionFilter = trim((string) $request->query('section', ''));
        if (! $sections->contains($sectionFilter)) {
            $sectionFilter = '';
        }

        $totalBeforeFilters = $records->count();

        if ($gradeFilter !== '') {
            $records = $records->where('grade_level', $gradeFilter);
        }
        if ($sectionFilter !== '') {
            $records = $records->where('section_name', $sectionFilter);
        }
        $records = $records->values();

        $statusCounts = [
            'severely_wasted' => 0,
            'wasted' => 0,
            'normal' => 0,
            'overweight' => 0,
        ];

        foreach ($records as $record) {
            $key = $this->statusKey((string) $record->nutritional_status);
            $statusCounts[$key]++;
        }

        $sectionSummary = $records
            ->groupBy(fn ($record) => (string) ($record->section ?: 'Unassigned'))
            ->map(function ($sectionRows, string $section): array {
                $counts = [
                    'severely_wasted' => 0,
                    'wasted' => 0,
                    'normal' => 0,
                    'overweight' => 0,
                ];

                foreach ($sectionRows as $row) {
                    $counts[$this->statusKey((string) $row->baseline_nutritional_status ?: (string) $row->nutritional_status)]++;
                }

                return [
                    'section' => $section,
                    'total' => count($sectionRows),
                    'counts' => $counts,
                ];
            })
            ->values();

        return view('feedingcor-dashboard.feed-healthrec', [
            'records' => $records,
            'statusCounts' => $statusCounts,
            'sectionSummary' => $sectionSummary,
            'gradeLevels' => $gradeLevels,
            'sections' => $sections,
            'filters' => ['grade_level' => $gradeFilter, 'section' => $sectionFilter],
            'totalBeforeFilters' => $totalBeforeFilters,
        ]);
    }

    /** Baseline/endline measurements may be recorded by the feeding coordinator or class adviser. */
    private function canRecordMeasurements(Request $request): bool
    {
        $role = strtolower(trim((string) $request->session()->get('active_role', '')));

        return in_array($role, ['feeding_coor', 'class_adviser'], true);
    }

    private function computeBmi(float $heightCm, float $weightKg): float
    {
        $heightMeters = $heightCm / 100;
        if ($heightMeters <= 0) {
            return 0;
        }

        return round($weightKg / ($heightMeters * $heightMeters), 2);
    }

    private function classifyStatus(float $bmi, int $age): string
    {
        if ($bmi < 16.0) {
            return 'Severely Wasted';
        }
        if ($bmi < 17.0) {
            return 'Wasted';
        }
        if ($bmi < 18.5) {
            return 'Underweight';
        }
        if ($bmi >= 25.0) {
            return 'Overweight';
        }

        return 'Normal';
    }

    private function statusKey(string $status): string
    {
        $normalized = strtolower($status);

        if (str_contains($normalized, 'severe')) {
            return 'severely_wasted';
        }
        if (str_contains($normalized, 'wast') || str_contains($normalized, 'underweight')) {
            return 'wasted';
        }
        if (str_contains($normalized, 'over')) {
            return 'overweight';
        }

        return 'normal';
    }
}
