<?php

namespace Tests\Feature;

use App\Http\Controllers\NurseController;
use App\Models\Institution;
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

        $this->assertStringContainsString('data-filter="grade" data-value="Grade 7"', $html);
        $this->assertStringContainsString('data-filter="grade" data-value="Grade 10"', $html);
        $this->assertStringContainsString('data-filter="section" data-value="curie"', $html);
        $this->assertStringContainsString('data-filter="section" data-value="dalton"', $html);
        $this->assertStringContainsString('data-filter="sex" data-value="male"', $html);
        $this->assertStringContainsString('data-filter="sex" data-value="female"', $html);

        // A grade nobody is in never becomes a chip.
        $this->assertStringNotContainsString('data-value="Grade 8"', $html);

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

        // Nothing from the old tab set was dropped — each section moved into
        // one of the six tabs.
        foreach ([
            'Personal Information', 'Parent/Guardian Information', 'Medical &amp; Family History',
            'SHD Form 2 Snapshot', 'Growth &amp; Nutrition', 'Health History',
            'Systems Review', 'Health Assessment', 'Consultation Log',
            'Add Clinic Note', 'Note History', 'Parental Consent', 'Medical Documents',
        ] as $section) {
            $response->assertSee($section, false);
        }
    }

    #[Test]
    public function the_profile_tabs_match_the_requested_set_with_badges(): void
    {
        $response = $this->withSession($this->nurseSession([$this->learner()]))
            ->get('/dashboard/student-health-records')
            ->assertOk();

        foreach ([
            'Sheet 1' => 'Learner Info',
            'Sheet 2' => 'Systems Review',
            'Consultation' => 'Log',
        ] as $tab => $badge) {
            $response->assertSee($tab)->assertSee($badge);
        }

        $response->assertSee('Clinic Notes')
            ->assertSee('Consent')
            ->assertSee('Documents');

        // Live badge targets the scripts fill in.
        foreach (['pConsultBadge', 'pNotesBadge', 'pConsentBadge', 'pDocsBadge'] as $badgeId) {
            $response->assertSee('id="'.$badgeId.'"', false);
        }
    }

    #[Test]
    public function the_profile_uses_the_same_modal_presentation_as_the_adviser(): void
    {
        $adviserShell = [
            'profile-backdrop',            // dimmed overlay
            'student-profile-modal',       // column-flex modal
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

        foreach ($adviserShell as $hook) {
            $nurse->assertSee($hook, false);
        }

        $nurse->assertSee('Student Profile')->assertSee('&larr;', false);

        // The same building blocks the adviser's own view-profile is made of.
        $adviserMarkup = file_get_contents(resource_path('views/adviser-dashboard/class-adviser.blade.php'));
        foreach ($adviserShell as $hook) {
            $this->assertStringContainsString($hook, $adviserMarkup, "Adviser profile should also use .{$hook}");
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

        $dashboard = strpos($html, route('dashboard.school-nurse').'" class="nsb-item');
        $records = strpos($html, route('dashboard.student-health-records').'" class="nsb-item');
        $queue = strpos($html, route('nurse.index').'" class="nsb-item');

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
}
