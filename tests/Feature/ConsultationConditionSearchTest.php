<?php

namespace Tests\Feature;

use App\Models\Condition;
use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Choosing a condition on New Consultation is a search, not a list.
 *
 * The catalogue runs to dozens of entries across seven categories, and
 * scrolling one to find "Headache" is slower than typing it. So the field is
 * a text box that filters as the nurse types — and it shows nothing until
 * they do: a list that opens on focus covers the fields below with a menu to
 * be read, when what the nurse wants is one answer.
 *
 * The visible box finds; a hidden input answers. That split is the thing
 * these tests protect — a name on screen that no longer matches the id
 * underneath it is the one state this must never submit in, and the server
 * would accept it silently because `condition_id` would simply be absent.
 */
class ConsultationConditionSearchTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);

        Condition::create(['name' => 'Headache', 'category' => 'General']);
        Condition::create(['name' => 'Abdominal pain', 'category' => 'Gastrointestinal']);
        Condition::create(['name' => 'Others', 'category' => 'Other']);
    }

    private function nurseSession(): array
    {
        return [
            'active_role' => 'school_nurse',
            'active_name' => 'Nurse Cruz',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
            'school_health_card_records' => [],
        ];
    }

    private function dialog(): string
    {
        return $this->withSession($this->nurseSession())
            ->get(route('dashboard.consultation-log'))
            ->assertOk()
            ->getContent();
    }

    #[Test]
    public function the_condition_field_is_a_search_box(): void
    {
        $html = $this->dialog();

        $this->assertStringContainsString('id="cm_condition_search"', $html);
        $this->assertStringContainsString('Type to search conditions', $html);
        $this->assertStringContainsString('role="combobox"', $html);
    }

    /** The dropdown it replaced is gone — no list to scroll. */
    #[Test]
    public function there_is_no_condition_dropdown_any_more(): void
    {
        $html = $this->dialog();

        $this->assertStringNotContainsString('<select id="cm_condition_id"', $html);
        $this->assertStringNotContainsString('Select a condition...', $html);
    }

    /**
     * The requirement, stated plainly: clicking the box shows nothing. The
     * list opens on typing alone, so there is deliberately no focus handler.
     */
    #[Test]
    public function the_list_stays_closed_until_something_is_typed(): void
    {
        $html = $this->dialog();

        // It starts closed…
        $this->assertStringContainsString('aria-expanded="false"', $html);
        $this->assertStringContainsString('<div class="cmcombo-list" id="cm_condition_results"', $html);

        // …and only an input event can open it.
        $this->assertStringContainsString("search.addEventListener('input', () => {", $html);
        $this->assertStringNotContainsString("search.addEventListener('focus'", $html);

        // An empty term closes it again rather than falling back to the list.
        $this->assertStringContainsString("if (term === '') {", $html);
    }

    /** The catalogue is embedded so a keystroke costs no round trip. */
    #[Test]
    public function the_catalogue_is_searchable_in_the_browser(): void
    {
        $html = $this->dialog();

        $this->assertStringContainsString('Headache', $html);
        $this->assertStringContainsString('Abdominal pain', $html);
        $this->assertStringContainsString('Gastrointestinal', $html);

        // Matching on the category too — a nurse who knows the group but not
        // the wording still finds it, ranked below the name matches.
        $this->assertStringContainsString('else if (startsWith(c.category)) tiers[2].push(c);', $html);
    }

    /**
     * Typing a letter brings the conditions that *begin* with it.
     *
     * It used to match a substring anywhere, so "a" returned Headache and
     * Toothache alongside Abdominal pain — the first keystroke told the nurse
     * nothing. Now a match has to start a word.
     */
    #[Test]
    public function a_letter_matches_the_start_of_a_word_not_the_middle(): void
    {
        $html = $this->dialog();

        $this->assertStringContainsString(
            'const startsWith = (value) => String(value).toLowerCase().startsWith(term);',
            $html
        );
        $this->assertStringContainsString('.some((word) => word.startsWith(term))', $html);

        // The old rule is gone from both the name and the category test.
        $this->assertStringNotContainsString('String(c.name).toLowerCase().includes(term)', $html);
        $this->assertStringNotContainsString('String(c.category).toLowerCase().includes(term)', $html);
    }

    /**
     * Ranked, not narrowed. A nurse types both ways — a letter to reach the
     * A conditions, and a whole word like "pain" that sits second in a name —
     * so a later-word match is kept but ordered below a name that opens with
     * the term.
     */
    #[Test]
    public function name_prefixes_outrank_later_words_and_categories(): void
    {
        $html = $this->dialog();

        $this->assertStringContainsString('if (startsWith(c.name)) tiers[0].push(c);', $html);
        $this->assertStringContainsString('else if (wordStartsWith(c.name)) tiers[1].push(c);', $html);
        $this->assertStringContainsString('else if (startsWith(c.category)) tiers[2].push(c);', $html);
        $this->assertStringContainsString('render(tiers[0].concat(tiers[1], tiers[2]), raw);', $html);
    }

    /**
     * The visible box is for finding; the hidden input is what posts. The
     * store reads `condition_id`, so that is the field that must carry it.
     */
    #[Test]
    public function the_answer_posts_from_a_hidden_field(): void
    {
        $html = $this->dialog();

        $this->assertStringContainsString('<input type="hidden" id="cm_condition_id" name="condition_id"', $html);
        $this->assertStringNotContainsString('name="condition_id" required', $html);
    }

    /** Typing over a chosen condition clears the choice underneath it. */
    #[Test]
    public function editing_the_text_after_a_pick_discards_the_pick(): void
    {
        $html = $this->dialog();

        $this->assertStringContainsString('if (!picked || picked.name.toLowerCase() !== term) {', $html);
        $this->assertStringContainsString("hidden.value = '';", $html);
    }

    /**
     * A typed name nobody picked is not a condition, and the browser cannot
     * see that on its own — the visible box is full while the hidden one is
     * empty.
     */
    #[Test]
    public function submitting_without_choosing_is_blocked_with_a_reason(): void
    {
        $html = $this->dialog();

        $this->assertStringContainsString("combo.closest('form')?.addEventListener('submit'", $html);
        $this->assertStringContainsString('search.reportValidity();', $html);
        $this->assertStringContainsString('Choose a condition from the list', $html);
    }

    /**
     * "Others" is pinned to the bottom of the list, always — not only when a
     * search comes up empty.
     *
     * A condition the catalogue does not carry is a real answer, and the
     * nurse should not have to discover that by first failing to find one.
     * Pinning it also means it is in the same place every time rather than
     * moving up and down as the results change.
     */
    #[Test]
    public function others_is_always_the_last_row(): void
    {
        $html = $this->dialog();

        $this->assertStringContainsString('if (otherCondition) {', $html);
        $this->assertStringContainsString("other.classList.add('cmcombo-row-other');", $html);
        $this->assertStringContainsString('Type the condition yourself', $html);

        // Appended after the matches, so it is last whatever the search found.
        $matches = strpos($html, 'matches.slice(0, 8).forEach');
        $pinned = strpos($html, 'if (otherCondition) {');
        $this->assertNotFalse($matches);
        $this->assertGreaterThan($matches, $pinned, 'Others must be appended after the matches.');

        // A no-match search still says so, above the same pinned row.
        $this->assertStringContainsString('No condition matches', $html);
    }

    /** Listed once. It is pinned, so it is never also a match. */
    #[Test]
    public function others_is_not_duplicated_among_the_matches(): void
    {
        $html = $this->dialog();

        $this->assertStringContainsString('if (String(c.id) === catchAll) return;', $html);
    }

    /** Choosing it opens a box to type the condition into. */
    #[Test]
    public function choosing_others_asks_for_the_condition_in_writing(): void
    {
        $html = $this->dialog();

        $this->assertStringContainsString('id="cm_condition_other_wrap"', $html);
        $this->assertStringContainsString('placeholder="Describe the condition"', $html);
        $this->assertStringContainsString('name="condition"', $html);

        // Revealed and required only once Others is the pick, and focused so
        // the nurse can type straight into it.
        $this->assertStringContainsString('otherInput.required = isOther;', $html);
        $this->assertStringContainsString('if (otherInput.required) otherInput.focus();', $html);
    }

    /**
     * Enter takes a lone real match, never the pinned row — otherwise a typo
     * with no matches would be filed under Others without the nurse choosing
     * it.
     */
    #[Test]
    public function enter_never_falls_through_to_the_pinned_row(): void
    {
        $html = $this->dialog();

        $this->assertStringContainsString(
            "list.querySelectorAll('.cmcombo-row:not(.cmcombo-row-other)')",
            $html
        );
    }

    /** Names come out of the database, so rows are built as nodes. */
    #[Test]
    public function results_are_never_built_from_innerhtml(): void
    {
        $html = $this->dialog();

        $start = strpos($html, "const combo = document.getElementById('cm_condition_combo');");
        $this->assertNotFalse($start);
        $script = substr($html, $start, (int) strpos($html, '</script>', $start) - $start);

        // The word itself appears in the code's own comment explaining why it
        // is not used, so look for the assignment rather than the name.
        $this->assertStringNotContainsString('innerHTML =', $script);
        $this->assertStringNotContainsString('insertAdjacentHTML', $script);
        $this->assertStringContainsString('textContent', $script);
        $this->assertStringContainsString('document.createElement', $script);
    }

    /**
     * With no catalogue seeded the field falls back to free text, exactly as
     * before — an empty search box nobody can pick from would be worse than
     * the dropdown it replaced.
     */
    #[Test]
    public function an_unseeded_catalogue_still_falls_back_to_free_text(): void
    {
        Condition::query()->delete();

        $html = $this->dialog();

        $this->assertStringContainsString('name="condition"', $html);
        $this->assertStringNotContainsString('id="cm_condition_search"', $html);
    }
}
