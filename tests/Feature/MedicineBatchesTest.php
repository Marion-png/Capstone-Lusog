<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Institution;
use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Models\MedicineDispense;
use App\Models\MedicineDispenseBatch;
use App\Models\MedicineReceipt;
use App\Support\MedicineBatches;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which box a dispensed medicine came out of.
 *
 * Stock is held in lots — the stock an item was created with and each
 * delivery after it — every lot with its own expiry date, adding up to the
 * item's stock. A dispense takes the lot that expires first, never one already
 * past its date, and records which lots it drew from; the item's own date is
 * read off the lots, so it moves on when the earliest lot is used up; and
 * expired stock stays counted until a stock writer takes it off the shelf.
 */
class MedicineBatchesTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(9, 0));
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function sessionFor(string $role, ?Institution $school = null): array
    {
        return [
            'active_role' => $role,
            'active_name' => 'Staff '.$role,
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => ($school ?? $this->school)->id,
        ];
    }

    /**
     * An item and its lots, written directly: [expiry, quantity, received].
     *
     * @param  list<array{0: ?string, 1: int, 2?: string}>  $lots
     */
    private function medicine(string $name, array $lots, ?Institution $school = null): Medicine
    {
        $school ??= $this->school;

        $medicine = Medicine::create([
            'institution_id' => $school->id,
            'name' => $name,
            'stock_quantity' => array_sum(array_column($lots, 1)),
            'minimum_threshold' => 5,
            'unit' => 'tablets',
        ]);

        foreach ($lots as $lot) {
            MedicineBatch::create([
                'medicine_id' => $medicine->id,
                'institution_id' => $school->id,
                'expiry_date' => $lot[0],
                'received_at' => $lot[2] ?? '2026-06-01',
                'quantity_received' => $lot[1],
                'quantity_remaining' => $lot[1],
                'source' => 'Division delivery',
            ]);
        }

        return $medicine;
    }

    private function dispense(Medicine $medicine, int $quantity, string $role = 'school_nurse')
    {
        return $this->withSession($this->sessionFor($role))
            ->from(route('dashboard.consultation-log'))
            ->post(route('consultations.store'), [
                'consulted_at' => '2026-10-01 09:00',
                'student_name' => 'Dimaculangan, Ligaya',
                'grade_section' => 'Grade 7 - Maingat',
                'condition' => 'Fever',
                'status' => 'treated',
                'medicine_id' => $medicine->id,
                'medicine_quantity' => $quantity,
            ]);
    }

    private function remaining(Medicine $medicine): array
    {
        return MedicineBatch::where('medicine_id', $medicine->id)
            ->orderBy('id')
            ->get()
            ->map(fn (MedicineBatch $b) => [$b->expiry_date?->toDateString(), $b->quantity_remaining])
            ->all();
    }

    // ── First expiry, first out ────────────────────────────────────────

    #[Test]
    public function a_dispense_takes_the_lot_that_expires_first_and_records_it(): void
    {
        // Received in the other order: the later delivery expires sooner.
        $paracetamol = $this->medicine('Paracetamol 500mg tablet', [
            ['2027-06-30', 20, '2026-05-01'],
            ['2026-11-15', 5, '2026-09-01'],
        ]);

        $this->dispense($paracetamol, 3)
            ->assertRedirect()
            ->assertSessionHas('success', 'Consultation saved. 3 tablets of Paracetamol 500mg tablet (exp 15 Nov 2026) deducted from inventory.');

        $this->assertSame([['2027-06-30', 20], ['2026-11-15', 2]], $this->remaining($paracetamol));
        $this->assertSame(22, $paracetamol->fresh()->stock_quantity);

        $dispense = MedicineDispense::firstOrFail();
        $this->assertSame(Consultation::firstOrFail()->id, $dispense->consultation_id, 'The dispense is filed on its visit.');
        $this->assertSame([['2026-11-15', 3]], $dispense->batches->map(fn ($row) => [$row->expiry_date->toDateString(), $row->quantity])->all());

        // The item is judged by the soonest lot still on the shelf.
        $this->assertSame('2026-11-15', $paracetamol->fresh()->expiry_date->toDateString());
    }

    #[Test]
    public function a_draw_larger_than_the_first_lot_runs_into_the_next_and_the_item_date_moves_on(): void
    {
        $paracetamol = $this->medicine('Paracetamol 500mg tablet', [
            ['2026-11-15', 5],
            ['2027-06-30', 20],
        ]);

        $this->dispense($paracetamol, 8)->assertSessionHasNoErrors('consultation');

        $this->assertSame([['2026-11-15', 0], ['2027-06-30', 17]], $this->remaining($paracetamol));
        $this->assertSame(
            [['2026-11-15', 5], ['2027-06-30', 3]],
            MedicineDispenseBatch::orderBy('id')->get()->map(fn ($row) => [$row->expiry_date->toDateString(), $row->quantity])->all()
        );
        $this->assertSame('2027-06-30', $paracetamol->fresh()->expiry_date->toDateString(), 'The used-up lot no longer decides the date.');
    }

    #[Test]
    public function an_undated_lot_goes_after_every_dated_one(): void
    {
        $paracetamol = $this->medicine('Paracetamol 500mg tablet', [
            [null, 10, '2026-01-01'],
            ['2027-06-30', 10, '2026-09-01'],
        ]);

        $this->dispense($paracetamol, 2)->assertSessionHasNoErrors('consultation');

        $this->assertSame([[null, 10], ['2027-06-30', 8]], $this->remaining($paracetamol));
    }

    // ── Expired stock is never dispensed ───────────────────────────────

    #[Test]
    public function an_expired_lot_is_skipped_and_never_handed_over(): void
    {
        $amoxicillin = $this->medicine('Amoxicillin 250mg capsule', [
            ['2026-09-01', 4],
            ['2027-03-01', 10],
        ]);

        $this->dispense($amoxicillin, 2)->assertSessionHasNoErrors('consultation');
        $this->assertSame([['2026-09-01', 4], ['2027-03-01', 8]], $this->remaining($amoxicillin));

        // More than the usable stock is refused, though the shelf holds it.
        $this->dispense($amoxicillin, 9)
            ->assertSessionHasErrors(['medicine_quantity' => 'Only 8 tablets of Amoxicillin 250mg capsule are within their expiry date. The rest have expired and cannot be dispensed.'], null, 'consultation');

        $this->assertSame(1, MedicineDispense::count(), 'The refused draw recorded nothing.');
        $this->assertSame(1, Consultation::count(), 'Nor the visit it was on.');
        $this->assertSame(12, $amoxicillin->fresh()->stock_quantity);
    }

    #[Test]
    public function a_medicine_whose_stock_has_all_expired_cannot_be_dispensed(): void
    {
        $cetirizine = $this->medicine('Cetirizine 10mg tablet', [['2026-10-01', 6]]);

        $this->dispense($cetirizine, 1)
            ->assertSessionHasErrors(['medicine_quantity' => 'The Cetirizine 10mg tablet in stock has expired and cannot be dispensed.'], null, 'consultation');

        $this->assertSame(6, $cetirizine->fresh()->stock_quantity, 'A lot expiring today is expired today.');
        $this->assertSame(0, MedicineDispense::count());
    }

    // ── Lots in, lots out ──────────────────────────────────────────────

    #[Test]
    public function a_delivery_is_a_lot_of_its_own(): void
    {
        $paracetamol = $this->medicine('Paracetamol 500mg tablet', [['2026-12-01', 10]]);

        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('medicine-inventory.receive', $paracetamol), [
                'quantity' => 30, 'expiry_date' => '2027-08-01', 'received_at' => '2026-10-01', 'source' => 'Division delivery',
            ])
            ->assertRedirect(route('dashboard.medicine-inventory'));

        $receipt = MedicineReceipt::firstOrFail();
        $lot = MedicineBatch::where('medicine_receipt_id', $receipt->id)->firstOrFail();
        $this->assertSame(30, $lot->quantity_remaining);
        $this->assertSame('2027-08-01', $lot->expiry_date->toDateString());
        $this->assertSame(40, $paracetamol->fresh()->stock_quantity);
        $this->assertSame(40, (int) MedicineBatch::where('medicine_id', $paracetamol->id)->sum('quantity_remaining'), 'The lots add up to the stock.');
        $this->assertSame('2026-12-01', $paracetamol->fresh()->expiry_date->toDateString());
    }

    #[Test]
    public function the_stock_an_item_is_created_with_is_its_opening_lot(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('medicine-inventory.store'), [
                'custom_name' => 'Oral Rehydration Salts', 'off_catalogue_reason' => 'Donated stock',
                'stock_quantity' => 12, 'minimum_threshold' => 4, 'unit' => 'sachets', 'expiry_date' => '2027-01-31',
            ])
            ->assertRedirect(route('dashboard.medicine-inventory'));

        $medicine = Medicine::where('name', 'Oral Rehydration Salts')->firstOrFail();
        $lot = MedicineBatch::where('medicine_id', $medicine->id)->firstOrFail();
        $this->assertSame(MedicineBatches::OPENING_SOURCE, $lot->source);
        $this->assertSame(12, $lot->quantity_remaining);
        $this->assertSame('2027-01-31', $lot->expiry_date->toDateString());
    }

    /** Stock no lot accounts for — an item from before lots — becomes one at the item's own date. */
    #[Test]
    public function stock_from_before_lots_is_dispensed_at_the_items_own_date(): void
    {
        $legacy = Medicine::create([
            'institution_id' => $this->school->id, 'name' => 'Paracetamol 500mg tablet',
            'stock_quantity' => 15, 'minimum_threshold' => 5, 'unit' => 'tablets', 'expiry_date' => '2027-02-01',
        ]);

        $this->dispense($legacy, 5)->assertSessionHasNoErrors('consultation');

        $this->assertSame([['2027-02-01', 10]], $this->remaining($legacy));
        $this->assertSame(10, $legacy->fresh()->stock_quantity);
        $this->assertSame('2027-02-01', MedicineDispenseBatch::firstOrFail()->expiry_date->toDateString());
    }

    // ── Taking expired stock off the shelf ─────────────────────────────

    #[Test]
    public function expired_stock_is_removed_and_nothing_usable_is_touched(): void
    {
        $amoxicillin = $this->medicine('Amoxicillin 250mg capsule', [
            ['2026-09-01', 4],
            ['2027-03-01', 10],
        ]);

        $this->withSession($this->sessionFor('clinic_staff'))
            ->post(route('medicine-inventory.dispose-expired', $amoxicillin))
            ->assertRedirect(route('dashboard.medicine-inventory'))
            ->assertSessionHas('success', 'Removed 4 expired tablets of Amoxicillin 250mg capsule from stock.');

        $this->assertSame([['2026-09-01', 0], ['2027-03-01', 10]], $this->remaining($amoxicillin));
        $this->assertSame(10, $amoxicillin->fresh()->stock_quantity);

        $disposed = MedicineBatch::where('medicine_id', $amoxicillin->id)->orderBy('expiry_date')->firstOrFail();
        $this->assertSame(4, $disposed->quantity_disposed);
        $this->assertSame('Staff clinic_staff', $disposed->disposed_by_name);
        $this->assertStringStartsWith('eyJpdiI6', (string) DB::table('medicine_batches')->where('id', $disposed->id)->value('disposed_by_name'), 'Who removed it is encrypted.');
        $this->assertTrue(AuditLog::where('subject_type', 'MedicineBatch')->where('action', 'updated')->exists(), 'The removal is on the audit trail.');

        // Nothing left to remove says so and changes nothing.
        $this->withSession($this->sessionFor('clinic_staff'))
            ->post(route('medicine-inventory.dispose-expired', $amoxicillin))
            ->assertSessionHas('error');
        $this->assertSame(10, $amoxicillin->fresh()->stock_quantity);
    }

    #[Test]
    public function removing_expired_stock_is_a_stock_writers_and_stays_in_the_school(): void
    {
        $amoxicillin = $this->medicine('Amoxicillin 250mg capsule', [['2026-09-01', 4]]);

        $this->withSession($this->sessionFor('clinic_teacher'))
            ->post(route('medicine-inventory.dispose-expired', $amoxicillin))
            ->assertRedirect(route('dashboard.medicine-inventory'))
            ->assertSessionHas('error');
        $this->withSession($this->sessionFor('class_adviser'))
            ->post(route('medicine-inventory.dispose-expired', $amoxicillin))
            ->assertRedirect();
        $this->assertSame(4, $amoxicillin->fresh()->stock_quantity);

        $elsewhere = Institution::create(['name' => 'Other NHS', 'status' => 'active']);
        $this->withSession($this->sessionFor('school_nurse', $elsewhere))
            ->post(route('medicine-inventory.dispose-expired', $amoxicillin))
            ->assertNotFound();
        $this->assertSame(4, $amoxicillin->fresh()->stock_quantity);
    }

    // ── The inventory says which lot goes next ─────────────────────────

    #[Test]
    public function the_inventory_names_the_lot_dispensed_next_and_the_expired_stock(): void
    {
        $this->medicine('Paracetamol 500mg tablet', [['2027-06-30', 20], ['2026-10-20', 5]]);
        $this->medicine('Amoxicillin 250mg capsule', [['2026-09-01', 4], ['2027-03-01', 10]]);

        $response = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.medicine-inventory'))
            ->assertOk();

        $response->assertSee('<th>Dispense Next</th>', false);
        $response->assertSee('20 Oct 2026');
        $response->assertSee('5 of 25 tablets');
        $response->assertSee('4 expired');
        $response->assertSee(route('medicine-inventory.dispose-expired', Medicine::where('name', 'Amoxicillin 250mg capsule')->first()), false);
        $this->assertSame(1, $response->viewData('stats')['expired'], 'An item holding expired stock is counted, even beside good stock.');
        $this->assertSame(1, $response->viewData('stats')['expiring'], 'Paracetamol: its next lot is inside the window.');

        // A teacher covering the clinic reads it, and is offered no removal.
        $this->withSession($this->sessionFor('clinic_teacher'))
            ->get(route('dashboard.medicine-inventory'))
            ->assertOk()
            ->assertSee('<th>Dispense Next</th>', false)
            ->assertDontSee('Remove expired');
    }

    /** The dialog lists the usable stock and the lots a draw would come from. */
    #[Test]
    public function the_consultation_dialog_offers_only_usable_stock(): void
    {
        $paracetamol = $this->medicine('Paracetamol 500mg tablet', [['2026-11-15', 5], ['2027-06-30', 20]]);
        $cetirizine = $this->medicine('Cetirizine 10mg tablet', [['2026-09-01', 6]]);

        $html = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.consultation-log'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<option value="'.$paracetamol->id.'"[^>]*data-usable="25"[^>]*>\s*Paracetamol 500mg tablet \(25 tablets left, exp 15 Nov 2026\)/s', $html);
        $this->assertStringContainsString('data-lots=\'[{"expiry":"exp 15 Nov 2026","remaining":5},{"expiry":"exp 30 Jun 2027","remaining":20}]\'', $html);
        $this->assertMatchesRegularExpression('/<option value="'.$cetirizine->id.'"[^>]*\sdisabled[^>]*>\s*Cetirizine 10mg tablet \(0 tablets left\) &mdash; expired stock only/s', $html);
    }

    // ── Existing stock is divided into lots once ───────────────────────

    #[Test]
    public function the_migration_divides_existing_stock_into_lots(): void
    {
        // An item from before lots: stock typed at creation plus two
        // deliveries, some of it already given out.
        $medicine = Medicine::create([
            'institution_id' => $this->school->id, 'name' => 'Paracetamol 500mg tablet',
            'stock_quantity' => 35, 'minimum_threshold' => 5, 'unit' => 'tablets', 'expiry_date' => '2026-11-01',
        ]);
        foreach ([['2026-12-01', 20], ['2027-05-01', 10]] as [$expiry, $quantity]) {
            MedicineReceipt::create([
                'institution_id' => $this->school->id, 'medicine_id' => $medicine->id,
                'quantity' => $quantity, 'expiry_date' => $expiry, 'received_at' => '2026-08-01',
            ]);
        }
        $empty = Medicine::create([
            'institution_id' => $this->school->id, 'name' => 'Cetirizine 10mg tablet',
            'stock_quantity' => 0, 'minimum_threshold' => 5, 'unit' => 'tablets', 'expiry_date' => '2026-05-01',
        ]);

        (require database_path('migrations/2026_10_26_000001_track_medicine_batches.php'))->up();

        // What is left is what would be dispensed last: both deliveries in
        // full, and the 5 the deliveries do not account for at the date the
        // item carried.
        $lots = MedicineBatch::where('medicine_id', $medicine->id)->orderBy('expiry_date')->get();
        $this->assertSame(
            [['2026-11-01', 5, MedicineBatches::OPENING_SOURCE], ['2026-12-01', 20, null], ['2027-05-01', 10, null]],
            $lots->map(fn ($l) => [$l->expiry_date->toDateString(), $l->quantity_remaining, $l->source])->all()
        );
        $this->assertSame(35, (int) $lots->sum('quantity_remaining'));
        $this->assertSame('2026-11-01', $medicine->fresh()->expiry_date->toDateString());

        // An empty shelf gets no lot, and no date.
        $this->assertSame(0, MedicineBatch::where('medicine_id', $empty->id)->count());
        $this->assertNull($empty->fresh()->expiry_date);

        // Running it again changes nothing.
        (require database_path('migrations/2026_10_26_000001_track_medicine_batches.php'))->up();
        $this->assertSame(3, MedicineBatch::where('medicine_id', $medicine->id)->count());
    }

    #[Test]
    public function the_migration_keeps_the_latest_expiring_deliveries_when_some_stock_was_used(): void
    {
        $medicine = Medicine::create([
            'institution_id' => $this->school->id, 'name' => 'Paracetamol 500mg tablet',
            'stock_quantity' => 12, 'minimum_threshold' => 5, 'unit' => 'tablets', 'expiry_date' => '2026-11-01',
        ]);
        foreach ([['2026-11-01', 20], ['2027-05-01', 10]] as [$expiry, $quantity]) {
            MedicineReceipt::create([
                'institution_id' => $this->school->id, 'medicine_id' => $medicine->id,
                'quantity' => $quantity, 'expiry_date' => $expiry, 'received_at' => '2026-08-01',
            ]);
        }

        (require database_path('migrations/2026_10_26_000001_track_medicine_batches.php'))->up();

        $this->assertSame(
            [['2026-11-01', 2], ['2027-05-01', 10]],
            MedicineBatch::where('medicine_id', $medicine->id)->orderBy('expiry_date')->get()
                ->map(fn ($l) => [$l->expiry_date->toDateString(), $l->quantity_remaining])->all()
        );
    }
}
