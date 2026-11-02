<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Institution;
use App\Models\Medicine;
use App\Models\MedicineDispense;
use App\Support\SchemaCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who recorded a clinic visit and handed the medicine over is printed beside
 * it, so the clinic can see whose entry a line in its log is.
 */
class ConsultationRecordedByTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    private Medicine $paracetamol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
        $this->paracetamol = Medicine::create([
            'institution_id' => $this->school->id,
            'name' => 'Paracetamol',
            'stock_quantity' => 100,
            'minimum_threshold' => 20,
            'unit' => 'tablets',
        ]);
    }

    private function clinicStaff(): array
    {
        return [
            'active_role' => 'clinic_staff',
            'active_name' => 'Staff Dizon',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
        ];
    }

    private function recordVisit(): Consultation
    {
        $this->withSession($this->clinicStaff())
            ->post(route('consultations.store'), [
                'consulted_at' => now()->toDateString(),
                'student_name' => 'Cruz, Juan',
                'grade_section' => 'Grade 10 - Dalton',
                'condition' => 'Headache',
                'status' => 'treated',
                'allergy_acknowledged' => '1',
                'medicine_id' => $this->paracetamol->id,
                'medicine_quantity' => 2,
            ])
            ->assertRedirect();

        return Consultation::sole();
    }

    #[Test]
    public function the_visit_keeps_who_recorded_it_encrypted(): void
    {
        $visit = $this->recordVisit();

        $this->assertSame('Staff Dizon', $visit->recorded_by_name);
        $this->assertSame('clinic_staff', $visit->recorded_by_role);
        $this->assertStringNotContainsString('Staff Dizon', (string) DB::table('consultations')->value('recorded_by_name'));
    }

    #[Test]
    public function the_log_and_the_dashboard_print_who_recorded_the_visit(): void
    {
        $this->recordVisit();

        $this->withSession($this->clinicStaff())
            ->get(route('dashboard.consultation-log'))
            ->assertOk()
            ->assertSee('<th>Recorded By</th>', false)
            ->assertSee('<span class="clog-by">Staff Dizon</span>', false)
            ->assertSee('<span class="clog-by-role">Clinic Staff</span>', false);

        $this->withSession($this->clinicStaff())
            ->get(route('dashboard.clinic-staff'))
            ->assertOk()
            ->assertSee('<th>Recorded By</th>', false)
            ->assertSee('<td>Staff Dizon</td>', false);
    }

    /** A visit from before the name was kept falls back to whoever dispensed on it. */
    #[Test]
    public function an_older_visit_is_attributed_to_whoever_dispensed_on_it(): void
    {
        $visit = Consultation::create([
            'institution_id' => $this->school->id,
            'consulted_at' => now(),
            'student_name' => 'Reyes, Ana',
            'grade_section' => 'Grade 9 - Rizal',
            'condition' => 'Fever',
            'status' => 'treated',
        ]);
        MedicineDispense::create([
            'institution_id' => $this->school->id,
            'medicine_id' => $this->paracetamol->id,
            'consultation_id' => $visit->id,
            'student_name' => 'Reyes, Ana',
            'quantity' => 1,
            'dispensed_by_name' => 'Nurse Cruz',
            'dispensed_by_role' => 'school_nurse',
            'dispensed_at' => now(),
        ]);

        $this->withSession($this->clinicStaff())
            ->get(route('dashboard.consultation-log'))
            ->assertOk()
            ->assertSee('<span class="clog-by">Nurse Cruz</span>', false)
            ->assertSee('<span class="clog-by-role">School Nurse</span>', false);
    }

    /** Visits already on file are named from the audit trail's entry for them. */
    #[Test]
    public function the_migration_names_existing_visits_from_the_audit_trail(): void
    {
        $visit = $this->recordVisit();

        Schema::table('consultations', fn ($table) => $table->dropColumn(['recorded_by_name', 'recorded_by_role']));
        SchemaCache::flush();

        (require database_path('migrations/2026_11_03_000003_add_recorded_by_to_consultations.php'))->up();
        SchemaCache::flush();

        $visit = Consultation::find($visit->id);
        $this->assertSame('Staff Dizon', $visit->recorded_by_name);
        $this->assertSame('clinic_staff', $visit->recorded_by_role);
    }
}
