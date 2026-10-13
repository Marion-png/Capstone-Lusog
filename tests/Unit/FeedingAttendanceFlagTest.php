<?php

namespace Tests\Unit;

use App\Support\FeedingAtRiskRule;
use App\Support\FeedingAttendanceFlag;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The attendance flow the capstone adviser set, pinned without a database:
 *
 *   day 3 of unexcused absence in a row → an attendance notice, nobody flagged
 *   day 7                                → awaiting confirmation, nobody flagged
 *   the coordinator confirms the record  → flagged for follow-up
 *   a Resolved follow-up                 → the flag closes, and the absences it
 *                                          covered cannot flag the learner again
 */
class FeedingAttendanceFlagTest extends TestCase
{
    private function rule(): FeedingAtRiskRule
    {
        // The adviser's figures: a notice at 3, the flag threshold at 7.
        return new FeedingAtRiskRule(FeedingAtRiskRule::MODE_UNEXCUSED_ABSENCE_DAYS, 80, 3, 10, 7, 3);
    }

    /**
     * Marks on consecutive school days from 2026-08-03, a Monday.
     *
     * @param  list<bool|null>  $marks
     * @return list<array{date: string, mark: bool|null}>
     */
    private function dated(array $marks): array
    {
        $day = new \DateTimeImmutable('2026-08-03');
        $out = [];

        foreach ($marks as $mark) {
            while ((int) $day->format('N') >= 6) {
                $day = $day->modify('+1 day');
            }
            $out[] = ['date' => $day->format('Y-m-d'), 'mark' => $mark];
            $day = $day->modify('+1 day');
        }

        return $out;
    }

    private function openFlag(): array
    {
        return ['stage' => 'flag', 'confirmed_on' => '2026-08-20', 'date_label' => 'Aug 20, 2026', 'by' => 'Coordinator', 'absences' => 7, 'run_started_on' => null, 'confirmed_through' => null];
    }

    #[Test]
    public function two_unexcused_absences_in_a_row_raise_nothing(): void
    {
        $standing = FeedingAttendanceFlag::evaluate($this->dated([true, true, false, false]), $this->rule());

        $this->assertSame(FeedingAttendanceFlag::ON_TRACK, $standing['state']);
        $this->assertFalse($standing['needs_action']);
        $this->assertFalse($standing['flagged']);
    }

    #[Test]
    public function day_three_raises_an_attendance_notice_and_flags_nobody(): void
    {
        $standing = FeedingAttendanceFlag::evaluate($this->dated([true, false, false, false]), $this->rule());

        $this->assertSame(FeedingAttendanceFlag::NOTICE, $standing['state']);
        $this->assertSame(3, $standing['run']);
        $this->assertTrue($standing['needs_action']);
        $this->assertFalse($standing['flagged']);
        $this->assertFalse($standing['threshold_reached']);
        // The notice is about exactly the absences in the run.
        $this->assertCount(3, $standing['evidence']);
        $this->assertSame($standing['run_started_on'], $standing['evidence'][0]);
    }

    #[Test]
    public function a_verified_notice_no_longer_asks_for_action_and_still_flags_nobody(): void
    {
        $marks = $this->dated([true, false, false, false]);
        $runStart = $marks[1]['date'];

        $standing = FeedingAttendanceFlag::evaluate($marks, $this->rule(), ['verified_runs' => [$runStart]]);

        $this->assertSame(FeedingAttendanceFlag::NOTICE, $standing['state']);
        $this->assertTrue($standing['notice_verified']);
        $this->assertFalse($standing['needs_action']);
        $this->assertFalse($standing['flagged']);
    }

    #[Test]
    public function a_verification_covers_its_own_run_and_never_the_next_one(): void
    {
        // Verified a run that has since ended; a new run of three is a new notice.
        $marks = $this->dated([false, false, false, true, false, false, false]);
        $oldRun = $marks[0]['date'];

        $standing = FeedingAttendanceFlag::evaluate($marks, $this->rule(), ['verified_runs' => [$oldRun]]);

        $this->assertSame(FeedingAttendanceFlag::NOTICE, $standing['state']);
        $this->assertFalse($standing['notice_verified']);
        $this->assertTrue($standing['needs_action']);
    }

