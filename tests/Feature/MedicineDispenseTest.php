<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\Medicine;
use App\Models\MedicineDispense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dispensing Log — the one clinic module the School Nurse does not share.
 *
 * Every other nurse page admits clinic_staff as well. This one does not:
 * issuing medicine draws stock down, and that is the nurse's call. These
 * tests pin that exclusivity, the stock arithmetic behind it, and the
 * encryption and per-school scoping the rest of the system requires.
 */
class MedicineDispenseTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    private Medicine $medicine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);

        $this->medicine = Medicine::create([
            'institution_id' => $this->school->id,
            'name' => 'Paracetamol',
            'stock_quantity' => 50,
            'minimum_threshold' => 20,
            'unit' => 'tabs',
        ]);
    }

    private function sessionFor(string $role): array
    {
        return [
            'active_role' => $role,
            'active_name' => 'Nurse Cruz',
            'active_username' => 'nurse1',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
        ];
    }

    #[Test]
    public function the_school_nurse_can_open_the_dispensing_log(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.dispensing-log'))
            ->assertOk()
            ->assertSee('Dispensing')
            ->assertSee('Paracetamol');
    }

    /** The point of the module: clinic staff are turned away. */
    #[Test]
    public function clinic_staff_cannot_open_or_post_to_the_dispensing_log(): void
    {
        $this->withSession($this->sessionFor('clinic_staff'))
            ->get(route('dashboard.dispensing-log'))
            ->assertRedirect(route('dashboard.clinic-staff'));

        $this->withSession($this->sessionFor('clinic_staff'))
            ->post(route('dispensing-log.store'), [
                'medicine_id' => $this->medicine->id,
                'student_name' => 'Dela Cruz, Juan',
                'quantity' => 1,
            ])
            ->assertRedirect(route('dashboard.clinic-staff'));

        $this->assertSame(0, MedicineDispense::count());
        $this->assertSame(50, $this->medicine->fresh()->stock_quantity);
    }

    #[Test]
    public function other_roles_are_redirected_to_their_own_dashboards(): void
    {
        $expected = [
            'class_adviser' => 'dashboard.class-adviser',
            'school_head' => 'dashboard.school-head',
            'feeding_coor' => 'dashboard.feedingcor-dashboard',
            'nutricor' => 'dashboard.nutricor-dashboard',
        ];

        foreach ($expected as $role => $route) {
            $this->withSession($this->sessionFor($role))
                ->get(route('dashboard.dispensing-log'))
                ->assertRedirect(route($route));
        }
    }

    #[Test]
    public function recording_a_dispense_draws_the_stock_down(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('dispensing-log.store'), [
                'medicine_id' => $this->medicine->id,
                'student_name' => 'Dela Cruz, Juan',
                'student_lrn' => '123456789012',
                'quantity' => 4,
                'reason' => 'Headache after PE',
            ])
            ->assertRedirect(route('dashboard.dispensing-log'));

        $this->assertSame(46, $this->medicine->fresh()->stock_quantity);

        $dispense = MedicineDispense::first();
        $this->assertNotNull($dispense);
        $this->assertSame(4, $dispense->quantity);
        $this->assertSame('Dela Cruz, Juan', $dispense->student_name);
        $this->assertSame('123456789012', $dispense->student_lrn);
        $this->assertSame('Nurse Cruz', $dispense->dispensed_by_name);
        $this->assertSame($this->school->id, $dispense->institution_id);
    }

    /** Stock may never go negative, and a rejected dispense writes nothing. */
    #[Test]
    public function dispensing_more_than_the_stock_is_rejected_and_changes_nothing(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('dispensing-log.store'), [
                'medicine_id' => $this->medicine->id,
                'student_name' => 'Dela Cruz, Juan',
                'quantity' => 51,
            ])
            ->assertSessionHasErrors('quantity');

        $this->assertSame(50, $this->medicine->fresh()->stock_quantity);
        $this->assertSame(0, MedicineDispense::count());
    }

    #[Test]
    public function the_learner_name_and_reason_are_encrypted_at_rest(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('dispensing-log.store'), [
                'medicine_id' => $this->medicine->id,
                'student_name' => 'Dela Cruz, Juan',
                'quantity' => 1,
                'reason' => 'Headache after PE',
            ]);

        $raw = DB::table('medicine_dispenses')->first();

        $this->assertNotSame('Dela Cruz, Juan', $raw->student_name);
        $this->assertNotSame('Headache after PE', $raw->reason);
        $this->assertStringNotContainsString('Dela Cruz', (string) $raw->student_name);
        $this->assertStringNotContainsString('Headache', (string) $raw->reason);

        // …and still reads back correctly through the model.
        $this->assertSame('Dela Cruz, Juan', MedicineDispense::first()->student_name);
    }

    #[Test]
    public function another_schools_dispensing_never_appears(): void
    {
        $otherSchool = Institution::create(['name' => 'Wireless ES', 'status' => 'active']);
        $otherMedicine = Medicine::create([
            'institution_id' => $otherSchool->id,
            'name' => 'Amoxicillin',
            'stock_quantity' => 30,
            'minimum_threshold' => 10,
            'unit' => 'caps',
        ]);

        MedicineDispense::create([
            'institution_id' => $otherSchool->id,
            'medicine_id' => $otherMedicine->id,
            'student_name' => 'Other School Learner',
            'quantity' => 2,
            'dispensed_at' => now(),
        ]);

        $html = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.dispensing-log'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Other School Learner', $html);
        $this->assertStringNotContainsString('Amoxicillin', $html);
    }

    /** A nurse at another school cannot dispense this school's stock. */
    #[Test]
    public function a_dispense_cannot_reach_across_schools(): void
    {
        $otherSchool = Institution::create(['name' => 'Wireless ES', 'status' => 'active']);

        $this->withSession(array_merge($this->sessionFor('school_nurse'), [
            'active_institution_id' => $otherSchool->id,
        ]))->post(route('dispensing-log.store'), [
            'medicine_id' => $this->medicine->id,
            'student_name' => 'Dela Cruz, Juan',
            'quantity' => 1,
        ])->assertNotFound();

        $this->assertSame(50, $this->medicine->fresh()->stock_quantity);
        $this->assertSame(0, MedicineDispense::count());
    }

    #[Test]
    public function the_dispense_is_written_to_the_audit_trail(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('dispensing-log.store'), [
                'medicine_id' => $this->medicine->id,
                'student_name' => 'Dela Cruz, Juan',
                'quantity' => 1,
            ]);

        // AuditTrail records the class basename, not the FQCN.
        $this->assertDatabaseHas('audit_logs', [
            'subject_type' => 'MedicineDispense',
            'action' => 'created',
        ]);
    }

    #[Test]
    public function the_rail_links_to_the_module_for_the_nurse(): void
    {
        $html = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.school-nurse'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'href="'.route('dashboard.dispensing-log').'"',
            $html,
            'Dispensing Log must be reachable from the nurse rail.'
        );
    }
}
