<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The class adviser dashboard once issued sixty queries to render, more than
 * half of them repeats: the same "does this table exist?" asked by four
 * different layers, and the same learners, consent forms and assessments
 * fetched once for the overview panel and again for the roster table. Against
 * a local SQLite file that is invisible. Against the hosted Postgres this app
 * actually runs on, where a round trip costs the better part of a second, it
 * took the page past PHP's execution limit and the adviser got a fatal error
 * instead of a dashboard — right after enrolling a student, which is where it
 * was reported from.
 *
 * The budget below is what keeps that from creeping back. It is a ceiling, not
 * a target: if a change pushes past it, the question to ask is whether the new
 * queries are ones the page genuinely needs, or the same read happening twice.
 */
class AdviserDashboardQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY_BUDGET = 30;

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
            'active_username' => 'adviser.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->institution->id,
            'assigned_grade_level' => 'Grade 7',
            'assigned_section' => 'Sampaguita',
        ];
    }

    private function makeStudents(int $count): void
    {
        foreach (range(1, $count) as $n) {
            StudentHealthRecord::create([
                'institution_id' => $this->institution->id,
                'school_year' => StudentHealthRecord::currentSchoolYear(),
                'student_name' => 'Learner, Number '.$n,
                'student_id' => 'LRN00000'.$n,
                'school_name' => 'Test School',
                'section' => 'Grade 7 / Sampaguita',
                'weight' => 30,
                'bmi_value' => 15.2,
                'nutritional_status' => 'Wasted',
                'student_details' => ['gender' => 'Male', 'grade_level' => 'Grade 7', 'section' => 'Sampaguita'],
            ]);
        }
    }

    /**
     * The data reads a request performed, each as SQL plus its bindings.
     *
     * Schema introspection is excluded: how it is spelled is a driver detail
     * (SQLite answers "what columns?" with a free local pragma, Postgres with a
     * round trip), so counting it here would test the driver rather than the
     * page. The introspection test below covers it separately.
     *
     * @return list<string>
     */
    private function recordQueries(callable $work): array
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if ($this->isIntrospection($query->sql)) {
                return;
            }

            $queries[] = $query->sql.' -- '.json_encode($query->bindings);
        });

        $work();

        return $queries;
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

    #[Test]
    public function the_dashboard_renders_within_its_query_budget(): void
    {
        $this->makeStudents(4);

        $queries = $this->recordQueries(function () {
            $this->withSession($this->adviserSession())
                ->get('/dashboard/class-adviser')
                ->assertOk();
        });

        $this->assertLessThanOrEqual(
            self::QUERY_BUDGET,
            count($queries),
            'The adviser dashboard issued '.count($queries).' queries, over its budget of '.self::QUERY_BUDGET.".\n".
            implode("\n", $queries)
        );
    }

    #[Test]
    public function the_query_count_does_not_grow_with_the_class(): void
    {
        $this->makeStudents(3);
        $small = $this->recordQueries(function () {
            $this->withSession($this->adviserSession())->get('/dashboard/class-adviser')->assertOk();
        });

        $this->makeStudents(12);
        $large = $this->recordQueries(function () {
            $this->withSession($this->adviserSession())->get('/dashboard/class-adviser')->assertOk();
        });

        // A per-learner query would make a full class of forty unservable long
        // before the page itself became slow to read.
        $this->assertSame(
            count($small),
            count($large),
            'The dashboard issues a query per learner rather than a fixed set.'
        );
    }

    #[Test]
    public function no_read_is_made_twice_in_one_request(): void
    {
        $this->makeStudents(4);

        $queries = $this->recordQueries(function () {
            $this->withSession($this->adviserSession())->get('/dashboard/class-adviser')->assertOk();
        });

        $repeated = array_filter(array_count_values($queries), fn (int $times): bool => $times > 1);

        $this->assertSame(
            [],
            $repeated,
            "The same read was issued more than once in a single request:\n".implode("\n", array_keys($repeated))
        );
    }

    #[Test]
    public function the_same_schema_question_is_never_asked_twice(): void
    {
        $this->makeStudents(2);

        $introspection = [];
        DB::listen(function ($query) use (&$introspection) {
            if ($this->isIntrospection($query->sql)) {
                $introspection[] = $query->sql.' -- '.json_encode($query->bindings);
            }
        });

        $this->withSession($this->adviserSession())->get('/dashboard/class-adviser')->assertOk();

        // Four layers ask whether student_health_records exists; the database
        // should hear the question once, whatever the driver spells it as.
        $repeated = array_filter(array_count_values($introspection), fn (int $times): bool => $times > 1);

        $this->assertSame(
            [],
            $repeated,
            "The same schema question was asked more than once:\n".implode("\n", array_keys($repeated))
        );
    }
}
