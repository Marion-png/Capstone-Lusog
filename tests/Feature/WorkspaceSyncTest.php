<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Institution;
use App\Models\Medicine;
use App\Models\StudentHealthRecord;
use App\Support\SchoolPulse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One desk's submission reaches the others' screens.
 *
 * Every role here reads something another role writes, and none of it happens
 * on the screen of the person who needs to see it. The mechanism is one
 * school-wide change stamp (`App\Support\SchoolPulse`) polled by one partial
 * (`partials/workspace-live`) — so the tests that matter are: does the stamp
 * actually move when somebody else writes, does it stay inside the school, and
 * does every screen that reads somebody else's work actually poll it.
 *
 * The fourth is the one that is easy to get wrong in the other direction:
 * `the_reload_is_held_back_when_there_is_work_in_progress`. A roster that
 * refreshes itself under a half-written consultation is worse than a stale
 * one, so the partial must never reload unconditionally.
 */
class WorkspaceSyncTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    private Institution $otherSchool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
        $this->otherSchool = Institution::create(['name' => 'Another NHS', 'status' => 'active']);
    }

    private function sessionFor(string $role, ?Institution $school = null): array
    {
        $school ??= $this->school;

        $session = [
            'active_role' => $role,
            'active_name' => 'Test '.$role,
            'active_username' => 'test'.$role,
            'active_school_name' => $school->name,
            'active_institution_id' => $school->id,
        ];

        if ($role === 'class_adviser') {
            $session['assigned_grade_level'] = 'Grade 10';
            $session['assigned_section'] = 'Dalton';
        }

        return $session;
    }

    /** The stamp as this role's browser would read it, over HTTP. */
    private function stampFor(string $role, ?Institution $school = null): string
    {
        return (string) $this->withSession($this->sessionFor($role, $school))
            ->getJson(route('workspace.pulse'))
            ->assertOk()
            ->json('stamp');
    }

    /** The page as a human reads it: `@json` escapes every slash in a URL. */
    private function pageOf(string $role, string $uri): string
    {
        $html = $this->withSession($this->sessionFor($role))->get($uri)->assertOk()->getContent();

        return str_replace('\/', '/', $html);
    }

    private function learner(array $overrides = []): StudentHealthRecord
    {
        return StudentHealthRecord::create(array_merge([
            'student_id' => (string) random_int(100000000000, 999999999999),
            'institution_id' => $this->school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'section' => 'Grade 10 - Dalton',
            'school_name' => $this->school->name,
            'student_name' => 'Cruz, Juan',
        ], $overrides));
    }

    // ── The stamp ───────────────────────────────────────────────────────

    /**
     * The whole point: a write by one role changes what every other role's
     * screen is told.
     */
    #[Test]
    public function one_desks_write_moves_every_other_desks_stamp(): void
    {
        $before = [
            'school_nurse' => $this->stampFor('school_nurse'),
            'clinic_teacher' => $this->stampFor('clinic_teacher'),
            'class_adviser' => $this->stampFor('class_adviser'),
            'school_head' => $this->stampFor('school_head'),
            'feeding_coor' => $this->stampFor('feeding_coor'),
        ];

        // Every role reads the same school, so every role reads the same stamp.
        $this->assertCount(1, array_unique($before), 'The roles do not share one reading of the school.');

        // The class adviser enrols a learner.
        $this->learner();

        foreach ($before as $role => $was) {
            $this->assertNotSame($was, $this->stampFor($role), $role.' was not told that the roster moved.');
        }
    }

    /** And it moves for each kind of write, not only for the roster. */
    #[Test]
    public function each_kind_of_submission_moves_the_stamp(): void
    {
        $stamp = $this->stampFor('school_nurse');

        $writes = [
            'a learner enrolled by the adviser' => fn () => $this->learner(),
            'a consultation logged by the clinic' => fn () => Consultation::create([
                'institution_id' => $this->school->id,
                'student_name' => 'Cruz, Juan',
                'grade_section' => 'Grade 10 - Dalton',
                'condition' => 'Headache',
                'status' => 'treated',
                'consulted_at' => now(),
            ]),
            'stock added by the nurse' => fn () => Medicine::create([
                'institution_id' => $this->school->id,
                'name' => 'Paracetamol 500mg tablet',
                'stock_quantity' => 50,
                'minimum_threshold' => 10,
                'unit' => 'tablets',
            ]),
        ];

        foreach ($writes as $what => $write) {
            $write();
            $now = $this->stampFor('school_nurse');
            $this->assertNotSame($stamp, $now, $what.' did not move the stamp.');
            $stamp = $now;
        }
    }

    /** A neighbouring school's work is not this school's business. */
    #[Test]
    public function another_schools_write_does_not_move_this_schools_stamp(): void
    {
        $ours = $this->stampFor('school_nurse');

        $this->learner(['institution_id' => $this->otherSchool->id, 'school_name' => $this->otherSchool->name]);

        $this->assertSame($ours, $this->stampFor('school_nurse'), "Another school's entry reached this school's stamp.");

        // And the other school's own nurse did see it move.
        $this->assertNotSame(
            SchoolPulse::stamp($this->school->id),
            SchoolPulse::stamp($this->otherSchool->id),
            'Two schools share one stamp.'
        );
    }

    /**
     * It is polled on a timer by every open tab, so it must carry nothing
     * about anybody.
     */
    #[Test]
    public function the_pulse_carries_no_personal_information(): void
    {
        $this->learner(['student_name' => 'Magbanua, Esperanza', 'student_id' => '123456789012']);

        $body = $this->withSession($this->sessionFor('school_nurse'))
            ->getJson(route('workspace.pulse'))
            ->assertOk()
            ->assertJsonStructure(['stamp'])
            ->getContent();

        $this->assertStringNotContainsString('Magbanua', $body);
        $this->assertStringNotContainsString('Esperanza', $body);
        $this->assertStringNotContainsString('123456789012', $body);
        // A hash and nothing else.
        $this->assertSame(['stamp'], array_keys(json_decode($body, true)));
    }

    /**
     * The stamp a session is served belongs to that session's school.
     *
     * This is the property the per-school scoping rests on, read from the
     * endpoint rather than from the class: a nurse at one school and a nurse
     * at another must never be handed the same answer to "has anything
     * changed", or one school's quiet afternoon would suppress the other's
     * refresh.
     */
    #[Test]
    public function the_stamp_served_belongs_to_the_sessions_own_school(): void
    {
        $this->learner();

        $ours = $this->stampFor('school_nurse');
        $theirs = $this->stampFor('school_nurse', $this->otherSchool);

        $this->assertNotSame($ours, $theirs, 'Two schools were served one stamp.');

        // And the same school reads the same stamp whichever role asks.
        $this->assertSame($ours, $this->stampFor('clinic_teacher'));
        $this->assertSame($ours, $this->stampFor('school_head'));
    }

    // ── Every screen that reads somebody else's work polls it ───────────

    /** @return array<string, array{0: string, 1: string}> */
    public static function readingScreens(): array
    {
        return [
            'nurse dashboard' => ['school_nurse', '/dashboard/school-nurse'],
            'nurse health records' => ['school_nurse', '/dashboard/student-health-records'],
            'nurse assessment queue' => ['school_nurse', '/nurse'],
            'nurse consent forms' => ['school_nurse', '/dashboard/school-nurse/consent-forms'],
            'consultation log' => ['school_nurse', '/dashboard/consultation-log'],
            'medicine inventory' => ['school_nurse', '/dashboard/medicine-inventory'],
            'clinic staff dashboard' => ['clinic_staff', '/dashboard/clinic-staff'],
            'clinic teacher dashboard' => ['clinic_teacher', '/dashboard/clinic-teacher'],
            'adviser consent forms' => ['class_adviser', '/dashboard/class-adviser/consent-forms'],
            'adviser nutritional status' => ['class_adviser', '/dashboard/class-adviser/feeding-status'],
            'head health overview' => ['school_head', '/dashboard/school-head/health'],
            'head consent compliance' => ['school_head', '/dashboard/school-head/consent'],
            'head inventory' => ['school_head', '/dashboard/school-head/inventory'],
            'nutricor dashboard' => ['nutricor', '/dashboard/nutricor-dashboard'],
        ];
    }

    #[Test]
    #[DataProvider('readingScreens')]
    public function a_reading_screen_polls_the_shared_stamp(string $role, string $uri): void
    {
        $this->assertStringContainsString(
            route('workspace.pulse'),
            $this->pageOf($role, $uri),
            $uri.' never notices what another desk writes.'
        );
    }

    /**
     * A reload is held back while there is something on the page to lose.
     *
     * The guard cannot be exercised without a browser, so this pins that the
     * reload is **conditional** — the failure this prevents is somebody
     * simplifying the partial back to an unconditional `location.reload()`,
     * which would wipe a half-written consultation every twenty seconds.
     */
    #[Test]
    public function the_reload_is_held_back_when_there_is_work_in_progress(): void
    {
        $html = $this->withSession($this->sessionFor('school_nurse'))
            ->get('/dashboard/consultation-log')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('hasWorkInProgress()', $html);
        $this->assertStringContainsString('if (hasWorkInProgress()) {', $html);
        // All three cases the guard covers.
        $this->assertStringContainsString('anOpenDialog()', $html);
        $this->assertStringContainsString('aFocusedField()', $html);
        $this->assertStringContainsString('aDirtyForm()', $html);
        // And the reader is told rather than left with a silently stale page.
        $this->assertStringContainsString('wl-notice', $html);
    }

    /** A page whose whole job is a form has nothing to refresh and everything to lose. */
    #[Test]
    public function a_form_page_does_not_poll(): void
    {
        $record = $this->learner();

        foreach (['/adviser/create'] as $uri) {
            $this->assertStringNotContainsString(
                route('workspace.pulse'),
                $this->pageOf('class_adviser', $uri),
                $uri.' is a form and must not refresh itself.'
            );
        }

        unset($record);
    }

    /** The partial is emitted once even where a page includes it twice. */
    #[Test]
    public function the_partial_is_emitted_once_per_page(): void
    {
        $html = $this->withSession($this->sessionFor('school_nurse'))
            ->get('/dashboard/medicine-inventory')
            ->assertOk()
            ->getContent();

        $this->assertSame(
            1,
            substr_count($html, 'id="wlNotice"'),
            'The live-refresh partial rendered more than once.'
        );
    }
}
