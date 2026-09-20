<?php

namespace Tests\Feature;

use App\Http\Controllers\NurseController;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
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
        // The record's own date stays, and the screenings are still there.
        $response->assertSee('name="date_of_examination"', false);
        $response->assertSee('Screening &amp; Physical Examination', false);
        $response->assertSee('name="vision_screening"', false);

        $this->withSession($this->nurseSession([$learner]))
            ->post(route('nurse.examine.save', 0), ['vision_screening' => '20/20'])
            ->assertRedirect(route('dashboard.student-health-records'));

        $exam = session('school_health_card_records')[0]['examination'];
        $this->assertSame('20/20', $exam['vision_screening']);
        $this->assertEquals(152, $exam['height_cm'], 'Stamped from the record, not the form.');
        $this->assertEquals(44, $exam['weight_kg']);
        $this->assertSame('Normal', $exam['nutritional_status_bmi']);
        $this->assertEquals(152, session('school_health_card_records')[0]['height_cm'], 'The measurement is untouched.');
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
            'p-sheet1', 'p-sheet2', 'p-consultation', 'p-clinic-notes', 'p-consent', 'p-documents',
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
            'Add Clinic Note', 'Note History', 'Parental Consent', 'Medical Documents',
        ] as $section) {
            $response->assertSee($section, false);
        }

        $response->assertDontSee('SHD Form 2 Snapshot');
        $response->assertDontSee('id="psStatus"', false);
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

        $response->assertSee('Clinic Notes')
            ->assertSee('Consultation Log')
            ->assertSee('Consent')
            ->assertSee('Documents');

        // Live badge targets the scripts fill in.
        foreach (['pConsultBadge', 'pNotesBadge', 'pConsentBadge', 'pDocsBadge'] as $badgeId) {
            $response->assertSee('id="'.$badgeId.'"', false);
        }

        // The tab strip reads in the order the school asked for, and the panels
        // are laid out in the same order so a printed profile follows the tabs.
        $html = $response->getContent();
        // Incident Reports closes the strip: it is the nurse's FDAR chart of
        // something that happened to this learner, and the adviser reads the
        // same panel on their own profile.
        $expected = ['p-sheet1', 'p-sheet2', 'p-consent', 'p-clinic-notes', 'p-consultation', 'p-documents', 'p-incidents'];

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
}
