<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\InstitutionSection;
use App\Models\StudentHealthRecord;
use App\Support\LearnerPlacement;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TeacherAssignmentSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 09:00:00');
        $this->school = Institution::create(['name' => 'Teacher Assignment School', 'status' => 'active']);

        foreach (['Grade 7' => 'MATIYAGA', 'Grade 8' => 'EAGLE'] as $grade => $section) {
            InstitutionSection::create([
                'institution_id' => $this->school->id,
                'grade_level' => $grade,
                'name' => $section,
            ]);
        }
    }

    public static function teacherRoles(): array
    {
        return [['class_adviser'], ['clinic_teacher']];
    }

    private function account(string $role = 'class_adviser', array $overrides = []): int
    {
        return (int) DB::table('accounts')->insertGetId(array_merge([
            'name' => 'Teacher Cruz',
            'username' => 'teacher.cruz',
            'password_hash' => Hash::make('teacherpass1'),
            'role' => $role,
            'institution_id' => $this->school->id,
            'school_name' => $this->school->name,
            'assigned_grade_level' => 'Grade 7',
            'assigned_section' => 'MATIYAGA',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function teacherSession(string $role = 'class_adviser', array $overrides = []): array
    {
        return array_merge([
            'active_role' => $role,
            'active_name' => 'Teacher Cruz',
            'active_username' => 'teacher.cruz',
            'active_institution_id' => $this->school->id,
            'active_school_name' => $this->school->name,
            'assigned_school_name' => $this->school->name,
            'assigned_grade_level' => 'Grade 7',
            'assigned_section' => 'MATIYAGA',
        ], $overrides);
    }

    private function assignment(array $overrides = []): array
    {
        return array_merge([
            'assigned_grade_level' => 'Grade 8',
            'assigned_section' => 'EAGLE',
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'confirm_assignment' => '1',
        ], $overrides);
    }

    private function learner(string $lrn, string $grade, string $section, string $year = '2026-2027'): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'school_year' => $year,
            'student_id' => $lrn,
            'student_name' => $lrn.', Learner',
            'school_name' => $this->school->name,
            'section' => $grade.' / '.$section,
            'student_details' => [
                'last_name' => $lrn,
                'first_name' => 'Learner',
                'grade_level' => $grade,
                'section' => $section,
            ],
        ]);
    }

    #[Test]
    #[DataProvider('teacherRoles')]
    public function both_teacher_roles_can_update_their_own_assignment(string $role): void
    {
        $id = $this->account($role);

        $this->withSession($this->teacherSession($role, ['school_health_card_records' => [['lrn' => 'OLD-CACHED']]]))
            ->from(route('settings'))
            ->post(route('settings.assignment'), $this->assignment([
                'assigned_grade_level' => '  grade 8 ',
                'assigned_section' => ' eagle ',
            ]))
            ->assertRedirect(route('settings'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success')
            ->assertSessionHas('assigned_grade_level', 'Grade 8')
            ->assertSessionHas('assigned_section', 'EAGLE')
            ->assertSessionHas('assigned_school_name', $this->school->name)
            ->assertSessionMissing('school_health_card_records');

        $this->assertDatabaseHas('accounts', [
            'id' => $id,
            'role' => $role,
            'assigned_grade_level' => 'Grade 8',
            'assigned_section' => 'EAGLE',
        ]);
    }

    #[Test]
    #[DataProvider('teacherRoles')]
    public function settings_renders_the_assignment_form_and_stored_assignment_for_teachers(string $role): void
    {
        $this->account($role);

        $this->withSession($this->teacherSession($role))
            ->get(route('settings'))
            ->assertOk()
            ->assertSee('action="'.route('settings.assignment').'"', false)
            ->assertSee('name="assigned_grade_level"', false)
            ->assertSee('name="assigned_section"', false)
            ->assertSee('name="confirm_assignment"', false)
            ->assertSee('MATIYAGA')
            ->assertSee(StudentHealthRecord::currentSchoolYear());
    }

    #[Test]
    public function other_roles_have_no_assignment_form_and_cannot_change_assignments(): void
    {
        foreach (['school_nurse', 'clinic_staff', 'school_head', 'feeding_coor', 'nutricor', 'system_admin'] as $role) {
            $id = $this->account($role, ['username' => $role]);
            $session = $this->teacherSession($role, ['active_username' => $role]);

            $this->withSession($session)->get(route('settings'))->assertOk()
                ->assertDontSee('action="'.route('settings.assignment').'"', false);

            $this->withSession($session)->post(route('settings.assignment'), $this->assignment())->assertForbidden();
            $this->assertDatabaseHas('accounts', ['id' => $id, 'assigned_section' => 'MATIYAGA']);
        }
    }

    #[Test]
    public function prototype_missing_and_role_mismatched_accounts_cannot_update_assignments(): void
    {
        config(['app.prototype_sessions' => true]);
        $id = $this->account('school_nurse');
        $this->account('class_adviser', ['username' => 'prototype']);

        foreach (['prototype', 'missing.teacher', 'teacher.cruz'] as $username) {
            $this->withSession($this->teacherSession('class_adviser', ['active_username' => $username]))
                ->post(route('settings.assignment'), $this->assignment())
                ->assertForbidden();
        }

        $this->assertDatabaseHas('accounts', ['id' => $id, 'assigned_section' => 'MATIYAGA']);
    }

    #[Test]
    public function posted_account_school_and_role_fields_cannot_target_another_account(): void
    {
        $id = $this->account();
        $colleague = $this->account('class_adviser', ['username' => 'colleague']);
        $otherSchool = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $sameUsername = $this->account('class_adviser', [
            'institution_id' => $otherSchool->id,
            'school_name' => $otherSchool->name,
        ]);

        $this->withSession($this->teacherSession())
            ->from(route('settings'))
            ->post(route('settings.assignment'), $this->assignment([
                'id' => $colleague,
                'account_id' => $sameUsername,
                'username' => 'colleague',
                'role' => 'system_admin',
                'institution_id' => $otherSchool->id,
                'school_name' => $otherSchool->name,
            ]))
            ->assertRedirect(route('settings'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('active_role', 'class_adviser')
            ->assertSessionHas('active_institution_id', $this->school->id);

        $this->assertDatabaseHas('accounts', [
            'id' => $id,
            'username' => 'teacher.cruz',
            'institution_id' => $this->school->id,
            'school_name' => $this->school->name,
            'role' => 'class_adviser',
            'assigned_section' => 'EAGLE',
        ]);
        $this->assertDatabaseHas('accounts', ['id' => $colleague, 'assigned_section' => 'MATIYAGA']);
        $this->assertDatabaseHas('accounts', ['id' => $sameUsername, 'assigned_section' => 'MATIYAGA']);
    }

    #[Test]
    public function sections_from_another_grade_or_another_school_are_refused(): void
    {
        $id = $this->account();
        $otherSchool = Institution::create(['name' => 'Other School', 'status' => 'active']);
        InstitutionSection::create(['institution_id' => $otherSchool->id, 'grade_level' => 'Grade 8', 'name' => 'OTHER']);

        foreach (['MATIYAGA', 'OTHER', 'MADE-UP'] as $section) {
            $this->withSession($this->teacherSession())
                ->from(route('settings'))
                ->post(route('settings.assignment'), $this->assignment(['assigned_section' => $section]))
                ->assertRedirect(route('settings'))
                ->assertSessionHasErrors('assigned_section', null, 'assignment');
        }

        $this->assertDatabaseHas('accounts', ['id' => $id, 'assigned_grade_level' => 'Grade 7', 'assigned_section' => 'MATIYAGA']);
    }

    public static function invalidAssignments(): array
    {
        return [
            'not a school year' => [['school_year' => '2027-2029'], 'school_year'],
            'year off the list' => [['school_year' => '2040-2041'], 'school_year'],
            'missing year' => [['school_year' => null], 'school_year'],
            'no confirmation' => [['confirm_assignment' => null], 'confirm_assignment'],
            'declined confirmation' => [['confirm_assignment' => '0'], 'confirm_assignment'],
            'empty grade' => [['assigned_grade_level' => '  '], 'assigned_grade_level'],
            'empty section' => [['assigned_section' => '  '], 'assigned_section'],
            'long grade' => [['assigned_grade_level' => str_repeat('G', 51)], 'assigned_grade_level'],
            'long section' => [['assigned_section' => str_repeat('S', 101)], 'assigned_section'],
        ];
    }

    #[Test]
    #[DataProvider('invalidAssignments')]
    public function invalid_or_unconfirmed_assignments_change_nothing(array $overrides, string $error): void
    {
        $id = $this->account();

        $this->withSession($this->teacherSession())
            ->from(route('settings'))
            ->post(route('settings.assignment'), $this->assignment($overrides))
            ->assertRedirect(route('settings'))
            ->assertSessionHasErrors($error, null, 'assignment')
            ->assertSessionHas('assigned_section', 'MATIYAGA');

        $this->assertDatabaseHas('accounts', ['id' => $id, 'assigned_grade_level' => 'Grade 7', 'assigned_section' => 'MATIYAGA']);
        $this->assertSame(0, AuditLog::where('subject_type', 'Account')->where('action', 'updated')->count());
    }

    #[Test]
    public function a_school_without_a_catalog_accepts_a_typed_assignment(): void
    {
        $id = $this->account();
        InstitutionSection::where('institution_id', $this->school->id)->delete();

        $this->withSession($this->teacherSession())->from(route('settings'))
            ->post(route('settings.assignment'), $this->assignment([
                'assigned_grade_level' => ' Grade 9 ',
                'assigned_section' => ' Rizal ',
            ]))
            ->assertRedirect(route('settings'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('accounts', ['id' => $id, 'assigned_grade_level' => 'Grade 9', 'assigned_section' => 'Rizal']);
    }

    #[Test]
    public function assignment_changes_are_audited_and_never_move_or_delete_students(): void
    {
        $id = $this->account();
        $this->learner('SAME-LEARNER', 'Grade 7', 'MATIYAGA', '2025-2026');
        $this->learner('SAME-LEARNER', 'Grade 8', 'EAGLE');
        $this->learner('OTHER-LEARNER', 'Grade 7', 'MATIYAGA');
        $before = DB::table('student_health_records')->orderBy('id')->get()->toArray();

        $this->withSession($this->teacherSession())->from(route('settings'))
            ->post(route('settings.assignment'), $this->assignment())->assertSessionHasNoErrors();

        $this->assertEquals($before, DB::table('student_health_records')->orderBy('id')->get()->toArray());
        $entry = AuditLog::where('subject_type', 'Account')->where('subject_id', $id)->where('action', 'updated')->latest('id')->first();
        $this->assertNotNull($entry);
        $this->assertSame('teacher.cruz', $entry->actor_username);
        $this->assertSame('2026-2027', $entry->details['school_year']);
        $this->assertSame(['assigned_grade_level' => 'Grade 7', 'assigned_section' => 'MATIYAGA', 'assigned_school_year' => null], $entry->details['old']);
        $this->assertSame(['assigned_grade_level' => 'Grade 8', 'assigned_section' => 'EAGLE', 'assigned_school_year' => null], $entry->details['new']);
    }

    // ── School-year testing ──────────────────────────────────────────

    #[Test]
    #[DataProvider('teacherRoles')]
    public function while_testing_a_teacher_may_set_their_assignment_to_any_school_year(string $role): void
    {
        $id = $this->account($role);

        $this->withSession($this->teacherSession($role))->get(route('settings'))->assertOk()
            ->assertSee('<select class="input" id="assignmentYear" name="school_year" required>', false)
            ->assertSee('<option value="2026-2027" selected>2026-2027 (current)</option>', false)
            ->assertSee('<option value="2031-2032" >', false)
            ->assertSee('<option value="2021-2022" >', false);

        $this->withSession($this->teacherSession($role))->from(route('settings'))
            ->post(route('settings.assignment'), $this->assignment(['school_year' => '2027-2028']))
            ->assertRedirect(route('settings'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'SY 2027-2028'))
            ->assertSessionHas('assigned_school_year', '2027-2028');
        $this->assertDatabaseHas('accounts', ['id' => $id, 'assigned_school_year' => '2027-2028']);

        $this->get(route('settings'))
            ->assertSee('<option value="2027-2028" selected>2027-2028</option>', false);

        // Choosing the calendar's year again stores nothing, so the
        // assignment follows the calendar into June as before.
        $this->from(route('settings'))
            ->post(route('settings.assignment'), $this->assignment(['school_year' => '2026-2027']))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('assigned_school_year');
        $this->assertDatabaseHas('accounts', ['id' => $id, 'assigned_school_year' => null]);
    }

    #[Test]
    public function only_the_teachers_own_session_works_in_the_chosen_year(): void
    {
        $this->account('class_adviser', [
            'assigned_grade_level' => 'Grade 8',
            'assigned_section' => 'EAGLE',
            'assigned_school_year' => '2027-2028',
        ]);
        $this->learner('THIS-YEAR', 'Grade 8', 'EAGLE');
        $this->learner('NEXT-YEAR', 'Grade 8', 'EAGLE', '2027-2028');

        $response = $this->withSession($this->teacherSession())->get('/dashboard/class-adviser')->assertOk();
        $this->assertSame(['NEXT-YEAR'], collect($response->viewData('records'))->pluck('student_id')->all());

        // A stray year in another role's session moves nothing.
        $this->flushSession()->withSession([
            'active_role' => 'school_nurse',
            'active_name' => 'Nurse Reyes',
            'active_institution_id' => $this->school->id,
            'assigned_school_year' => '2027-2028',
        ])->get('/dashboard/student-health-records')->assertOk()
            ->assertSee('THIS-YEAR')
            ->assertDontSee('NEXT-YEAR');
    }

    #[Test]
    public function with_testing_off_the_assignment_is_for_the_calendars_year_alone(): void
    {
        config(['app.school_year_testing' => false]);
        $id = $this->account('class_adviser', ['assigned_school_year' => '2027-2028']);

        // A year stored while testing was on is ignored once it is off.
        $this->withSession($this->teacherSession())->get(route('settings'))->assertOk()
            ->assertSee('id="assignmentYear" name="school_year" value="2026-2027" readonly', false)
            ->assertDontSee('<select class="input" id="assignmentYear"', false);

        foreach (['2025-2026', '2027-2028'] as $year) {
            $this->withSession($this->teacherSession())->from(route('settings'))
                ->post(route('settings.assignment'), $this->assignment(['school_year' => $year]))
                ->assertSessionHasErrors('school_year', null, 'assignment');
        }

        $this->assertDatabaseHas('accounts', ['id' => $id, 'assigned_section' => 'MATIYAGA']);
    }

    /**
     * What the setting is for: close a Grade 7 class, move to next year's
     * Grade 8, and carry the learner over — their Grade 7 year kept beside it.
     */
    #[Test]
    public function a_learner_can_be_followed_into_the_next_school_year(): void
    {
        $this->account();
        $this->learner('100000000777', 'Grade 7', 'MATIYAGA');

        $this->withSession($this->teacherSession())
            ->post(route('class-adviser.close-year.store'), [
                'school_year' => '2026-2027',
                'statuses' => ['100000000777' => LearnerPlacement::PROMOTED],
            ])
            ->assertSessionHas('success');

        $this->from(route('settings'))
            ->post(route('settings.assignment'), $this->assignment(['school_year' => '2027-2028']))
            ->assertSessionHasNoErrors();

        $this->post(route('adviser.add-to-section'), ['add_lrn' => '100000000777'])
            ->assertSessionHas('success');

        $rows = StudentHealthRecord::where('student_id', '100000000777')->orderBy('school_year')->get();
        $this->assertSame(['2026-2027', '2027-2028'], $rows->pluck('school_year')->all());
        $this->assertSame('Grade 7 / MATIYAGA', $rows[0]->section);
        $this->assertSame(LearnerPlacement::PROMOTED, $rows[0]->end_status);
        $this->assertSame('Grade 8 / EAGLE', $rows[1]->section);
        $this->assertSame(LearnerPlacement::CARRIED_OVER, $rows[1]->joined_as);

        $response = $this->get('/dashboard/class-adviser')->assertOk();
        $this->assertSame(['100000000777'], collect($response->viewData('records'))->pluck('student_id')->all());
    }

    #[Test]
    public function another_open_session_uses_the_saved_assignment_before_reading_learners(): void
    {
        $id = $this->account();
        $this->learner('OLD-CLASS', 'Grade 7', 'MATIYAGA');
        $this->learner('NEW-CLASS', 'Grade 8', 'EAGLE');
        $staleSession = $this->teacherSession('class_adviser', [
            'school_health_card_records' => [[
                'lrn' => 'OBSOLETE-CACHED-LEARNER',
                'first_name' => 'Old cached learner',
                'grade_level' => 'Grade 8',
                'section' => 'EAGLE',
            ]],
        ]);

        $this->withSession($staleSession)->from(route('settings'))
            ->post(route('settings.assignment'), $this->assignment())->assertSessionHasNoErrors();

        $response = $this->withSession($staleSession)
            ->get('/dashboard/class-adviser')->assertOk()
            ->assertSessionHas('assigned_grade_level', 'Grade 8')
            ->assertSessionHas('assigned_section', 'EAGLE');

        $this->assertSame(['NEW-CLASS'], collect($response->viewData('records'))->pluck('student_id')->all());
        $this->withSession($staleSession)->get('/dashboard/class-adviser/students/OLD-CLASS')
            ->assertRedirect()->assertSessionHas('error');
        $this->withSession($staleSession)->get('/dashboard/class-adviser/students/NEW-CLASS')->assertOk();
        $this->assertDatabaseHas('accounts', ['id' => $id, 'assigned_section' => 'EAGLE']);
    }

    #[Test]
    public function a_clinic_teachers_assignment_does_not_reduce_school_wide_clinic_access(): void
    {
        $this->account('clinic_teacher');
        $this->learner('OLD-CLASS', 'Grade 7', 'MATIYAGA');
        $this->learner('NEW-CLASS', 'Grade 8', 'EAGLE');

        $this->withSession($this->teacherSession('clinic_teacher'))->from(route('settings'))
            ->post(route('settings.assignment'), $this->assignment())->assertSessionHasNoErrors();

        $this->get('/dashboard/student-health-records')->assertOk()
            ->assertSee('OLD-CLASS')
            ->assertSee('NEW-CLASS');
    }

    #[Test]
    #[DataProvider('teacherRoles')]
    public function the_saved_assignment_survives_logging_out_and_back_in(string $role): void
    {
        $this->account($role);
        $this->withSession($this->teacherSession($role))->from(route('settings'))
            ->post(route('settings.assignment'), $this->assignment())->assertSessionHasNoErrors();
        $this->post(route('logout'))->assertRedirect();

        $this->post('/login', ['email' => 'teacher.cruz', 'password' => 'teacherpass1'])
            ->assertRedirect()
            ->assertSessionHas('active_role', $role)
            ->assertSessionHas('assigned_grade_level', 'Grade 8')
            ->assertSessionHas('assigned_section', 'EAGLE')
            ->assertSessionHas('assigned_school_name', $this->school->name);
    }
}
