<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The My Students tab renders the adviser's own roster as a records table
 * (LRN, name, sex, age, health/profile/consent status), and the enrolment
 * form's Sheet 2 (Systems Review) is persisted alongside Sheet 1 rather
 * than being discarded on submit.
 */
class AdviserMyStudentsTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    private function adviserSession(): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Test Adviser',
            'active_username' => 'adviser1',
            'active_institution_id' => $this->institution->id,
            'active_school_name' => 'Test School',
            'assigned_school_name' => 'Test School',
            'assigned_grade_level' => 'Grade 1',
            'assigned_section' => 'Sampaguita',
        ];
    }

    /** @param  array<string, mixed>  $overrides */
    private function enrol(array $overrides = []): void
    {
        $this->withSession($this->adviserSession())
            ->post(route('adviser.store'), array_merge([
                'last_name' => 'Dela Cruz',
                'first_name' => 'Juan',
                'middle_name' => 'A',
                'lrn' => '123456789012',
                'birth_date' => '2015-06-01',
                'birthplace' => 'Davao City',
                'parent_guardian' => 'Maria Dela Cruz',
                'address' => '123 Mabini St., Davao City',
                'school_id' => 'SCH-001',
                'region' => 'XI',
                'division' => 'Davao City',
                'telephone_no' => '09171234567',
                'gender' => 'Male',
                'height_cm' => 110,
                'weight_kg' => 18.5,
                'grade_level' => 'Grade 1',
                'section' => 'Sampaguita',
            ], $overrides))
            ->assertRedirect(route('dashboard.class-adviser'));
    }

    /** @test */
    public function the_my_students_table_lists_the_roster_with_its_status_columns(): void
    {
        $this->enrol();

        $response = $this->flushSession()
            ->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser', ['tab' => 'saved']));

        $response->assertOk();
        $response->assertSee('Student Records');
        $response->assertSee('123456789012');
        $response->assertSee('Dela Cruz, Juan A.');
        $response->assertSee('Male');

        // No assessment and no consent form yet, so both columns read Pending.
        $response->assertSee('ms-profile-pending', false);
        $response->assertSee('ms-consent-pending', false);

        // Rows carry the metadata the client-side search/filter reads.
        $response->assertSee('data-status="pending"', false);
        $response->assertSee('data-lrn="123456789012"', false);
    }

    /** @test */
    public function the_enrol_button_is_labelled_enroll_student_and_both_sheets_are_available(): void
    {
        $response = $this->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser', ['tab' => 'saved']));

        $response->assertOk();
        $response->assertSee('Enroll Student');
        $response->assertDontSee('Add Student');

        // Sheet 2 is rendered as an enabled tab — never gated behind Sheet 1.
        $response->assertSee('id="sheetTab2"', false);
        $response->assertDontSee('sheet-tab" disabled', false);
    }

    /** @test */
    public function the_breadcrumb_names_the_tab_being_viewed(): void
    {
        $session = $this->adviserSession();

        $this->withSession($session)->get(route('dashboard.class-adviser'))
            ->assertOk()->assertSee('>Dashboard</div>', false);

        $this->withSession($session)->get(route('dashboard.class-adviser', ['tab' => 'saved']))
            ->assertOk()->assertSee('>My Students</div>', false);

        $this->withSession($session)->get(route('dashboard.class-adviser', ['tab' => 'form']))
            ->assertOk()->assertSee('>Enroll Student</div>', false);
    }

    /** @test */
    public function the_sidebar_has_no_health_assessment_entry(): void
    {
        // Assert the nav link is gone, not the phrase — the enrolment form is
        // itself the Mandatory Learner's Health Assessment and says so.
        $this->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser'))
            ->assertOk()
            ->assertDontSee(route('health-assessments.index'), false);
    }

    /** @test */
    public function the_sidebar_has_no_school_health_card_entry_and_tab_form_highlights_my_students(): void
    {
        // The enrolment form is reached from the Enroll Student button, so it
        // no longer has a nav entry of its own. Assert the link is gone rather
        // than the phrase — "School Health Card" is legitimate prose elsewhere.
        $this->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser'))
            ->assertOk()
            ->assertDontSee('tab=form', false);

        // A bookmarked ?tab=form deep link still opens the form panel, with My
        // Students marked active in the sidebar.
        $response = $this->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser', ['tab' => 'form']));

        $response->assertOk();
        $response->assertSee('id="prototype-form-panel" class="card section section-panel active"', false);
        $response->assertSee('Enroll Student');
    }

    /** @test */
    public function sheet_two_answers_are_saved_with_the_student_and_survive_session_loss(): void
    {
        $this->enrol([
            'systems_review' => [
                'skin_normal' => '1',
                'skin_lesions' => '1',
                'resp_cough' => '1',
                'dental_caries' => '1',
                'right_eye' => '20/20',
                'left_eye' => '20/25',
                'summary' => 'Mild cough noted during assessment.',
                'recommendations' => 'Refer to school clinic.',
                'examiner_name' => 'Maria Santos, RN',
            ],
        ]);

        $record = StudentHealthRecord::where('student_id', '123456789012')->first();
        $this->assertNotNull($record);

        $review = $record->student_details['systems_review'];
        $this->assertTrue($review['skin_normal']);
        $this->assertTrue($review['skin_lesions']);
        $this->assertTrue($review['resp_cough']);
        $this->assertTrue($review['dental_caries']);
        $this->assertSame('20/20', $review['right_eye']);
        $this->assertSame('Mild cough noted during assessment.', $review['summary']);
        $this->assertSame('Maria Santos, RN', $review['examiner_name']);

        // Unchecked boxes are absent from the request and must store as false,
        // and unfilled text answers as null — never as a stale default.
        $this->assertFalse($review['skin_pallor']);
        $this->assertFalse($review['immun_incomplete']);
        $this->assertNull($review['notes']);

        // The rebuilt roster carries Sheet 2 back into the session.
        $this->flushSession()
            ->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser'))
            ->assertOk();

        $row = collect(session('school_health_card_records'))
            ->first(fn ($r) => ($r['lrn'] ?? '') === '123456789012');

        $this->assertSame('20/25', $row['systems_review']['left_eye']);
    }

    /** @test */
    public function every_row_offers_view_edit_and_parent_consent(): void
    {
        $this->enrol();

        $response = $this->flushSession()
            ->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser', ['tab' => 'saved']));

        $response->assertOk();
        $response->assertSee('title="View Profile"', false);
        $response->assertSee('title="Edit Profile"', false);
        $response->assertSee('title="Parent\'s Consent"', false);

        // Parent's Consent posts this learner's LRN so the adviser lands on
        // their own consent form, not the index.
        $response->assertSee('action="'.route('consent-forms.open').'"', false);
        $response->assertSee('name="lrn" value="123456789012"', false);
    }

    /** @test */
    public function parent_consent_action_opens_that_learners_consent_form(): void
    {
        $this->enrol();

        $this->withSession($this->adviserSession())
            ->post(route('consent-forms.open'), ['lrn' => '123456789012'])
            ->assertRedirectContains('/consent-forms/');
    }

    /** @test */
    public function view_profile_on_my_students_links_to_the_dedicated_profile_page(): void
    {
        $this->enrol();

        $response = $this->flushSession()
            ->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser', ['tab' => 'saved']));

        $response->assertOk();
        $response->assertSee(
            'href="'.route('dashboard.class-adviser.student-profile', '123456789012').'"',
            false
        );
    }

    /** @test */
    public function the_student_profile_page_is_read_only_and_keeps_the_shared_sidebar(): void
    {
        $this->enrol();

        $html = $this->flushSession()
            ->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser.student-profile', '123456789012'))
            ->assertOk()
            ->getContent();

        // The shared adviser chrome is still present — this is a full page,
        // not a bare fragment.
        $this->assertStringContainsString('id="asbSearchInput"', $html);
        $this->assertStringContainsString('class="asb-sidebar"', $html);

        // Isolate the profile card and assert it carries no way to submit.
        $start = strpos($html, 'class="card student-profile-page"');
        $end = strpos($html, '<script>', $start);
        $this->assertNotFalse($start);
        $card = substr($html, $start, $end - $start);

        foreach (['<form', '<input', '<select', '<textarea'] as $editable) {
            $this->assertStringNotContainsString(
                $editable,
                $card,
                "The read-only student profile must not contain {$editable}."
            );
        }

        $this->assertStringNotContainsString(route('health-assessment.store'), $card);
        $this->assertStringNotContainsString('Save Health Assessment', $card);

        // Print and Edit Profile (which navigates to the real edit form) are
        // offered, and the read-only intent for clinic-recorded data is stated.
        $this->assertStringContainsString('id="vpPrint"', $card);
        $this->assertStringContainsString('id="vpEditProfile"', $html);
        $this->assertStringContainsString('Read-only', $html);
    }

    /** @test */
    public function the_profile_page_carries_the_tabs_and_their_read_only_data(): void
    {
        $this->enrol(['systems_review' => ['resp_cough' => '1']]);

        $response = $this->flushSession()
            ->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser.student-profile', '123456789012'));

        $response->assertOk();
        $response->assertSee('data-panel="vpTabSheet1"', false);
        $response->assertSee('data-panel="vpTabSheet2"', false);
        $response->assertSee('data-panel="vpTabConsent"', false);
        $response->assertSee('data-panel="vpTabFeeding"', false);

        // The Consent and Feeding tabs read from the meta embedded for the
        // page's own rendering script.
        $response->assertSee('STUDENT_PROFILE_META', false);
        $response->assertSee('consent_detail', false);
        $response->assertSee('baseline_status', false);
    }

    /** @test */
    public function sheet_one_of_the_profile_shows_only_sheet_one_content(): void
    {
        $this->enrol();

        $html = $this->flushSession()
            ->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser.student-profile', '123456789012'))
            ->assertOk()
            ->getContent();

        $start = strpos($html, 'id="vpTabSheet1"');
        $end = strpos($html, 'id="vpTabSheet2"', $start);
        $this->assertNotFalse($start);
        $sheetOne = substr($html, $start, $end - $start);

        // Sheet 1 mirrors the enrolment form's Sheet 1 and nothing else.
        $this->assertStringContainsString('Learner Information', $sheetOne);
        $this->assertStringContainsString('Parent/Guardian', $sheetOne);
        $this->assertStringContainsString('Medical &amp; Family History', $sheetOne);
        $this->assertStringContainsString('General Appearance', $sheetOne);
        $this->assertStringContainsString('Vital Signs', $sheetOne);

        foreach ([
            'School Nurse Examination',
            'Health Assessment',
            'Systems Review',
            'Vision Screening',
            'Deworming',
            'Pending School Nurse Review',
        ] as $foreign) {
            $this->assertStringNotContainsString(
                $foreign,
                $sheetOne,
                "'{$foreign}' is not Sheet 1 content and must not appear there."
            );
        }
    }

    /** @test */
    public function the_student_profile_page_404s_for_a_learner_outside_the_advisers_roster(): void
    {
        $this->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser.student-profile', '999999999999'))
            ->assertRedirect(route('dashboard.class-adviser', ['tab' => 'saved']));
    }

    /** @test */
    public function editing_a_learner_updates_their_row_instead_of_adding_a_second_one(): void
    {
        $this->enrol(['systems_review' => ['resp_cough' => '1']]);

        // Re-post the same LRN, as the Edit Profile form does.
        $this->enrol([
            'address' => '456 Rizal St., Davao City',
            'telephone_no' => '09998887777',
            'weight_kg' => 21.0,
            'systems_review' => ['resp_cough' => '1', 'dental_caries' => '1'],
        ]);

        $this->assertSame(
            1,
            StudentHealthRecord::where('student_id', '123456789012')->count(),
            'The same LRN must resolve to one health record.'
        );

        $roster = collect(session('school_health_card_records'))
            ->filter(fn ($r) => ($r['lrn'] ?? '') === '123456789012');

        $this->assertCount(1, $roster, 'The learner must appear once in My Students.');

        $row = $roster->first();
        $this->assertSame('456 Rizal St., Davao City', $row['address']);
        $this->assertSame('09998887777', $row['telephone_no']);
        $this->assertTrue($row['systems_review']['dental_caries']);

        $record = StudentHealthRecord::where('student_id', '123456789012')->first();
        $this->assertSame('456 Rizal St., Davao City', $record->student_details['address']);
        $this->assertEquals(21.0, $record->baseline_weight_kg);
    }

    /** @test */
    public function editing_a_learner_preserves_the_nurse_examination(): void
    {
        $this->enrol();

        // The nurse's examination lives on the roster row alongside the
        // adviser's fields; an adviser edit must not clear it.
        $roster = session('school_health_card_records');
        $roster[0]['examination'] = ['examined_by' => 'Nurse Cruz', 'date_of_examination' => '2026-07-01'];

        $this->withSession(array_merge($this->adviserSession(), [
            'school_health_card_records' => $roster,
        ]))->post(route('adviser.store'), [
            'last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'middle_name' => 'A',
            'lrn' => '123456789012', 'birth_date' => '2015-06-01', 'birthplace' => 'Davao City',
            'parent_guardian' => 'Maria Dela Cruz', 'address' => 'Edited address',
            'school_id' => 'SCH-001', 'region' => 'XI', 'division' => 'Davao City',
            'telephone_no' => '09171234567', 'gender' => 'Male', 'height_cm' => 112,
            'weight_kg' => 19.0, 'grade_level' => 'Grade 1', 'section' => 'Sampaguita',
        ])->assertRedirect(route('dashboard.class-adviser'));

        $row = collect(session('school_health_card_records'))
            ->first(fn ($r) => ($r['lrn'] ?? '') === '123456789012');

        $this->assertSame('Edited address', $row['address']);
        $this->assertSame('Nurse Cruz', $row['examination']['examined_by']);
    }

    /** A 1x1 transparent PNG as the canvas would hand it over. */
    private function signature(): string
    {
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
    }

    /** @test */
    public function the_examiner_signature_pad_sits_after_recommendations_on_sheet_two(): void
    {
        $response = $this->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser', ['tab' => 'saved']));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('id="signatureCanvas"', $html);
        $this->assertStringContainsString('name="systems_review[examiner_signature]"', $html);
        $this->assertStringContainsString('Draw Signature', $html);
        $this->assertStringContainsString('Upload Image', $html);

        // Order on Sheet 2: Recommendations → Signature → Examiner Name.
        $recommendations = strpos($html, 'systems_review[recommendations]');
        $signature = strpos($html, 'id="signatureCanvas"');
        $examinerName = strpos($html, 'systems_review[examiner_name]');

        $this->assertTrue($recommendations < $signature, 'Signature must come after Recommendations.');
        $this->assertTrue($signature < $examinerName, 'Signature must come before Examiner Name.');
    }

    /** @test */
    public function the_signature_is_stored_on_the_record_but_kept_out_of_the_session(): void
    {
        $this->enrol(['systems_review' => ['examiner_signature' => $this->signature()]]);

        $record = StudentHealthRecord::where('student_id', '123456789012')->first();
        $this->assertSame(
            $this->signature(),
            $record->student_details['systems_review']['examiner_signature']
        );

        // The roster is read and rewritten on every request, so it carries only
        // a flag — never the image.
        $row = collect(session('school_health_card_records'))
            ->first(fn ($r) => ($r['lrn'] ?? '') === '123456789012');

        $this->assertNull($row['systems_review']['examiner_signature']);
        $this->assertTrue($row['systems_review']['examiner_signature_present']);

        // Same after the roster is rebuilt from the database.
        $this->flushSession()->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser'))->assertOk();

        $rebuilt = collect(session('school_health_card_records'))
            ->first(fn ($r) => ($r['lrn'] ?? '') === '123456789012');

        $this->assertNull($rebuilt['systems_review']['examiner_signature']);
        $this->assertTrue($rebuilt['systems_review']['examiner_signature_present']);
    }

    /** @test */
    public function editing_without_re_signing_keeps_the_signature_on_file(): void
    {
        $this->enrol(['systems_review' => ['examiner_signature' => $this->signature()]]);

        // The pad opens blank on edit, so a blank submit must not wipe it.
        $this->enrol([
            'address' => 'Edited address',
            'systems_review' => ['examiner_signature' => ''],
        ]);

        $record = StudentHealthRecord::where('student_id', '123456789012')->first();
        $this->assertSame('Edited address', $record->student_details['address']);
        $this->assertSame(
            $this->signature(),
            $record->student_details['systems_review']['examiner_signature'],
            'A blank signature field on edit must keep the stored signature.'
        );
    }

    /** @test */
    public function a_new_signature_replaces_the_stored_one(): void
    {
        $this->enrol(['systems_review' => ['examiner_signature' => $this->signature()]]);

        $replacement = 'data:image/jpeg;base64,'.base64_encode('replacement');
        $this->enrol(['systems_review' => ['examiner_signature' => $replacement]]);

        $record = StudentHealthRecord::where('student_id', '123456789012')->first();
        $this->assertSame($replacement, $record->student_details['systems_review']['examiner_signature']);
    }

    /** @test */
    public function a_signature_that_is_not_an_image_data_url_is_discarded(): void
    {
        $this->enrol(['systems_review' => ['examiner_signature' => '<script>alert(1)</script>']]);

        $record = StudentHealthRecord::where('student_id', '123456789012')->first();
        $this->assertNull($record->student_details['systems_review']['examiner_signature']);
    }

    /** @test */
    public function an_oversized_signature_is_rejected(): void
    {
        $this->withSession($this->adviserSession())
            ->post(route('adviser.store'), [
                'last_name' => 'Dela Cruz', 'first_name' => 'Juan',
                'lrn' => '123456789012', 'birth_date' => '2015-06-01',
                'birthplace' => 'Davao City', 'parent_guardian' => 'Maria Dela Cruz',
                'address' => '123 Mabini St.', 'school_id' => 'SCH-001', 'region' => 'XI',
                'division' => 'Davao City', 'telephone_no' => '09171234567', 'gender' => 'Male',
                'height_cm' => 110, 'weight_kg' => 18.5,
                'grade_level' => 'Grade 1', 'section' => 'Sampaguita',
                'systems_review' => ['examiner_signature' => 'data:image/png;base64,'.str_repeat('A', 2_900_001)],
            ])
            ->assertSessionHasErrors('systems_review.examiner_signature');

        $this->assertNull(StudentHealthRecord::where('student_id', '123456789012')->first());
    }

    /** @test */
    public function sheet_one_captures_medical_history_appearance_and_the_new_vitals(): void
    {
        $this->enrol([
            'temperature_c' => 36.5,
            'pulse_bpm' => 72,
            'blood_pressure' => '110/70',
            'health_history' => [
                'med_seizure' => '1',
                'med_allergies' => '1',
                'allergies_detail' => 'Peanuts',
                'fam_diabetes' => '1',
                'current_medications' => 'Salbutamol',
                'genetic_disorders' => 'None known',
                'consciousness' => 'Drowsy',
                'posture' => 'Abnormal',
                'posture_detail' => 'Slight limp',
                'hygiene' => 'Adequate',
            ],
        ]);

        $details = StudentHealthRecord::where('student_id', '123456789012')->first()->student_details;

        $this->assertEquals(36.5, $details['temperature_c']);
        $this->assertEquals(72, $details['pulse_bpm']);
        $this->assertSame('110/70', $details['blood_pressure']);

        $history = $details['health_history'];
        $this->assertTrue($history['med_seizure']);
        $this->assertTrue($history['med_allergies']);
        $this->assertTrue($history['fam_diabetes']);
        $this->assertSame('Peanuts', $history['allergies_detail']);
        $this->assertSame('Salbutamol', $history['current_medications']);
        $this->assertSame('Drowsy', $history['consciousness']);
        $this->assertSame('Abnormal', $history['posture']);
        $this->assertSame('Slight limp', $history['posture_detail']);

        // Unticked boxes store as false, unfilled text as null.
        $this->assertFalse($history['med_asthma']);
        $this->assertNull($history['other_conditions']);

        // The roster rebuild carries it all back for Edit Profile to repopulate.
        $this->flushSession()->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser'))->assertOk();

        $row = collect(session('school_health_card_records'))
            ->first(fn ($r) => ($r['lrn'] ?? '') === '123456789012');

        $this->assertSame('Peanuts', $row['health_history']['allergies_detail']);
        $this->assertSame('110/70', $row['blood_pressure']);
    }

    /** @test */
    public function the_new_sheet_one_fields_render_on_the_enrolment_form(): void
    {
        $response = $this->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser', ['tab' => 'form']));

        $response->assertOk();
        $response->assertSee('Medical History');
        $response->assertSee('Family History');
        $response->assertSee('General Appearance');
        $response->assertSee('name="health_history[med_asthma]"', false);
        $response->assertSee('name="health_history[fam_hypertension]"', false);
        $response->assertSee('name="health_history[consciousness]"', false);
        $response->assertSee('name="temperature_c"', false);
        $response->assertSee('name="pulse_bpm"', false);
        $response->assertSee('name="blood_pressure"', false);
    }

    /** @test */
    public function out_of_range_vitals_are_rejected(): void
    {
        $this->withSession($this->adviserSession())
            ->post(route('adviser.store'), [
                'last_name' => 'Dela Cruz', 'first_name' => 'Juan',
                'lrn' => '123456789012', 'birth_date' => '2015-06-01',
                'birthplace' => 'Davao City', 'parent_guardian' => 'Maria Dela Cruz',
                'address' => '123 Mabini St.', 'school_id' => 'SCH-001', 'region' => 'XI',
                'division' => 'Davao City', 'telephone_no' => '09171234567', 'gender' => 'Male',
                'height_cm' => 110, 'weight_kg' => 18.5,
                'grade_level' => 'Grade 1', 'section' => 'Sampaguita',
                'temperature_c' => 120,
                'pulse_bpm' => 900,
            ])
            ->assertSessionHasErrors(['temperature_c', 'pulse_bpm']);

        $this->assertNull(StudentHealthRecord::where('student_id', '123456789012')->first());
    }

    /** @test */
    public function unknown_sheet_two_keys_are_dropped(): void
    {
        $this->enrol([
            'systems_review' => [
                'skin_normal' => '1',
                'evil_key' => 'should not be stored',
            ],
        ]);

        $review = StudentHealthRecord::where('student_id', '123456789012')
            ->first()
            ->student_details['systems_review'];

        $this->assertArrayNotHasKey('evil_key', $review);
        $this->assertTrue($review['skin_normal']);
    }

    /** @test */
    public function enrolling_without_sheet_two_still_succeeds(): void
    {
        $this->enrol();

        $review = StudentHealthRecord::where('student_id', '123456789012')
            ->first()
            ->student_details['systems_review'];

        $this->assertFalse($review['skin_normal']);
        $this->assertNull($review['summary']);
    }
}
