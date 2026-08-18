<?php

namespace App\Http\Controllers;

use App\Models\HealthConsentForm;
use App\Models\ParentalConsentForm;
use App\Models\StudentHealthRecord;
use App\Support\SchemaCache;
use App\Support\StudentRosterSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class HealthConsentFormController extends Controller
{
    /** Adviser: list of assigned students with the status of each consent form. */
    public function index(Request $request)
    {
        if ($redirect = $this->requireRole($request, ['class_adviser'])) {
            return $redirect;
        }

        $students = $this->assignedStudents($request);
        $schoolYear = HealthConsentForm::currentSchoolYear();
        $institutionId = $request->session()->get('active_institution_id');

        $forms = HealthConsentForm::where('school_year', $schoolYear)
            ->when($institutionId, fn ($q, $id) => $q->where('institution_id', $id))
            ->whereIn('student_lrn', $students->pluck('lrn')->filter()->values())
            ->get()
            ->keyBy('student_lrn');

        $unreadCount = HealthConsentForm::where('adviser_unread', true)
            ->when($institutionId, fn ($q, $id) => $q->where('institution_id', $id))
            ->count();

        $uploads = $this->uploadedConsentsByLrn($request, $students->pluck('lrn')->filter()->values());

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
            'unreadCount' => $unreadCount,
            'rows' => $rows,
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
     * Latest uploaded consent scan per learner for the current school year,
     * keyed by LRN. parental_consent_forms hangs off student_health_records,
     * so the join goes through the plain student_id column.
     */
    private function uploadedConsentsByLrn(Request $request, Collection $lrns): Collection
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
            ->forCurrentSchoolYear()
            ->get();

        if ($records->isEmpty()) {
            return collect();
        }

        $lrnByRecordId = $records->pluck('student_id', 'id');

        // Sorted oldest-first so keyBy leaves the most recent upload per learner.
        return ParentalConsentForm::whereIn('student_health_record_id', $records->pluck('id'))
            ->where('school_year', ParentalConsentForm::currentSchoolYear())
            ->get()
            ->sortBy('created_at')
            ->keyBy(fn (ParentalConsentForm $upload) => (string) ($lrnByRecordId[$upload->student_health_record_id] ?? ''));
    }

    /**
     * A consent status is only reported once there is a document behind it.
     *
     * A parent-signed online form carries its own e-signature, so it stands on
     * its own. An uploaded record does not: the signed scan is its evidence,
     * and `consent` is nullable on upload, so a record can exist carrying an
     * answer with no file attached. Reporting that answer as Approved would
     * claim consent the school cannot produce — those stay Pending Upload.
     *
     * consent_choice and consent_type are both encrypted, so this compares
     * decrypted values in PHP.
     */
    private function deriveConsentStatus(?HealthConsentForm $form, ?ParentalConsentForm $upload): string
    {
        $signed = [HealthConsentForm::STATUS_SIGNED, HealthConsentForm::STATUS_REVIEWED];

        if ($form !== null && in_array($form->status, $signed, true)) {
            return match ($form->consent_choice) {
                HealthConsentForm::CONSENT_DENY => 'declined',
                HealthConsentForm::CONSENT_SPECIFIC => 'partial',
                default => 'approved',
            };
        }

        if ($upload !== null && $upload->file_path !== null) {
            return match ($upload->consent_type) {
                'refused' => 'declined',
                'partial' => 'partial',
                'full' => 'approved',
                default => 'pending',
            };
        }

        return 'pending';
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

    /** School Nurse: read-only list of completed consent forms for the school. */
    public function nurseIndex(Request $request)
    {
        if ($redirect = $this->requireRole($request, ['school_nurse', 'clinic_staff'])) {
            return $redirect;
        }

        $forms = HealthConsentForm::whereIn('status', [
            HealthConsentForm::STATUS_SIGNED,
            HealthConsentForm::STATUS_REVIEWED,
        ])
            ->when($request->session()->get('active_institution_id'), fn ($q, $id) => $q->where('institution_id', $id))
            ->orderByDesc('signed_at')
            ->get();

        return view('consent-forms.nurse-index', ['forms' => $forms]);
    }

    /** School Nurse: read-only view of one completed form. */
    public function nurseShow(Request $request, HealthConsentForm $form)
    {
        if ($redirect = $this->requireRole($request, ['school_nurse', 'clinic_staff'])) {
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
        if ($redirect = $this->requireRole($request, ['class_adviser', 'school_nurse', 'clinic_staff'])) {
            return $redirect;
        }

        $this->assertSameInstitution($request, $form);

        return view('consent-forms.print', ['form' => $form]);
    }

    /** Students of the adviser's assigned class, normalized for this module. */
    private function assignedStudents(Request $request)
    {
        // Rebuild the roster from the database so the list survives session
        // expiry, re-login, and server restarts.
        StudentRosterSync::syncToSession($request);

        $grade = (string) $request->session()->get('assigned_grade_level', '');
        $section = (string) $request->session()->get('assigned_section', '');

        return collect($request->session()->get('school_health_card_records', []))
            ->filter(function ($row) use ($grade, $section) {
                if ($grade === '' || $section === '') {
                    return true;
                }

                return (string) ($row['grade_level'] ?? '') === $grade
                    && strcasecmp(trim((string) ($row['section'] ?? '')), trim($section)) === 0;
            })
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
