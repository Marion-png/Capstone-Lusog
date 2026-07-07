<?php

namespace App\Http\Controllers;

use App\Models\HealthConsentForm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $forms = HealthConsentForm::where('school_year', $schoolYear)
            ->whereIn('student_lrn', $students->pluck('lrn')->filter()->values())
            ->get()
            ->keyBy('student_lrn');

        $unreadCount = HealthConsentForm::where('adviser_unread', true)->count();

        return view('consent-forms.index', [
            'students' => $students,
            'forms' => $forms,
            'schoolYear' => $schoolYear,
            'unreadCount' => $unreadCount,
        ]);
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

        $form = HealthConsentForm::firstOrNew([
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

    /** Nurse: read-only list of completed consent forms for the school. */
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

    /** Nurse: read-only view of one completed form. */
    public function nurseShow(Request $request, HealthConsentForm $form)
    {
        if ($redirect = $this->requireRole($request, ['school_nurse', 'clinic_staff'])) {
            return $redirect;
        }

        if (! in_array($form->status, [HealthConsentForm::STATUS_SIGNED, HealthConsentForm::STATUS_REVIEWED], true)) {
            return redirect()->route('consent-forms.nurse-index')
                ->with('error', 'This form is not yet available.');
        }

        $form->addAudit('Viewed by school nurse', (string) $request->session()->get('active_role'), (string) $request->session()->get('active_name', 'School Nurse'));
        $form->save();

        return view('consent-forms.nurse-show', ['form' => $form]);
    }

    /** Print / export-as-PDF view (browser print dialog). */
    public function print(Request $request, HealthConsentForm $form)
    {
        if ($redirect = $this->requireRole($request, ['class_adviser', 'school_nurse', 'clinic_staff'])) {
            return $redirect;
        }

        return view('consent-forms.print', ['form' => $form]);
    }

    /** Students of the adviser's assigned class, normalized for this module. */
    private function assignedStudents(Request $request)
    {
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
}
