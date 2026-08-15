<?php

namespace Tests\Feature;

use App\Models\Condition;
use App\Models\Consultation;
use App\Models\Institution;
use Database\Seeders\ConditionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The consultation dialog picks a condition from the clinic's catalogue.
 *
 * A free-text box let the same complaint be spelled three ways, which then
 * split the "top conditions" reports. The dialog now offers the seeded
 * catalogue as a dropdown, grouped by category.
 *
 * The catalogue is data, not code, so the dialog degrades rather than
 * breaking when it is empty — a real risk here, since the table shipped
 * unseeded.
 */
class ConsultationConditionDropdownTest extends TestCase
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

    private function seedCatalogue(): void
    {
        $this->seed(ConditionSeeder::class);
    }

    private function log(): string
    {
        return $this->withSession($this->nurseSession())
            ->get(route('dashboard.consultation-log'))
            ->assertOk()
            ->getContent();
    }

    #[Test]
    public function the_condition_field_is_a_dropdown_of_the_catalogue(): void
    {
        $this->seedCatalogue();

        $html = $this->log();

        $this->assertMatchesRegularExpression(
            '/<select id="cm_condition_id" name="condition_id"[^>]*>/',
            $html
        );

        // Common high-school clinic complaints are all offered.
        foreach (['Headache', 'Fever', 'Toothache', 'Abdominal Pain', 'Dysmenorrhea', 'Skin allergy'] as $condition) {
            $this->assertStringContainsString('>'.$condition.'</option>', $html);
        }
    }

    /** 33 items in a flat list is unreadable; they are grouped by category. */
    #[Test]
    public function the_conditions_are_grouped_by_category(): void
    {
        $this->seedCatalogue();

        $html = $this->log();

        foreach (['Respiratory', 'Gastrointestinal', 'Injury', 'Neurological', 'Skin'] as $category) {
            $this->assertStringContainsString('<optgroup label="'.$category.'">', $html);
        }
    }

    #[Test]
    public function every_seeded_condition_is_offered(): void
    {
        $this->seedCatalogue();

        $html = $this->log();

        foreach (Condition::all() as $condition) {
            $this->assertStringContainsString(
                'value="'.$condition->id.'"',
                $html,
                "{$condition->name} is missing from the dropdown."
            );
        }
    }

    /** Picking from the dropdown stores the catalogue's own wording. */
    #[Test]
    public function a_selected_condition_is_stored_by_its_catalogue_name(): void
    {
        $this->seedCatalogue();
        $headache = Condition::where('name', 'Headache')->firstOrFail();

        $this->withSession($this->nurseSession())
            ->post(route('consultations.store'), [
                'consulted_at' => now()->format('Y-m-d\TH:i'),
                'student_name' => 'Cruz, Juan',
                'grade_section' => 'Grade 10 - Dalton',
                'condition_id' => $headache->id,
                'status' => 'treated',
            ])->assertRedirect();

        $consultation = Consultation::firstOrFail();
        $this->assertSame('Headache', $consultation->condition);
        $this->assertSame($headache->id, $consultation->condition_id);
    }

    /** The catalogue's catch-all is offered, and asks for the detail. */
    #[Test]
    public function the_dropdown_offers_an_other_option_that_asks_for_detail(): void
    {
        $this->seedCatalogue();
        $others = Condition::where('name', 'Others')->firstOrFail();

        $html = $this->log();

        $this->assertStringContainsString('>Others</option>', $html);
        // The select knows which option is the catch-all…
        $this->assertStringContainsString('data-catch-all="'.$others->id.'"', $html);

        // …and it is the last option, so a reader scans the real complaints
        // first and falls through to it only when none of them fit.
        $selectStart = strpos($html, '<select id="cm_condition_id"');
        $selectEnd = strpos($html, '</select>', $selectStart);
        $select = substr($html, $selectStart, $selectEnd - $selectStart);

        $this->assertSame(
            1,
            preg_match('/>Others<\/option>\s*<\/optgroup>\s*$/', trim($select)),
            'Others must be the final option in the dropdown.'
        );
        // …and a describe-it field is present, hidden until it is chosen.
        $this->assertStringContainsString('id="cm_condition_other_wrap" hidden', $html);
        $this->assertStringContainsString('placeholder="Describe the condition"', $html);
    }

    /** Choosing Other stores what was typed, not the word "Others". */
    #[Test]
    public function other_stores_the_typed_detail(): void
    {
        $this->seedCatalogue();
        $others = Condition::where('name', 'Others')->firstOrFail();

        $this->withSession($this->nurseSession())
            ->post(route('consultations.store'), [
                'consulted_at' => now()->format('Y-m-d\TH:i'),
                'student_name' => 'Cruz, Juan',
                'grade_section' => 'Grade 10 - Dalton',
                'condition_id' => $others->id,
                'condition' => 'Insect sting on the forearm',
                'status' => 'treated',
            ])->assertRedirect();

        $consultation = Consultation::firstOrFail();

        $this->assertSame('Insect sting on the forearm', $consultation->condition);
        // Still catalogued as the catch-all, so it can be grouped later.
        $this->assertSame($others->id, $consultation->condition_id);
    }

    /** "Others" with no detail is useless, so it is refused. */
    #[Test]
    public function other_without_a_description_is_rejected(): void
    {
        $this->seedCatalogue();
        $others = Condition::where('name', 'Others')->firstOrFail();

        $this->withSession($this->nurseSession())
            ->post(route('consultations.store'), [
                'consulted_at' => now()->format('Y-m-d\TH:i'),
                'student_name' => 'Cruz, Juan',
                'grade_section' => 'Grade 10 - Dalton',
                'condition_id' => $others->id,
                'status' => 'treated',
            ])->assertSessionHasErrors('condition', null, 'consultation');

        $this->assertSame(0, Consultation::count());
    }

    /** A normal condition ignores any stray text and uses the catalogue. */
    #[Test]
    public function a_catalogued_condition_is_not_overridden_by_stray_text(): void
    {
        $this->seedCatalogue();
        $headache = Condition::where('name', 'Headache')->firstOrFail();

        $this->withSession($this->nurseSession())
            ->post(route('consultations.store'), [
                'consulted_at' => now()->format('Y-m-d\TH:i'),
                'student_name' => 'Cruz, Juan',
                'grade_section' => 'Grade 10 - Dalton',
                'condition_id' => $headache->id,
                'condition' => 'something else entirely',
                'status' => 'treated',
            ])->assertRedirect();

        $this->assertSame('Headache', Consultation::firstOrFail()->condition);
    }

    /**
     * With no catalogue the dialog falls back to free text.
     *
     * The table shipped unseeded, so an empty dropdown nobody could pick
     * from was the likely outcome of wiring this up naively.
     */
    #[Test]
    public function an_unseeded_catalogue_falls_back_to_free_text(): void
    {
        $this->assertSame(0, Condition::count());

        $html = $this->log();

        $this->assertStringNotContainsString('<select id="cm_condition_id"', $html);
        $this->assertStringContainsString('<input id="cm_condition_id" type="text" name="condition"', $html);
    }

    /** The free-text fallback still records a consultation. */
    #[Test]
    public function the_free_text_fallback_still_saves(): void
    {
        $this->withSession($this->nurseSession())
            ->post(route('consultations.store'), [
                'consulted_at' => now()->format('Y-m-d\TH:i'),
                'student_name' => 'Cruz, Juan',
                'grade_section' => 'Grade 10 - Dalton',
                'condition' => 'Headache',
                'status' => 'treated',
            ])->assertRedirect();

        $this->assertSame('Headache', Consultation::firstOrFail()->condition);
    }
}
