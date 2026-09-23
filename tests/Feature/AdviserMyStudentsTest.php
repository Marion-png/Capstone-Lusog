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
    /**
     * A systems review put on the record directly, as the nurse's Fill
     * Medical Record does. Sheet 2 is the nurse's, so the adviser's form can
     * no longer write one — but how a stored review is carried, normalised
     * and kept out of the session is still this suite's business.
     *
     * @param  array<string, mixed>  $review
     */
    private function storeReview(array $review): StudentHealthRecord
    {
        $record = StudentHealthRecord::where('student_id', '123456789012')->firstOrFail();
        $details = $record->student_details;
        $details['systems_review'] = array_merge($details['systems_review'] ?? [], $review);
        $record->forceFill(['student_details' => $details])->save();

        return $record->fresh();
    }

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

    /**
     * The School Health Card form renders no School ID, Region or Division
     * input, so requiring them server-side made every submission bounce back.
     *
     * @test
     */
    public function the_form_enrols_a_learner_without_any_school_level_identifier(): void
    {
        $response = $this->withSession($this->adviserSession())
            ->post(route('adviser.store'), [
                'last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'middle_name' => 'A',
                'lrn' => '123456789012', 'birth_date' => '2015-06-01',
                'birthplace' => 'Davao City', 'parent_guardian' => 'Maria Dela Cruz',
                'address' => '123 Mabini St.', 'telephone_no' => '09171234567',
                'gender' => 'Male', 'height_cm' => 110, 'weight_kg' => 18.5,
                'grade_level' => 'Grade 1', 'section' => 'Sampaguita',
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('dashboard.class-adviser'));

        $record = StudentHealthRecord::query()->where('student_id', '123456789012')->first();
        $this->assertNotNull($record);
        $this->assertArrayNotHasKey('school_id', $record->student_details);
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

    /**
     * My Students reads alphabetically by surname, not in enrolment order —
     * that is the list a search from the topbar lands on.
     *
     * @test
     */
    public function my_students_is_alphabetical(): void
    {
        $row = fn (string $lrn, string $last, string $first) => [
            'lrn' => $lrn, 'last_name' => $last, 'first_name' => $first,
            'grade_level' => 'Grade 1', 'section' => 'Sampaguita',
        ];

        $html = $this->withSession(array_merge($this->adviserSession(), [
            'school_health_card_records' => [
                $row('300000000003', 'Reyes', 'Ana'),
                $row('300000000001', 'cruz', 'Juan'),
                $row('300000000002', 'Dela Cruz', 'Maria'),
                $row('300000000004', 'Bautista', 'Leo'),
            ],
        ]))->get(route('dashboard.class-adviser', ['tab' => 'saved']))->assertOk()->getContent();

        preg_match_all('/class="js-student-row"\s+data-name="([^"]+)"/', $html, $m);

        $this->assertSame(['bautista, leo', 'cruz, juan', 'dela cruz, maria', 'reyes, ana'], $m[1]);
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
    public function sheet_two_is_the_nurses_to_write_and_survives_session_loss(): void
    {
        // Posted by the adviser and ignored: Sheet 2 is a clinical finding.
        $this->enrol([
            'systems_review' => [
                'skin_lesions' => '1',
                'summary' => 'Typed by the adviser.',
                'examiner_name' => 'Not the nurse',
            ],
        ]);

        $record = StudentHealthRecord::where('student_id', '123456789012')->first();
        $this->assertNotNull($record);
        $this->assertFalse($record->student_details['systems_review']['skin_lesions']);
        $this->assertNull($record->student_details['systems_review']['summary']);

        // Recorded by the nurse, it reads back whole.
        $record = $this->storeReview([
            'skin_normal' => true,
            'resp_cough' => true,
            'right_eye' => '20/20',
            'left_eye' => '20/25',
            'summary' => 'Mild cough noted during assessment.',
            'examiner_name' => 'Maria Santos, RN',
        ]);

        $review = $record->student_details['systems_review'];
        $this->assertTrue($review['skin_normal']);
        $this->assertTrue($review['resp_cough']);
        $this->assertSame('20/20', $review['right_eye']);
        $this->assertSame('Mild cough noted during assessment.', $review['summary']);
        $this->assertSame('Maria Santos, RN', $review['examiner_name']);

        // An answer nobody gave stores as false or null — never a stale default.
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
        $this->assertStringContainsString('id="lsearchInput"', $html);
        $this->assertStringContainsString('class="asb-sidebar"', $html);

        // Isolate the profile card and assert it carries no way to edit the
        // learner's record — changes go through Edit Profile, not this page.
        $start = strpos($html, 'class="card student-profile-page"');
        $end = strpos($html, '<script>', $start);
        $this->assertNotFalse($start);
        $card = substr($html, $start, $end - $start);

        // The Incident Report tab is the one panel that writes, and what it
        // writes is a NEW record about something that happened — not an edit
        // of the learner's health card, which is what this guard protects.
        // It is cut out here and checked on its own below, so the ban still
        // covers Sheet 1, Sheet 2, Consent, Feeding, Notes, Consultations and
        // Documents: a control appearing in any of those still fails. The
        // incident panel is now read-only too, and the check below pins that.
        $incidentAt = strpos($card, 'id="vpTabIncidents"');
        $this->assertNotFalse($incidentAt, 'The Incident Report panel is missing.');
        $readOnlyCard = substr($card, 0, $incidentAt);

        foreach (['<form', '<select', '<textarea'] as $editable) {
            $this->assertStringNotContainsString(
                $editable,
                $readOnlyCard,
                "The read-only part of the student profile must not contain {$editable}."
            );
        }

        // Two inputs outside the incident panel, both file pickers, both
        // named: Medical Documents and the learner's profile photo. The
        // adviser files things here; no health field is editable. Counting
        // them keeps the guard's force — a third input means somebody added
        // a way to edit the record from a page that is supposed to read it.
        $this->assertSame(2, substr_count($readOnlyCard, '<input'), 'Only the document and photo pickers may be inputs here.');
        $this->assertStringContainsString('id="sdInput"', $readOnlyCard);
        $this->assertStringContainsString('id="vpPhotoInput"', $readOnlyCard);

        // The Incident Report panel used to be the one thing the adviser wrote
        // here, and the only form on the page. It is charted in FDAR by the
        // school nurse now and the adviser reads it, so the profile has no
        // form at all — which makes the read-only claim in this test's name
        // literally true for the first time.
        // Not asserted on the store URL: it and the index share one URI and
        // differ only by method, so its absence would prove nothing. What
        // proves it is that there is no form to post one — on this panel or
        // anywhere else on the page.
        $incidentPanel = substr($card, $incidentAt);
        $this->assertStringContainsString('id="incidentReadOnly"', $incidentPanel);
        $this->assertStringNotContainsString('id="incidentForm"', $incidentPanel);
        $this->assertStringNotContainsString('File an Incident Report', $incidentPanel);
        $this->assertSame(0, substr_count($card, '<form'), 'The student profile writes nothing at all.');
        $this->assertStringNotContainsString(route('adviser.store'), $card);

        $this->assertStringNotContainsString(route('health-assessment.store'), $card);
        $this->assertStringNotContainsString('Save Health Assessment', $card);

        // Print and Edit Profile (which navigates to the real edit form) are
        // offered, and each clinic-recorded panel states its read-only intent.
        $this->assertStringContainsString('id="vpPrint"', $card);
        $this->assertStringContainsString('id="vpEditProfile"', $html);
        // Clinic Notes is read-only; Consultation Log is read-only AND redacted,
        // so it carries its own notice saying what is withheld and why.
        $this->assertSame(1, substr_count($html, '<b>Read-Only:</b>'), 'Clinic Notes carries the read-only notice.');
        $this->assertStringContainsString('<b>Date and time only:</b>', $html);
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
    public function the_profile_tabs_read_in_the_order_the_school_asked_for(): void
    {
        $this->enrol();

        $html = $this->flushSession()
            ->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser.student-profile', '123456789012'))
            ->assertOk()
            ->getContent();

        $expected = [
            'vpTabSheet1', 'vpTabSheet2', 'vpTabConsent', 'vpTabFeeding',
            'vpTabNotes', 'vpTabConsultations', 'vpTabDocuments', 'vpTabIncidents',
        ];

        preg_match_all('/data-panel="([^"]+)"/', $html, $tabs);
        $this->assertSame($expected, $tabs[1]);

        // The panels follow the same order, so a printed profile — which lays
        // every panel out in DOM order — reads the way the tabs do.
        preg_match_all('/class="sp-panel[^"]*" id="(vpTab[A-Za-z0-9]+)"/', $html, $panels);
        $this->assertSame($expected, $panels[1]);
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
        $this->enrol();
        $this->storeReview(['resp_cough' => true]);

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

        // Sheet 2 is the nurse's. Neither post writes one, so what the nurse
        // recorded stands and what the form carried is ignored.
        // AdviserSheetTwoReadOnlyTest owns that rule.
        $this->assertTrue($row['systems_review']['resp_cough']);
        $this->assertFalse($row['systems_review']['dental_caries']);

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
            'region' => 'XI', 'division' => 'Davao City',
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
        $this->enrol();
        $record = $this->storeReview(['examiner_signature' => $this->signature()]);

        $this->assertSame(
            $this->signature(),
            $record->student_details['systems_review']['examiner_signature']
        );

        // The roster is read and rewritten on every request, so it carries only
        // a flag — never the image.
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
        $this->enrol();
        $this->storeReview(['examiner_signature' => $this->signature()]);

        // The pad is locked and opens blank, so a submit must not wipe it.
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

    /**
     * The signature is the examiner attesting to the findings above it, so
     * it is frozen with them: once a learner is on file, an edit cannot
     * re-sign Sheet 2 any more than it can change what was found.
     *
     * This test used to assert the opposite. Re-signing from the edit form
     * was how a stored signature got replaced, and with Sheet 2 read-only
     * there is deliberately no path to it from the adviser's side.
     *
     * @test
     */
    public function a_signature_cannot_be_replaced_from_an_edit(): void
    {
        $this->enrol();
        $this->storeReview(['examiner_signature' => $this->signature()]);

        $replacement = 'data:image/jpeg;base64,'.base64_encode('replacement');
        $this->enrol(['systems_review' => ['examiner_signature' => $replacement]]);

        $record = StudentHealthRecord::where('student_id', '123456789012')->first();
        $this->assertSame(
            $this->signature(),
            $record->student_details['systems_review']['examiner_signature'],
            'The signature on file is the one the examiner gave.'
        );
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
                'address' => '123 Mabini St.', 'region' => 'XI',
                'division' => 'Davao City', 'telephone_no' => '09171234567', 'gender' => 'Male',
                'height_cm' => 110, 'weight_kg' => 18.5,
                'grade_level' => 'Grade 1', 'section' => 'Sampaguita',
                'systems_review' => ['examiner_signature' => 'data:image/png;base64,'.str_repeat('A', 2_900_001)],
            ])
            ->assertSessionHasErrors('systems_review.examiner_signature');

        $this->assertNull(StudentHealthRecord::where('student_id', '123456789012')->first());
    }

    /**
     * Sheet 1 still captures the medical and family history the adviser
     * fills in — but no longer the vital signs. Those moved to the school
     * nurse; AdviserVitalSignsTest owns that rule.
     *
     * @test
     */
    public function sheet_one_captures_medical_history_and_appearance(): void
    {
        $this->enrol([
            'health_history' => [
                'med_seizure' => '1',
                'med_allergies' => '1',
                'allergies_detail' => 'Peanuts',
                'fam_diabetes' => '1',
                'current_medications' => 'Salbutamol',
                'genetic_disorders' => 'None known',
                'consciousness' => 'Drowsy',
                'posture' => 'Poor',
                'posture_detail' => 'Slight limp',
                'hygiene' => 'Adequate',
            ],
        ]);

        $details = StudentHealthRecord::where('student_id', '123456789012')->first()->student_details;

        $history = $details['health_history'];
        $this->assertTrue($history['med_seizure']);
        $this->assertTrue($history['med_allergies']);
        $this->assertTrue($history['fam_diabetes']);
        $this->assertSame('Peanuts', $history['allergies_detail']);
        $this->assertSame('Salbutamol', $history['current_medications']);
        $this->assertSame('Drowsy', $history['consciousness']);
        $this->assertSame('Poor', $history['posture']);
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
    }

    /**
     * Posture / Gait reads Good or Poor. The field used to read Normal /
     * Abnormal, so the form offers only the new pair, an old form still
     * posting the old words is saved under the new ones, and a record already
     * stored under them is handed to every profile as Good / Poor.
     *
     * @test
     */
    public function posture_gait_reads_good_or_poor_and_translates_the_old_words(): void
    {
        $form = $this->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser', ['tab' => 'form']))
            ->assertOk();

        $form->assertSee('name="health_history[posture]" value="Good"', false);
        $form->assertSee('name="health_history[posture]" value="Poor"', false);
        $form->assertDontSee('name="health_history[posture]" value="Normal"', false);
        $form->assertDontSee('name="health_history[posture]" value="Abnormal"', false);
        $form->assertSee('If poor, specify');

        // An old form posting the old words is saved under the new ones.
        $this->enrol(['health_history' => ['posture' => 'Abnormal', 'posture_detail' => 'Slight limp']]);
        $record = StudentHealthRecord::where('student_id', '123456789012')->firstOrFail();
        $this->assertSame('Poor', $record->student_details['health_history']['posture']);

        // A record already stored under the old words reaches the roster translated.
        $details = $record->student_details;
        $details['health_history']['posture'] = 'Normal';
        $record->forceFill(['student_details' => $details])->save();

        $this->flushSession()->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser'))->assertOk();

        $row = collect(session('school_health_card_records'))->firstWhere('lrn', '123456789012');
        $this->assertSame('Good', $row['health_history']['posture']);
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

        // Vital signs are the school nurse's. The adviser sees them as a
        // readout and has no field to type one into.
        $response->assertDontSee('name="temperature_c"', false);
        $response->assertDontSee('name="pulse_bpm"', false);
        $response->assertDontSee('name="blood_pressure"', false);
        $response->assertSee('id="vitalTemperature"', false);
    }

    /**
     * An adviser posting vitals is simply ignored — the fields are not on
     * their form and not in their validator, so the save succeeds and the
     * readings are not written. The range rules moved to the nurse's
     * endpoint with the fields; AdviserVitalSignsTest covers them there.
     *
     * @test
     */
    public function vitals_posted_by_an_adviser_are_ignored(): void
    {
        $this->withSession($this->adviserSession())
            ->post(route('adviser.store'), [
                'last_name' => 'Dela Cruz', 'first_name' => 'Juan',
                'lrn' => '123456789012', 'birth_date' => '2015-06-01',
                'birthplace' => 'Davao City', 'parent_guardian' => 'Maria Dela Cruz',
                'address' => '123 Mabini St.', 'region' => 'XI',
                'division' => 'Davao City', 'telephone_no' => '09171234567', 'gender' => 'Male',
                'height_cm' => 110, 'weight_kg' => 18.5,
                'grade_level' => 'Grade 1', 'section' => 'Sampaguita',
                'temperature_c' => 120,
                'pulse_bpm' => 900,
            ])
            ->assertSessionHasNoErrors();

        $record = StudentHealthRecord::where('student_id', '123456789012')->first();

        $this->assertNotNull($record, 'The learner still saves — the vitals are dropped, not the record.');
        $this->assertNull($record->student_details['temperature_c'] ?? null);
        $this->assertNull($record->student_details['pulse_bpm'] ?? null);
    }

    /**
     * The whitelist runs over the stored review as well as over input, so a
     * key that reached the column some other way is dropped on the next save
     * rather than carried forward.
     *
     * @test
     */
    public function unknown_sheet_two_keys_are_dropped(): void
    {
        $this->enrol();
        $this->storeReview(['skin_normal' => true, 'evil_key' => 'should not be stored']);

        // Any save rewrites the card, and the review is normalised on the way.
        $this->enrol(['address' => 'Edited address']);

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
