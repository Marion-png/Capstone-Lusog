<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\Medicine;
use App\Models\MedicineDispense;
use App\Support\MedicineCatalogue;
use App\Support\RxNormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Medicine Inventory, as testers found it on 8 November 2026:
 *
 * - "No medicine has been dispensed…" while Paracetamol had been (#11);
 * - only the most depleted item's forecast could be read (#12);
 * - a Low Stock item with no history recommended "—" (#13);
 * - an item already on the shelf could be added again (#15);
 * - gauze and gloves were checked against RxNorm (#16);
 * - "Antifungal cream" offered no real generic to look up (#17).
 */
class MedicineInventoryTesterFixesTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 11, 9)->setTime(10, 0));
        $this->school = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    private function nurse(): array
    {
        return [
            'active_role' => 'school_nurse',
            'active_name' => 'Nurse Cruz',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->school->id,
        ];
    }

    private function medicine(string $name, int $stock, int $minimum, string $unit = 'pcs'): Medicine
    {
        return Medicine::create([
            'institution_id' => $this->school->id,
            'name' => $name,
            'stock_quantity' => $stock,
            'minimum_threshold' => $minimum,
            'unit' => $unit,
        ]);
    }

    private function dispense(Medicine $medicine, int $quantity, string $when): void
    {
        MedicineDispense::create([
            'institution_id' => $this->school->id,
            'medicine_id' => $medicine->id,
            'student_name' => 'Cruz, Juan',
            'quantity' => $quantity,
            'dispensed_by_name' => 'Nurse Cruz',
            'dispensed_by_role' => 'school_nurse',
            'dispensed_at' => $when,
        ]);
    }

    #[Test]
    public function any_item_s_forecast_can_be_read_and_the_message_names_it(): void
    {
        $antacid = $this->medicine('Antacid tablet', 5, 20, 'tablets');
        $paracetamol = $this->medicine('Paracetamol 500mg tablet', 200, 20, 'tablets');
        $this->dispense($paracetamol, 30, '2026-09-10 09:00:00');
        $this->dispense($paracetamol, 40, '2026-10-10 09:00:00');
        $this->dispense($paracetamol, 20, '2026-11-03 09:00:00');

        // Unasked, the panel reads the item nearest to running out.
        $default = $this->withSession($this->nurse())->get(route('dashboard.medicine-inventory'))->assertOk();
        $this->assertSame($antacid->id, $default->viewData('prediction')['medicine_id']);
        $html = $default->getContent();
        $this->assertStringContainsString('Antacid tablet has not been dispensed in the last 6 months', $html);
        $this->assertStringNotContainsString('No medicine has been dispensed', $html);
        $this->assertStringContainsString('name="forecast"', $html);

        // Asked for, it reads that item, history and all.
        $chosen = $this->withSession($this->nurse())
            ->get(route('dashboard.medicine-inventory', ['forecast' => $paracetamol->id]))
            ->assertOk()
            ->viewData('prediction');
        $this->assertSame($paracetamol->id, $chosen['medicine_id']);
        $this->assertTrue($chosen['has_history']);
        // Last three months 30, 40, 20 → 30 × 1.2 = 36, above the line of 20.
        $this->assertSame(36, $chosen['recommended_doses']);
        $this->assertSame(0, $chosen['recommended_order']);

        // Another school's item, or nonsense, falls back to the default.
        $theirs = Medicine::create(['institution_id' => Institution::create(['name' => 'Other', 'status' => 'active'])->id, 'name' => 'Theirs', 'stock_quantity' => 1, 'minimum_threshold' => 5, 'unit' => 'pcs']);
        foreach ([$theirs->id, 'abc'] as $forecast) {
            $this->assertSame($antacid->id, $this->withSession($this->nurse())
                ->get(route('dashboard.medicine-inventory', ['forecast' => $forecast]))
                ->viewData('prediction')['medicine_id']);
        }
    }

    #[Test]
    public function a_low_item_with_no_history_still_recommends_reaching_its_threshold(): void
    {
        $this->medicine('Antacid tablet', 5, 20, 'tablets');

        $response = $this->withSession($this->nurse())->get(route('dashboard.medicine-inventory'))->assertOk();
        $prediction = $response->viewData('prediction');

        $this->assertFalse($prediction['has_history']);
        $this->assertSame(15, $prediction['recommended_order']);
        $this->assertStringContainsString('Reorder at least 15 tablets to reach the minimum threshold.', $response->getContent());
    }

    #[Test]
    public function an_item_already_on_the_shelf_cannot_be_added_again(): void
    {
        $existing = $this->medicine('Paracetamol 500mg tablet', 40, 20, 'tablets');

        // The catalogue offers it as already stocked, and not to be picked.
        $html = $this->withSession($this->nurse())->get(route('dashboard.medicine-inventory'))->getContent();
        $this->assertMatchesRegularExpression('/<option value="Paracetamol 500mg tablet"[^>]*disabled[^>]*>Paracetamol 500mg tablet \(in inventory\)/', $html);

        // Retyped as "Other" with different case and spacing, it is still caught.
        $this->withSession($this->nurse())
            ->from(route('dashboard.medicine-inventory'))
            ->post(route('medicine-inventory.store'), [
                'catalogue_name' => MedicineCatalogue::OTHER,
                'custom_name' => 'paracetamol 500 mg  TABLET',
                'off_catalogue_reason' => 'Testing',
                'stock_quantity' => 10,
                'minimum_threshold' => 5,
                'unit' => 'tablets',
                'generic_name' => 'Paracetamol',
            ])
            ->assertSessionHasErrors(['name' => 'This medicine is already in the inventory (Paracetamol 500mg tablet). Use Receive Stock to add quantity.']);

        // A brand of the same generic, strength and form is the same item.
        $this->withSession($this->nurse())
            ->from(route('dashboard.medicine-inventory'))
            ->post(route('medicine-inventory.store'), [
                'catalogue_name' => MedicineCatalogue::OTHER,
                'custom_name' => 'Biogesic 500 mg Tablet',
                'off_catalogue_reason' => 'Testing',
                'stock_quantity' => 10,
                'minimum_threshold' => 5,
                'unit' => 'tablets',
                'generic_name' => 'Paracetamol',
            ])
            ->assertSessionHasErrors(['name' => 'This medicine is already in the inventory (Paracetamol 500mg tablet). Use Receive Stock to add quantity.']);

        $this->assertSame(1, Medicine::count());

        // The refused dialog offers that item's Receive Stock.
        $this->withSession($this->nurse() + ['duplicate_medicine_id' => $existing->id])
            ->get(route('dashboard.medicine-inventory'))
            ->assertSee('data-duplicate-receive="'.$existing->id.'"', false);
    }

    #[Test]
    public function a_supply_is_not_checked_against_rxnorm(): void
    {
        $this->assertTrue(MedicineCatalogue::isSupply('Sterile gauze pad'));
        $this->assertTrue(MedicineCatalogue::isSupply('Disposable gloves'));
        $this->assertTrue(MedicineCatalogue::isSupply('Gauze roll 4 inch'), 'Typed outside the list, named as a supply.');
        $this->assertFalse(MedicineCatalogue::isSupply('Paracetamol 500mg tablet'));
        $this->assertFalse(MedicineCatalogue::isSupply('Povidone-iodine 10% solution'));
        $this->assertFalse(MedicineCatalogue::isSupply('Cottonseed oil'), 'Whole words only.');

        // Saved with no generic name, and never looked up.
        $this->withSession($this->nurse())
            ->from(route('dashboard.medicine-inventory'))
            ->post(route('medicine-inventory.store'), [
                'catalogue_name' => 'Sterile gauze pad',
                'stock_quantity' => 50,
                'minimum_threshold' => 10,
                'unit' => 'pcs',
            ])
            ->assertSessionHasNoErrors();

        $gauze = Medicine::where('name', 'Sterile gauze pad')->firstOrFail();
        $this->assertNull($gauze->generic_name);
        $this->assertTrue($gauze->isSupply());
        if (Medicine::supportsItemType()) {
            $this->assertSame(Medicine::TYPE_SUPPLY, $gauze->item_type, 'The type is kept on the row (report #16).');
        }

        // The type the nurse chose decides, whatever the name reads as: an
        // off-list "Bandage scissors" kept as a supply asks for no generic.
        $this->withSession($this->nurse())
            ->from(route('dashboard.medicine-inventory'))
            ->post(route('medicine-inventory.store'), [
                'catalogue_name' => MedicineCatalogue::OTHER,
                'custom_name' => 'Bandage scissors',
                'off_catalogue_reason' => 'Wound care',
                'item_type' => Medicine::TYPE_SUPPLY,
                'stock_quantity' => 2,
                'minimum_threshold' => 1,
                'unit' => 'pcs',
            ])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Medicine::where('name', 'Bandage scissors')->firstOrFail()->isSupply());

        $html = $this->withSession($this->nurse())->get(route('dashboard.medicine-inventory'))->getContent();
        $this->assertStringContainsString('Not applicable', $html);

        // The generic-name endpoint refuses a supply.
        if (RxNormService::supported()) {
            $this->withSession($this->nurse())
                ->post(route('medicine-inventory.rxnorm', $gauze), ['generic_name' => 'Gauze'])
                ->assertSessionHas('error');
            $this->assertNull($gauze->fresh()->generic_name);
        }
    }

    /**
     * "Antifungal cream" names a kind of medicine, and its generic is the
     * clinic's product's (report #17). It is asked for — empty, with where to
     * read it — never guessed; and a generic the kind cannot be, like the
     * antiseptic Povidone-iodine, is named for correction.
     */
    #[Test]
    public function the_antifungal_cream_asks_for_its_products_generic_and_a_wrong_one_is_named(): void
    {
        $this->assertNull(MedicineCatalogue::genericFor('Antifungal cream'), 'No generic is guessed.');
        $this->assertStringContainsString('Clotrimazole or Miconazole', (string) MedicineCatalogue::labelHint('Antifungal cream'));
        $this->assertNull(MedicineCatalogue::labelHint('Paracetamol 500mg tablet'));

        $this->assertNotNull(MedicineCatalogue::genericMismatch('Antifungal cream', 'Povidone-iodine'));
        $this->assertNull(MedicineCatalogue::genericMismatch('Antifungal cream', 'Clotrimazole'));
        $this->assertNull(MedicineCatalogue::genericMismatch('Paracetamol 500mg tablet', 'Paracetamol'), 'Nothing is said of an item named by its generic.');

        $this->medicine('Ibuprofen 200mg tablet', 40, 20, 'tablets');
        $html = $this->withSession($this->nurse())->get(route('dashboard.medicine-inventory'))->getContent();

        $this->assertStringContainsString('data-generic-hint="Clotrimazole or Miconazole', $html);
        $this->assertStringNotContainsString('data-generic="Clotrimazole"', $html);
        if (RxNormService::supported()) {
            // An empty generic reads as a control to set it, not as a value.
            $this->assertStringContainsString('>Set generic name</button>', $html);
        }
    }

    /**
     * Report #14: "Generic name" — the label of an old button — was saved as
     * Paracetamol's and Ibuprofen's generic. A label or an example is refused
     * wherever a generic is set.
     */
    #[Test]
    public function a_fields_label_is_never_saved_as_a_generic_name(): void
    {
        foreach (['Generic name', 'generic', 'e.g. Mefenamic Acid', 'N/A'] as $placeholder) {
            $this->assertTrue(RxNormService::isPlaceholder($placeholder), $placeholder);
        }
        $this->assertFalse(RxNormService::isPlaceholder('Paracetamol'));
        $this->assertFalse(RxNormService::isPlaceholder('Mefenamic Acid'));

        if (! RxNormService::supported()) {
            $this->markTestSkipped('The allergy-check tables are not on this database.');
        }

        $this->withSession($this->nurse())
            ->from(route('dashboard.medicine-inventory'))
            ->post(route('medicine-inventory.store'), [
                'catalogue_name' => 'Ibuprofen 200mg tablet',
                'generic_name' => 'Generic name',
                'stock_quantity' => 10,
                'minimum_threshold' => 5,
                'unit' => 'tablets',
            ])
            ->assertSessionHasErrors('generic_name');

        $this->assertSame(0, Medicine::count());
    }
}
