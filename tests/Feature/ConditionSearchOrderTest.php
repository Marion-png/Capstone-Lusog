<?php

namespace Tests\Feature;

use App\Models\Condition;
use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The condition search returns results in alphabetical order.
 *
 * Grouping by category mattered when this was a list to scan — a reader found
 * "Respiratory" and then looked inside it. A search is the other way round:
 * the nurse types the complaint, so the complaint is what orders the answers,
 * and results that put "Fever" above "Abdominal pain" because one happens to
 * be Respiratory read as unordered.
 *
 * It matters beyond tidiness. The list shows the first eight matches, so the
 * ordering decides which eight a broad term produces.
 */
class ConditionSearchOrderTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function dialog(): string
    {
        return $this->withSession([
            'active_role' => 'school_nurse',
            'active_name' => 'Nurse Cruz',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
            'school_health_card_records' => [],
        ])->get(route('dashboard.consultation-log'))->assertOk()->getContent();
    }

    /** The embedded catalogue, in the order the browser will search it. */
    private function searchableNames(): array
    {
        $html = $this->dialog();

        $start = strpos($html, 'const conditions = [');
        $this->assertNotFalse($start, 'The searchable catalogue is missing.');

        $json = substr($html, strpos($html, '[', $start));
        $json = substr($json, 0, strpos($json, '];') + 1);

        $decoded = json_decode(html_entity_decode($json, ENT_QUOTES), true);
        $this->assertIsArray($decoded, 'The catalogue did not decode.');

        return array_column($decoded, 'name');
    }

    #[Test]
    public function the_catalogue_is_alphabetical_by_name(): void
    {
        // Seeded deliberately out of order, and across categories, so passing
        // cannot be an accident of insertion order.
        Condition::create(['name' => 'Toothache', 'category' => 'Oral']);
        Condition::create(['name' => 'Abdominal pain', 'category' => 'Gastrointestinal']);
        Condition::create(['name' => 'Fever', 'category' => 'General']);
        Condition::create(['name' => 'Cough', 'category' => 'Respiratory']);

        $names = $this->searchableNames();

        $sorted = $names;
        sort($sorted, SORT_NATURAL | SORT_FLAG_CASE);

        $this->assertSame($sorted, $names);
        $this->assertSame('Abdominal pain', $names[0]);
    }

    /**
     * Category no longer decides position. "Abdominal pain" comes first even
     * though Gastrointestinal sorts after General and Cardiovascular.
     */
    #[Test]
    public function category_no_longer_groups_the_results(): void
    {
        Condition::create(['name' => 'Chest pain', 'category' => 'Cardiovascular']);
        Condition::create(['name' => 'Abdominal pain', 'category' => 'Gastrointestinal']);
        Condition::create(['name' => 'Fever', 'category' => 'General']);

        $this->assertSame(
            ['Abdominal pain', 'Chest pain', 'Fever'],
            $this->searchableNames()
        );
    }

    /**
     * "Others" sorts with everything else now. It used to be pinned last so a
     * reader scanned real complaints first; the search reaches that end by
     * only proposing it once a term has matched nothing.
     */
    #[Test]
    public function the_catch_all_takes_its_alphabetical_place(): void
    {
        Condition::create(['name' => 'Others', 'category' => 'Other']);
        Condition::create(['name' => 'Toothache', 'category' => 'Oral']);
        Condition::create(['name' => 'Abdominal pain', 'category' => 'Gastrointestinal']);

        $this->assertSame(
            ['Abdominal pain', 'Others', 'Toothache'],
            $this->searchableNames()
        );
    }

    /** Case does not create a second alphabet. */
    #[Test]
    public function sorting_ignores_case(): void
    {
        Condition::create(['name' => 'abdominal pain', 'category' => 'Gastrointestinal']);
        Condition::create(['name' => 'Bleeding', 'category' => 'Injury']);
        Condition::create(['name' => 'Cough', 'category' => 'Respiratory']);

        $this->assertSame(
            ['abdominal pain', 'Bleeding', 'Cough'],
            $this->searchableNames()
        );
    }
}
