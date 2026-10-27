<?php

namespace App\Http\Controllers;

use App\Models\HealthConsentForm;
use App\Models\ParentalConsentForm;
use App\Models\StudentHealthRecord;
use App\Support\AccountSettings;
use App\Support\AdviserClassScope;
use App\Support\ConsentFormScanner;
use App\Support\ConsentStanding;
use App\Support\EncryptedFileStorage;
use App\Support\FeedingBeneficiarySummary;
use App\Support\LearnerPlacement;
use App\Support\NurseConsentList;
use App\Support\SchemaCache;
use App\Support\SchoolHeadOverview;
use App\Support\StudentRosterSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class HealthConsentFormController extends Controller
{
    /**
     * Adviser: list of assigned students with the status of each consent form.
     *
     * Any school year the school holds records for can be read here, not only
     * the current one. A consent is filed under the year it was given, and
     * nothing deletes it when that year ends — but a page that reads the
     * current year alone hides every one of them on the first of June, which
     * to the adviser is the same as losing them. An earlier year is read-only:
     * a new consent is always filed under the current year.
     */
    public function index(Request $request)
    {
        if ($redirect = $this->requireRole($request, ['class_adviser'])) {
            return $redirect;
        }

        [$schoolYear, $schoolYears] = $this->readSchoolYear($request);
        $isCurrentYear = $schoolYear === HealthConsentForm::currentSchoolYear();

        $students = $isCurrentYear
            ? $this->assignedStudents($request)
            : $this->classInYear($request, $schoolYear);
        $institutionId = $request->session()->get('active_institution_id');

        $forms = HealthConsentForm::where('school_year', $schoolYear)
            ->when($institutionId, fn ($q, $id) => $q->where('institution_id', $id))
            ->whereIn('student_lrn', $students->pluck('lrn')->filter()->values())
            ->get()
            ->keyBy('student_lrn');

        $unreadCount = HealthConsentForm::where('adviser_unread', true)
            ->when($institutionId, fn ($q, $id) => $q->where('institution_id', $id))
            ->count();

        $uploads = $this->uploadedConsentsByLrn($request, $students->pluck('lrn')->filter()->values(), $schoolYear);

        // One row per learner, combining the e-signature workflow with any
        // scanned Sulat-Pahibalo that was uploaded for them.
        $rows = $students->map(function (array $student) use ($forms, $uploads) {
            $form = $forms->get($student['lrn']);
            $upload = $uploads->get($student['lrn']);

            return [
                'lrn' => $student['lrn'],
                'name' => $student['name'],
                'parent_guardian' => $student['parent_guardian'],
                'form' => $form,
                'upload' => $upload,
                'status' => $this->deriveConsentStatus($form, $upload),
                'dated_at' => $upload?->created_at?->toDateString()
                    ?? $form?->signed_at?->toDateString(),
                'has_form_file' => $upload !== null && $upload->file_path !== null,
                'has_med_cert' => (bool) $upload?->medical_cert_attached,
                // The adviser's own remark, falling back to the reason recorded
                // against a partial or declined consent.
                'notes' => $upload?->notes
                    ?: ($upload?->partial_exception ?: ($upload?->refused_reason ?: null)),
                'file_name' => $upload?->file_original_name,
                // The audited download, which serves the learner's own adviser
                // as well as the clinic — so the scan filed in an earlier year
                // can still be opened, not just named.
                'file_url' => $upload !== null && $upload->file_path !== null
                    ? route('parental-consent.download', $upload->id)
                    : null,
                'med_cert_name' => $upload?->med_cert_original_name,
                // Surfaced so a record awaiting its scan still shows what the
                // parent answered, without that answer being read as consent.
                'recorded_response' => $this->recordedResponseLabel($upload),
            ];
        })->values();

        $counts = $rows->countBy('status');
        $withConsent = ($counts['approved'] ?? 0) + ($counts['partial'] ?? 0);

        return view('consent-forms.index', [
            'students' => $students,
            'forms' => $forms,
            'schoolYear' => $schoolYear,
            'schoolYears' => $schoolYears,
            'currentSchoolYear' => HealthConsentForm::currentSchoolYear(),
            'isCurrentYear' => $isCurrentYear,
            'unreadCount' => $unreadCount,
            'rows' => $rows,
            // The upload dialog offers to read the parent's answer off the
            // photo only where a reader is configured; otherwise the adviser
            // chooses it from the paper exactly as before.
            'scannerReady' => app(ConsentFormScanner::class)->isAvailable(),
            'stats' => [
                'approved' => $counts['approved'] ?? 0,
                'partial' => $counts['partial'] ?? 0,
                'declined' => $counts['declined'] ?? 0,
                'pending' => $counts['pending'] ?? 0,
                'total' => $rows->count(),
                'with_consent' => $withConsent,
                'rate' => $rows->count() > 0 ? (int) round(($withConsent / $rows->count()) * 100) : 0,
            ],
        ]);
    }

    /**
     * The school year a consent list is reading, and every year it may read.
     *
     * The years are the ones the school holds learner records for, newest
     * first — the same list the School Head's tabs offer — so a year with
     * nobody on its roll is never a choice. A value off the query string that
     * is not on that list falls back to the current year rather than emptying
     * the page.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function readSchoolYear(Request $request): array
    {
        $institutionId = $request->session()->get('active_institution_id');
        $years = SchoolHeadOverview::schoolYears($institutionId ? (int) $institutionId : null);
        $requested = trim((string) $request->query('school_year', ''));

        return [
            in_array($requested, $years, true) ? $requested : HealthConsentForm::currentSchoolYear(),
            $years,
        ];
    }

    /**
     * The adviser's learners, as they stood in an earlier school year.
     *
     * Access follows the learner (the school-year handover, rule 4): the
     * learners are the ones in this adviser's section now — their latest
     * placement open and in it — and the year chooses which of their forms is
     * read, whichever section they were in then. Last year's form moves with a
     * promoted learner to their new adviser, and is gone from the adviser they
     * left. The session roster holds the current year only, so the year's own
     * rows are read. Same shape as `assignedStudents()`, so the page renders
     * either alike.
     */
    private function classInYear(Request $request, string $schoolYear): Collection
    {
        $institutionId = $request->session()->get('active_institution_id');
        $latest = LearnerPlacement::latestRows($institutionId ? (int) $institutionId : null);
        $assigned = AdviserClassScope::assignedClass($request) ?? ['', ''];

        return StudentHealthRecord::rosterFor($institutionId, $schoolYear)
            ->filter(function (StudentHealthRecord $record) use ($latest, $assigned): bool {
                $now = $latest[(string) $record->student_id] ?? null;

                return $now !== null
                    && LearnerPlacement::isOpen($now)
                    && LearnerPlacement::inSection($now, $assigned[0], $assigned[1]);
            })
            ->unique(fn (StudentHealthRecord $record): string => (string) $record->student_id)
            ->map(function (StudentHealthRecord $record): array {
                $details = is_array($record->student_details) ? $record->student_details : [];
                [$grade, $section] = array_pad(explode(' / ', (string) $record->section, 2), 2, '');
                $name = trim(($details['last_name'] ?? '').', '.($details['first_name'] ?? '').' '.($details['middle_name'] ?? ''));

                return [
                    'lrn' => (string) $record->student_id,
                    // A record saved before the full entry was kept carries its
                    // name in the legacy column only.
                    'name' => trim($name, ', ') !== '' ? $name : (string) $record->student_name,
                    'grade_level' => trim($grade),
                    'section' => trim($section),
                    'parent_guardian' => (string) ($details['parent_guardian'] ?? ''),
                    'address' => (string) ($details['address'] ?? ''),
                    'division' => (string) ($details['division'] ?? ''),
                ];
            })
            // The names are decrypted, so the order is set here, never in SQL.
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * Latest uploaded consent scan per learner for one school year, keyed by
     * LRN. parental_consent_forms hangs off student_health_records, so the
     * join goes through the plain student_id column.
     */
    private function uploadedConsentsByLrn(Request $request, Collection $lrns, string $schoolYear): Collection
    {
        if ($lrns->isEmpty() || ! SchemaCache::hasTable('parental_consent_forms')) {
            return collect();
        }

        $records = StudentHealthRecord::query()
            ->when(
                $request->session()->get('active_institution_id'),
                fn ($q, $id) => $q->where('institution_id', $id)
            )
            ->whereIn('student_id', $lrns)
            ->forCurrentSchoolYear($schoolYear)
            ->get();

        if ($records->isEmpty()) {
            return collect();
        }

        $lrnByRecordId = $records->pluck('student_id', 'id');

        // Sorted oldest-first so keyBy leaves the most recent upload per learner.
        // The health-services letter only: a feeding-programme consent answers
        // a different question and must never stand in for this one.
        return ParentalConsentForm::healthServices()
            ->whereIn('student_health_record_id', $records->pluck('id'))
            ->where('school_year', $schoolYear)
            ->get()
            ->sortBy('created_at')
            ->keyBy(fn (ParentalConsentForm $upload) => (string) ($lrnByRecordId[$upload->student_health_record_id] ?? ''));
    }

    /**
     * Where one learner's consent stands — decided in `ConsentStanding`, which
     * the adviser's student profile and their dashboard counts read too, so
     * three screens cannot report three answers about one child.
     */
    private function deriveConsentStatus(?HealthConsentForm $form, ?ParentalConsentForm $upload): string
    {
        return ConsentStanding::for($form, $upload);
    }

    /** How the adviser recorded the parent's answer, whether or not the scan is in. */
    private function recordedResponseLabel(?ParentalConsentForm $upload): ?string
    {
        return match ($upload?->consent_type) {
            'full' => 'Full Consent',
            'partial' => 'Partial Consent',
            'refused' => 'Declined',
            default => null,
        };
    }

    /** Adviser: open (or create as draft) the form for one student. */
    public function open(Request $request): RedirectResponse
    {
        if ($redirect = $this->requireRole($request, ['class_adviser'])) {
            return $redirect;
        }

        $validated = $request->validate(['lrn' => ['required', 'string']]);

        $student = $this->assignedStudents($request)
            ->first(fn ($row) => (string) ($row['lrn'] ?? '') === $validated['lrn']);

        if (! $student) {
            return redirect()->route('consent-forms.index')
                ->with('error', 'Student not found in your assigned class.');
        }

        $form = HealthConsentForm::query()
            ->when($request->session()->get('active_institution_id'), fn ($q, $id) => $q->where('institution_id', $id))
            ->firstOrNew([
                'student_lrn' => $validated['lrn'],
                'school_year' => HealthConsentForm::currentSchoolYear(),
            ]);

        if (! $form->exists) {
            $form->fill([
                'institution_id' => $request->session()->get('active_institution_id'),
                'division' => $student['division'] ?: HealthConsentForm::DEFAULT_DIVISION,
                'school_name' => (string) ($request->session()->get('assigned_school_name')
                    ?: $request->session()->get('active_school_name')
                    ?: HealthConsentForm::DEFAULT_SCHOOL_NAME),
                'school_address' => HealthConsentForm::DEFAULT_SCHOOL_ADDRESS,
                'student_name' => $student['name'],
                'student_address' => $student['address'],
                'grade_level' => $student['grade_level'],
                'section' => $student['section'],
                'parent_guardian_name' => $student['parent_guardian'],
                'services' => [],
                'status' => HealthConsentForm::STATUS_DRAFT,
                'created_by_name' => (string) $request->session()->get('active_name', 'Class Adviser'),
            ]);
            $form->addAudit('Created draft', 'class_adviser', (string) $request->session()->get('active_name', 'Class Adviser'));
            $form->save();
        }

        return redirect()->route('consent-forms.show', $form);
    }

    /** Adviser: view a form (editable while draft, read-only afterwards). */
    public function show(Request $request, HealthConsentForm $form)
    {
        if ($redirect = $this->requireRole($request, ['class_adviser'])) {
            return $redirect;
        }

        $this->assertSameInstitution($request, $form);
        $this->assertAdviserReaches($request, $form, write: false);

        if ($form->adviser_unread) {
            $form->adviser_unread = false;
            $form->save();
        }

        return view('consent-forms.show', ['form' => $form]);
    }

    /** Adviser: save selected services while the form is a draft. */
    public function saveDraft(Request $request, HealthConsentForm $form): RedirectResponse
    {
        if ($redirect = $this->requireRole($request, ['class_adviser'])) {
            return $redirect;
        }

        $this->assertSameInstitution($request, $form);
        $this->assertAdviserReaches($request, $form, write: true);

        if ($form->isLockedForAdviser()) {
            return redirect()->route('consent-forms.show', $form)
                ->with('error', 'This form was already sent and can no longer be edited.');
        }

        $form->services = $this->validatedServices($request);
        $form->addAudit('Saved draft', 'class_adviser', (string) $request->session()->get('active_name', 'Class Adviser'));
        $form->save();

        return redirect()->route('consent-forms.show', $form)->with('success', 'Draft saved.');
    }

    /** Adviser: send the form to the parent (locks the adviser section). */
    public function send(Request $request, HealthConsentForm $form): RedirectResponse
    {
        if ($redirect = $this->requireRole($request, ['class_adviser'])) {
            return $redirect;
        }

        $this->assertSameInstitution($request, $form);
        $this->assertAdviserReaches($request, $form, write: true);

        if ($form->isLockedForAdviser()) {
            return redirect()->route('consent-forms.show', $form)
                ->with('error', 'This form was already sent.');
        }

        $services = $this->validatedServices($request);
        if (empty($services)) {
            return redirect()->route('consent-forms.show', $form)
                ->with('error', 'Select at least one health service before sending.');
        }

        $form->services = $services;
        $form->status = HealthConsentForm::STATUS_SENT;
        $form->token = $form->token ?: Str::random(40);
        $form->sent_at = now();
        $form->addAudit('Sent to parent', 'class_adviser', (string) $request->session()->get('active_name', 'Class Adviser'));
        $form->save();

        return redirect()->route('consent-forms.show', $form)
            ->with('success', 'Form sent to parent. Share the parent link below.');
    }

    /** Parent: open the form via its unique link (no login required). */
    public function parentShow(string $token)
    {
        $form = HealthConsentForm::where('token', $token)->firstOrFail();

        return view('consent-forms.parent', ['form' => $form]);
    }

    /** Parent: submit the consent section with the electronic signature. */
    public function parentSubmit(Request $request, string $token): RedirectResponse
    {
        $form = HealthConsentForm::where('token', $token)->firstOrFail();

        if ($form->isLockedForParent()) {
            return redirect()->route('consent-forms.parent', $token)
                ->with('error', 'This consent form has already been submitted.');
        }

        $validated = $request->validate([
            'consent_choice' => ['required', 'in:all,specific,deny'],
            'consent_exceptions' => ['required_if:consent_choice,specific', 'nullable', 'string', 'max:1000'],
            'refusal_reason' => ['required_if:consent_choice,deny', 'nullable', 'string', 'max:1000'],
            'allergy_food' => ['nullable', 'string', 'max:1000'],
            'allergy_medicine' => ['nullable', 'string', 'max:1000'],
            'prev_immunization' => ['nullable', 'string', 'max:1000'],
            'other_illness' => ['nullable', 'string', 'max:1000'],
            'signature' => ['required', 'string', 'starts_with:data:image/png;base64,', 'max:200000'],
        ], [
            'consent_choice.required' => 'Please choose one consent option.',
            'consent_exceptions.required_if' => 'Please specify which health services.',
            'refusal_reason.required_if' => 'Please provide the reason.',
            'signature.required' => 'Please draw your signature before submitting.',
        ]);

        $form->fill([
            'consent_choice' => $validated['consent_choice'],
            'consent_exceptions' => $validated['consent_choice'] === 'specific' ? $validated['consent_exceptions'] : null,
            'refusal_reason' => $validated['consent_choice'] === 'deny' ? $validated['refusal_reason'] : null,
            'allergy_food' => $validated['allergy_food'] ?? null,
            'allergy_medicine' => $validated['allergy_medicine'] ?? null,
            'prev_immunization' => $validated['prev_immunization'] ?? null,
            'other_illness' => $validated['other_illness'] ?? null,
            'signature' => $validated['signature'],
        ]);
        $form->status = HealthConsentForm::STATUS_SIGNED;
        $form->signed_at = now();
        $form->adviser_unread = true;
        $form->addAudit('Signed by parent/guardian', 'parent_guardian', (string) ($form->parent_guardian_name ?: 'Parent/Guardian'));
        $form->save();

        return redirect()->route('consent-forms.parent', $token)
            ->with('success', 'Consent form submitted. Thank you!');
    }

    /** Adviser: mark a signed form as reviewed, releasing it to the nurse. */
    public function review(Request $request, HealthConsentForm $form): RedirectResponse
    {
        if ($redirect = $this->requireRole($request, ['class_adviser'])) {
            return $redirect;
        }

        $this->assertSameInstitution($request, $form);
        $this->assertAdviserReaches($request, $form, write: true);

        if ($form->status !== HealthConsentForm::STATUS_SIGNED) {
            return redirect()->route('consent-forms.show', $form)
                ->with('error', 'Only forms signed by the parent can be marked as reviewed.');
        }

        $form->status = HealthConsentForm::STATUS_REVIEWED;
        $form->reviewed_at = now();
        $form->adviser_unread = false;
        $form->addAudit('Reviewed by adviser', 'class_adviser', (string) $request->session()->get('active_name', 'Class Adviser'));
        $form->save();

        return redirect()->route('consent-forms.show', $form)
            ->with('success', 'Form marked as reviewed and released to the School Nurse.');
    }

    /**
     * Adviser: record a consent that came back on paper.
     *
     * Most consents arrive through the parent's own link. Some arrive the way
     * they always have — signed in ink, in a child's bag — and the consent half
     * of the document is editable only through that link, so until this screen
     * existed a paper form had no way into the system at all.
     *
     * **Nothing here is decided by the reader.** The photograph is scanned to
     * fill the fields in (consent-forms.scan), the adviser checks every one of
     * them against the paper in their hand, and the form is written only when
     * they submit it. A parent's consent authorises medical procedures on a
     * child; a model reading a photograph is a typing aid, never the
     * authorisation. That is why the scan endpoint records nothing and this
     * action reads its values from the submitted form, never from a scan.
     */
    public function paperForm(Request $request, HealthConsentForm $form)
    {
        if ($redirect = $this->requireRole($request, ['class_adviser'])) {
            return $redirect;
        }

        $this->assertSameInstitution($request, $form);
        $this->assertAdviserReaches($request, $form, write: true);

        if ($this->paperFormIsClosed($form)) {
            return redirect()->route('consent-forms.show', $form)
                ->with('error', 'This form already carries the parent\'s answers.');
        }

        return view('consent-forms.paper', [
            'form' => $form,
            'scannerReady' => app(ConsentFormScanner::class)->isAvailable(),
            'writtenFields' => ConsentFormScanner::WRITTEN_FIELDS,
        ]);
    }

    /**
     * Adviser: save the paper form, as the adviser confirmed it.
     *
     * The same validation the parent's own submission runs, minus the drawn
     * signature: the signature is on the paper, and the photograph is what the
     * record keeps as evidence. A consent with no photograph cannot be saved
     * here — the drawn PNG column is empty for these, so the sheet itself is
     * the only thing standing behind the claim that a parent agreed.
     */
    public function recordPaperForm(Request $request, HealthConsentForm $form): RedirectResponse
    {
        if ($redirect = $this->requireRole($request, ['class_adviser'])) {
            return $redirect;
        }

        $this->assertSameInstitution($request, $form);
        $this->assertAdviserReaches($request, $form, write: true);

        if ($this->paperFormIsClosed($form)) {
            return redirect()->route('consent-forms.show', $form)
                ->with('error', 'This form already carries the parent\'s answers.');
        }

        $validated = $request->validate([
            'consent_choice' => ['required', 'in:all,specific,deny'],
            'consent_exceptions' => ['required_if:consent_choice,specific', 'nullable', 'string', 'max:1000'],
            'refusal_reason' => ['required_if:consent_choice,deny', 'nullable', 'string', 'max:1000'],
            'allergy_food' => ['nullable', 'string', 'max:1000'],
            'allergy_medicine' => ['nullable', 'string', 'max:1000'],
            'prev_immunization' => ['nullable', 'string', 'max:1000'],
            'other_illness' => ['nullable', 'string', 'max:1000'],
            'parent_guardian_name' => ['required', 'string', 'max:255'],
            'paper_form' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            // Not a checkbox that styles a warning: the adviser is attesting
            // that they read the paper and that these are its answers.
            'confirmed' => ['accepted'],
        ], [
            'consent_exceptions.required_if' => 'Write which health services the parent excepted.',
            'refusal_reason.required_if' => 'Write the reason the parent gave.',
            'paper_form.required' => 'Attach the photograph of the signed form.',
            'confirmed.accepted' => 'Confirm that these answers match the paper form.',
        ]);

        $path = EncryptedFileStorage::store($request->file('paper_form'), 'consent-forms');

        $form->fill([
            'consent_choice' => $validated['consent_choice'],
            'consent_exceptions' => $validated['consent_choice'] === 'specific' ? $validated['consent_exceptions'] : null,
            'refusal_reason' => $validated['consent_choice'] === 'deny' ? $validated['refusal_reason'] : null,
            'allergy_food' => $validated['allergy_food'] ?? null,
            'allergy_medicine' => $validated['allergy_medicine'] ?? null,
            'prev_immunization' => $validated['prev_immunization'] ?? null,
            'other_illness' => $validated['other_illness'] ?? null,
            'parent_guardian_name' => $validated['parent_guardian_name'],
            'paper_form_path' => $path,
        ]);

        $form->status = HealthConsentForm::STATUS_SIGNED;
        $form->signed_at = now();
        $form->adviser_unread = false;
        // Said on the record, not inferred from an empty signature column: a
        // nurse reading this later must be able to tell a consent signed on a
        // screen from one transcribed off paper by a member of staff.
        $form->addAudit(
            'Recorded from the signed paper form',
            'class_adviser',
            (string) $request->session()->get('active_name', 'Class Adviser'),
        );
        $form->save();

        return redirect()->route('consent-forms.show', $form)
            ->with('success', 'Consent recorded from the paper form. Review it to release it to the School Nurse.');
    }

    /**
     * The photographed form itself, for the desks that may already read it.
     *
     * A signed consent names a child and their parent's health decisions, so
     * the image is served through the same scope the form is — never from a
     * public disk, and never to a role that cannot open the form it belongs to.
     */
    public function paperFormImage(Request $request, HealthConsentForm $form)
    {
        if ($redirect = $this->requireRole($request, ['class_adviser', 'school_nurse', 'clinic_staff', 'school_head', 'clinic_teacher'])) {
            return $redirect;
        }

        $this->assertSameInstitution($request, $form);
        $this->assertAdviserReaches($request, $form, write: false);

        abort_unless((string) $form->paper_form_path !== '', 404);

        return EncryptedFileStorage::response(
            (string) $form->paper_form_path,
            'consent-form-'.$form->id.'.jpg',
            'inline',
        );
    }

    /**
     * A form that already carries the parent's answers is not re-recorded
     * here: a second reading of the same paper would overwrite the first
     * without anybody being told which one the parent actually signed.
     */
    private function paperFormIsClosed(HealthConsentForm $form): bool
    {
        return in_array($form->status, [
            HealthConsentForm::STATUS_SIGNED,
            HealthConsentForm::STATUS_REVIEWED,
        ], true);
    }

    /** The roles that read the clinic's consent pages. */
    private const CLINIC_READERS = ['school_nurse', 'clinic_staff', 'clinic_teacher'];

    /**
     * The clinic's consent list: every learner on the year's roll, their
     * standing for the chosen service, and the four figures over the learners
     * that service applies to — read once, by NurseConsentList, so the list
     * and the cards cannot disagree.
     */
    public function nurseIndex(Request $request)
    {
        if ($redirect = $this->requireRole($request, self::CLINIC_READERS)) {
            return $redirect;
        }

        // One school year at a time, the current one by default. A consent
        // answers for the year it was given in, and an earlier year's forms
        // and scans stay reachable here after it ends.
        [$schoolYear, $schoolYears] = $this->readSchoolYear($request);
        $currentSchoolYear = HealthConsentForm::currentSchoolYear();

        // Every filter reads an encrypted or a derived value, so each is
        // applied in PHP after the fetch. A value off the query string that is
        // not on its list narrows nothing rather than emptying the page.
        $service = $this->readService($request);

        $grade = (int) $request->query('grade', 0);
        if (! in_array($grade, FeedingBeneficiarySummary::GRADE_LEVELS, true)) {
            $grade = null;
        }

        $status = trim((string) $request->query('status', ''));
        if (! array_key_exists($status, NurseConsentList::STATUS_FILTERS)) {
            $status = '';
        }

        $list = NurseConsentList::build(
            $request->session()->get('active_institution_id'),
            $schoolYear,
            $schoolYear === $currentSchoolYear,
            $service,
            $grade,
            $status,
        );

        return view('consent-forms.nurse-index', [
            'rows' => $list['rows'],
            'cards' => $list['cards'],
            'service' => $service,
            'grade' => $grade,
            'status' => $status,
            'services' => HealthConsentForm::PLANNED_SERVICES,
            'grades' => FeedingBeneficiarySummary::GRADE_LEVELS,
            'statusFilters' => NurseConsentList::STATUS_FILTERS,
            'schoolYear' => $schoolYear,
            'schoolYears' => $schoolYears,
            'currentSchoolYear' => $currentSchoolYear,
            'isCurrentYear' => $schoolYear === $currentSchoolYear,
        ] + $this->clinicShell($request));
    }

    /**
     * One learner's consent, as the clinic reads it: the standing for the
     * service chosen on the list, the photo of the signed form, and the values
     * the adviser confirmed. It reads; nothing on it writes, and it never calls
     * the photo reader. Keyed by record id, never by LRN.
     */
    public function nurseLearner(Request $request, StudentHealthRecord $record)
    {
        if ($redirect = $this->requireRole($request, self::CLINIC_READERS)) {
            return $redirect;
        }

        abort_unless((int) $record->institution_id === (int) $request->session()->get('active_institution_id'), 404);

        $service = $this->readService($request);
        $year = (string) $record->school_year;

        return view('consent-forms.nurse-learner', [
            'detail' => NurseConsentList::learner($record, $service),
            'service' => $service,
            'backUrl' => route('consent-forms.nurse-index', array_filter([
                'school_year' => $year !== HealthConsentForm::currentSchoolYear() ? $year : null,
                'service' => $service !== '' ? $service : null,
            ])),
        ] + $this->clinicShell($request));
    }

    /** The service a clinic page was asked about, or '' for every service. */
    private function readService(Request $request): string
    {
        $service = trim((string) $request->query('service', ''));

        return array_key_exists($service, HealthConsentForm::PLANNED_SERVICES) ? $service : '';
    }

    /**
     * Who is reading, and the way back to their own desk. Three roles share
     * these pages, and each must be named as itself and sent home to its own
     * dashboard rather than to the nurse's.
     *
     * @return array{roleLabel: string, dashboardUrl: string, simulatedData: bool}
     */
    private function clinicShell(Request $request): array
    {
        $role = (string) $request->session()->get('active_role', '');

        return [
            'roleLabel' => AccountSettings::ROLE_LABELS[$role] ?? 'Clinic',
            'dashboardUrl' => route(match ($role) {
                'clinic_teacher' => 'dashboard.clinic-teacher',
                'clinic_staff' => 'dashboard.clinic-staff',
                default => 'dashboard.school-nurse',
            }),
            'simulatedData' => (bool) config('app.simulated_data'),
        ];
    }

    /** School Nurse: read-only view of one completed form. */
    public function nurseShow(Request $request, HealthConsentForm $form)
    {
        if ($redirect = $this->requireRole($request, ['school_nurse', 'clinic_staff', 'clinic_teacher'])) {
            return $redirect;
        }

        $this->assertSameInstitution($request, $form);

        if (! in_array($form->status, [HealthConsentForm::STATUS_SIGNED, HealthConsentForm::STATUS_REVIEWED], true)) {
            return redirect()->route('consent-forms.nurse-index')
                ->with('error', 'This form is not yet available.');
        }

        $form->addAudit('Viewed by School Nurse', (string) $request->session()->get('active_role'), (string) $request->session()->get('active_name', 'School Nurse'));
        $form->save();

        return view('consent-forms.nurse-show', ['form' => $form]);
    }

    /**
     * The signed letter, read by the School Head.
     *
     * The Consent Compliance tab reports a form's *standing* and never its
     * contents, which is right for a monitoring table. Opening one specific
     * signed letter is a different act with a reason behind it — the head
     * checking that the parent of a named learner really did consent to a
     * service — so it is its own page, its own click, and its own audit entry.
     *
     * Read-only, and only ever a form a parent has actually returned: a draft or
     * an unanswered letter has no signature to show. Scoped to the head's own
     * school like every other read.
     */
    public function headShow(Request $request, HealthConsentForm $form)
    {
        if ($redirect = $this->requireRole($request, ['school_head'])) {
            return $redirect;
        }

        $this->assertSameInstitution($request, $form);

        if (! in_array($form->status, [HealthConsentForm::STATUS_SIGNED, HealthConsentForm::STATUS_REVIEWED], true)) {
            return redirect()->route('dashboard.school-head.consent')
                ->with('error', 'That form has not been signed by a parent or guardian yet.');
        }

        $form->addAudit(
            'Viewed by School Head',
            (string) $request->session()->get('active_role'),
            (string) $request->session()->get('active_name', 'School Head'),
        );
        $form->save();

        return view('consent-forms.nurse-show', ['form' => $form]);
    }

    /** Print / export-as-PDF view (browser print dialog). */
    public function print(Request $request, HealthConsentForm $form)
    {
        if ($redirect = $this->requireRole($request, ['class_adviser', 'school_nurse', 'clinic_staff', 'clinic_teacher'])) {
            return $redirect;
        }

        $this->assertSameInstitution($request, $form);
        $this->assertAdviserReaches($request, $form, write: false);

        return view('consent-forms.print', ['form' => $form]);
    }

    /** Students of the adviser's assigned class, normalized for this module. */
    private function assignedStudents(Request $request)
    {
        // Rebuild the roster from the database so the list survives session
        // expiry, re-login, and server restarts.
        StudentRosterSync::syncToSession($request);

        // Narrowed by role, not by whether an assignment happens to be filled
        // in: this picker is shared with the school nurse and clinic staff, who
        // have no class and read the whole school, while a class adviser reads
        // their own class only.
        return AdviserClassScope::rosterRows($request)
            ->map(fn ($row) => [
                'lrn' => (string) ($row['lrn'] ?? ''),
                'name' => trim(($row['last_name'] ?? '').', '.($row['first_name'] ?? '').' '.($row['middle_name'] ?? '')),
                'grade_level' => (string) ($row['grade_level'] ?? ''),
                'section' => (string) ($row['section'] ?? ''),
                'parent_guardian' => (string) ($row['parent_guardian'] ?? ''),
                'address' => (string) ($row['address'] ?? ''),
                'division' => (string) ($row['division'] ?? ''),
            ])
            ->values();
    }

    private function validatedServices(Request $request): array
    {
        $validated = $request->validate([
            'services' => ['nullable', 'array'],
            'services.*' => ['string', 'in:'.implode(',', HealthConsentForm::serviceKeys())],
        ]);

        return array_values(array_unique($validated['services'] ?? []));
    }

    private function requireRole(Request $request, array $roles): ?RedirectResponse
    {
        if (! in_array($request->session()->get('active_role'), $roles, true)) {
            return redirect()->route('login')->with('error', 'You do not have access to that page.');
        }

        return null;
    }

    /**
     * A class adviser reaches a form only through the learner it is for, and
     * only while that learner is theirs (the school-year handover, rule 4):
     * in their section now to read or change it, or archived from their
     * section to read it. The adviser a learner has left is told the record is
     * no longer theirs. Other roles are unaffected.
     */
    private function assertAdviserReaches(Request $request, HealthConsentForm $form, bool $write): void
    {
        if ((string) $request->session()->get('active_role') !== 'class_adviser') {
            return;
        }

        $lrn = (string) $form->student_lrn;

        // On the adviser's own roster now — the same list the form was opened
        // from. The roster already drops anybody whose placement has closed.
        if (AdviserClassScope::rosterRows($request)->contains(fn (array $row): bool => (string) ($row['lrn'] ?? '') === $lrn)) {
            return;
        }

        $access = AdviserClassScope::learnerAccess($request, $lrn);

        if ($access === AdviserClassScope::ACCESS_CURRENT) {
            return;
        }

        if (! $write && $access === AdviserClassScope::ACCESS_ARCHIVED) {
            return;
        }

        abort_if($access === AdviserClassScope::ACCESS_PREVIOUS, 403, AdviserClassScope::NO_LONGER_MESSAGE);
        abort(404);
    }

    /** Forms are never visible across schools, even via a direct URL. */
    private function assertSameInstitution(Request $request, HealthConsentForm $form): void
    {
        $institutionId = $request->session()->get('active_institution_id');

        abort_if(
            $institutionId && $form->institution_id && (int) $form->institution_id !== (int) $institutionId,
            404
        );
    }
}
