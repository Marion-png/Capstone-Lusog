<?php

namespace Tests\Feature;

use App\Http\Controllers\NurseController;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\BmiAssessmentReport;
use App\Support\NutritionalHealthStatus;
use App\Support\Sheet2Review;
use App\Support\StudentRosterSync;
use App\Support\StudentVitalSigns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The nurse's Health Records page: filter chips built from the real roster,
 * a records table, and the inline profile view. Its "Fill Medical Record"
 * links must address the same raw session row that saveExamination() writes
 * to — the deduplicated list is keyed by raw index for exactly that reason.
 */
class NurseHealthRecordsPageTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    /** @param  list<array<string, mixed>>  $roster */
    private function nurseSession(array $roster): array
    {
        return [
            'active_role' => 'school_nurse',
            'active_name' => 'Ana Reyes',
            'active_username' => 'ana.reyes',
            'active_institution_id' => $this->institution->id,
            'active_school_name' => 'Sta. Ana NHS',
            'school_health_card_records' => $roster,
        ];
    }

    /** @param  array<string, mixed>  $overrides */
    private function learner(array $overrides = []): array
    {
        return array_merge([
            'last_name' => 'Gomez', 'first_name' => 'Jose', 'middle_name' => 'Cruz',
            'lrn' => '100000000001', 'grade_level' => 'Grade 10', 'section' => 'Dalton',
            'gender' => 'Male', 'age' => 15, 'height_cm' => 150, 'weight_kg' => 40,
            'nutritional_status_bmi_for_age' => 'Normal', 'examination' => [],
        ], $overrides);
    }

    #[Test]
    public function the_fill_medical_record_link_points_at_the_row_it_was_rendered_on(): void
    {
        // A duplicate LRN is exactly the case the deduplication exists for:
        // the examined copy at raw index 2 is the one that survives.
        $roster = [
            $this->learner(['lrn' => 'LRN-A', 'last_name' => 'Alpha']),
            $this->learner(['lrn' => 'LRN-B', 'last_name' => 'Bravo']),
            $this->learner(['lrn' => 'LRN-A', 'last_name' => 'Alpha', 'examination' => ['deworming' => 'V']]),
            $this->learner(['lrn' => 'LRN-C', 'last_name' => 'Charlie']),
        ];

        $deduped = NurseController::dedupedRoster($roster);

        // Alpha survives at its raw index 2 (the examined copy), not re-indexed to 0.
        $this->assertSame([1, 2, 3], array_keys($deduped));
        $this->assertSame('LRN-B', $deduped[1]['lrn']);
        $this->assertSame('LRN-A', $deduped[2]['lrn']);
        $this->assertSame('LRN-C', $deduped[3]['lrn']);

        $html = $this->withSession($this->nurseSession($roster))
            ->get('/dashboard/student-health-records')
            ->assertOk()
            ->getContent();

        // Every rendered link resolves to the same learner the row shows.
        foreach ($deduped as $rawIndex => $row) {
            $this->assertStringContainsString(
                'data-route="'.route('nurse.examine', $rawIndex).'"',
                $html
            );
        }

        // And that raw index really opens that learner's examination form.
        $this->withSession($this->nurseSession($roster))
            ->get(route('nurse.examine', 2))
            ->assertOk()
            ->assertSee('LRN-A');
    }

    /**
     * "Fill Medical Record" opens the Systems Review, Screenings, and
     * Recommendations form. Vital signs are recorded on the profile's own
     * panel and height/weight are the adviser's, so the form no longer
     * repeats either — but the examination is still stamped with the record's
     * figures on save.
     */
    #[Test]
    public function the_examination_form_is_the_systems_review_and_carries_no_measurements(): void
    {
        $learner = $this->learner(['height_cm' => 152, 'weight_kg' => 44, 'nutritional_status_bmi_for_age' => 'Normal']);

        $response = $this->withSession($this->nurseSession([$learner]))
            ->get(route('nurse.examine', 0))
            ->assertOk();

        $response->assertSee('Systems Review, <span>Screenings, and Recommendations</span>', false);
        $response->assertDontSee('Medical Examination Form');
        $response->assertDontSee('Vital Signs &amp; Physical Measurements', false);
        $response->assertDontSee('Anthropometric Data');
        foreach (['temperature_bp', 'heart_rate', 'pulse_rate', 'respiratory_rate', 'height_cm', 'weight_kg', 'nutritional_status_bmi'] as $field) {
            $response->assertDontSee('name="'.$field.'"', false);
        }
        // The record's own date stays, and the form is Sheet 2's five sections.
        $response->assertSee('name="date_of_examination"', false);
        foreach ([
            'F. Evaluation of Body Systems', 'G. Vision and Hearing Screening', 'H. Oral Health Examination',
            'I. Immunization Status', 'J. Assessment Summary and Recommendations',
        ] as $section) {
            $response->assertSee($section);
        }
        foreach (array_keys(Sheet2Review::SYSTEMS) as $system) {
            $response->assertSee('name="systems['.$system.'][finding]"', false);
            $response->assertSee('name="systems['.$system.'][notes]"', false);
        }
        foreach (['vision_right', 'vision_left', 'vision_result', 'hearing_result', 'teeth_condition', 'last_dental_visit', 'dental_referral', 'immunization_status', 'missing_vaccines', 'immunization_reviewed_at', 'summary_findings', 'recommendations'] as $field) {
            $response->assertSee('name="'.$field.'"', false);
        }
        $response->assertSee('Examiner Signature / Name:');
        $response->assertSee('Ana Reyes');

        // Sheet 2 is answered from lists: every field whose answer is one of a
        // fixed set is a dropdown, as the template rules it. Only the two eye
        // measurements, the vaccines and the summary prose are typed.
        $html = $response->getContent();

        foreach ([
            'vision_result' => Sheet2Review::VISION_RESULTS,
            'hearing_result' => Sheet2Review::HEARING_RESULTS,
            'teeth_condition' => Sheet2Review::TEETH,
            'dental_referral' => Sheet2Review::DENTAL_REFERRALS,
            'immunization_status' => Sheet2Review::IMMUNIZATION,
        ] as $field => $options) {
            $this->assertStringContainsString('<select name="'.$field.'">', $html, $field.' must be a dropdown.');
            foreach ($options as $option) {
                $this->assertStringContainsString('<option value="'.$option.'"', $html);
            }
        }

        // Nothing on the sheet is ticked or typed where a list decides it.
        $this->assertStringNotContainsString('name="vision_result" value="Pass"', $html, 'No checkbox: the outcome is chosen.');
        $this->assertStringNotContainsString('type="text" name="dental_referral"', $html);

        $this->assertSame(['Pass', 'Refer'], Sheet2Review::VISION_RESULTS);
        $this->assertSame(['Passed Both', 'Failed Right', 'Failed Left', 'Refer'], Sheet2Review::HEARING_RESULTS);
        $this->assertSame(['No referral required', 'Referred for dental care'], Sheet2Review::DENTAL_REFERRALS);

        // Supplementation & Programs went too — no deworming, iron, SBFP/4Ps,
        // menarche, immunization, others or Examined By controls, and no
        // consent banner for a control that is not there.
        $response->assertDontSee('Supplementation &amp; Programs', false);
        foreach (['iron_supplementation', 'deworming', 'sbfp_beneficiary', 'four_ps_beneficiary', 'menarche', 'immunization', 'others', 'examined_by'] as $field) {
            $response->assertDontSee('name="'.$field.'"', false);
        }
        $response->assertDontSee('No signed parental consent on file');

        $this->withSession($this->nurseSession([$learner]))
            ->post(route('nurse.examine.save', 0), [
                'date_of_examination' => '2026-08-02',
                'systems' => [
                    'integumentary' => ['finding' => 'Normal', 'notes' => ''],
                    'respiratory' => ['finding' => 'Abnormal', 'notes' => 'Wheeze on exertion'],
                    'neurological' => ['finding' => 'not-an-option', 'notes' => ''],
                ],
                'vision_right' => '20/20', 'vision_left' => '20/25', 'vision_result' => 'Pass',
                'hearing_result' => 'Failed Left',
                'teeth_condition' => 'Good', 'dental_referral' => 'No referral required',
                'immunization_status' => 'Complete', 'immunization_reviewed_at' => '2026-08-02',
                'summary_findings' => 'Controlled bronchial asthma.',
                'recommendations' => 'Keep inhaler accessible during PE classes.',
            ])
            ->assertRedirect(route('dashboard.student-health-records'));

        $exam = session('school_health_card_records')[0]['examination'];

        // Sheet 2 lands under one key, every system present, options validated,
        // signed by whoever is signed in on the date of examination.
        $sheet = $exam[Sheet2Review::KEY];
        $this->assertSame(['finding' => 'Normal', 'notes' => ''], $sheet['systems']['integumentary']);
        $this->assertSame(['finding' => 'Abnormal', 'notes' => 'Wheeze on exertion'], $sheet['systems']['respiratory']);
        $this->assertSame('', $sheet['systems']['neurological']['finding'], 'A value off the list is dropped.');
        $this->assertArrayHasKey('genitourinary', $sheet['systems']);
        $this->assertSame(['right' => '20/20', 'left' => '20/25', 'result' => 'Pass'], $sheet['vision']);
        $this->assertSame('Failed Left', $sheet['hearing']['result']);
        $this->assertSame('Good', $sheet['oral']['teeth']);
        $this->assertSame('Complete', $sheet['immunization']['status']);
        $this->assertSame('Controlled bronchial asthma.', $sheet['summary']['findings']);
        $this->assertSame('Ana Reyes', $sheet['summary']['examiner']);
        $this->assertSame('2026-08-02', $sheet['summary']['date']);

        // With no Examined By box, the examination is attributed to whoever is signed in.
        $this->assertSame('Ana Reyes', $exam['examined_by']);
        $this->assertEquals(152, $exam['height_cm'], 'Stamped from the record, not the form.');
        $this->assertEquals(44, $exam['weight_kg']);
        $this->assertSame('Normal', $exam['nutritional_status_bmi']);
        $this->assertEquals(152, session('school_health_card_records')[0]['height_cm'], 'The measurement is untouched.');
    }

    /**
     * Sheet 2 opens on the record's answers: the adviser's checklist and the
     * older examination fields fill the form as a first draft, and once the
     * nurse has saved, her sheet is what the form, the tab and the export read.
     */
    #[Test]
    public function sheet_two_prefills_from_the_record_and_then_reads_the_nurses_own(): void
    {
        $learner = $this->learner([
            'systems_review' => [
                'skin_lesions' => true, 'heent_normal' => true, 'resp_clear' => true,
                'dental_fair' => true, 'dental_caries' => true, 'dental_referral' => true,
                'immun_incomplete' => true, 'right_eye' => '20/20', 'left_eye' => '20/40',
                'immun_date' => '2026-08-02', 'recommendations' => 'Refer to dentist.',
            ],
            'examination' => ['vision_screening' => 'Pass', 'abdomen' => 'Soft', 'immunization' => 'MMR'],
        ]);

        $html = $this->withSession($this->nurseSession([$learner]))
            ->get(route('nurse.examine', 0))
            ->assertOk()
            ->getContent();

        // Derived from the adviser's checklist, and said so.
        $this->assertStringContainsString("Filled in from the class adviser's Sheet 2", $html);
        $this->assertMatchesRegularExpression('/name="systems\[integumentary\]\[finding\]"[^>]*>.*?<option value="Abnormal" selected/s', $html);
        $this->assertMatchesRegularExpression('/name="systems\[heent_eyes\]\[finding\]"[^>]*>.*?<option value="Normal" selected/s', $html);
        $this->assertStringContainsString('name="systems[gastrointestinal][notes]" value="Soft"', $html);
        $this->assertStringContainsString('name="vision_left" value="20/40"', $html);
        $this->assertMatchesRegularExpression('/name="vision_result">.*?<option value="Pass" selected/s', $html);
        $this->assertMatchesRegularExpression('/name="teeth_condition"[^>]*>.*?<option value="Fair" selected/s', $html);
        // The derived draft lands on one of the two options, never a sentence:
        // the field is a dropdown, and what the adviser ticked is already on
        // the body-systems rows above.
        $this->assertMatchesRegularExpression('/name="dental_referral">.*?<option value="Referred for dental care" selected/s', $html);
        $this->assertMatchesRegularExpression('/name="immunization_status"[^>]*>.*?<option value="Incomplete" selected/s', $html);
        $this->assertStringContainsString('name="missing_vaccines" value="MMR"', $html);
        $this->assertStringContainsString('Refer to dentist.', $html);

        // The nurse's own sheet, once saved, is what everything reads.
        $sheet = Sheet2Review::read([
            Sheet2Review::KEY => Sheet2Review::fromInput([
                'systems' => ['integumentary' => ['finding' => 'Normal', 'notes' => 'Healed']],
                'teeth_condition' => 'Good',
            ]),
        ], $learner['systems_review']);

        $this->assertSame('nurse', $sheet['source']);
        $this->assertSame('Normal', $sheet['systems']['integumentary']['finding']);
        $this->assertSame('Good', $sheet['oral']['teeth']);
        $this->assertSame('', $sheet['vision']['left'], 'The nurse\'s sheet is not back-filled from the adviser\'s once it exists.');

        // The profile's Sheet 2 tab renders the nurse's sheet when present.
        $profile = $this->withSession($this->nurseSession([$learner]))
            ->get(route('dashboard.student-health-records'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('const renderSheet2 = ', $profile);
        $this->assertStringContainsString('renderSystemsReview(record.systems_review, record.examination)', $profile);
    }

    /** The records table reads by last name, whatever order the roster arrived in. */
    #[Test]
    public function the_records_table_is_alphabetical_by_last_name(): void
    {
        $roster = [
            $this->learner(['lrn' => 'LRN-3', 'last_name' => 'Reyes', 'first_name' => 'Ana']),
            $this->learner(['lrn' => 'LRN-1', 'last_name' => 'cruz', 'first_name' => 'Juan']),
            $this->learner(['lrn' => 'LRN-2', 'last_name' => 'Dela Cruz', 'first_name' => 'Maria']),
            $this->learner(['lrn' => 'LRN-4', 'last_name' => 'Bautista', 'first_name' => 'Leo']),
        ];

        $html = $this->withSession($this->nurseSession($roster))
            ->get('/dashboard/student-health-records')
            ->assertOk()
            ->getContent();

        preg_match_all('/<td class="shr-name">([^<]+)<\/td>/', $html, $m);
        $this->assertSame(['Bautista, Leo C.', 'cruz, Juan C.', 'Dela Cruz, Maria C.', 'Reyes, Ana C.'], $m[1]);

        // Sorting must not detach a row from the raw index its link is keyed by.
        $this->assertStringContainsString('data-lrn="LRN-4"', $html);
        $this->assertMatchesRegularExpression('/data-route="[^"]*\/nurse\/3\/examine"[^>]*data-lrn="LRN-4"/', $html);
    }

    #[Test]
    public function filter_chips_are_built_from_the_roster_actually_on_file(): void
    {
        $roster = [
            $this->learner(['lrn' => '1', 'grade_level' => 'Grade 7', 'section' => 'Curie', 'gender' => 'Female']),
            $this->learner(['lrn' => '2', 'grade_level' => 'Grade 7', 'section' => 'Curie', 'gender' => 'Male']),
            $this->learner(['lrn' => '3', 'grade_level' => 'Grade 10', 'section' => 'Dalton', 'gender' => 'Male']),
        ];

        $response = $this->withSession($this->nurseSession($roster))
            ->get('/dashboard/student-health-records')
            ->assertOk();

        $html = $response->getContent();

        // The three filters are dropdowns, each built from the roster.
        foreach (['grade', 'sex', 'section'] as $group) {
            $this->assertStringContainsString('data-filter="'.$group.'"', $html);
        }

        // Options carry the value the filter matches on, and its count.
        $this->assertStringContainsString('<option value="Grade 7">Grade 7 (2)</option>', $html);
        $this->assertStringContainsString('<option value="Grade 10">Grade 10 (1)</option>', $html);
        $this->assertStringContainsString('<option value="curie">Curie (2)</option>', $html);
        $this->assertStringContainsString('<option value="dalton">Dalton (1)</option>', $html);
        $this->assertStringContainsString('<option value="male">Male (2)</option>', $html);
        $this->assertStringContainsString('<option value="female">Female (1)</option>', $html);

        // A grade nobody is in never becomes an option.
        $this->assertStringNotContainsString('value="Grade 8"', $html);

        // Rows carry the hooks the chips filter on.
        $this->assertStringContainsString('data-grade="Grade 7"', $html);
        $this->assertStringContainsString('data-sex="female"', $html);
        $this->assertStringContainsString('data-section="dalton"', $html);
    }

    #[Test]
    public function the_records_table_and_profile_modal_both_render(): void
    {
        $roster = [$this->learner(['nutritional_status_bmi_for_age' => 'Wasted'])];

        $response = $this->withSession($this->nurseSession($roster))
            ->get('/dashboard/student-health-records')
            ->assertOk();

        // List view: table columns and the learner's row.
        $response->assertSee('Student Records')
            ->assertSee('Gomez, Jose C.')
            ->assertSee('100000000001')
            ->assertSee('Wasted')
            ->assertSee('Pending')
            ->assertSee('View Profile');

        // Profile: actions and every tab still present.
        $response->assertSee('Fill Medical Record')->assertSee('Print');

        foreach ([
            'p-sheet1', 'p-sheet2', 'p-consultation', 'p-consent', 'p-documents',
        ] as $panel) {
            $response->assertSee('data-panel="'.$panel.'"', false);
            $response->assertSee('id="'.$panel.'"', false);
        }

        // Each section moved into one of the six tabs. The "SHD Form 2
        // Snapshot" block was removed on request: it only restated the grade
        // and whether an examination existed, both already on the profile.
        foreach ([
            'Personal Information', 'Parent/Guardian Information', 'Medical &amp; Family History',
            'Growth &amp; Nutrition', 'Health History',
            'Systems Review', 'Health Assessment', 'Consultation Log',
            'Parental Consent', 'Medical Documents',
        ] as $section) {
            $response->assertSee($section, false);
        }

        $response->assertDontSee('SHD Form 2 Snapshot');
        $response->assertDontSee('id="psStatus"', false);

        // Clinic Notes went too: a note is written with the visit now (New
        // Consultation) and read under it on the Consultation Log tab.
        $response->assertDontSee('data-panel="p-clinic-notes"', false);
        $response->assertDontSee('Add Clinic Note');
        $response->assertDontSee('id="clinicNoteForm"', false);
        $response->assertSee('id="cm_notes"', false);
        $response->assertSee("label.textContent = 'Notes / comments'", false);
    }

    #[Test]
    public function the_profile_tabs_match_the_requested_set_and_order(): void
    {
        $response = $this->withSession($this->nurseSession([$this->learner()]))
            ->get('/dashboard/student-health-records')
            ->assertOk();

        foreach ([
            'Sheet 1' => 'Learner Info',
            'Sheet 2' => 'Systems Review',
        ] as $tab => $badge) {
            $response->assertSee($tab)->assertSee($badge);
        }

        $response->assertSee('Consultation Log')
            ->assertSee('Consent')
            ->assertSee('Documents');

        // Live badge targets the scripts fill in.
        foreach (['pConsultBadge', 'pConsentBadge', 'pDocsBadge'] as $badgeId) {
            $response->assertSee('id="'.$badgeId.'"', false);
        }

        // The tab strip reads in the order the school asked for, and the panels
        // are laid out in the same order so a printed profile follows the tabs.
        $html = $response->getContent();
        // Incident Reports closes the strip: it is the nurse's FDAR chart of
        // something that happened to this learner, and the adviser reads the
        // same panel on their own profile.
        // Nutritional Health Status sits beside the two MLAT sheets: it is
        // the measurement half of the same record, read before the clinic
        // history that follows it.
        $expected = ['p-sheet1', 'p-sheet2', 'p-nutrition', 'p-consent', 'p-consultation', 'p-documents', 'p-incidents'];

        preg_match_all('/data-panel="([^"]+)"/', $html, $tabs);
        $this->assertSame($expected, $tabs[1]);

        preg_match_all('/<section id="(p-[a-z0-9-]+)" class="sp-panel/', $html, $panels);
        $this->assertSame($expected, $panels[1]);
    }

    #[Test]
    public function the_documents_tab_carries_the_same_uploader_the_adviser_has(): void
    {
        $html = $this->withSession($this->nurseSession([$this->learner()]))
            ->get('/dashboard/student-health-records')
            ->assertOk()
            ->getContent();

        // The shared component, not a nurse-only copy of it.
        foreach (['id="sdDrop"', 'id="sdInput"', 'id="sdList"', 'window.StudentDocuments'] as $hook) {
            $this->assertStringContainsString($hook, $html);
        }

        $this->assertStringContainsString('Drag and drop medical documents here, or click to browse', $html);
        $this->assertStringContainsString('Supported formats: PDF, JPG, PNG, DOC, XLS (Max 10MB)', $html);
        $this->assertStringContainsString('Uploaded Documents', $html);

        // Both profiles include the very same partials.
        $adviserMarkup = file_get_contents(resource_path('views/adviser-dashboard/student-profile.blade.php'));
        foreach (['partials.student-documents-panel', 'partials.student-documents-script'] as $partial) {
            $this->assertStringContainsString($partial, $adviserMarkup);
        }
    }

    #[Test]
    public function the_profile_uses_the_same_presentational_building_blocks_as_the_adviser(): void
    {
        // The adviser's profile is now a dedicated full page (not a modal —
        // it keeps the shared sidebar), so the overlay-specific classes are
        // nurse-only. The Facebook-profile card/tabs shell is still shared.
        $nurseModalOnly = [
            'profile-backdrop',            // dimmed overlay
            'student-profile-modal',       // column-flex modal
        ];

        $sharedShell = [
            'student-profile-topline',     // back arrow + "Student Profile"
            'sp-cover',                    // gradient cover strip
            'sp-identity',                 // identity card
            'sp-avatar',                   // overlapping initials circle
            'sp-name',
            'sp-class',
            'sp-meta',                     // LRN · Sex · Age · DOB
            'sp-identity-actions',
            'sp-tabs',
            'sp-tab',
            'sp-panel',
            'student-profile-body',
        ];

        $nurse = $this->withSession($this->nurseSession([$this->learner()]))
            ->get('/dashboard/student-health-records')
            ->assertOk();

        foreach (array_merge($nurseModalOnly, $sharedShell) as $hook) {
            $nurse->assertSee($hook, false);
        }

        $nurse->assertSee('Student Profile')->assertSee('&larr;', false);

        // The same building blocks the adviser's own view-profile page is made of.
        $adviserMarkup = file_get_contents(resource_path('views/adviser-dashboard/student-profile.blade.php'));
        foreach ($sharedShell as $hook) {
            $this->assertStringContainsString($hook, $adviserMarkup, "Adviser profile page should also use .{$hook}");
        }
    }

    #[Test]
    public function both_clinic_roles_get_the_documents_tab(): void
    {
        $roster = [$this->learner()];
        $tab = 'data-panel="p-documents"';

        // The conditions API already allows school_nurse, so the documents tab
        // is no longer clinic-staff only.
        $this->withSession(array_merge($this->nurseSession($roster), ['active_role' => 'clinic_staff']))
            ->get('/dashboard/student-health-records')
            ->assertOk()
            ->assertSee($tab, false);

        $this->withSession($this->nurseSession($roster))
            ->get('/dashboard/student-health-records')
            ->assertOk()
            ->assertSee($tab, false);
    }

    #[Test]
    public function an_apostrophe_in_a_learner_name_does_not_break_the_row_payload(): void
    {
        $roster = [$this->learner(['last_name' => "O'Brien", 'first_name' => 'Seán'])];

        $html = $this->withSession($this->nurseSession($roster))
            ->get('/dashboard/student-health-records')
            ->assertOk()
            ->getContent();

        // data-record sits in a single-quoted attribute, so the apostrophe is
        // hex-escaped in the JSON instead of closing the attribute early.
        $this->assertStringContainsString('\u0027Brien', $html);
        $this->assertStringNotContainsString("data-record='{\"last_name\":\"O'", $html);

        // The row still renders the readable name for the nurse.
        $this->assertStringContainsString('O&#039;Brien, Seán C.', $html);
    }

    #[Test]
    public function health_records_sits_directly_after_dashboard_in_the_side_menu(): void
    {
        $html = $this->withSession($this->nurseSession([]))
            ->get('/dashboard/school-nurse')
            ->assertOk()
            ->getContent();

        $dashboard = strpos($html, route('dashboard.school-nurse').'" class="sb-link');
        $records = strpos($html, route('dashboard.student-health-records').'" class="sb-link');
        $queue = strpos($html, route('nurse.index').'" class="sb-link');

        $this->assertNotFalse($dashboard);
        $this->assertNotFalse($records);
        $this->assertNotFalse($queue);
        $this->assertLessThan($records, $dashboard, 'Health Records must follow Dashboard.');
        $this->assertLessThan($queue, $records, 'Health Records must come before Review Queue.');
    }

    #[Test]
    public function the_empty_roster_shows_an_empty_state_rather_than_erroring(): void
    {
        $this->withSession($this->nurseSession([]))
            ->get('/dashboard/student-health-records')
            ->assertOk()
            ->assertSee('No Adviser Submissions Yet');
    }

    /** @param  array<string, mixed>  $overrides */
    private function dbRecord(array $overrides = []): StudentHealthRecord
    {
        return StudentHealthRecord::create(array_merge([
            'institution_id' => $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Gomez, Jose C.',
            'student_id' => '100000000001',
            'school_name' => 'Sta. Ana NHS',
            'section' => 'Grade 10 / Dalton',
            'weight' => 40,
            'bmi_value' => 17.8,
            'nutritional_status' => 'Normal',
            'student_details' => [],
        ], $overrides));
    }

    /**
     * An older record only has the nutrition columns, so its name is parsed
     * off `student_name`. The nurse's vital signs are written into the same
     * `student_details` blob, which used to make the blob "non-empty" and
     * switch that parsing off — the learner then rendered as "-".
     */
    #[Test]
    public function a_name_survives_the_nurse_recording_vital_signs_on_an_older_record(): void
    {
        $record = $this->dbRecord();
        StudentVitalSigns::write($record, ['temperature_c' => '36.8', 'pulse_bpm' => '80', 'blood_pressure' => '110/70'], 'Ana Reyes');

        $this->assertNotSame([], $record->fresh()->student_details, 'The blob now holds the vitals.');

        $response = $this->withSession($this->nurseSession([]))
            ->get('/dashboard/student-health-records')
            ->assertOk();

        $response->assertSee('<td class="shr-name">Gomez, Jose C.</td>', false);

        $row = collect(session('school_health_card_records'))->firstWhere('lrn', '100000000001');
        $this->assertSame('Gomez', $row['last_name']);
        $this->assertSame('Jose', $row['first_name']);
        $this->assertSame('36.8', $row['temperature_c'], 'The vitals are still carried across.');
    }

    /**
     * The database is the source of truth. A name the adviser corrected used
     * to reach the nurse only once their session expired, because the sync
     * added LRNs missing from the session and never touched the rows already
     * there.
     */
    #[Test]
    public function a_corrected_name_reaches_a_session_that_already_holds_the_learner(): void
    {
        $record = $this->dbRecord([
            'student_details' => ['last_name' => 'Gomez', 'first_name' => 'Jose', 'middle_name' => 'Cruz', 'gender' => 'Male'],
        ]);

        $stale = $this->learner(['last_name' => 'Gomes', 'examination' => ['deworming' => 'V']]);
        $other = $this->learner(['lrn' => 'SESSION-ONLY', 'last_name' => 'Session Only']);

        $response = $this->withSession($this->nurseSession([$stale, $other]))
            ->get('/dashboard/student-health-records')
            ->assertOk();

        $response->assertSee('Gomez, Jose C.');
        $response->assertDontSee('Gomes');

        $roster = session('school_health_card_records');
        // Refreshed in place: the same raw index, so the "Fill Medical Record"
        // link keeps opening the learner it was rendered for, and a row the
        // database does not know about is left alone.
        $this->assertSame('Gomez', $roster[0]['last_name']);
        $this->assertSame('Session Only', $roster[1]['last_name']);
        // The examination is refreshed from its own column, not kept stale.
        $this->assertSame([], $roster[0]['examination']);
        $this->assertSame($record->student_id, $roster[0]['lrn']);
    }

    /** The stored name carries a middle INITIAL, so only a trailing one is read as the middle name. */
    #[Test]
    public function a_two_word_first_name_is_not_split_into_a_middle_name(): void
    {
        $this->assertSame(['Dela Cruz', 'Maria Clara', 'S.'], StudentRosterSync::splitStudentName('Dela Cruz, Maria Clara S.'));
        $this->assertSame(['Dela Cruz', 'Maria Clara', ''], StudentRosterSync::splitStudentName('Dela Cruz, Maria Clara'));
        $this->assertSame(['Gomez', 'Jose', 'C'], StudentRosterSync::splitStudentName('Gomez, Jose C'));
        $this->assertSame(['Gomez', 'Jose', ''], StudentRosterSync::splitStudentName('Gomez,Jose'));
        $this->assertSame(['Juan Dela Cruz', '', ''], StudentRosterSync::splitStudentName('Juan Dela Cruz'));
        $this->assertSame(['', '', ''], StudentRosterSync::splitStudentName('   '));

        $this->dbRecord(['student_name' => 'Dela Cruz, Maria Clara S.']);

        $this->withSession($this->nurseSession([]))
            ->get('/dashboard/student-health-records')
            ->assertOk()
            ->assertSee('<td class="shr-name">Dela Cruz, Maria Clara S.</td>', false)
            ->assertDontSee('Maria C.');
    }

    /**
     * A screening outcome is one of the sheet's own options. A value the
     * form cannot produce is dropped, and an outcome typed on the older
     * free-text form is read onto the option it meant — never invented.
     */
    #[Test]
    public function screening_results_are_read_onto_the_sheets_own_options(): void
    {
        $sheet = Sheet2Review::fromInput([
            'vision_result' => 'refer', 'hearing_result' => 'failed right',
        ]);
        $this->assertSame('Refer', $sheet['vision']['result']);
        $this->assertSame('Failed Right', $sheet['hearing']['result']);

        $sheet = Sheet2Review::fromInput([
            'vision_result' => 'Excellent', 'hearing_result' => 'Both ears fine',
        ]);
        $this->assertSame('', $sheet['vision']['result']);
        $this->assertSame('', $sheet['hearing']['result']);

        // The draft derived from the older examination fields.
        $derived = Sheet2Review::read(['vision_screening' => 'Passed', 'auditory_screening' => 'Referred to ENT'], []);
        $this->assertSame('Pass', $derived['vision']['result']);
        $this->assertSame('Refer', $derived['hearing']['result']);

        $derived = Sheet2Review::read(['vision_screening' => 'passed', 'auditory_screening' => 'ok'], []);
        $this->assertSame('Pass', $derived['vision']['result']);
        $this->assertSame('', $derived['hearing']['result'], 'A word the options do not name stays blank.');
    }

    /**
     * Back, Cancel and the save return to the page the form was opened from
     * — the profile the nurse was reading, or whichever tab sent them here —
     * never to the dashboard. A return address outside the app is ignored.
     */
    #[Test]
    public function the_examination_form_returns_to_the_page_it_was_opened_from(): void
    {
        $learner = $this->learner();
        $session = $this->nurseSession([$learner]);
        $profile = route('dashboard.student-health-records', ['open' => '123456789012']);

        // The profile passes itself as the return address.
        $html = $this->withSession($session)
            ->get(route('nurse.examine', ['index' => 0, 'return_to' => $profile]))
            ->assertOk()
            ->getContent();
        $this->assertSame(2, substr_count($html, 'href="'.e($profile).'"'), 'Back and Cancel both lead to the profile.');
        $this->assertStringContainsString('name="return_to" value="'.e($profile).'"', $html);

        // Opened from another tab with no address of its own: the referrer.
        $html = $this->withSession($session)
            ->from(route('dashboard.school-nurse'))
            ->get(route('nurse.examine', 0))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('href="'.route('dashboard.school-nurse').'"', $html);
        $this->flushHeaders();

        // An address outside the app falls back to Health Records.
        $html = $this->withSession($session)
            ->get(route('nurse.examine', ['index' => 0, 'return_to' => 'https://evil.example/phish']))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringContainsString('href="'.route('dashboard.student-health-records').'"', $html);

        // The save lands where the form said it would.
        $this->withSession($session)
            ->post(route('nurse.examine.save', 0), ['date_of_examination' => '2026-08-02', 'return_to' => $profile])
            ->assertRedirect($profile);
    }

    /**
     * The Nutritional Health Status tab: height, weight, BMI with its
     * BMI-for-age classification and height-for-age with its own, for the
     * baseline weigh-in and the endline one. Every figure is the record's,
     * read once on the server, and a phase nobody measured says so rather
     * than borrowing the other phase's numbers.
     */
    #[Test]
    public function the_nutritional_health_status_tab_reads_both_weigh_ins(): void
    {
        $record = StudentHealthRecord::create([
            'institution_id' => 1,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_id' => '130000000009',
            'student_name' => 'Cruz, Juan',
            'section' => 'Grade 10 / Dalton',
            'weight' => 38, 'bmi_value' => 16.2, 'nutritional_status' => 'Wasted',
            'baseline_height_cm' => 153, 'baseline_weight_kg' => 38, 'baseline_age' => 15,
            'baseline_bmi_value' => 16.2, 'baseline_nutritional_status' => 'Wasted',
            'baseline_recorded_at' => '2026-07-01',
            'student_details' => [
                'lrn' => '130000000009', 'last_name' => 'Cruz', 'first_name' => 'Juan',
                'grade_level' => 'Grade 10', 'section' => 'Dalton',
                'nutritional_status_height_for_age' => 'Normal',
            ],
        ]);

        // Before the closing weigh-in: baseline reads, endline is blank.
        $status = NutritionalHealthStatus::forRecord($record);
        $this->assertTrue($status['has_any']);
        $this->assertTrue($status['baseline']['measured']);
        $this->assertSame('153', $status['baseline']['height_cm']);
        $this->assertSame('38', $status['baseline']['weight_kg']);
        $this->assertSame('16.2', $status['baseline']['bmi']);
        $this->assertSame('Wasted', $status['baseline']['bmi_status']);
        $this->assertSame('Normal', $status['baseline']['hfa_status']);
        $this->assertSame('2026-07-01', $status['baseline']['recorded_at']);

        $this->assertFalse($status['endline']['measured'], 'Nobody has re-measured this learner.');
        $this->assertSame('', $status['endline']['bmi']);
        $this->assertSame('', $status['endline']['bmi_status'], 'An unmeasured phase never borrows the baseline reading.');
        $this->assertSame('', $status['endline']['hfa_status']);

        // After it, the endline is its own reading — height-for-age recomputed
        // from the endline height and age, since it is stored nowhere.
        $record->update([
            'endline_height_cm' => 157, 'endline_weight_kg' => 46, 'endline_age' => 16,
            'endline_bmi_value' => 18.7, 'endline_nutritional_status' => 'Normal',
            'endline_recorded_at' => '2027-03-01',
        ]);

        $status = NutritionalHealthStatus::forRecord($record->fresh());
        $this->assertTrue($status['endline']['measured']);
        $this->assertSame('157', $status['endline']['height_cm']);
        $this->assertSame('18.7', $status['endline']['bmi']);
        $this->assertSame('Normal', $status['endline']['bmi_status']);
        $this->assertSame(
            BmiAssessmentReport::classifyHeightForAge(157.0, 16),
            $status['endline']['hfa_status'],
            'Endline height-for-age is the DepEd grid\'s own classifier, never a second one.'
        );

        // And the tab is on the profile, with both panels.
        $html = $this->withSession($this->nurseSession([$this->learner()]))
            ->get('/dashboard/student-health-records')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-panel="p-nutrition">Nutritional Health Status</button>', $html);
        $this->assertStringContainsString('<section id="p-nutrition" class="sp-panel">', $html);
        foreach (['pnBaseline', 'pnEndline', 'pnEmpty'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html);
        }
        $this->assertStringContainsString('Height-for-Age', $html);
    }

    /**
     * Growth & Nutrition reports what the height means, not only what it was.
     *
     * The panel plots height against weight over time; height-for-age is the
     * reading those two figures produce for a child of this age, so it sits
     * beside them — taken from the same App\Support\NutritionalHealthStatus
     * the Nutritional Health Status tab renders, never classified a second
     * time in the view.
     */
    #[Test]
    public function the_growth_and_nutrition_panel_carries_height_for_age(): void
    {
        $html = $this->withSession($this->nurseSession([$this->learner()]))
            ->get('/dashboard/student-health-records')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Growth &amp; Nutrition', $html);
        $this->assertStringContainsString('<div class="k">Height-for-Age:</div>', $html);
        $this->assertStringContainsString('id="pgHfa"', $html);

        // It reads the shared reading, and nothing in the view classifies.
        $this->assertStringContainsString('record.nutrition', $html);
    }
}
