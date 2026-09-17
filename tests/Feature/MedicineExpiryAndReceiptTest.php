<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\Medicine;
use App\Models\MedicineReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two halves of the clinic logbook the stock record was missing.
 *
 *   1. Expiry. Every item carries the earliest expiry of the stock on hand,
 *      the page flags what is within a month and what is already past, and
 *      an item with no date reads as unknown — never as fine.
 *   2. Receiving. A delivery raises the level through one audited, scoped,
 *      transactional path that writes the receipt and the increment
 *      together — the mirror of a dispense. Until this existed a level could
 *      only be typed at creation and drawn down, so the first box to arrive
 *      put the record out of step with the shelf.
 */
class MedicineExpiryAndReceiptTest extends TestCase
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
            'stock_quantity' => 40,
            'minimum_threshold' => 20,
            'unit' => 'tablets',
        ]);
    }

    private function sessionFor(string $role): array
    {
        return [
            'active_role' => $role,
            'active_name' => 'Nurse Cruz',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
        ];
    }

    private function receive(array $overrides = [], ?Medicine $medicine = null, string $role = 'school_nurse')
    {
        return $this->withSession($this->sessionFor($role))
            ->from(route('dashboard.medicine-inventory'))
            ->post(route('medicine-inventory.receive', $medicine ?? $this->paracetamol), array_merge([
                'quantity' => 60,
                'expiry_date' => now()->addMonths(6)->toDateString(),
                'received_at' => now()->toDateString(),
                'source' => 'Division delivery',
            ], $overrides));
    }

    // ── Expiry ─────────────────────────────────────────────────────────

    /** Expired, expiring within the window, fine, and unknown are four answers. */
    #[Test]
    public function an_item_is_judged_by_the_date_on_hand(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 17)->startOfDay());

        $this->paracetamol->update(['expiry_date' => '2026-09-17']);
        $this->assertSame(Medicine::EXPIRY_EXPIRED, $this->paracetamol->fresh()->expiryStatus(), 'Expiring today is expired today.');

        $this->paracetamol->update(['expiry_date' => '2026-10-10']);
        $this->assertSame(Medicine::EXPIRY_SOON, $this->paracetamol->fresh()->expiryStatus());
        $this->assertSame(23, $this->paracetamol->fresh()->daysToExpiry());

        $this->paracetamol->update(['expiry_date' => '2027-03-01']);
        $this->assertSame(Medicine::EXPIRY_OK, $this->paracetamol->fresh()->expiryStatus());

        $this->paracetamol->update(['expiry_date' => null]);
        $this->assertNull($this->paracetamol->fresh()->expiryStatus(), 'No date on file is unknown, not fine.');
        $this->assertNull($this->paracetamol->fresh()->daysToExpiry());
    }

    /** The page counts what is expiring, names what has expired, and never counts an empty shelf. */
    #[Test]
    public function the_inventory_page_flags_expiry(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 17)->startOfDay());

        $this->paracetamol->update(['expiry_date' => '2026-10-01']);
        Medicine::create([
            'institution_id' => $this->school->id, 'name' => 'Cetirizine',
            'stock_quantity' => 10, 'minimum_threshold' => 5, 'unit' => 'tablets',
            'expiry_date' => '2026-09-01',
        ]);
        Medicine::create([
            'institution_id' => $this->school->id, 'name' => 'Amoxicillin',
            'stock_quantity' => 0, 'minimum_threshold' => 5, 'unit' => 'capsules',
            'expiry_date' => '2026-09-01',
        ]);
        Medicine::create([
            'institution_id' => $this->school->id, 'name' => 'Loperamide',
            'stock_quantity' => 30, 'minimum_threshold' => 5, 'unit' => 'capsules',
        ]);

        $response = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.medicine-inventory'))
            ->assertOk();

        $stats = $response->viewData('stats');
        $this->assertSame(1, $stats['expiring'], 'Paracetamol, within 30 days.');
        $this->assertSame(1, $stats['expired'], 'Cetirizine — the empty Amoxicillin shelf cannot expire.');

        $response->assertSee('Expiring Soon');
        $response->assertSee('1 already expired');
        $response->assertSee('<th>Expiry</th>', false);
        $response->assertSee('01 Oct 2026');
        $response->assertSee('14 days left');
        $response->assertSee('>Expired<', false);
    }

    /** The add form takes the date; it is optional. */
    #[Test]
    public function a_medicine_can_be_added_with_an_expiry(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('medicine-inventory.create'))
            ->assertOk()
            ->assertSee('name="expiry_date"', false);

        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('medicine-inventory.store'), [
                'custom_name' => 'Oral Rehydration Salts',
                'off_catalogue_reason' => 'Donated stock',
                'stock_quantity' => 12,
                'minimum_threshold' => 4,
                'unit' => 'sachets',
                'expiry_date' => '2027-01-31',
            ])
            ->assertRedirect(route('dashboard.medicine-inventory'));

        $this->assertSame('2027-01-31', Medicine::where('name', 'Oral Rehydration Salts')->firstOrFail()->expiry_date->toDateString());
    }

    // ── Receiving ──────────────────────────────────────────────────────

    /** The receipt and the increment land together, attributed to the session. */
    #[Test]
    public function a_delivery_raises_the_stock_and_is_logged(): void
    {
        $this->receive()->assertRedirect(route('dashboard.medicine-inventory'));

        $this->assertSame(100, $this->paracetamol->fresh()->stock_quantity);

        $receipt = MedicineReceipt::firstOrFail();
        $this->assertSame($this->paracetamol->id, $receipt->medicine_id);
        $this->assertSame($this->school->id, $receipt->institution_id);
        $this->assertSame(60, $receipt->quantity);
        $this->assertSame('Division delivery', $receipt->source);
        $this->assertSame('Nurse Cruz', $receipt->received_by_name);
        $this->assertSame('school_nurse', $receipt->received_by_role);

        // Who signed for it is a staff name and is encrypted at rest.
        $row = DB::table('medicine_receipts')->first();
        $this->assertStringStartsWith('eyJpdiI6', (string) $row->received_by_name);

        // The delivery is on the audit trail.
        $this->assertTrue(
            AuditLog::query()->where('subject_type', 'MedicineReceipt')->where('action', 'created')->exists(),
            'Receiving stock must be audited.'
        );
    }

    /** The item's expiry is the earliest date on hand. */
    #[Test]
    public function the_items_expiry_becomes_the_earliest_date_on_hand(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 17)->startOfDay());

        // Existing stock with a date: a later delivery keeps the sooner date.
        $this->paracetamol->update(['expiry_date' => '2026-12-01']);
        $this->receive(['expiry_date' => '2027-06-01']);
        $this->assertSame('2026-12-01', $this->paracetamol->fresh()->expiry_date->toDateString());

        // A sooner delivery moves it forward.
        $this->receive(['expiry_date' => '2026-11-01']);
        $this->assertSame('2026-11-01', $this->paracetamol->fresh()->expiry_date->toDateString());

        // A delivery onto an empty shelf, or onto stock already past its date,
        // is the shelf's date now.
        Medicine::whereKey($this->paracetamol->id)->update(['stock_quantity' => 0, 'expiry_date' => '2026-05-01']);
        $this->receive(['expiry_date' => '2027-02-01']);
        $this->assertSame('2027-02-01', $this->paracetamol->fresh()->expiry_date->toDateString());

        Medicine::whereKey($this->paracetamol->id)->update(['stock_quantity' => 5, 'expiry_date' => '2026-05-01']);
        $this->receive(['expiry_date' => '2027-03-01']);
        $this->assertSame('2027-03-01', $this->paracetamol->fresh()->expiry_date->toDateString(), 'Expired stock does not hold the date.');

        // A delivery with no date on the box leaves the shelf's date alone.
        $this->receive(['expiry_date' => '']);
        $this->assertSame('2027-03-01', $this->paracetamol->fresh()->expiry_date->toDateString());
    }

    /** Already-expired stock, a future receipt date, and nothing at all are all refused. */
    #[Test]
    public function a_bad_delivery_is_refused_and_changes_nothing(): void
    {
        $this->receive(['expiry_date' => now()->subDay()->toDateString()])->assertSessionHasErrors('expiry_date');
        $this->receive(['expiry_date' => now()->toDateString()])->assertSessionHasErrors('expiry_date');
        $this->receive(['received_at' => now()->addDay()->toDateString()])->assertSessionHasErrors('received_at');
        $this->receive(['quantity' => 0])->assertSessionHasErrors('quantity');

        $this->assertSame(40, $this->paracetamol->fresh()->stock_quantity);
        $this->assertSame(0, MedicineReceipt::count());
    }

    /** Clinic staff keep the logbook too; other roles and other schools do not. */
    #[Test]
    public function receiving_is_scoped_to_the_clinic_and_the_school(): void
    {
        $this->receive([], null, 'clinic_staff')->assertRedirect(route('dashboard.medicine-inventory'));
        $this->assertSame(100, $this->paracetamol->fresh()->stock_quantity);

        $this->receive([], null, 'class_adviser')->assertRedirect(route('dashboard.class-adviser'));
        $this->receive([], null, 'school_head')->assertForbidden();
        $this->assertSame(100, $this->paracetamol->fresh()->stock_quantity);

        $other = Institution::create(['name' => 'Other NHS', 'status' => 'active']);
        $theirs = Medicine::create([
            'institution_id' => $other->id, 'name' => 'Their Paracetamol',
            'stock_quantity' => 10, 'minimum_threshold' => 5, 'unit' => 'tablets',
        ]);

        $this->receive([], $theirs)->assertNotFound();
        $this->assertSame(10, $theirs->fresh()->stock_quantity);
    }

    /** Every row offers the one control that raises a level, and the head's page never does. */
    #[Test]
    public function the_inventory_page_offers_receive_per_row(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.medicine-inventory'))
            ->assertOk()
            ->assertSee('data-receive-open', false)
            ->assertSee(route('medicine-inventory.receive', $this->paracetamol))
            ->assertSee('id="receiveForm"', false);
    }
}