    #[Test]
    public function day_seven_awaits_the_coordinators_confirmation_and_flags_nobody(): void
    {
        $standing = FeedingAttendanceFlag::evaluate($this->dated(array_fill(0, 7, false)), $this->rule());

        $this->assertSame(FeedingAttendanceFlag::AWAITING_CONFIRMATION, $standing['state']);
        $this->assertTrue($standing['threshold_reached']);
        $this->assertTrue($standing['needs_action']);
        $this->assertFalse($standing['flagged'], 'Reaching day 7 is never a flag without the coordinator\'s confirmation.');
        $this->assertCount(7, $standing['evidence']);
    }

    #[Test]
    public function a_verified_notice_does_not_stand_in_for_the_confirmation_at_day_seven(): void
    {
        $marks = $this->dated(array_fill(0, 7, false));

        $standing = FeedingAttendanceFlag::evaluate($marks, $this->rule(), ['verified_runs' => [$marks[0]['date']]]);

        $this->assertSame(FeedingAttendanceFlag::AWAITING_CONFIRMATION, $standing['state']);
        $this->assertFalse($standing['flagged']);
    }

    #[Test]
    public function only_a_confirmation_flags_a_learner_for_follow_up(): void
    {
        $standing = FeedingAttendanceFlag::evaluate(
            $this->dated(array_fill(0, 7, false)),
            $this->rule(),
            ['open' => $this->openFlag()]
        );

        $this->assertSame(FeedingAttendanceFlag::FLAGGED, $standing['state']);
        $this->assertTrue($standing['flagged']);
        $this->assertSame('Flagged for Follow-Up', $standing['label']);
        $this->assertSame('Coordinator', $standing['confirmation']['by']);
    }

    #[Test]
    public function a_flag_stays_until_it_is_resolved_even_after_the_learner_returns(): void
    {
        // Back at the feeding line the day after — still a learner somebody has
        // to follow up, because the coordinator confirmed a week of absence.
        $marks = $this->dated(array_merge(array_fill(0, 7, false), [true, true]));

        $standing = FeedingAttendanceFlag::evaluate($marks, $this->rule(), ['open' => $this->openFlag()]);

        $this->assertTrue($standing['flagged']);
    }

    #[Test]
    public function a_resolved_flag_does_not_return_on_the_same_absences(): void
    {
        $marks = $this->dated(array_merge(array_fill(0, 7, false), [true, true]));
        $cutoff = $marks[6]['date'];

        $standing = FeedingAttendanceFlag::evaluate($marks, $this->rule(), ['cutoff' => $cutoff]);

        $this->assertSame(FeedingAttendanceFlag::ON_TRACK, $standing['state']);
        $this->assertFalse($standing['flagged']);
        $this->assertFalse($standing['needs_action']);
    }

    #[Test]
    public function a_fresh_run_after_a_resolved_flag_starts_the_flow_again_from_day_one(): void
    {
        $first = array_fill(0, 7, false);
        $marks = $this->dated(array_merge($first, [true], array_fill(0, 3, false)));
        $cutoff = $marks[6]['date'];

        $standing = FeedingAttendanceFlag::evaluate($marks, $this->rule(), ['cutoff' => $cutoff]);

        $this->assertSame(FeedingAttendanceFlag::NOTICE, $standing['state']);
        $this->assertSame(3, $standing['run']);

        $marks = $this->dated(array_merge($first, [true], array_fill(0, 7, false)));
        $standing = FeedingAttendanceFlag::evaluate($marks, $this->rule(), ['cutoff' => $cutoff]);

        $this->assertSame(FeedingAttendanceFlag::AWAITING_CONFIRMATION, $standing['state']);
    }

