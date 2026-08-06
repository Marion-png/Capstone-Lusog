<?php

namespace Tests\Unit;

use App\Support\FeedingAtRiskRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The at-risk flag decides which malnutrition-risk learners get followed up, so
 * this is the logic with real consequences if it is wrong. These tests pin two
 * things above all: an unconfirmed mark never votes, and nutritional status
 * never enters the calculation (the rule only ever sees attendance marks).
 */
class FeedingAtRiskRuleTest extends TestCase
{
    private function rate(float $threshold = 75): FeedingAtRiskRule
    {
        return new FeedingAtRiskRule(FeedingAtRiskRule::MODE_ATTENDANCE_RATE, $threshold, 3);
    }

    private function consecutive(int $absences = 3): FeedingAtRiskRule
    {
        return new FeedingAtRiskRule(FeedingAtRiskRule::MODE_CONSECUTIVE_ABSENCES, 75, $absences);
    }

    #[Test]
    public function a_learner_below_the_rate_threshold_is_flagged(): void
    {
        // 2 of 4 = 50%, under 75.
        $this->assertTrue($this->rate()->isAtRisk([true, false, true, false]));
    }

    #[Test]
    public function a_learner_at_the_threshold_is_not_flagged(): void
    {
        // 3 of 4 = exactly 75%; the rule is "below", not "at or below".
        $this->assertFalse($this->rate()->isAtRisk([true, true, true, false]));
    }

    #[Test]
    public function perfect_attendance_is_never_flagged(): void
    {
        $this->assertFalse($this->rate()->isAtRisk([true, true, true, true]));
    }

    #[Test]
    public function a_learner_with_no_sessions_on_file_is_not_flagged(): void
    {
        // No attendance yet must never mean at-risk — otherwise every learner
        // is flagged the moment they are enrolled.
        $this->assertFalse($this->rate()->isAtRisk([]));
    }

    #[Test]
    public function unconfirmed_marks_alone_never_flag_a_learner(): void
    {
        // An entire sheet nobody could read is not evidence of absence.
        $this->assertFalse($this->rate()->isAtRisk([null, null, null, null]));
    }

    #[Test]
    public function unconfirmed_marks_are_excluded_from_the_rate(): void
    {
        // Confirmed: 3 present, 1 absent = 75% → not flagged.
        // If the two NULLs were counted as absences it would be 50% → flagged.
        $this->assertFalse($this->rate()->isAtRisk([true, true, true, false, null, null]));
        $this->assertSame(75.0, $this->rate()->attendanceRate([true, true, true, false, null, null]));
    }

    #[Test]
    public function unconfirmed_marks_cannot_rescue_a_learner_who_is_genuinely_below(): void
    {
        // Confirmed: 1 of 4 = 25%. Treating NULLs as attendance would hide it.
        $this->assertTrue($this->rate()->isAtRisk([true, false, false, false, null, null]));
    }

    #[Test]
    public function confirming_an_unclear_mark_can_flip_the_verdict(): void
    {
        $marks = [true, true, false, null];

        // Unresolved: 2 of 3 confirmed = 66.7% → already flagged.
        $this->assertTrue($this->rate()->isAtRisk($marks));

        // Confirmed present: 3 of 4 = 75% → clears.
        $marks[3] = true;
        $this->assertFalse($this->rate()->isAtRisk($marks));
    }

    #[Test]
    public function the_rate_threshold_is_configurable(): void
    {
        $marks = [true, true, true, false]; // 75%

        $this->assertFalse($this->rate(75)->isAtRisk($marks));
        $this->assertTrue($this->rate(80)->isAtRisk($marks));
        $this->assertFalse($this->rate(50)->isAtRisk($marks));
    }

    #[Test]
    public function any_single_absence_is_expressible_as_a_threshold(): void
    {
        // The spec's alternative default: 100% required means one miss flags.
        $rule = $this->rate(100);

        $this->assertTrue($rule->isAtRisk([true, true, true, false]));
        $this->assertFalse($rule->isAtRisk([true, true, true, true]));
    }

    #[Test]
    public function consecutive_mode_flags_a_run_of_absences(): void
    {
        $this->assertTrue($this->consecutive(3)->isAtRisk([true, false, false, false, true]));
    }

    #[Test]
    public function consecutive_mode_ignores_scattered_absences(): void
    {
        // Same total absences, never three in a row.
        $this->assertFalse($this->consecutive(3)->isAtRisk([false, true, false, true, false]));
    }

    #[Test]
    public function consecutive_mode_resets_the_run_on_attendance(): void
    {
        $this->assertFalse($this->consecutive(3)->isAtRisk([false, false, true, false, false]));
    }

    #[Test]
    public function consecutive_mode_skips_unconfirmed_marks_without_breaking_a_run(): void
    {
        // An unreadable mark mid-streak must not mask a genuine run of three.
        $this->assertTrue($this->consecutive(3)->isAtRisk([false, false, null, false]));
    }

    #[Test]
    public function present_count_counts_only_confirmed_attendance(): void
    {
        $this->assertSame(2, $this->rate()->presentCount([true, true, false, null]));
    }

    #[Test]
    public function attendance_rate_is_null_when_nothing_is_confirmed(): void
    {
        $this->assertNull($this->rate()->attendanceRate([null, null]));
        $this->assertNull($this->rate()->attendanceRate([]));
    }

    #[Test]
    public function the_rule_describes_itself_for_staff(): void
    {
        $this->assertSame('attendance below 75%', $this->rate(75)->describe());
        $this->assertSame('3 or more absences in a row', $this->consecutive(3)->describe());
    }
}
