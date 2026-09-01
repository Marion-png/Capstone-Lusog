<?php

namespace Tests\Feature;

use App\Models\Condition;
use App\Models\Institution;
use App\Models\Medicine;
use App\Models\MedicineDispense;
use App\Support\MedicineUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Medicine inventory: automatic deduction, the head's low-stock view, and
 * how fast each medicine is actually being consumed.
 *
 * Three things the school asked for:
 *
 *   1. A medicine handed over during a consultation comes off the stock,
 *      in the same transaction as the consultation itself.
 *   2. The school head sees the low-stock picture, so a reorder can be
 *      raised in time.
 *   3. Monthly usage per medicine is visible — how fast paracetamol is
 *      going, not just how much is left.
 *
 * (3) replaced a generator that invented six months of "seasonal" figures
 * from a hash of the medicine's name and fed them to a recommended-order
 * calculation. A reorder quantity computed from numbers nobody dispensed is
 * worse than none at all, because it looks like evidence.
 */
class MedicineInventoryUsageTest extends TestCase
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

    private function sessionFor(string $role): array
    {
        return [
            'active_role' => $role,
            'active_name' => $role === 'school_nurse' ? 'Nurse Cruz' : 'Staff Member',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
        ];
    }

    private function consultationPayload(array $overrides = []): array
    {
        return array_merge([
            'consulted_at' => now()->toDateString(),
            'student_name' => 'Cruz, Juan',
            'grade_section' => 'Grade 10 - Dalton',
            'condition' => 'Headache',
            'status' => 'treated',
        ], $overrides);
    }

    private function dispense(int $quantity, string $when): MedicineDispense
    {
        return MedicineDispense::create([
            'institution_id' => $this->school->id,
            'medicine_id' => $this->paracetamol->id,
            'student_name' => 'Cruz, Juan',
            'quantity' => $quantity,
            'dispensed_by_name' => 'Nurse Cruz',
            'dispensed_by_role' => 'school_nurse',
            'dispensed_at' => $when,
        ]);
    }

    // ── 1. Dispensing during a consultation ──────────────────────────

    #[Test]
    public function a_medicine_given_during_a_consultation_comes_off_the_stock(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('consultations.store'), $this->consultationPayload([
                'medicine_id' => $this->paracetamol->id,
                'medicine_quantity' => 4,
            ]))
            ->assertRedirect();

        $this->assertSame(96, $this->paracetamol->fresh()->stock_quantity);

        $dispense = MedicineDispense::first();
        $this->assertNotNull($dispense, 'The draw on stock must be on the dispensing log.');
        $this->assertSame(4, $dispense->quantity);
        $this->assertSame($this->paracetamol->id, $dispense->medicine_id);
        $this->assertSame('Nurse Cruz', $dispense->dispensed_by_name);
    }

    /** No medicine chosen, no deduction — the field is optional. */
    #[Test]
    public function a_consultation_without_a_medicine_touches_no_stock(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('consultations.store'), $this->consultationPayload())
            ->assertRedirect();

        $this->assertSame(100, $this->paracetamol->fresh()->stock_quantity);
        $this->assertSame(0, MedicineDispense::count());
    }

    /**
     * Either both land or neither does. A consultation saved without its
     * dispense leaves the stock overstating what the clinic holds.
     */
    #[Test]
    public function dispensing_more_than_the_stock_saves_nothing_at_all(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->from(route('dashboard.consultation-log'))
            ->post(route('consultations.store'), $this->consultationPayload([
                'medicine_id' => $this->paracetamol->id,
                'medicine_quantity' => 500,
            ]))
            ->assertRedirect()
            ->assertSessionHasErrors('medicine_quantity', null, 'consultation');

        $this->assertSame(100, $this->paracetamol->fresh()->stock_quantity);
        $this->assertSame(0, MedicineDispense::count());
        $this->assertDatabaseCount('consultations', 0);
    }

    /**
     * Clinic staff log consultations but are deliberately not admitted to the
     * dispensing path, and this must not become a way around that.
     */
    #[Test]
    public function clinic_staff_cannot_dispense_through_a_consultation(): void
    {
        $this->withSession($this->sessionFor('clinic_staff'))
            ->post(route('consultations.store'), $this->consultationPayload([
                'medicine_id' => $this->paracetamol->id,
                'medicine_quantity' => 4,
            ]))
            ->assertRedirect();

        // The consultation saves; the stock is untouched.
        $this->assertDatabaseCount('consultations', 1);
        $this->assertSame(100, $this->paracetamol->fresh()->stock_quantity);
        $this->assertSame(0, MedicineDispense::count());
    }

    /** The nurse's dialog offers the field; clinic staff's does not. */
    #[Test]
    public function only_the_nurse_is_offered_the_medicine_field(): void
    {
        Condition::create(['name' => 'Headache', 'category' => 'General']);

        $nurse = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.consultation-log'))->assertOk()->getContent();

        $clinic = $this->withSession($this->sessionFor('clinic_staff'))
            ->get(route('dashboard.consultation-log'))->assertOk()->getContent();

        $this->assertStringContainsString('id="cm_medicine_id"', $nurse);
        $this->assertStringContainsString('Deducted from inventory when this consultation is saved.', $nurse);
        $this->assertStringNotContainsString('id="cm_medicine_id"', $clinic);
    }

    // ── 3. Monthly usage per medicine ────────────────────────────────

    #[Test]
    public function usage_is_read_from_the_dispensing_log(): void
    {
        $this->dispense(10, now()->toDateTimeString());
        $this->dispense(5, now()->toDateTimeString());
        $this->dispense(8, now()->subMonth()->startOfMonth()->addDay()->toDateTimeString());

        $summary = MedicineUsage::summaryFor($this->paracetamol->id, $this->school->id);

        $this->assertSame(15, $summary['this_month']);
        $this->assertSame(23, $summary['total']);
        $this->assertSame(2, $summary['months_of_history']);
    }

    /** A month with no dispensing is a zero, not a missing point. */
    #[Test]
    public function every_month_in_the_window_is_present(): void
    {
        $this->dispense(6, now()->toDateTimeString());

        $rows = MedicineUsage::monthlyFor($this->paracetamol->id, $this->school->id);

        $this->assertCount(MedicineUsage::MONTHS, $rows);
        $this->assertSame(6, end($rows)['used']);
        $this->assertSame(0, $rows[0]['used']);
    }

    /**
     * Nothing dispensed is "no idea how long this lasts", not "forever" —
     * and the difference matters to somebody deciding whether to reorder.
     */
    #[Test]
    public function cover_is_undefined_when_nothing_has_been_dispensed(): void
    {
        $this->assertNull(MedicineUsage::monthsOfCover($this->paracetamol, $this->school->id));

        $this->dispense(20, now()->toDateTimeString());

        $this->assertNotNull(MedicineUsage::monthsOfCover($this->paracetamol->fresh(), $this->school->id));
    }

    /** Another school's dispensing never counts toward this one's rate. */
    #[Test]
    public function usage_is_scoped_to_the_school(): void
    {
        $other = Institution::create(['name' => 'Wireless ES', 'status' => 'active']);

        // A distinct name only because `medicines.name` carries a GLOBAL unique
        // index — two schools cannot both stock "Paracetamol", which is a real
        // hole in the multi-school invariant and is noted for a separate fix.
        $theirs = Medicine::create([
            'institution_id' => $other->id,
            'name' => 'Paracetamol (Wireless ES)',
            'stock_quantity' => 50,
            'minimum_threshold' => 10,
            'unit' => 'tablets',
        ]);

        MedicineDispense::create([
            'institution_id' => $other->id,
            'medicine_id' => $theirs->id,
            'student_name' => 'Other, Learner',
            'quantity' => 40,
            'dispensed_at' => now(),
        ]);

        $this->assertSame(0, MedicineUsage::summaryFor($this->paracetamol->id, $this->school->id)['total']);
    }

    /** The nurse's inventory page shows the real figures. */
    #[Test]
    public function the_inventory_page_shows_usage_per_medicine(): void
    {
        $this->dispense(12, now()->toDateTimeString());

        $html = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.medicine-inventory'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Used This Month', $html);
        $this->assertStringContainsString('Monthly Avg', $html);
        $this->assertStringContainsString('Cover', $html);
        $this->assertStringContainsString('going out at about', $html);
    }

    /**
     * With no dispensing on record there is no rate, so the page says so
     * rather than recommending an order computed from an empty log.
     */
    #[Test]
    public function an_empty_log_recommends_nothing(): void
    {
        $html = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.medicine-inventory'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Not enough dispensing history yet.', $html);
        $this->assertStringNotContainsString('tends to spike in January', $html);
    }

    // ── 2. The school head ───────────────────────────────────────────

    #[Test]
    public function the_head_sees_the_low_stock_picture(): void
    {
        Medicine::create([
            'institution_id' => $this->school->id,
            'name' => 'Amoxicillin',
            'stock_quantity' => 2,
            'minimum_threshold' => 20,
            'unit' => 'capsules',
        ]);

        $html = $this->withSession($this->sessionFor('school_head'))
            ->get(route('dashboard.school-head.inventory'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Low Stock', $html);
        $this->assertStringContainsString('Out of Stock', $html);
        $this->assertStringContainsString('Amoxicillin', $html);
    }

    /** And the consumption rate beside it, so a reorder can be timely. */
    #[Test]
    public function the_head_sees_how_fast_each_medicine_is_going(): void
    {
        $this->dispense(15, now()->toDateTimeString());

        $html = $this->withSession($this->sessionFor('school_head'))
            ->get(route('dashboard.school-head.inventory'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Used this month', $html);
        $this->assertStringContainsString('Monthly avg', $html);
        $this->assertStringContainsString('Cover', $html);
    }

    /**
     * The head reads stock, never the dispensing log — that names the learner
     * a medicine went to and why, which is clinical information about a child.
     */
    #[Test]
    public function the_head_still_never_sees_who_a_medicine_went_to(): void
    {
        MedicineDispense::create([
            'institution_id' => $this->school->id,
            'medicine_id' => $this->paracetamol->id,
            'student_name' => 'Zymotic Learner Marker',
            'reason' => 'Placebo reason marker',
            'quantity' => 3,
            'dispensed_at' => now(),
        ]);

        $html = $this->withSession($this->sessionFor('school_head'))
            ->get(route('dashboard.school-head.inventory'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Zymotic Learner Marker', $html);
        $this->assertStringNotContainsString('Placebo reason marker', $html);
    }
}
