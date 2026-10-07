<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\ConsultationPhoto;
use App\Models\Institution;
use App\Models\Medicine;
use App\Models\MedicineDispense;
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
            // The shared stamp, polled by every role on every reading screen,
            // so it is the one whose cost is paid most often of all. Its table
            // list is the longest in the app — which is precisely why it must
            // stay at a single UNION ALL.
            'workspace, every role (thirteen watched tables)' => ['school_nurse', '/dashboard/pulse'],
            'workspace, clinic teacher' => ['clinic_teacher', '/dashboard/pulse'],
            'school head (the same thirteen)' => ['school_head', '/dashboard/school-head/metrics/pulse'],
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

    /** @return array<string, array{0: string, 1: string}> */
    public static function clinicListPages(): array
    {
        return [
            'class adviser dashboard (a photo count per visit)' => ['class_adviser', '/dashboard/class-adviser'],
            'medicine inventory (a usage read per medicine)' => ['school_nurse', '/dashboard/medicine-inventory'],
            'consultation log' => ['school_nurse', '/dashboard/consultation-log'],
        ];
    }

    /**
     * Each of these once issued a query per row it listed, so a clinic that
     * kept using the system made its own pages slower every week.
     */
    #[Test]
    #[DataProvider('clinicListPages')]
    public function a_clinic_list_does_not_query_per_row(string $role, string $url): void
    {
        $learner = $this->classLearner();

        $this->addClinicRows($learner, 2);
        $small = count($this->readsFor($role, $url));

        $this->addClinicRows($learner, 12);
        $large = count($this->readsFor($role, $url));

        $this->assertSame($small, $large, "{$url} issues a query per visit or medicine rather than a fixed set.");
    }

    /** Batching the counts must not move them between visits. */
    #[Test]
    public function the_adviser_sees_each_visits_own_shared_photo_count(): void
    {
        $learner = $this->classLearner();
        [$withShared, $withNone, $withPrivate] = [
            $this->visitFor($learner),
            $this->visitFor($learner),
            $this->visitFor($learner),
        ];
        $this->photoOn($withShared, true);
        $this->photoOn($withShared, true);
        $this->photoOn($withShared, false);
        $this->photoOn($withPrivate, false);

        $visits = collect($this->withSession($this->sessionFor('class_adviser'))
            ->get('/dashboard/class-adviser')->assertOk()
            ->viewData('rosterMeta')[$learner->student_id]['consultations'])
            ->pluck('shared_photo_count', 'id');

        $this->assertSame(2, $visits[$withShared->id]);
        $this->assertSame(0, $visits[$withNone->id]);
        $this->assertSame(0, $visits[$withPrivate->id], 'A photo the nurse did not share is never counted for the adviser.');
    }

    /** The log's figures are read in two queries now; each must still count its own visits. */
    #[Test]
    public function the_consultation_log_figures_count_the_right_visits(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 7)->setTime(10, 0)); // a Wednesday

        foreach ([
            ['2026-10-07 08:15:00', 'referred'],   // today
            ['2026-10-07 09:40:00', 'treated'],    // today
            ['2026-10-05 13:00:00', 'treated'],    // Monday, this week
            ['2026-10-04 23:30:00', 'referred'],   // Sunday, last week, this month
            ['2026-09-30 10:00:00', 'treated'],    // last month
            ['2025-10-07 10:00:00', 'treated'],    // this month a year ago
        ] as [$at, $status]) {
            Consultation::create([
                'institution_id' => $this->institution->id,
                'consulted_at' => $at,
                'student_name' => 'Learner, Number 0',
                'grade_section' => 'Grade 7 - Sampaguita',
                'condition' => 'Headache',
                'treatment_given' => 'Rest',
                'status' => $status,
            ]);
        }

        $response = $this->withSession($this->sessionFor('school_nurse'))
            ->get('/dashboard/consultation-log')->assertOk();

        $this->assertSame(
            ['total' => 6, 'month' => 4, 'week' => 3, 'today' => 2, 'referrals' => 2],
            $response->viewData('stats'),
        );
        $this->assertSame(
            ['Mon' => 1, 'Tue' => 0, 'Wed' => 2, 'Thu' => 0, 'Fri' => 0, 'Sat' => 0, 'Sun' => 0],
            collect($response->viewData('dailyTrend'))->pluck('count', 'label')->all(),
        );
        $this->assertSame(6, $response->viewData('consultations')->total());
    }

    /** A learner in the adviser's own class, named so clinic visits match them. */
    private function classLearner(): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Dela Cruz, Juan',
            'student_id' => '123456789012',
            'school_name' => 'Budget School',
            'section' => 'Grade 7 / Sampaguita',
            'weight' => 30,
            'bmi_value' => 15,
            'nutritional_status' => 'Normal',
            'baseline_nutritional_status' => 'Normal',
            'student_details' => [
                'lrn' => '123456789012', 'last_name' => 'Dela Cruz', 'first_name' => 'Juan',
                'gender' => 'Male', 'grade_level' => 'Grade 7', 'section' => 'Sampaguita',
            ],
        ]);
    }

    private function visitFor(StudentHealthRecord $learner): Consultation
    {
        return Consultation::create([
            'institution_id' => $this->institution->id,
            'consulted_at' => now(),
            'student_name' => $learner->student_name,
            'grade_section' => 'Grade 7 - Sampaguita',
            'condition' => 'Headache',
            'treatment_given' => 'Rest',
            'status' => 'treated',
        ]);
    }

    private function photoOn(Consultation $visit, bool $shared): void
    {
        ConsultationPhoto::create([
            'consultation_id' => $visit->id,
            'institution_id' => $visit->institution_id,
            'file_path' => 'consultation-photos/'.$visit->id.'-'.uniqid().'.jpg',
            'file_original_name' => 'photo.jpg',
            'file_size' => 10,
            'shared_with_adviser' => $shared,
        ]);
    }

    /** Visits (each with a shared photo), medicines and dispenses — the rows the clinic pages list. */
    private function addClinicRows(StudentHealthRecord $learner, int $count): void
    {
        foreach (range(1, $count) as $i) {
            $visit = $this->visitFor($learner);
            $this->photoOn($visit, true);

            $medicine = Medicine::create([
                'institution_id' => $this->institution->id,
                'name' => 'Medicine '.uniqid(),
                'stock_quantity' => 20,
                'minimum_threshold' => 5,
                'unit' => 'tablet',
            ]);

            MedicineDispense::create([
                'institution_id' => $this->institution->id,
                'medicine_id' => $medicine->id,
                'student_lrn' => $learner->student_id,
                'student_name' => $learner->student_name,
                'reason' => 'Headache',
                'quantity' => 1,
                'dispensed_by_name' => 'Nurse',
                'dispensed_by_role' => 'school_nurse',
                'dispensed_at' => now()->subDays($i % 40),
            ]);
        }
    }
}
