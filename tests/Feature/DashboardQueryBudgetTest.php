<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The budget AdviserDashboardQueryBudgetTest keeps over the class adviser,
 * applied to the two roles that read the most and to the endpoints every role
 * polls on a timer.
 *
 * What makes this application feel slow is not how much work a page does but
 * how many separate times it asks the database to do some: it runs against a
 * hosted Postgres reached through a tunnel, where one round trip costs the
 * better part of a second. Against the local SQLite these tests use, that cost
 * is invisible unless something counts it.
 *
 * Two things are pinned here, both of which were broken:
 *
 *   - A change-detection pulse costs one query. Every dashboard polls one every
 *     twenty seconds for an answer that is almost always "nothing changed". The
 *     School Head's ran a COUNT per watched table — ten round trips, repeated
 *     for every open tab — and a click made while one was in flight waited
 *     behind it. App\Support\ChangeStamp gathers them in a single UNION ALL.
 *   - A page reads the roster once. Every column worth filtering a roster on is
 *     encrypted at rest, so a second fetch of the same roll is a second
 *     decryption of the whole school as well as a second round trip.
 *
 * Schema introspection is excluded from the counts for the reason the adviser
 * test gives: how it is spelled is a driver detail, so counting it would test
 * the driver rather than the page.
 */
class DashboardQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::create(['name' => 'Budget School', 'status' => 'active']);

        $statuses = ['Severely Wasted', 'Wasted', 'Normal', 'Overweight', 'Underweight'];

        foreach (range(0, 11) as $i) {
            StudentHealthRecord::create([
                'institution_id' => $this->institution->id,
                'school_year' => StudentHealthRecord::currentSchoolYear(),
                'student_name' => 'Learner, Number '.$i,
                'student_id' => 'LRN'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'school_name' => 'Budget School',
                'section' => 'Grade '.(7 + ($i % 4)).' / Sampaguita',
                'weight' => 30,
                'bmi_value' => 13 + ($i % 10),
                'nutritional_status' => $statuses[$i % 5],
                'baseline_nutritional_status' => $statuses[$i % 5],
                'student_details' => ['gender' => $i % 2 ? 'Male' : 'Female', 'grade_level' => 'Grade 7', 'section' => 'Sampaguita'],
                'feeding_enrolled_at' => $i % 5 < 2 ? now() : null,
            ]);
        }
    }

    private function sessionFor(string $role): array
    {
        return [
            'active_role' => $role,
            'active_name' => 'Budget Staff',
            'active_username' => 'budget.staff',
            'active_school_name' => 'Budget School',
            'active_institution_id' => $this->institution->id,
            'assigned_grade_level' => 'Grade 7',
            'assigned_section' => 'Sampaguita',
        ];
    }

    private function isIntrospection(string $sql): bool
    {
        foreach (['sqlite_master', 'pragma', 'information_schema', 'pg_class', 'pg_namespace'] as $marker) {
            if (str_contains(strtolower($sql), $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The data reads one request performed, each as SQL plus its bindings.
     *
     * @return list<string>
     */
    private function readsFor(string $role, string $url): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (! $this->isIntrospection($query->sql)) {
                $queries[] = $query->sql.' -- '.json_encode($query->bindings);
            }
        });

        $this->withSession($this->sessionFor($role))->get($url)->assertOk();

        return $queries;
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function pulseEndpoints(): array
    {
        return [
            'school head (ten watched tables)' => ['school_head', '/dashboard/school-head/metrics/pulse'],
            'feeding coordinator (two)' => ['feeding_coor', '/dashboard/feedingcor-dashboard/metrics/pulse'],
            'class adviser (four)' => ['class_adviser', '/dashboard/class-adviser/activity/pulse'],
        ];
    }

    #[Test]
    #[DataProvider('pulseEndpoints')]
    public function a_pulse_costs_one_query(string $role, string $url): void
    {
        $queries = $this->readsFor($role, $url);

        $this->assertCount(
            1,
            $queries,
            'A pulse is polled every twenty seconds by every open tab, so it reads every table it watches '
            ."in one round trip:\n".implode("\n", $queries)
        );
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function rosterPages(): array
    {
        return [
            'coordinator dashboard' => ['feeding_coor', '/dashboard/feedingcor-dashboard'],
            'coordinator beneficiaries' => ['feeding_coor', '/dashboard/feedingcor-health-records'],
            'coordinator attendance' => ['feeding_coor', '/dashboard/feedingcor-attendance'],
            'coordinator at-risk' => ['feeding_coor', '/dashboard/feedingcor-at-risk'],
            'school head dashboard' => ['school_head', '/dashboard/school-head'],
            'school head masterlist' => ['school_head', '/dashboard/school-head/masterlist'],
        ];
    }

    #[Test]
    #[DataProvider('rosterPages')]
    public function a_page_makes_no_read_twice(string $role, string $url): void
    {
        $queries = $this->readsFor($role, $url);

        $repeated = array_filter(array_count_values($queries), fn (int $times): bool => $times > 1);

        $this->assertSame(
            [],
            $repeated,
            "The same read was issued more than once in a single request:\n".implode("\n", array_keys($repeated))
        );
    }

    /**
     * A per-learner query would make a full school unservable long before the
     * page itself became slow to read.
     */
    #[Test]
    public function the_coordinator_dashboard_does_not_query_per_learner(): void
    {
        $small = count($this->readsFor('feeding_coor', '/dashboard/feedingcor-dashboard'));

        foreach (range(100, 130) as $i) {
            StudentHealthRecord::create([
                'institution_id' => $this->institution->id,
                'school_year' => StudentHealthRecord::currentSchoolYear(),
                'student_name' => 'Learner, Number '.$i,
                'student_id' => 'LRN'.$i,
                'school_name' => 'Budget School',
                'section' => 'Grade 7 / Sampaguita',
                'weight' => 30,
                'bmi_value' => 14,
                'nutritional_status' => 'Wasted',
                'baseline_nutritional_status' => 'Wasted',
                'student_details' => ['gender' => 'Male'],
                'feeding_enrolled_at' => now(),
            ]);
        }

        $large = count($this->readsFor('feeding_coor', '/dashboard/feedingcor-dashboard'));

        $this->assertSame($small, $large, 'The coordinator dashboard issues a query per learner rather than a fixed set.');
    }
}
