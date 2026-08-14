<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the grade level / section filters on the coordinator's Student Health
 * Records page. The learner's grade and section live together in one plain
 * `section` column as "Grade 7 / Section A", so both filters are derived by
 * splitting that string and applied in PHP — never in SQL, since the rest of
 * the row (names, BMI, statuses) is encrypted at rest.
 */
class FeedingHealthRecordFilterTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    private function coordinatorSession(): array
    {
        return [
            'active_role' => 'feeding_coor',
            'active_name' => 'Test Coordinator',
            'active_username' => 'feedcor.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->institution->id,
        ];
    }

    private function makeStudent(string $name, string $section): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => $name,
            'student_id' => 'LRN'.random_int(100000, 999999),
            'school_name' => 'Test School',
            'section' => $section,
            'weight' => 30,
            'bmi_value' => 17.2,
            'nutritional_status' => 'Wasted',
            'baseline_nutritional_status' => 'Wasted',
            'student_details' => ['gender' => 'Male'],
            // Qualified by the measurement AND enrolled by the coordinator —
            // only then is a learner a beneficiary, and only then does the
            // page's default "All beneficiaries" view list them.
            'feeding_enrolled_at' => now(),
        ]);
    }

    private function seedThreeSections(): void
    {
        $this->makeStudent('Alpha Learner', 'Grade 7 / Rizal');
        $this->makeStudent('Bravo Learner', 'Grade 7 / Bonifacio');
        $this->makeStudent('Charlie Learner', 'Grade 8 / Mabini');
    }

    /**
     * The session roster row the adviser's entry leaves behind for this learner.
     * StudentRosterSync rebuilds it from the database, so in real use the page
     * always sees both copies of the same learner at once.
     *
     * @return array<string, mixed>
     */
    private function rosterRow(StudentHealthRecord $record, string $last, string $first, string $middle = ''): array
    {
        [$grade, $section] = array_pad(explode(' / ', $record->section, 2), 2, '');

        return [
            'lrn' => $record->student_id,
            'last_name' => $last,
            'first_name' => $first,
            'middle_name' => $middle,
            'grade_level' => $grade,
            'section' => $section,
            'bmi_value' => 17.2,
            'nutritional_status_bmi_for_age' => 'Wasted',
            'height_cm' => 140,
        ];
    }

    /** How many table rows on the page name this learner. */
    private function rowsNaming(string $name, array $session, string $query = ''): int
    {
        $html = $this->withSession($session)
            ->get('/dashboard/feedingcor-health-records'.$query)
            ->assertOk()
            ->getContent();

        preg_match_all('#<tr[^>]*>.*?</tr>#s', $html, $rows);

        return collect($rows[0])->filter(fn (string $row): bool => str_contains($row, e($name)))->count();
    }

    #[Test]
    public function a_learner_held_in_both_the_database_and_the_session_is_listed_once(): void
    {
        // Exactly the shape that duplicated every learner on this page: the row
        // is in the database, and the adviser's session roster carries a working
        // copy of the same learner.
        $record = $this->makeStudent('Nailes, Stephen C.', 'Grade 11 / BSIT - 4B');

        $session = $this->coordinatorSession() + [
            'school_health_card_records' => [$this->rosterRow($record, 'Nailes', 'Stephen', 'Cruz')],
        ];

        // Both copies render the same learner under the same name — which is
        // exactly why the page drew the row twice.
        $this->assertSame(1, $this->rowsNaming('Nailes, Stephen C.', $session));
    }

    #[Test]
    public function a_learner_only_the_session_knows_about_is_still_listed(): void
    {
        // The session copy is a fallback, not dead weight — a learner with no
        // row this query can see must not vanish from the page.
        $session = $this->coordinatorSession() + [
            'school_health_card_records' => [[
                'lrn' => 'LRN424242',
                'last_name' => 'Solano',
                'first_name' => 'Rita',
                'middle_name' => 'B',
                'grade_level' => 'Grade 9',
                'section' => 'Narra',
                'bmi_value' => 13.4,
                'nutritional_status_bmi_for_age' => 'Severely Wasted',
                'height_cm' => 138,
            ]],
        ];

        // A learner with no database row has no enrolment either, so the view
        // that lists them is Pending enrollment — not "All beneficiaries",
        // which is the enrolled roll. What must not happen is vanishing.
        $this->assertSame(1, $this->rowsNaming('Solano, Rita B.', $session, '?view=pending'));
        $this->assertSame(0, $this->rowsNaming('Solano, Rita B.', $session));
    }

    #[Test]
    public function the_beneficiary_count_is_not_inflated_by_the_session_copy(): void
    {
        $first = $this->makeStudent('Alpha Learner', 'Grade 7 / Rizal');
        $second = $this->makeStudent('Bravo Learner', 'Grade 7 / Rizal');

        $session = $this->coordinatorSession() + [
            'school_health_card_records' => [
                $this->rosterRow($first, 'Alpha', 'Learner'),
                $this->rosterRow($second, 'Bravo', 'Learner'),
            ],
        ];

        // Two learners on file — the summary cards and the consolidated report
        // both read off this same collection, so a doubled list doubled them too.
        $this->withSession($session)
            ->get('/dashboard/feedingcor-health-records')
            ->assertOk()
            ->assertSee('Showing 2 of 2 beneficiaries');
    }

    #[Test]
    public function unfiltered_page_lists_every_beneficiary(): void
    {
        $this->seedThreeSections();

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records');

        $response->assertOk()
            ->assertSee('Alpha Learner')
            ->assertSee('Bravo Learner')
            ->assertSee('Charlie Learner');
    }

    #[Test]
    public function grade_level_filter_keeps_only_that_grade(): void
    {
        $this->seedThreeSections();

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records?grade_level=Grade+7');

        $response->assertOk()
            ->assertSee('Alpha Learner')
            ->assertSee('Bravo Learner')
            ->assertDontSee('Charlie Learner');
    }

    #[Test]
    public function section_filter_narrows_to_a_single_section(): void
    {
        $this->seedThreeSections();

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records?grade_level=Grade+7&section=Rizal');

        $response->assertOk()
            ->assertSee('Alpha Learner')
            ->assertDontSee('Bravo Learner')
            ->assertDontSee('Charlie Learner');
    }

    #[Test]
    public function grade_and_section_are_shown_as_separate_columns(): void
    {
        $this->makeStudent('Alpha Learner', 'Grade 7 / Rizal');

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records');

        // The column head says Grade, so the cell carries the number alone —
        // repeating the word on every row said nothing new.
        $response->assertOk()
            ->assertSee('Grade Level')
            ->assertSee('<td>7</td>', false)
            ->assertSee('<td>Rizal</td>', false);
    }

    #[Test]
    public function section_filter_is_dropped_when_it_does_not_belong_to_the_chosen_grade(): void
    {
        $this->seedThreeSections();

        // Rizal is a Grade 7 section; asking for it under Grade 8 must fall back
        // to "all sections of Grade 8" rather than returning an empty table.
        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records?grade_level=Grade+8&section=Rizal');

        $response->assertOk()
            ->assertSee('Charlie Learner')
            ->assertDontSee('Alpha Learner');
    }

    #[Test]
    public function an_unknown_grade_level_is_ignored_rather_than_emptying_the_page(): void
    {
        $this->seedThreeSections();

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records?grade_level=Grade+99');

        $response->assertOk()
            ->assertSee('Alpha Learner')
            ->assertSee('Charlie Learner');
    }

    #[Test]
    public function filter_options_are_offered_for_every_grade_and_the_chosen_grades_sections(): void
    {
        $this->seedThreeSections();

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records?grade_level=Grade+7');

        // Both grades stay selectable so the filter can always be undone, while
        // sections list only the ones inside Grade 7.
        $response->assertOk()
            ->assertSee('value="Grade 7" selected', false)
            ->assertSee('value="Grade 8"', false)
            ->assertSee('value="Rizal"', false)
            ->assertSee('value="Bonifacio"', false)
            ->assertDontSee('value="Mabini"', false);
    }

    #[Test]
    public function the_count_and_the_rows_follow_the_filter(): void
    {
        $this->seedThreeSections();

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records?grade_level=Grade+8');

        // One Grade 8 learner out of three overall. Grade and section are their
        // own columns now, so the row is read from those rather than from the
        // combined "Grade 8 / Mabini" string.
        $response->assertOk()
            ->assertSee('Showing 1 of 3 beneficiaries')
            ->assertSee('Charlie Learner')
            ->assertSee('<td>Mabini</td>', false)
            ->assertDontSee('Alpha Learner')
            ->assertDontSee('Bravo Learner');
    }

    #[Test]
    public function records_stay_scoped_to_the_active_school(): void
    {
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $this->makeStudent('Alpha Learner', 'Grade 7 / Rizal');

        StudentHealthRecord::create([
            'institution_id' => $other->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Outsider Learner',
            'student_id' => 'LRN999999',
            'school_name' => 'Other School',
            'section' => 'Grade 7 / Rizal',
            'weight' => 30,
            'bmi_value' => 17.2,
            'nutritional_status' => 'Wasted',
            'baseline_nutritional_status' => 'Wasted',
            'student_details' => ['gender' => 'Male'],
        ]);

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-health-records?grade_level=Grade+7');

        $response->assertOk()
            ->assertSee('Alpha Learner')
            ->assertDontSee('Outsider Learner');
    }
}
