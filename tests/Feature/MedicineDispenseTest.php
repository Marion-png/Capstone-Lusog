<?php

namespace Tests\Feature;

use App\Http\Controllers\MedicineDispenseController;
use App\Models\AuditLog;
use App\Models\Condition;
use App\Models\Institution;
use App\Models\Medicine;
use App\Models\MedicineDispense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The dispensing record, after the Dispensing Log page was retired.
 *
 * A dispense is recorded **where it happens** — in the consultation dialog,
 * on the visit the medicine was given at — and nowhere else. The standalone
 * page was a second form for the same write, and two ways to draw the same
 * stock down is two ways for them to disagree about what the school has left.
 *
 * What the page's removal must *not* change is the record itself. The table,
 * the model and every row stay; `App\Support\MedicineUsage` still reads them
 * for the Medicine Inventory forecast. These tests pin the invariants that
 * used to live on the page and now belong to the row:
 *
 *   - the learner's name and the reason are encrypted at rest,
 *   - every dispense lands in the audit trail,
 *   - the record is scoped to one school,
 *   - and the page is genuinely gone.
 *
 * The stock arithmetic and the nurse-only guard moved with the write and are
 * covered by MedicineInventoryUsageTest, which owns the consultation path.
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

    /** Dispense two tablets the way the app now does: on a consultation. */
    private function dispenseOnAConsultation(int $quantity = 2): void
    {
        $condition = Condition::query()->first() ?? Condition::create([
            'name' => 'Headache',
            'category' => 'General',
        ]);

        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('consultations.store'), [
                'consulted_at' => now()->toDateString(),
                'student_name' => 'Cruz, Juan',
                'grade_section' => 'Grade 10 - Dalton',
                'condition_id' => $condition->id,
                'status' => 'treated',
                'treatment_given' => 'Rest and paracetamol.',
                'medicine_id' => $this->medicine->id,
                'medicine_quantity' => $quantity,
            ])
            ->assertRedirect();
    }

    // ── The page is gone ────────────────────────────────────────────────────

    #[Test]
    public function the_dispensing_log_page_no_longer_exists(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->get('/dashboard/dispensing-log')
            ->assertNotFound();

        $this->withSession($this->sessionFor('school_nurse'))
            ->post('/dashboard/dispensing-log', ['medicine_id' => $this->medicine->id, 'quantity' => 1])
            ->assertNotFound();
    }

    /** Neither the route nor the controller behind it survives. */
    #[Test]
    public function nothing_names_the_retired_route_or_controller(): void
    {
        $names = collect(app('router')->getRoutes())
            ->map(fn ($route) => (string) $route->getName())
            ->filter()
            ->all();

        $this->assertNotContains('dashboard.dispensing-log', $names);
        $this->assertNotContains('dispensing-log.store', $names);

        $this->assertFalse(
            class_exists(MedicineDispenseController::class),
            'MedicineDispenseController was removed with its page.'
        );
    }

    #[Test]
    public function the_nurse_rail_offers_no_dispensing_log(): void
    {
        $html = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.school-nurse'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Dispensing Log', $html);
        $this->assertStringNotContainsString('/dashboard/dispensing-log', $html);
    }

    // ── The record it wrote is unchanged ────────────────────────────────────

    #[Test]
    public function a_consultation_still_writes_the_dispensing_record(): void
    {
        $this->dispenseOnAConsultation(2);

        $dispense = MedicineDispense::firstOrFail();

        $this->assertSame($this->medicine->id, $dispense->medicine_id);
        $this->assertSame(2, (int) $dispense->quantity);
        $this->assertSame($this->school->id, $dispense->institution_id);
        $this->assertSame(48, (int) $this->medicine->fresh()->stock_quantity);
    }

    /**
     * Who a medicine went to and why is clinical information about a child.
     * It was encrypted when the page wrote it and must stay encrypted now the
     * consultation does.
     */
    #[Test]
    public function the_learner_name_and_reason_are_encrypted_at_rest(): void
    {
        $this->dispenseOnAConsultation();

        $row = DB::table('medicine_dispenses')->first();

        foreach (['student_name', 'reason', 'dispensed_by_name'] as $column) {
            $this->assertNotEmpty($row->{$column}, "{$column} must be stored.");
            $this->assertStringStartsWith(
                'eyJpdiI6',
                (string) $row->{$column},
                "{$column} must be encrypted at rest."
            );
        }

        // Lookup keys stay plain — the usage forecast groups on them in SQL.
        $this->assertSame($this->medicine->id, (int) $row->medicine_id);
        $this->assertSame($this->school->id, (int) $row->institution_id);
    }

    #[Test]
    public function the_dispense_is_written_to_the_audit_trail(): void
    {
        $this->dispenseOnAConsultation();

        $this->assertTrue(
            AuditLog::query()
                ->where('subject_type', class_basename(MedicineDispense::class))
                ->exists(),
            'Every dispense must leave an audit entry.'
        );
    }

    /** A dispense belongs to one school and is never read by another. */
    #[Test]
    public function the_record_is_scoped_to_one_school(): void
    {
        $this->dispenseOnAConsultation();

        $other = Institution::create(['name' => 'Other NHS', 'status' => 'active']);

        $this->assertSame(1, MedicineDispense::query()->forInstitution($this->school->id)->count());
        $this->assertSame(0, MedicineDispense::query()->forInstitution($other->id)->count());
    }
}
