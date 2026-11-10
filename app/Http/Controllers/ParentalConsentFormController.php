<?php

namespace App\Http\Controllers;

use App\Models\HealthConsentForm;
use App\Models\ParentalConsentForm;
use App\Models\StudentHealthRecord;
use App\Support\AdviserClassScope;
use App\Support\AuditTrail;
use App\Support\ConsentFormScanner;
use App\Support\ConsentReviewLog;
use App\Support\EncryptedFileStorage;
use App\Support\FeedingBeneficiarySummary;
use App\Support\HealthServicesOffered;
use App\Support\LearnerPlacement;
use App\Support\MedicineAllergies;
use App\Support\SchemaCache;
use App\Support\SchoolClock;
use App\Support\SchoolYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class ParentalConsentFormController extends Controller
{
    /**
     * Save parental consent details (and optional signed form upload) for the health services program.
     * Restricted to class_adviser for students in their own assigned class only.
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless(
            $request->session()->get('active_role') === 'class_adviser',
            403,
            'Only a Class Adviser may upload parental consent forms.'
        );

        $validated = $request->validate([
            'lrn' => ['required', 'string', 'max:50'],
            // The dialog asks, but does not insist: an adviser filing a stack
            // of forms must not be made to guess, so "not recorded yet" stays a
            // real answer and an upload carrying none reads as pending rather
            // than as a consent. ConsentStanding::for() reads the parent's own
            // answer, or an attested paper record, ahead of this one, so the
            // two can never contradict each other.
            'consent_type' => ['nullable', 'string', 'in:full,partial,refused'],
            // The answer may have been read off the photo by the consent
            // reader. A model reading a photograph is a typing aid, never the
            // authorisation, so that reading is filed only once the adviser has
            // said it matches the paper — the same attestation Record Signed
            // Paper Form asks for. An answer the adviser chose themselves needs
            // none, exactly as before.
            'answer_from_scan' => ['nullable', 'boolean'],
            'scan_token' => ['nullable', 'string', 'max:100000'],
            'answer_confirmed' => ['accepted_if:answer_from_scan,1'],
            // The services the letter lists, ticked off the paper. Optional,
            // as the answer is: an upload without them reads as the program
            // it was filed for, exactly as every upload did before.
            'services' => ['nullable', 'array'],
            'services.*' => ['string', Rule::in(array_keys(HealthConsentForm::PLANNED_SERVICES))],
            // The services the adviser marked Consented or Excluded in the
            // dialog, then confirmed on its summary. With it, `services` is the
            // Consented list and a partial consent's excepted services are recorded, so
            // each service reads approved or not approved instead of "check the
            // exceptions". Without it the upload reads as it always did.
            'services_confirmed' => ['nullable', 'boolean'],
            'excepted_services' => ['nullable', 'array'],
            'excepted_services.*' => ['string', Rule::in(array_keys(HealthConsentForm::PLANNED_SERVICES))],
            'partial_exception' => ['nullable', 'string', 'max:500'],
            'refused_reason' => ['nullable', 'string', 'max:500'],
            'allergy_food' => ['nullable', 'boolean'],
            'allergy_food_detail' => ['nullable', 'string', 'max:255'],
            'allergy_medicine' => ['nullable', 'boolean'],
            'allergy_medicine_detail' => ['nullable', 'string', 'max:255'],
            'prev_immunization' => ['nullable', 'boolean'],
            'prev_immunization_detail' => ['nullable', 'string', 'max:255'],
            'has_other_illness' => ['nullable', 'boolean'],
            'other_illness_detail' => ['nullable', 'string', 'max:255'],
            'medical_cert_attached' => ['nullable', 'boolean'],
            // Whether the parent's name and signature are both on the form, as
            // the adviser confirmed it against the photo. The dialog always
            // sends it; a form without both is filed as incomplete and gives
            // no service until a complete one is on file. A request without
            // the field files as complete, as every upload did before.
            'form_complete' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'consent' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'medical_certificate' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ], [
            'answer_confirmed.accepted_if' => 'Confirm that the answer read from the photo matches the signed form.',
        ]);

        $consentType = $validated['consent_type'] ?? null;
        $agreed = in_array($consentType, ['full', 'partial'], true);
        $confirmed = $agreed && ! empty($validated['services_confirmed']);

        // A consent that approves nothing is not a consent: an upload confirmed
        // as agreed names at least one service, or an empty list would be read
        // as the one program every older upload covers.
        if ($confirmed && empty($validated['services'])) {
            throw ValidationException::withMessages([
                'services' => 'Mark at least one service the parent approved.',
            ]);
        }

        // A "yes, except" leaves something out: the services it excludes are
        // the rows marked Excluded, so one confirmed with none is a full
        // consent filed under the wrong answer.
        if ($confirmed && $consentType === 'partial' && empty($validated['excepted_services'])) {
            throw ValidationException::withMessages([
                'excepted_services' => 'Mark the services the parent excepted as Excluded.',
            ]);
        }

        $advisedGrade = (string) $request->session()->get('assigned_grade_level', '');
        $advisedSection = (string) $request->session()->get('assigned_section', '');

        abort_if(
            $advisedGrade === '' || $advisedSection === '',
            403,
            'Your account has no assigned class.'
        );

        $expectedSection = trim("{$advisedGrade} / {$advisedSection}");

        $record = StudentHealthRecord::currentForStudent($validated['lrn'], $request->session()->get('active_institution_id'));

        abort_if(
            $record === null || $record->section !== $expectedSection || ! LearnerPlacement::isOpen($record),
            403,
            'You may only upload consent forms for students in your assigned class.'
        );

        $scan = $this->verifiedScan($request, $validated);
        $storesScanResult = SchemaCache::hasColumn('parental_consent_forms', 'scan_result');
        if ($scan !== null && ! $storesScanResult) {
            throw ValidationException::withMessages([
                'consent' => 'The consent photo reading cannot be saved yet. Please contact the system administrator to finish the consent update.',
            ]);
        }

        if ($scan !== null && ($validated['consent_type'] ?? null) !== null) {
            $request->validate(['answer_confirmed' => ['accepted']], [
                'answer_confirmed.accepted' => 'Confirm that the answer read from the photo matches the signed form.',
            ]);
            // The reader's answer, or the adviser's correction of it: both are
            // filed with the reading, and the review log says which.
            $validated['answer_from_scan'] = self::scanAnswer($scan) === $validated['consent_type'];

            // Keep the clinical fields in step with the reading the adviser
            // checked. Explicit corrections take precedence over a proposal.
            foreach (['consent_exceptions' => 'partial_exception', 'refusal_reason' => 'refused_reason'] as $source => $target) {
                if (! array_key_exists($target, $validated)) {
                    $validated[$target] = (string) data_get($scan, 'written.'.$source.'.text', '');
                }
            }
            foreach (['allergy_food' => 'allergy_food', 'allergy_medicine' => 'allergy_medicine', 'prev_immunization' => 'prev_immunization', 'other_illness' => 'has_other_illness'] as $source => $target) {
                $entry = data_get($scan, 'written.'.$source, []);
                $detail = (string) ($entry['text'] ?? '');
                $detailKey = $source === 'other_illness' ? 'other_illness_detail' : $source.'_detail';
                if (! array_key_exists($target, $validated)) {
                    $validated[$target] = ($entry['box'] ?? '') === ConsentFormScanner::TICKED
                        || ConsentFormScanner::saysSomething($detail);
                }
                if (! array_key_exists($detailKey, $validated)) {
                    $validated[$detailKey] = $detail;
                }
            }
        }

        $schoolYear = ParentalConsentForm::currentSchoolYear();
        $path = null;
        $originalName = null;

        if ($request->hasFile('consent')) {
            $file = $request->file('consent');
            $originalName = $file->getClientOriginalName();
            $path = EncryptedFileStorage::store($file, 'parental-consents/'.$record->id);
        }

        $medCertPath = null;
        $medCertOriginalName = null;

        if ($request->hasFile('medical_certificate')) {
            $medCert = $request->file('medical_certificate');
            $medCertOriginalName = $medCert->getClientOriginalName();
            $medCertPath = EncryptedFileStorage::store($medCert, 'parental-consents/'.$record->id.'/med-cert');
        }

        // A write-in line counts when something is written on it: "wala" on
        // the allergy line is the parent saying there is none.
        $writes = fn (string $field): bool => ConsentFormScanner::saysSomething((string) ($validated[$field] ?? ''));

        $services = array_values(array_unique($validated['services'] ?? []));

        // A partial consent's exceptions, as the adviser marked them. They are
        // services the letter lists, so they stay on its list, and are read as
        // not approved through ConsentStanding::answer()'s `excluded`.
        $excepted = $confirmed && $consentType === 'partial'
            ? array_values(array_diff(array_unique($validated['excepted_services'] ?? []), $services))
            : null;
        if ($excepted !== null) {
            $services = array_values(array_unique([...$services, ...$excepted]));
        }

        $incomplete = $request->has('form_complete') && ! $request->boolean('form_complete');

        // For each service and field, what the reader proposed beside what the
        // adviser files, so edited fields can be counted later.
        $review = $scan !== null && SchemaCache::hasColumn('parental_consent_forms', 'scan_review')
            ? $this->reviewLog($scan, $record, $schoolYear, [
                'consent_type' => $consentType,
                'services' => $services,
                'excluded' => $excepted,
                'partial_exception' => $consentType === 'partial' ? ($validated['partial_exception'] ?? null) : null,
                'refused_reason' => $consentType === 'refused' ? ($validated['refused_reason'] ?? null) : null,
                'allergy_food_detail' => $validated['allergy_food_detail'] ?? null,
                'allergy_medicine_detail' => $validated['allergy_medicine_detail'] ?? null,
                'prev_immunization_detail' => $validated['prev_immunization_detail'] ?? null,
                'other_illness_detail' => $validated['other_illness_detail'] ?? null,
                'incomplete' => $incomplete,
            ])
            : null;

        $upload = ParentalConsentForm::create([
            'student_health_record_id' => $record->id,
            'program_type' => ParentalConsentForm::PROGRAM_HEALTH_SERVICES,
            'school_year' => $schoolYear,
            'consent_type' => $consentType,
            // Each blank belongs to the answer it is written under.
            'partial_exception' => $consentType === 'partial' ? ($validated['partial_exception'] ?? null) : null,
            'refused_reason' => $consentType === 'refused' ? ($validated['refused_reason'] ?? null) : null,
            'allergy_food' => ! empty($validated['allergy_food']) || $writes('allergy_food_detail'),
            'allergy_food_detail' => $validated['allergy_food_detail'] ?? null,
            'allergy_medicine' => ! empty($validated['allergy_medicine']) || $writes('allergy_medicine_detail'),
            'allergy_medicine_detail' => $validated['allergy_medicine_detail'] ?? null,
            'prev_immunization' => ! empty($validated['prev_immunization']) || $writes('prev_immunization_detail'),
            'prev_immunization_detail' => $validated['prev_immunization_detail'] ?? null,
            'has_other_illness' => ! empty($validated['has_other_illness']) || $writes('other_illness_detail'),
            'other_illness_detail' => $validated['other_illness_detail'] ?? null,
            // Uploading a certificate is itself proof one was attached, so the
            // flag is set either by the file or by the explicit checkbox.
            'medical_cert_attached' => $medCertPath !== null || ! empty($validated['medical_cert_attached']),
            'med_cert_path' => $medCertPath,
            'med_cert_original_name' => $medCertOriginalName,
            'notes' => $validated['notes'] ?? null,
            'file_path' => $path,
            'file_original_name' => $originalName,
            'uploaded_by_name' => (string) $request->session()->get('active_name', 'Class Adviser'),
            ...($storesScanResult ? ['scan_result' => $scan] : []),
            ...($review !== null ? ['scan_review' => $review] : []),
            ...($incomplete && SchemaCache::hasColumn('parental_consent_forms', 'incomplete') ? ['incomplete' => true] : []),
        ] + (SchemaCache::hasColumn('parental_consent_forms', 'services')
            // Written once the column exists, so an upload still files
            // before `php artisan migrate` has been run.
            ? ['services' => $services !== [] ? $services : null]
            : [])
        + ($excepted !== null && SchemaCache::hasColumn('parental_consent_forms', 'excluded_services')
            ? ['excluded_services' => $excepted]
            : []));

        // Who decided the answer is part of the evidence: a nurse asking how a
        // "full consent" got onto this record can find that the reader
        // proposed it and the adviser confirmed it against the paper.
        if (! empty($validated['answer_from_scan']) && ($validated['consent_type'] ?? null) !== null) {
            AuditTrail::record(
                'confirmed',
                'ParentalConsentForm',
                $upload->id,
                'Consent answer read from the photographed form and confirmed by the class adviser',
            );
        } elseif ($scan !== null && $consentType !== null) {
            AuditTrail::record(
                'confirmed',
                'ParentalConsentForm',
                $upload->id,
                'Consent answer chosen by the class adviser in place of the photo reading',
            );
        }

        if ($incomplete) {
            AuditTrail::record(
                'confirmed',
                'ParentalConsentForm',
                $upload->id,
                'Consent form filed as incomplete: missing parent name or signature',
            );
        }

        // A medicine allergy on the returned form, as the adviser confirmed it,
        // is looked up in RxNorm.
        MedicineAllergies::syncLater($record->institution_id, $record->student_id, (string) $request->session()->get('active_name', ''));

        // The last step of the upload flow, the same words whatever the answer:
        // the form is on file, and the Consent tab says what it approves.
        return back()
            ->with('consent_success', 'Consent saved successfully.')
            ->with('consent_detail_lrn', $validated['lrn']);
    }

    /** Only the server's reading of this exact learner and image can be filed. */
    private function verifiedScan(Request $request, array $validated): ?array
    {
        $token = $validated['scan_token'] ?? null;
        if (! is_string($token) || $token === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages(['scan_token' => 'The saved reading is invalid. Read the photo again.']);
        }

        $file = $request->file('consent');
        if (! is_array($payload)
            || ! is_array($payload['draft'] ?? null)
            || ($payload['expires_at'] ?? 0) < now()->timestamp
            || ($payload['lrn'] ?? '') !== trim($validated['lrn'])
            || (string) ($payload['institution_id'] ?? '') !== (string) $request->session()->get('active_institution_id')
            || ($payload['actor'] ?? '') !== (string) $request->session()->get('active_username', '')
            || $file === null
            || ! hash_equals((string) ($payload['photo_hash'] ?? ''), (string) hash_file('sha256', $file->getRealPath()))) {
            throw ValidationException::withMessages(['scan_token' => 'This reading no longer matches the selected student and photo. Read the photo again.']);
        }

        if (! empty($payload['draft']['learner_mismatch'])) {
            throw ValidationException::withMessages(['consent' => 'The name on this form belongs to another student. Choose the correct signed form.']);
        }

        return $payload['draft'];
    }

    /** The answer the reader read off the sheet, as the upload names it; null when it read none. */
    private static function scanAnswer(array $draft): ?string
    {
        return ['all' => 'full', 'specific' => 'partial', 'deny' => 'refused'][$draft['consent_choice'] ?? ''] ?? null;
    }

    /**
     * The review log for one upload, read against the learner's grade and sex
     * and the services the school offers that year — the same list the dialog
     * started its rows from.
     *
     * @param  array<string, mixed>  $scan
     * @param  array<string, mixed>  $saved
     * @return array<string, mixed>
     */
    private function reviewLog(array $scan, StudentHealthRecord $record, string $schoolYear, array $saved): array
    {
        [$gradeLabel] = FeedingBeneficiarySummary::splitSection((string) $record->section);

        return ConsentReviewLog::build(
            $scan,
            $saved,
            LearnerPlacement::gradeNumber($gradeLabel),
            FeedingBeneficiarySummary::sexOf($record),
            HealthServicesOffered::for($record->institution_id ? (int) $record->institution_id : null, $schoolYear),
        );
    }

    /**
     * Return whether a valid consent form exists for a student for the current school year.
     * Accessible by class_adviser (own class only), clinic_staff, and school_nurse.
     */
    public function consentStatus(Request $request): JsonResponse
    {
        $activeRole = (string) $request->session()->get('active_role', '');

        abort_unless(
            in_array($activeRole, ['class_adviser', 'clinic_staff', 'school_nurse', 'clinic_teacher'], true),
            403,
            'Access denied.'
        );

        $lrn = (string) $request->query('lrn', '');
        if ($lrn === '') {
            return response()->json(['has_consent' => false, 'school_year' => ParentalConsentForm::currentSchoolYear()]);
        }

        // Health Records can open an earlier school year; the consent read is
        // that year's then (SchoolYear::askedFor — an adviser's is always this year's).
        $schoolYear = SchoolYear::askedFor($request);
        $record = StudentHealthRecord::currentForStudent($lrn, $request->session()->get('active_institution_id'), $schoolYear);

        if ($activeRole === 'class_adviser' && $record !== null) {
            $grade = (string) $request->session()->get('assigned_grade_level', '');
            $section = (string) $request->session()->get('assigned_section', '');
            $expected = trim("{$grade} / {$section}");
            if ($grade === '' || $section === '' || $record->section !== $expected || ! LearnerPlacement::isOpen($record)) {
                return response()->json(['has_consent' => false, 'school_year' => ParentalConsentForm::currentSchoolYear()]);
            }
        }

        if ($record === null) {
            return response()->json(['has_consent' => false, 'school_year' => $schoolYear]);
        }

        $form = ParentalConsentForm::where('student_health_record_id', $record->id)
            ->where('program_type', 'Deworming')
            ->where('school_year', $schoolYear)
            ->latest()
            ->first();

        return response()->json([
            'has_consent' => $form !== null,
            'school_year' => $schoolYear,
            'uploaded_by' => $form?->uploaded_by_name,
            'uploaded_at' => SchoolClock::local($form?->created_at)?->format('M d, Y'),
            'consent_id' => $form?->id,
            'consent_type' => $form?->consent_type,
            // Missing the parent's name or signature: on file, but it gives no
            // service until a complete form is filed.
            'incomplete' => (bool) $form?->incomplete,
            'partial_exception' => $form?->partial_exception,
            'refused_reason' => $form?->refused_reason,
            'allergy_food' => (bool) $form?->allergy_food,
            'allergy_food_detail' => $form?->allergy_food_detail,
            'allergy_medicine' => (bool) $form?->allergy_medicine,
            'allergy_medicine_detail' => $form?->allergy_medicine_detail,
            'prev_immunization' => (bool) $form?->prev_immunization,
            'prev_immunization_detail' => $form?->prev_immunization_detail,
            'has_other_illness' => (bool) $form?->has_other_illness,
            'other_illness_detail' => $form?->other_illness_detail,
            'medical_cert_attached' => (bool) $form?->medical_cert_attached,
            'has_file' => $form?->file_path !== null,
            // The name the guardian's form was filed under, off the same row
            // the upload wrote it to. A screen that offers the document should
            // be able to say which document it is offering.
            'file_name' => $form?->file_path !== null ? ($form->file_original_name ?: null) : null,
        ]);
    }

    /**
     * Serve a consent form file for download.
     *
     * The clinic reads it, and so does **the learner's own class adviser** —
     * they are the desk that scanned the guardian's form and filed it, and a
     * role that may upload a document but never open it again cannot check its
     * own work. The same split `MlatExportController::download` keeps, and the
     * same per-section test: `AdviserClassScope::coversRecord()`, so an adviser
     * reaches their own class and no colleague's. `parental-consent/*` is
     * already in `AuditSensitiveAccess::SENSITIVE_PATTERNS`, so every one of
     * these reads is recorded whoever made it.
     */
    public function download(Request $request, int $id): Response
    {
        $role = (string) $request->session()->get('active_role', '');

        abort_unless(
            in_array($role, ['clinic_staff', 'school_nurse', 'class_adviser', 'clinic_teacher'], true),
            403,
            'Only the clinic or the learner\'s class adviser may open consent forms.'
        );

        $form = ParentalConsentForm::with('studentHealthRecord')->find($id);
        abort_if($form === null, 404, 'Consent form not found.');

        $institutionId = $request->session()->get('active_institution_id');
        $recordInstitutionId = $form->studentHealthRecord?->institution_id;
        abort_if(
            $institutionId && $recordInstitutionId && (int) $recordInstitutionId !== (int) $institutionId,
            404,
            'Consent form not found.'
        );

        // An adviser is scoped twice — to their school, and within it to one
        // class. Fails closed: no record to test against is not a pass.
        //
        // The class is the learner's now, not the one the scan was filed
        // under: last year's form moves with the learner to their new adviser
        // and is gone from the adviser they left (rule 4 of the school-year
        // handover). An adviser still reads a learner archived from their own
        // section.
        if ($role === 'class_adviser') {
            $record = $form->studentHealthRecord;
            abort_if(
                $record === null || ! AdviserClassScope::mayReadLearner($request, (string) $record->student_id),
                404,
                'Consent form not found.'
            );
        }

        abort_if($form->file_path === null, 404, 'No file was uploaded for this consent record.');

        abort_unless(
            Storage::disk('local')->exists($form->file_path),
            404,
            'Consent form file not found on disk.'
        );

        return EncryptedFileStorage::response($form->file_path, $form->file_original_name, 'inline');
    }
}
