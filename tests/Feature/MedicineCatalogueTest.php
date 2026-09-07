<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\Medicine;
use App\Support\MedicineCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Stock is added from the DepEd-allowed list, not typed.
 *
 * Free text made "Paracetamol 500mg" and "paracetamol 500 mg" two items
 * nobody could total, reorder against, or report on. A dropdown fixes that.
 *
 * Going outside the list is allowed on purpose. The school nurse stated
 * plainly that she dispenses beyond it when clinically necessary —
 * antibiotics, mefenamic acid, allergy medicine. Refusing to record that
 * would not change the practice; it would only make the stock list disagree
 * with the shelf, and an off-list medicine kept in a notebook cannot be
 * counted, reordered or answered for. So an off-list entry is accepted,
 * marked as off-list, and required to carry a reason.
 */
class MedicineCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function nurseSession(): array
    {
        return [
            'active_role' => 'school_nurse',
            'active_name' => 'Nurse Cruz',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
        ];
    }

    private function add(array $payload)
    {
        return $this->withSession($this->nurseSession())
            ->post(route('medicine-inventory.store'), array_merge([
                'stock_quantity' => 50,
                'minimum_threshold' => 20,
                'unit' => 'pcs',
            ], $payload));
    }

    // ── The catalogue ────────────────────────────────────────────────

    #[Test]
    public function the_catalogue_is_grouped_and_flattens_to_names(): void
    {
        $grouped = MedicineCatalogue::grouped();
        $names = MedicineCatalogue::names();

        $this->assertNotEmpty($grouped);
        $this->assertNotEmpty($names);
        $this->assertContains('Paracetamol 500mg tablet', $names);
        $this->assertTrue(MedicineCatalogue::has('Paracetamol 500mg tablet'));
        $this->assertFalse(MedicineCatalogue::has('Something invented'));
    }

    #[Test]
    public function the_form_offers_the_list_instead_of_a_text_box(): void
    {
        $html = $this->withSession($this->nurseSession())
            ->get(route('medicine-inventory.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="catalogue_name"', $html);
        $this->assertStringContainsString('<optgroup label="Analgesic / Antipyretic">', $html);
        $this->assertStringContainsString('Paracetamol 500mg tablet', $html);

        // The old free-text name field is gone.
        $this->assertStringNotContainsString('<input id="name" name="name"', $html);
    }

    /** The list is the default; "Other" sits last because it is the exception. */
    #[Test]
    public function the_off_list_option_is_offered_last(): void
    {
        $html = $this->withSession($this->nurseSession())
            ->get(route('medicine-inventory.create'))
            ->assertOk()
            ->getContent();

        $other = strpos($html, MedicineCatalogue::OTHER);
        $lastGroup = strrpos($html, '<optgroup');

        $this->assertNotFalse($other);
        $this->assertGreaterThan($lastGroup, $other, 'Other belongs after every group.');
        $this->assertStringContainsString('Other — not on the DepEd list', $html);
    }

    // ── Adding from the list ─────────────────────────────────────────

    #[Test]
    public function a_catalogue_medicine_is_stored_as_on_list(): void
    {
        $this->add(['catalogue_name' => 'Paracetamol 500mg tablet'])->assertRedirect();

        $medicine = Medicine::first();

        $this->assertSame('Paracetamol 500mg tablet', $medicine->name);
        $this->assertFalse($medicine->off_catalogue);
        $this->assertNull($medicine->off_catalogue_reason);
    }

    // ── Going outside it ─────────────────────────────────────────────

    #[Test]
    public function an_off_list_medicine_is_accepted_and_marked(): void
    {
        $this->add([
            'catalogue_name' => MedicineCatalogue::OTHER,
            'custom_name' => 'Mefenamic acid 500mg tablet',
            'off_catalogue_reason' => 'Dysmenorrhea cases referred by the physician',
        ])->assertRedirect();

        $medicine = Medicine::first();

        $this->assertSame('Mefenamic acid 500mg tablet', $medicine->name);
        $this->assertTrue($medicine->off_catalogue, 'Off-list stock must be marked as such.');
        $this->assertSame('Dysmenorrhea cases referred by the physician', $medicine->off_catalogue_reason);
    }

    /** Marked, but not unexplained — the school has to be able to answer for it. */
    #[Test]
    public function an_off_list_medicine_needs_a_reason(): void
    {
        $this->add([
            'catalogue_name' => MedicineCatalogue::OTHER,
            'custom_name' => 'Amoxicillin 500mg capsule',
        ])->assertSessionHasErrors('off_catalogue_reason');

        $this->assertSame(0, Medicine::count());
    }

    /** A reason on a catalogue medicine is simply unnecessary, not an error. */
    #[Test]
    public function a_catalogue_medicine_needs_no_reason(): void
    {
        $this->add(['catalogue_name' => 'Albendazole 400mg tablet'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Medicine::count());
    }

    /** An empty choice is still an error — the name is required. */
    #[Test]
    public function a_medicine_with_no_name_is_refused(): void
    {
        $this->add(['catalogue_name' => ''])->assertSessionHasErrors('name');

        $this->assertSame(0, Medicine::count());
    }

    /**
     * A name typed into the off-list box that happens to match the catalogue
     * is still resolved honestly — the resolver trusts the picked value, not
     * the free-text one.
     */
    #[Test]
    public function the_resolver_reports_what_was_actually_chosen(): void
    {
        $onList = MedicineCatalogue::resolve('Paracetamol 500mg tablet', '');
        $this->assertSame('Paracetamol 500mg tablet', $onList['name']);
        $this->assertFalse($onList['off_catalogue']);

        $offList = MedicineCatalogue::resolve(MedicineCatalogue::OTHER, 'Cetirizine 10mg tablet');
        $this->assertSame('Cetirizine 10mg tablet', $offList['name']);
        $this->assertTrue($offList['off_catalogue']);

        // A choice that is not on the list is off-list, whatever it claims.
        $invented = MedicineCatalogue::resolve('Not A Real Medicine', 'Typed instead');
        $this->assertTrue($invented['off_catalogue']);
    }

    // ── The school can turn the exception off ────────────────────────

    #[Test]
    public function a_school_can_make_the_catalogue_a_hard_limit(): void
    {
        config()->set('medicines.allow_off_catalogue', false);

        $this->assertFalse(MedicineCatalogue::allowsOffCatalogue());

        $html = $this->withSession($this->nurseSession())
            ->get(route('medicine-inventory.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Other — not on the DepEd list', $html);
        $this->assertStringNotContainsString('id="offCatalogueBlock"', $html);
    }
}
