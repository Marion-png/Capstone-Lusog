<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\InstitutionProvisioner;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Guards the Per-Institution Database Isolation invariant.
 *
 * Unlike the rest of the suite, this test turns OFF shared-database mode and
 * provisions genuinely separate PostgreSQL databases, because the whole point of
 * the invariant is that two schools' rows are not merely filtered apart — they
 * live in different databases and cannot be joined at all.
 */
class InstitutionDatabaseIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $schoolA;

    private Institution $schoolB;

    protected function setUp(): void
    {
        parent::setUp();

        // Real per-school databases for this test class only.
        Config::set('tenancy.shared_database', false);
        Config::set('tenancy.database_prefix', 'capstone_lusog_test_inst_');

        Tenancy::forget();

        $this->schoolA = Institution::create(['name' => 'School A', 'status' => 'active']);
        $this->schoolB = Institution::create(['name' => 'School B', 'status' => 'active']);

        InstitutionProvisioner::provision($this->schoolA);
        InstitutionProvisioner::provision($this->schoolB);
    }

    protected function tearDown(): void
    {
        Tenancy::forget();

        foreach ([$this->schoolA ?? null, $this->schoolB ?? null] as $institution) {
            if ($institution) {
                InstitutionProvisioner::drop($institution);
            }
        }

        parent::tearDown();
    }

    private function makeRecord(Institution $institution, string $lrn, string $name): StudentHealthRecord
    {
        return Tenancy::runFor($institution->id, fn () => StudentHealthRecord::create([
            'institution_id' => $institution->id,
            'student_id' => $lrn,
            'student_name' => $name,
            'section' => 'Grade 7 / Rosal',
            'school_year' => '2026-2027',
            'weight' => '30',
            'bmi_value' => '16',
            'nutritional_status' => 'Normal',
        ]));
    }

    #[Test]
    public function each_institution_gets_its_own_database(): void
    {
        $this->assertSame('capstone_lusog_test_inst_'.$this->schoolA->id, Tenancy::databaseFor($this->schoolA->id));
        $this->assertNotSame(
            Tenancy::databaseFor($this->schoolA->id),
            Tenancy::databaseFor($this->schoolB->id),
            'Two schools must never resolve to the same database.',
        );

        foreach ([$this->schoolA, $this->schoolB] as $institution) {
            $this->assertNotNull(
                DB::selectOne('select 1 from pg_database where datname = ?', [Tenancy::databaseFor($institution->id)]),
                "{$institution->name} should have a database on the server.",
            );
        }
    }

    #[Test]
    public function provisioning_records_the_database_name_on_the_institution(): void
    {
        $this->schoolA->refresh();

        $this->assertTrue($this->schoolA->isProvisioned());
        $this->assertSame('capstone_lusog_test_inst_'.$this->schoolA->id, $this->schoolA->database_name);
        $this->assertNotNull($this->schoolA->provisioned_at);
    }

    #[Test]
    public function a_learner_written_for_one_school_is_invisible_to_the_other(): void
    {
        $this->makeRecord($this->schoolA, 'LRN-A-001', 'Ana Santos');

        $seenByA = Tenancy::runFor($this->schoolA->id, fn () => StudentHealthRecord::count());
        $seenByB = Tenancy::runFor($this->schoolB->id, fn () => StudentHealthRecord::count());

        $this->assertSame(1, $seenByA, 'School A should see the learner it created.');
        $this->assertSame(0, $seenByB, "School B must not see School A's learner at all.");
    }

    #[Test]
    public function the_same_lrn_can_exist_independently_in_two_schools(): void
    {
        $this->makeRecord($this->schoolA, 'LRN001', 'Ana Santos');
        $this->makeRecord($this->schoolB, 'LRN001', 'Ben Cruz');

        $nameAtA = Tenancy::runFor($this->schoolA->id, fn () => (string) StudentHealthRecord::first()->student_name);
        $nameAtB = Tenancy::runFor($this->schoolB->id, fn () => (string) StudentHealthRecord::first()->student_name);

        $this->assertSame('Ana Santos', $nameAtA);
        $this->assertSame('Ben Cruz', $nameAtB);
    }

    #[Test]
    public function school_data_does_not_land_in_the_central_database(): void
    {
        $this->makeRecord($this->schoolA, 'LRN-A-002', 'Ana Santos');

        // The central connection is where accounts and institutions live; a
        // learner row appearing here would mean the tenant binding leaked.
        $centralRows = DB::connection(Config::get('database.default'))
            ->table('student_health_records')
            ->count();

        $this->assertSame(0, $centralRows, 'Learner rows must not be written to the central database.');
    }

    #[Test]
    public function a_tenant_query_without_a_bound_institution_fails_loudly(): void
    {
        Tenancy::forget();

        $this->expectException(RuntimeException::class);

        Tenancy::bindOrFail(null);
    }

    #[Test]
    public function run_for_restores_the_previously_bound_institution(): void
    {
        Tenancy::bind($this->schoolA->id);

        Tenancy::runFor($this->schoolB->id, function (): void {
            // no-op; we only care about what is bound afterwards
        });

        $this->assertSame(
            $this->schoolA->id,
            Tenancy::current(),
            'runFor must put the previous school back so a caller mid-request is unaffected.',
        );
    }

    #[Test]
    public function provisioning_is_idempotent(): void
    {
        $createdAgain = InstitutionProvisioner::provision($this->schoolA);

        $this->assertFalse($createdAgain, 'Re-provisioning an existing school must not recreate its database.');
    }

    /**
     * The separation requirement covers every module, not just health records.
     * Each entry writes one row into School A and expects School B to see none.
     */
    public static function schoolOwnedModules(): array
    {
        return [
            'health records' => ['student_health_records', [
                'student_id' => 'LRN-MOD-1',
                'student_name' => 'Ana Santos',
                'section' => 'Grade 7 / Rosal',
                'school_year' => '2026-2027',
                'weight' => '30',
                'bmi_value' => '16',
                'nutritional_status' => 'Normal',
            ]],
            'medicine inventory' => ['medicines', [
                'name' => 'Paracetamol 500mg',
            ]],
            'deworming' => ['deworming_requests', [
                'id' => 'dw-mod-1',
                'campaign' => 'January 2027',
                'total_students' => 10,
                'consenting_students' => 8,
                'tablets_requested' => 8,
            ]],
            'clinic notes' => ['clinic_notes', [
                'student_lrn' => 'LRN-MOD-1',
                'school_year' => '2026-2027',
                'note' => 'Observed and advised rest.',
                'author_name' => 'Nurse Ana',
            ]],
            'announcements' => ['announcements', [
                'title' => 'Deworming week',
                'body' => 'Deworming runs all week.',
                'posted_by_name' => 'Nurse Ana',
                'posted_by_role' => 'school_nurse',
            ]],
        ];
    }

    #[Test]
    #[DataProvider('schoolOwnedModules')]
    public function every_school_owned_module_is_isolated(string $table, array $row): void
    {
        Tenancy::runFor(
            $this->schoolA->id,
            fn () => Tenancy::table($table)->insert($row + [
                'institution_id' => $this->schoolA->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
        );

        $atA = Tenancy::runFor($this->schoolA->id, fn () => Tenancy::table($table)->count());
        $atB = Tenancy::runFor($this->schoolB->id, fn () => Tenancy::table($table)->count());

        $this->assertSame(1, $atA, "School A should hold its own {$table} row.");
        $this->assertSame(0, $atB, "School B must not see School A's {$table} data.");
    }

    #[Test]
    public function reference_conditions_are_seeded_into_each_school_database(): void
    {
        foreach ([$this->schoolA, $this->schoolB] as $institution) {
            $count = Tenancy::runFor($institution->id, fn () => DB::connection('tenant')->table('conditions')->count());

            $this->assertGreaterThan(0, $count, "{$institution->name} needs its own copy of the condition catalog.");
        }
    }
}
