<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\ClassMasterlistTemplate;
use App\Support\SchoolPulse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The School Nurse's Forms & Templates tab.
 *
 * It is the Feeding Coordinator's SBFP forms page — the BMI reports and both
 * master lists — read in the nurse's own rail, without the narrative report.
 * The class advisers' blank Class Masterlist used to be offered here too; it is
 * the adviser's form alone (tester's report, 8 November 2026, #26). The Clinic
 * Teacher reads the nurse's copy in their own rail. One controller renders every desk's
 * copy, so the figures on a form cannot differ between them.
 */
class NurseFormsAndTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    /** @return array<string, mixed> */
    private function nurseSession(): array
    {
        return [
            'active_role' => 'school_nurse',
            'active_name' => 'Nurse Cruz',
            'active_username' => 'nurse.cruz',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->institution->id,
        ];
    }

    /** @return array<string, mixed> */
    private function coordinatorSession(): array
    {
        return [
            'active_role' => 'feeding_coor',
            'active_name' => 'Test Coordinator',
            'active_username' => 'feedcor.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->institution->id,
        ];
    }

    /** @return array<string, mixed> */
    private function clinicTeacherSession(): array
    {
        return array_merge($this->nurseSession(), [
            'active_role' => 'clinic_teacher',
            'active_name' => 'Teacher Lim',
            'active_username' => 'teacher.lim',
        ]);
    }

    private function learner(string $name, string $section, string $gender, string $status): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => $name,
            'student_id' => 'LRN'.random_int(100000, 999999),
            'school_name' => 'Test School',
            'section' => $section,
            'weight' => 30,
            'bmi_value' => 15.0,
            'nutritional_status' => $status,
            'baseline_nutritional_status' => $status,
            'student_details' => ['gender' => $gender, 'nutritional_status_height_for_age' => 'Normal'],
        ]);
    }

    #[Test]
    public function the_nurse_reads_every_coordinator_form_but_the_narrative_report(): void
    {
        $html = $this->withSession($this->nurseSession())
            ->get(route('dashboard.school-nurse.forms'))
            ->assertOk()
            ->getContent();

        // The forms the coordinator keeps, by their template ids.
        foreach (['bmi-report', 'feeding-beneficiaries', 'feeding-masterlist'] as $template) {
            $this->assertStringContainsString('value="'.$template.'"', $html, "The nurse's copy is missing {$template}.");
            $this->assertStringContainsString('data-template="'.$template.'"', $html);
        }

        // The narrative report is not hidden — it is not in the document: no
        // option, no panel, no drafted text. (The page script still names the
        // template id in a branch that can no longer be reached.)
        $this->assertStringNotContainsString('value="feeding-narrative"', $html);
        $this->assertStringNotContainsString('data-template="feeding-narrative"', $html);
        $this->assertStringNotContainsString('id="narrativePanel"', $html);
        $this->assertStringNotContainsString('Generate Interpretation', $html);
        $this->assertStringNotContainsString('data-derived=', $html);
        $this->assertSame([], $this->withSession($this->nurseSession())
            ->get(route('dashboard.school-nurse.forms'))
            ->viewData('narrative'), 'No narrative is drafted for the nurse.');

        // The advisers' class masterlist is theirs alone, and is not here.
        $this->assertStringNotContainsString('value="class-masterlist"', $html);
        $this->assertStringNotContainsString('data-template="class-masterlist"', $html);
        $this->assertStringNotContainsString('Class Adviser (blank template)', $html);
    }

    #[Test]
    public function it_is_drawn_in_the_nurses_own_rail(): void
    {
        $html = $this->withSession($this->nurseSession())
            ->get(route('dashboard.school-nurse.forms'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('href="'.route('dashboard.school-nurse.forms').'" class="sb-link active"', $html);
        $this->assertStringNotContainsString('<aside class="asb-sidebar"', $html, "A nurse's click must never land on the coordinator's menu.");
        $this->assertStringContainsString('<span>School Nurse</span>', $html);
        $this->assertStringContainsString('Forms &amp; <span>Templates</span>', $html);
        $this->assertSame(1, substr_count($html, '<aside'));

        // The live roster rides the nurse's own pulse and path, never the
        // coordinator's, where a demo session would be re-seeded as them.
        $this->assertStringContainsString(json_encode(route('workspace.pulse')), $html);
        $this->assertStringContainsString(json_encode(route('dashboard.school-nurse.forms.roster')), $html);
        $this->assertStringNotContainsString(json_encode(route('dashboard.feedingcor.metrics.pulse')), $html);
    }

    #[Test]
    public function the_coordinators_page_is_unchanged(): void
    {
        $html = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-sbfp-forms')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="feeding-narrative"', $html);
        $this->assertStringContainsString('Generate Interpretation', $html);
        $this->assertStringContainsString('<aside class="asb-sidebar"', $html);
        $this->assertStringNotContainsString('data-template="class-masterlist"', $html);
        $this->assertStringContainsString(json_encode(route('dashboard.feedingcor.metrics.pulse')), $html);
    }

    #[Test]
    public function every_desk_prints_the_same_figures(): void
    {
        $this->learner('Reyes, Ana', 'Grade 7 / Matiyaga', 'Female', 'Wasted')
            ->update(['feeding_enrolled_at' => now()]);
        $this->learner('Gomez, Jose', 'Grade 8 / Dalton', 'Male', 'Severely Wasted');

        $nurse = $this->withSession($this->nurseSession())->get(route('dashboard.school-nurse.forms'))->assertOk();
        $teacher = $this->withSession($this->clinicTeacherSession())->get(route('dashboard.clinic-teacher.forms'))->assertOk();
        $coordinator = $this->withSession($this->coordinatorSession())->get('/dashboard/feedingcor-sbfp-forms')->assertOk();

        foreach (['bmiValues', 'bmiValueSets', 'bmiReports', 'studentsByGrade', 'sectionsByGrade'] as $key) {
            $this->assertSame($coordinator->viewData($key), $nurse->viewData($key), "{$key} differs between the coordinator and the nurse.");
            $this->assertSame($coordinator->viewData($key), $teacher->viewData($key), "{$key} differs between the coordinator and the clinic teacher.");
        }

        // The control is on all three, over figures that really do differ.
        foreach ([$coordinator, $nurse, $teacher] as $desk) {
            $desk->assertSee('id="reportPopulationSelect"', false);
        }
        $this->assertSame(2, $coordinator->viewData('bmiReports')['all']['values']['']['bmib_overall_total_nst']);
        $this->assertSame(1, $coordinator->viewData('bmiReports')['beneficiaries']['values']['']['bmib_overall_total_nst']);
    }

    #[Test]
    public function the_clinic_teacher_reads_the_nurses_copy_in_their_own_rail(): void
    {
        $html = $this->withSession($this->clinicTeacherSession())
            ->get(route('dashboard.clinic-teacher.forms'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('href="'.route('dashboard.clinic-teacher.forms').'" class="sb-link active"', $html);
        $this->assertStringNotContainsString('href="'.route('dashboard.school-nurse.forms').'"', $html);
        $this->assertStringContainsString('<span>Clinic Teacher</span>', $html);
        $this->assertStringContainsString('Forms &amp; <span>Templates</span>', $html);
        $this->assertSame(1, substr_count($html, '<aside'));

        // The same forms as the nurse's copy, and no narrative report.
        foreach (['bmi-report', 'feeding-beneficiaries', 'feeding-masterlist'] as $template) {
            $this->assertStringContainsString('data-template="'.$template.'"', $html);
        }
        $this->assertStringNotContainsString('data-template="feeding-narrative"', $html);
        $this->assertStringNotContainsString('data-template="class-masterlist"', $html);

        // Its live roster stays on this role's own path.
        $this->assertStringContainsString(json_encode(route('workspace.pulse')), $html);
        $this->assertStringContainsString(json_encode(route('dashboard.clinic-teacher.forms.roster')), $html);
        $this->assertStringNotContainsString(route('dashboard.school-nurse.forms.roster'), $html);
    }

    #[Test]
    public function the_class_masterlist_is_the_advisers_alone(): void
    {
        // No clinic path serves it any more.
        $this->assertFalse(app('router')->has('dashboard.school-nurse.forms.class-masterlist'));
        $this->assertFalse(app('router')->has('dashboard.clinic-teacher.forms.class-masterlist'));

        foreach ([[$this->nurseSession(), 'dashboard.school-nurse.forms'], [$this->clinicTeacherSession(), 'dashboard.clinic-teacher.forms']] as [$session, $route]) {
            $html = $this->withSession($session)->get(route($route))->assertOk()->getContent();

            $this->assertStringNotContainsString(ClassMasterlistTemplate::TITLE, $html, $route);
            $this->assertStringNotContainsString('cm-line', $html, $route);
        }
    }

    #[Test]
    public function the_clinic_teachers_roster_is_stamped_with_the_pulse_it_polls(): void
    {
        $this->learner('Reyes, Ana', 'Grade 7 / Matiyaga', 'Female', 'Wasted');

        $roster = $this->withSession($this->clinicTeacherSession())
            ->getJson(route('dashboard.clinic-teacher.forms.roster'))
            ->assertOk();

        $this->assertSame(
            $roster->json('stamp'),
            $this->withSession($this->clinicTeacherSession())->getJson(route('workspace.pulse'))->json('stamp'),
        );
    }

    #[Test]
    public function an_adviser_still_gets_their_own_class_on_the_form(): void
    {
        $session = app('session.store');
        $session->replace([
            'active_role' => 'class_adviser',
            'active_name' => 'Maria Santos',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->institution->id,
            'assigned_grade_level' => 'Grade 7',
            'assigned_section' => 'Sampaguita',
        ]);

        $template = ClassMasterlistTemplate::forSession($session);

        $cells = array_map(fn (array $row): string => $row['cells'][0], $template['rows']);

        $this->assertContains('GRADE 7 - SAMPAGUITA', $cells);
        $this->assertContains('MARIA SANTOS', $cells);
    }

    #[Test]
    public function the_page_layout_follows_the_workbooks_merges(): void
    {
        $rows = ClassMasterlistTemplate::printRows(ClassMasterlistTemplate::build(
            ['school' => 'Test School', 'address' => ''], '', '2026-2027', ''
        ));

        // Every banner line spans the form.
        $this->assertSame([['text' => 'Test School', 'colspan' => 6, 'rowspan' => 1]], $rows[0]['cells']);

        // The stacked header: NO, LRN and REMARKS span both rows; the name
        // caption is one cell over three columns on the second.
        $this->assertSame(
            [
                ['text' => 'NO', 'colspan' => 1, 'rowspan' => 2],
                ['text' => 'MALE', 'colspan' => 3, 'rowspan' => 1],
                ['text' => 'LRN', 'colspan' => 1, 'rowspan' => 2],
                ['text' => 'REMARKS', 'colspan' => 1, 'rowspan' => 2],
            ],
            $rows[6]['cells']
        );
        $this->assertSame([['text' => ClassMasterlistTemplate::NAME_CAPTION, 'colspan' => 3, 'rowspan' => 1]], $rows[7]['cells']);

        // A band runs on across the row; a learner's line keeps six boxes.
        $this->assertSame([['text' => 'MALE', 'colspan' => 6, 'rowspan' => 1]], $rows[8]['cells']);
        $this->assertCount(6, $rows[9]['cells']);

        // The line above "ADVISER" is the one signed on.
        $last = count($rows) - 1;
        $this->assertTrue($rows[$last - 1]['signature']);
        $this->assertFalse($rows[$last]['signature']);
    }

    #[Test]
    public function the_nurses_live_roster_is_stamped_with_the_pulse_it_polls(): void
    {
        $this->learner('Reyes, Ana', 'Grade 7 / Matiyaga', 'Female', 'Wasted');

        $roster = $this->withSession($this->nurseSession())
            ->getJson(route('dashboard.school-nurse.forms.roster'))
            ->assertOk();

        $this->assertSame(SchoolPulse::stamp($this->institution->id), $roster->json('stamp'));
        $this->assertSame(
            $roster->json('stamp'),
            $this->withSession($this->nurseSession())->getJson(route('workspace.pulse'))->json('stamp'),
            'Compared like with like, or the roster is re-read on every tick.'
        );
        $this->assertSame('Reyes, Ana', $roster->json('studentsByGrade')['Grade 7'][0]['name']);
    }

    #[Test]
    public function a_role_that_keeps_no_forms_is_turned_away(): void
    {
        $head = array_merge($this->nurseSession(), ['active_role' => 'school_head', 'active_username' => 'head.test']);

        $this->withSession($head)
            ->get(route('dashboard.school-nurse.forms'))
            ->assertRedirect(route('login'));

        $this->withSession($head)
            ->getJson(route('dashboard.school-nurse.forms.roster'))
            ->assertForbidden();
    }
}
