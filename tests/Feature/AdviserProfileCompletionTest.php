<?php

namespace Tests\Feature;

use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\FeedingProgramCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Completed Program" is closed out by the END of the feeding programme.
 *
 * The adviser's Complete filter used to key on the health assessment form
 * alone, so a learner could read Complete on day 3 of 120 with no closing
 * measurement anywhere. Now the bucket is empty while the cycle runs —
 * those learners fall to Pending Assessment — and fills once the 120 days
 * are done with the learners who actually finished: fed, and measured.
 *
 * Three conditions, all in App\Support\ProfileCompletionRule:
 *   1. the school's 120 feeding days are done,
 *   2. the learner has an endline reading,
 *   3. a learner the coordinator enrolled also has a confirmed session.
 *
 * (3) is conditional on purpose: only wasted and underweight Grade 7-10
 * learners are ever enrolled, so requiring it of everyone would leave a
 * healthy learner permanently incomplete however carefully their adviser
 * filled the card in.
 */
class AdviserProfileCompletionTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function adviserSession(): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Maria Santos',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
            'assigned_grade_level' => 'Grade 10',
            'assigned_section' => 'Dalton',
            'school_health_card_records' => [[
                'lrn' => '700000000001',
                'first_name' => 'Juan',
                'last_name' => 'Cruz',
                'grade_level' => 'Grade 10',
                'section' => 'Dalton',
            ]],
        ];
    }

    private function learner(array $overrides = []): StudentHealthRecord
    {
        return StudentHealthRecord::create(array_merge([
            'institution_id' => $this->school->id,
            'student_id' => '700000000001',
            'student_name' => 'Cruz, Juan',
            'school_name' => 'Sta. Ana NHS',
            'grade_level' => 'Grade 10',
            'section' => 'Grade 10 / Dalton',
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'weight' => '40',
            'bmi_value' => '15.2',
            'nutritional_status' => 'Wasted',
            'baseline_height_cm' => '140',
            'baseline_weight_kg' => '30',
            'baseline_bmi_value' => '15.2',
            'baseline_nutritional_status' => 'Wasted',
        ], $overrides));
    }

    /**
     * A finished cycle is 120 *feeding* days back — about 168 calendar days,
     * derived from the constant so the fixture cannot drift from it.
     */
    private function finishTheCycle(StudentHealthRecord $record): void
    {
        $calendarDays = (int) ceil(FeedingProgramCycle::DURATION_DAYS / 5 * 7) + 7;

        FeedingAttendance::create([
            'institution_id' => $this->school->id,
            'student_health_record_id' => $record->id,
            'session_date' => now()->subDays($calendarDays)->toDateString(),
            'is_present' => true,
        ]);
    }

    private function startTheCycleRecently(StudentHealthRecord $record): void
    {
        FeedingAttendance::create([
            'institution_id' => $this->school->id,
            'student_health_record_id' => $record->id,
            'session_date' => now()->subDays(10)->toDateString(),
            'is_present' => true,
        ]);
    }

    private function withEndline(StudentHealthRecord $record): StudentHealthRecord
    {
        $record->forceFill([
            'endline_age' => 15,
            'endline_height_cm' => '145',
            'endline_weight_kg' => '46',
            'endline_bmi_value' => '21.88',
            'endline_nutritional_status' => 'Normal',
        ])->save();

        return $record->fresh();
    }

    private function dashboard(): string
    {
        return $this->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser'))
            ->assertOk()
            ->getContent();
    }

    /** The status the learner's row renders with. */
    private function badgeStatus(string $html): string
    {
        $this->assertMatchesRegularExpression('/data-status="(complete|partial|pending)"/', $html);
        preg_match('/data-status="(complete|partial|pending)"/', $html, $m);

        return $m[1];
    }

    #[Test]
    public function mid_cycle_nobody_is_complete(): void
    {
        $record = $this->learner();
        $this->startTheCycleRecently($record);
        $this->withEndline($record);

        $html = $this->dashboard();

        $this->assertSame('pending', $this->badgeStatus($html));
        $this->assertStringContainsString('<b>0</b><span>Completed Program</span>', $html);
    }

    /** And the card says why the bucket is empty. */
    #[Test]
    public function mid_cycle_the_card_says_it_is_not_open_yet(): void
    {
        $record = $this->learner();
        $this->startTheCycleRecently($record);

        $html = $this->dashboard();

        $this->assertStringContainsString('Opens when the 120 feeding days are done', $html);
    }

    /** The badge names what is outstanding. */
    #[Test]
    public function the_badge_says_where_the_programme_is(): void
    {
        $record = $this->learner();
        $this->startTheCycleRecently($record);

        $html = $this->dashboard();

        $this->assertStringContainsString('title="Feeding programme is on day', $html);
    }

    #[Test]
    public function a_fed_and_measured_learner_is_complete_once_the_cycle_ends(): void
    {
        $record = $this->learner(['feeding_enrolled_at' => now()->subMonths(6)]);
        $this->finishTheCycle($record);
        $this->withEndline($record);

        $html = $this->dashboard();

        $this->assertSame('complete', $this->badgeStatus($html));
        $this->assertStringContainsString('<b>1</b><span>Completed Program</span>', $html);
        $this->assertStringContainsString('120 feeding days done, endline recorded', $html);
    }

    /** No closing measurement, no completion — even after the 120 days. */
    #[Test]
    public function a_learner_with_no_endline_is_not_complete(): void
    {
        $record = $this->learner(['feeding_enrolled_at' => now()->subMonths(6)]);
        $this->finishTheCycle($record);

        $html = $this->dashboard();

        $this->assertNotSame('complete', $this->badgeStatus($html));
        $this->assertStringContainsString('<b>0</b><span>Completed Program</span>', $html);
        $this->assertStringContainsString('title="Endline measurement not recorded"', $html);
    }

    /**
     * A beneficiary the programme never actually fed did not complete it.
     * The cycle running its length is the school's fact, not the learner's.
     */
    #[Test]
    public function an_enrolled_learner_who_was_never_fed_is_not_complete(): void
    {
        // The cycle is started by another learner's session, so this one has
        // an enrolment and an endline but no confirmed mark of their own.
        $other = StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'student_id' => '700000000002',
            'student_name' => 'Reyes, Ana',
            'school_name' => 'Sta. Ana NHS',
            'grade_level' => 'Grade 10',
            'section' => 'Grade 10 / Dalton',
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'weight' => '40',
            'bmi_value' => '18',
            'nutritional_status' => 'Normal',
        ]);
        $this->finishTheCycle($other);

        $record = $this->learner(['feeding_enrolled_at' => now()->subMonths(6)]);
        $this->withEndline($record);

        $html = $this->dashboard();

        $this->assertNotSame('complete', $this->badgeStatus($html));
        $this->assertStringContainsString('title="No confirmed feeding session on record"', $html);
    }

    /**
     * A learner the programme never enrolled has nothing to attend, so the
     * closing measurement is the whole test. Otherwise a healthy learner
     * could never be complete however carefully their card was filled in.
     */
    #[Test]
    public function a_learner_who_was_never_enrolled_needs_only_the_endline(): void
    {
        $other = StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'student_id' => '700000000002',
            'student_name' => 'Reyes, Ana',
            'school_name' => 'Sta. Ana NHS',
            'grade_level' => 'Grade 10',
            'section' => 'Grade 10 / Dalton',
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'weight' => '40',
            'bmi_value' => '18',
            'nutritional_status' => 'Normal',
        ]);
        $this->finishTheCycle($other);

        $record = $this->learner(['nutritional_status' => 'Normal']);
        $this->withEndline($record);

        $html = $this->dashboard();

        $this->assertSame('complete', $this->badgeStatus($html));
    }

    /**
     * An unconfirmed scanned mark proves nothing — it can neither complete a
     * learner nor hold one back on its own.
     */
    #[Test]
    public function an_unconfirmed_mark_does_not_count_as_having_been_fed(): void
    {
        $other = StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'student_id' => '700000000002',
            'student_name' => 'Reyes, Ana',
            'school_name' => 'Sta. Ana NHS',
            'grade_level' => 'Grade 10',
            'section' => 'Grade 10 / Dalton',
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'weight' => '40',
            'bmi_value' => '18',
            'nutritional_status' => 'Normal',
        ]);
        $this->finishTheCycle($other);

        $record = $this->learner(['feeding_enrolled_at' => now()->subMonths(6)]);
        $this->withEndline($record);

        FeedingAttendance::create([
            'institution_id' => $this->school->id,
            'student_health_record_id' => $record->id,
            'session_date' => now()->subDays(30)->toDateString(),
            'is_present' => null,
        ]);

        $html = $this->dashboard();

        $this->assertNotSame('complete', $this->badgeStatus($html));
    }

    /** A school that has never fed anybody has no cycle, so no completions. */
    #[Test]
    public function a_programme_that_never_started_completes_nobody(): void
    {
        $record = $this->learner();
        $this->withEndline($record);

        $html = $this->dashboard();

        $this->assertStringContainsString('<b>0</b><span>Completed Program</span>', $html);
        $this->assertStringContainsString('title="Feeding programme has not started"', $html);
    }

    /** The filter option names what the bucket now holds. */
    #[Test]
    public function the_filter_reads_completed_program(): void
    {
        $html = $this->dashboard();

        $this->assertStringContainsString('<option value="complete">Completed Program</option>', $html);
        $this->assertStringNotContainsString('<option value="complete">Complete Profile</option>', $html);

        // Pending Assessment is where a mid-cycle learner lands, and it stays.
        $this->assertStringContainsString('<option value="pending">Pending Assessment</option>', $html);
        $this->assertStringContainsString('<option value="partial">Partial Profile</option>', $html);
    }

    /**
     * The card and the list are one reading. A card saying 1 above a filter
     * showing 0 rows is how two screens start arguing about one class.
     */
    #[Test]
    public function the_card_and_the_row_agree(): void
    {
        $record = $this->learner(['feeding_enrolled_at' => now()->subMonths(6)]);
        $this->finishTheCycle($record);
        $this->withEndline($record);

        $html = $this->dashboard();

        $this->assertStringContainsString('<b>1</b><span>Completed Program</span>', $html);
        $this->assertSame(1, substr_count($html, 'data-status="complete"'));
    }
}
