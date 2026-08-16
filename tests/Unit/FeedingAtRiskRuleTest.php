<?php

namespace Tests\Unit;

use App\Support\FeedingAtRiskRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The at-risk flag decides which malnutrition-risk learners get followed up, so
 * this is the logic with real consequences if it is wrong. These tests pin three
 * things above all: an unconfirmed mark never votes, nutritional status never
 * enters the calculation (the rule only ever sees attendance marks), and a rate
 * taken over too few sessions classifies nobody.
 */
class FeedingAtRiskRuleTest extends TestCase
{
    /**
     * The threshold cases below are about the arithmetic, so they run with the
     * observation window switched off (0 = classify from the first confirmed
     * session). The window has its own tests further down.
     */
    private function rate(float $threshold = 75, int $minimumObservationDays = 0): FeedingAtRiskRule
    {
        return new FeedingAtRiskRule(FeedingAtRiskRule::MODE_ATTENDANCE_RATE, $threshold, 3, $minimumObservationDays);
    }

    private function consecutive(int $absences = 3): FeedingAtRiskRule
    {
        return new FeedingAtRiskRule(FeedingAtRiskRule::MODE_CONSECUTIVE_ABSENCES, 75, $absences, 0);
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

        // With a window set, the sentence carries it: staff reading why a
        // learner is not flagged must be able to see the second half of the rule.
        $this->assertSame(
            'attendance below 80%, after at least 10 recorded feeding days',
            $this->rate(80, 10)->describe()
        );
    }

    // ── The minimum observation period ──────────────────────────────────
    // A rate is arithmetic; a classification is a decision about a child. One
    // of four sessions is 25% and fails every threshold a school might set, but
    // four sessions is not a programme problem — it is a sample too small to
    // put anyone on a follow-up list over.

    #[Test]
    public function a_learner_inside_the_observation_window_is_never_flagged(): void
    {
        $rule = $this->rate(80, 10);

        // 1 of 4 = 25%, far under 80 — and still not a verdict, because four
        // recorded sessions is not ten.
        $this->assertFalse($rule->isAtRisk([true, false, false, false]));
        $this->assertSame(FeedingAtRiskRule::STATUS_EARLY_MONITORING, $rule->status([true, false, false, false]));
    }

    #[Test]
    public function the_rate_is_still_computed_inside_the_window(): void
    {
        // The window suppresses the classification, never the figure: a
        // coordinator must be able to see 25% and act on it as a human, even
        // though the rule will not flag it yet.
        $this->assertSame(25.0, $this->rate(80, 10)->attendanceRate([true, false, false, false]));
    }

    #[Test]
    public function the_threshold_applies_the_moment_the_window_is_met(): void
    {
        $rule = $this->rate(80, 10);
        $marks = [true, false, false, false, false, false, false, false, false];

        // Nine confirmed sessions: one short, so still unclassified.
        $this->assertFalse($rule->isAtRisk($marks));
        $this->assertSame(1, $rule->sessionsUntilClassification($marks));

        // The tenth confirmed session is what turns the rate into a verdict.
        $marks[] = false;
        $this->assertTrue($rule->isAtRisk($marks));
        $this->assertSame(0, $rule->sessionsUntilClassification($marks));
        $this->assertSame(FeedingAtRiskRule::STATUS_AT_RISK, $rule->status($marks));
    }

    #[Test]
    public function a_learner_past_the_window_and_above_the_threshold_is_on_track(): void
    {
        $rule = $this->rate(80, 10);
        $marks = array_fill(0, 10, true);

        $this->assertFalse($rule->isAtRisk($marks));
        $this->assertSame(FeedingAtRiskRule::STATUS_ON_TRACK, $rule->status($marks));
    }

    #[Test]
    public function the_window_counts_confirmed_sessions_not_rows(): void
    {
        $rule = $this->rate(80, 3);

        // Ten marks, but only two a human has confirmed. The window is about
        // evidence, and an unread scan is not evidence — so the learner is
        // still unclassified rather than judged on a photograph nobody read.
        $marks = array_merge([true, false], array_fill(0, 8, null));

        $this->assertSame(2, $rule->confirmedCount($marks));
        $this->assertFalse($rule->isAtRisk($marks));
        $this->assertSame(FeedingAtRiskRule::STATUS_EARLY_MONITORING, $rule->status($marks));
    }

    #[Test]
    public function a_school_can_waive_the_window(): void
    {
        // 0 and 1 both mean "classify from the first confirmed session" — a
        // school that wants the threshold to bite immediately can have it.
        foreach ([0, 1] as $window) {
            $rule = $this->rate(80, $window);

            $this->assertTrue($rule->isAtRisk([false]), 'window of '.$window);
            $this->assertSame(FeedingAtRiskRule::STATUS_AT_RISK, $rule->status([false]));
        }
    }

    #[Test]
    public function no_confirmed_session_is_never_classified_whatever_the_window(): void
    {
        // Waiving the observation period lets the threshold apply sooner; it
        // can never turn "no evidence" into evidence.
        $this->assertFalse($this->rate(80, 0)->isAtRisk([]));
        $this->assertFalse($this->rate(80, 0)->isAtRisk([null, null]));
        $this->assertSame(FeedingAtRiskRule::STATUS_EARLY_MONITORING, $this->rate(80, 0)->status([]));
    }

    #[Test]
    public function the_window_holds_the_consecutive_absence_rule_too(): void
    {
        // One rule, one window: a school must never see one learner classified
        // on three sessions and another held back on four.
        $rule = new FeedingAtRiskRule(FeedingAtRiskRule::MODE_CONSECUTIVE_ABSENCES, 75, 3, 10);

        $this->assertFalse($rule->isAtRisk([false, false, false]));
        $this->assertTrue($rule->isAtRisk(array_fill(0, 10, false)));
    }
}
