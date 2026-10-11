<?php

namespace Tests\Feature;

use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The nurse's Health Assessment queue (`nurse.index`) filters by Grade Level,
 * Gender and Status. Grade and gender are scope — they move the cards and the
 * table together — while Status narrows the table alone, so the cards keep
 * reporting both pending and completed for the learners in scope.
 */
class NurseAssessmentQueueFilterTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    /** @return array<string, mixed> */
    private function queueSession(): array
    {
        return [
            'active_role' => 'school_nurse',
            'active_name' => 'Ana Reyes',
            'active_username' => 'ana.reyes',
            'active_institution_id' => $this->institution->id,
            'active_school_name' => 'Sta. Ana NHS',
            'school_health_card_records' => [
                // Advisers have typed gender three ways; all three are one group.
                ['last_name' => 'Gomez', 'first_name' => 'Jose', 'lrn' => '100000000001',
                    'grade_level' => 'Grade 10', 'gender' => 'M', 'examination' => []],
                ['last_name' => 'Reyes', 'first_name' => 'Ana', 'lrn' => '100000000002',
                    'grade_level' => 'Grade 7', 'gender' => 'Female',
                    'examination' => ['date_of_examination' => now()->toDateString()]],
                ['last_name' => 'Santos', 'first_name' => 'Pedro', 'lrn' => '100000000003',
                    'grade_level' => 'Grade 7', 'gender' => 'male', 'examination' => []],
                ['last_name' => 'Cruz', 'first_name' => 'Maria', 'lrn' => '100000000004',
                    'grade_level' => 'Grade 7', 'gender' => 'Female', 'examination' => []],
            ],
        ];
    }

    #[Test]
    public function the_page_offers_the_three_filters_in_grade_order(): void
    {
        $response = $this->withSession($this->queueSession())->get(route('nurse.index'))->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('<label class="field-label" for="queueGrade">Grade Level</label>', $html);
        $this->assertStringContainsString('<label class="field-label" for="queueSex">Gender</label>', $html);
        $this->assertStringContainsString('<label class="field-label" for="queueStatus">Status</label>', $html);

        // Grade 7 before Grade 10: grade order, not string order.
        $this->assertSame(['Grade 7', 'Grade 10'], array_keys($response->viewData('filterOptions')['grade']));
        $this->assertSame(['Male' => 2, 'Female' => 2], $response->viewData('filterOptions')['sex']);
        $this->assertSame(['pending' => 3, 'completed' => 1], $response->viewData('filterOptions')['status']);

        // Unfiltered, every learner is listed.
        $this->assertCount(4, $response->viewData('records'));
    }

    #[Test]
    public function grade_and_gender_narrow_the_table_and_the_cards_together(): void
    {
        $response = $this->withSession($this->queueSession())
            ->get(route('nurse.index', ['grade' => 'Grade 7', 'sex' => 'Male']))
            ->assertOk();
        $html = $response->getContent();

        // "male" is Male: only Pedro is a Grade 7 boy. Rows are checked by
        // their own examination link — the topbar's learner search embeds
        // every name on the roster, so a name alone proves nothing.
        $this->assertSame([2], array_keys($response->viewData('records')));
        $this->assertStringContainsString(route('nurse.examine', 2), $html);
        foreach ([0, 1, 3] as $hidden) {
            $this->assertStringNotContainsString(route('nurse.examine', $hidden), $html);
        }

        $this->assertSame(['total' => 1, 'pending' => 1, 'completed' => 0], $response->viewData('queueCounts'));
        $this->assertStringContainsString('Grade 7 · Male', $html);

        // The row prints the normalised value the filter matched on.
        $this->assertStringContainsString('<td>Male</td>', $html);
    }

    #[Test]
    public function status_narrows_the_table_but_not_the_cards(): void
    {
        $response = $this->withSession($this->queueSession())
            ->get(route('nurse.index', ['grade' => 'Grade 7', 'status' => 'pending']))
            ->assertOk();

        // Pedro and Maria are pending in Grade 7; Ana is completed and hidden.
        $this->assertSame([2, 3], array_keys($response->viewData('records')));
        $this->assertStringNotContainsString(route('nurse.examine', 1), $response->getContent());

        // The cards still report both halves of the Grade 7 scope.
        $this->assertSame(['total' => 3, 'pending' => 2, 'completed' => 1], $response->viewData('queueCounts'));
    }

    #[Test]
    public function a_filtered_row_still_opens_its_own_learners_form(): void
    {
        $html = $this->withSession($this->queueSession())
            ->get(route('nurse.index', ['grade' => 'Grade 10']))
            ->assertOk()
            ->getContent();

        // The examination link is keyed by the raw session index, which a
        // filter must not renumber.
        $this->assertStringContainsString(route('nurse.examine', 0), $html);
        $this->assertStringNotContainsString(route('nurse.examine', 2), $html);
    }

    #[Test]
    public function an_unrecognised_value_is_dropped_rather_than_emptying_the_page(): void
    {
        $response = $this->withSession($this->queueSession())
            ->get(route('nurse.index', ['grade' => 'Grade 99', 'sex' => 'Other', 'status' => 'archived']))
            ->assertOk();

        $this->assertSame(['grade' => '', 'sex' => '', 'status' => ''], $response->viewData('filters'));
        $this->assertCount(4, $response->viewData('records'));

        // An array off the query string is not a crash either.
        $this->withSession($this->queueSession())
            ->get(route('nurse.index').'?grade[]=Grade+7&sex[]=Male')
            ->assertOk();
    }

    #[Test]
    public function a_filter_that_matches_nobody_says_so_and_offers_a_way_back(): void
    {
        $html = $this->withSession($this->queueSession())
            ->get(route('nurse.index', ['grade' => 'Grade 10', 'status' => 'completed']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No submitted health cards match these filters.', $html);
        $this->assertStringNotContainsString('No adviser submissions yet', $html);
        $this->assertStringContainsString('Clear filters', $html);
    }
}