    #[Test]
    public function a_learner_who_came_back_before_the_confirmation_still_awaits_it(): void
    {
        // Seven days gone, back on the eighth: the record still has to be
        // confirmed — or corrected — before the week is forgotten.
        $standing = FeedingAttendanceFlag::evaluate(
            $this->dated(array_merge(array_fill(0, 7, false), [true])),
            $this->rule()
        );

        $this->assertSame(FeedingAttendanceFlag::AWAITING_CONFIRMATION, $standing['state']);
        $this->assertSame(0, $standing['run']);
        $this->assertCount(7, $standing['evidence']);
    }

    #[Test]
    public function an_excused_or_unconfirmed_mark_neither_extends_nor_breaks_a_run(): void
    {
        // NULL is excused or unread: three unexcused absences around it are
        // still day 3, never day 4, and never broken back to zero.
        $standing = FeedingAttendanceFlag::evaluate($this->dated([true, false, null, false, false]), $this->rule());

        $this->assertSame(FeedingAttendanceFlag::NOTICE, $standing['state']);
        $this->assertSame(3, $standing['run']);
    }

    #[Test]
    public function excused_absences_alone_never_raise_a_notice(): void
    {
        $standing = FeedingAttendanceFlag::evaluate($this->dated([true, null, null, null, null, null, null, null]), $this->rule());

        $this->assertSame(FeedingAttendanceFlag::ON_TRACK, $standing['state']);
        $this->assertSame(0, $standing['run']);
    }

    #[Test]
    public function a_rate_school_also_waits_for_the_confirmation(): void
    {
        $rule = new FeedingAtRiskRule(FeedingAtRiskRule::MODE_ATTENDANCE_RATE, 80, 3, 0, 7, 3);
        // 2 of 5 = 40%, below 80 — but the last mark is a present one.
        $marks = $this->dated([true, false, true, false, false]);
        $marks[] = ['date' => '2026-08-10', 'mark' => true];

        $standing = FeedingAttendanceFlag::evaluate($marks, $rule);

        $this->assertSame(FeedingAttendanceFlag::AWAITING_CONFIRMATION, $standing['state']);
        $this->assertFalse($standing['flagged']);
    }

    #[Test]
    public function no_confirmed_session_is_early_monitoring_never_a_notice(): void
    {
        $standing = FeedingAttendanceFlag::evaluate([], $this->rule());

        $this->assertSame(FeedingAttendanceFlag::EARLY_MONITORING, $standing['state']);
        $this->assertFalse($standing['needs_action']);
    }

    #[Test]
    public function only_the_feeding_coordinator_and_the_school_nurse_see_a_flag(): void
    {
        $this->assertTrue(FeedingAttendanceFlag::visibleTo('feeding_coor'));
        $this->assertTrue(FeedingAttendanceFlag::visibleTo('school_nurse'));

        foreach (['clinic_teacher', 'class_adviser', 'clinic_staff', 'school_head', 'nutricor', 'system_admin', '', null] as $role) {
            $this->assertFalse(FeedingAttendanceFlag::visibleTo($role), 'role '.var_export($role, true));
        }
    }

    #[Test]
    public function the_awaiting_confirmation_comes_first_on_the_work_list(): void
    {
        $awaiting = FeedingAttendanceFlag::evaluate($this->dated(array_fill(0, 7, false)), $this->rule());
        $notice = FeedingAttendanceFlag::evaluate($this->dated([true, false, false, false]), $this->rule());

        $this->assertSame(FeedingAttendanceFlag::PRIORITY_HIGH, $awaiting['priority']);
        $this->assertSame(FeedingAttendanceFlag::PRIORITY_MEDIUM, $notice['priority']);
        $this->assertLessThan(
            FeedingAttendanceFlag::stateRank(FeedingAttendanceFlag::FLAGGED),
            FeedingAttendanceFlag::stateRank(FeedingAttendanceFlag::AWAITING_CONFIRMATION)
        );
    }
}
