<?php

namespace App\Support;

use App\Models\StudentHealthRecord;
use Illuminate\Support\Collection;

/**
 * The baseline-to-endline write-up, derived from the figures rather than typed
 * out beside them.
 *
 * The coordinator writes this narrative by hand every cycle — "X% increase from
 * baseline to endline" — reading it off the very reports this application
 * already computes, and re-typing a figure is how a report ends up disagreeing
 * with the table above it. So the paragraphs are generated from
 * `FeedingNutritionProgress`, `FeedingBeneficiarySummary` and
 * `FeedingProgramCycle`: the same three sources the Dashboard and the SBFP
 * forms read.
 *
 * Two things this deliberately is not:
 *
 * - **It is not a conclusion.** Every sentence reports a count, a rate or a
 *   date that is on file. Nothing here interprets a child's health, attributes
 *   a change to the programme, or predicts anything. Improvement is stated as
 *   the movement up the wasting scale that it is, and against a denominator of
 *   every beneficiary, so the paragraph cannot read better than the panel.
 * - **It is not the final text.** It fills the narrative form as a starting
 *   draft; the coordinator edits it, and a draft already saved is never
 *   overwritten by it, because hand-written work outranks a re-fill.
 *
 * A figure nobody has recorded is said to be missing rather than given as
 * zero — "no endline weighing has been recorded yet" is the honest sentence,
 * and "0% improved" is not.
 */
final class FeedingReportNarrative
{
    /**
     * The five sections of the narrative form, keyed by its own field names.
     *
     * @param  Collection<int, StudentHealthRecord>  $records  the school's roll for the year
     * @return array<string, string>
     */
    public static function build(
        Collection $records,
        string $schoolName,
        string $schoolYear,
        FeedingProgramCycle $cycle,
        FeedingAtRiskRule $rule,
    ): array {
        $beneficiaries = $records
            ->filter(fn (StudentHealthRecord $record): bool => FeedingBeneficiarySummary::isBeneficiary($record))
            ->values();

        $progress = FeedingNutritionProgress::build(
            $beneficiaries,
            fn (StudentHealthRecord $r): string => self::scaleStatus((string) ($r->baseline_nutritional_status ?: $r->nutritional_status)),
            fn (StudentHealthRecord $r): string => self::scaleStatus((string) $r->endline_nutritional_status),
        );

        $tally = FeedingBeneficiarySummary::tally($beneficiaries, $rule);
        $school = $schoolName !== '' ? $schoolName : 'the school';
        $year = str_replace('-', '–', $schoolYear);

        return [
            'narr_introduction' => self::introduction($school, $year, $progress['total'], $cycle),
            'narr_background' => self::background($school, $progress, $tally),
            'narr_implementation' => self::implementation($cycle, $tally, $rule),
            'narr_results' => self::results($progress),
            'narr_conclusion' => self::conclusion($progress, $tally, $rule),
        ];
    }

    /**
     * The wasting scale as FeedingNutritionProgress ranks it.
     *
     * The adviser's non-standard "Underweight" folds into Wasted, exactly as
     * the DepEd BMI sheets do and as every other coordinator surface does, so
     * the narrative counts the same learners the grid counts.
     */
    private static function scaleStatus(string $status): string
    {
        $normalized = FeedingBeneficiarySummary::normalize($status);

        return match ($normalized) {
            'Severely Wasted' => 'Severely Wasted',
            'Wasted', 'Underweight' => 'Wasted',
            'Normal' => 'Normal',
            'Overweight', 'Obese' => FeedingNutritionProgress::ABOVE_NORMAL,
            default => '',
        };
    }

    private static function introduction(string $school, string $year, int $total, FeedingProgramCycle $cycle): string
    {
        $sentence = $school.' implemented the School-Based Feeding Program (SBFP) for School Year '.$year
            .' for '.self::count($total, 'enrolled beneficiary', 'enrolled beneficiaries').'.';

        if (! $cycle->hasStarted()) {
            return $sentence.' No feeding session has been recorded yet, so the cycle has not started.';
        }

        return $sentence.' The cycle runs for '.$cycle->durationDays().' feeding days and is currently on day '
            .$cycle->day().' ('.self::percent($cycle->percent()).'% elapsed).';
    }

    /**
     * @param  array<string, mixed>  $progress
     * @param  array<string, mixed>  $tally
     */
    private static function background(string $school, array $progress, array $tally): string
    {
        $rows = collect($progress['rows'])->keyBy('label');
        $severely = (int) ($rows['Severely Wasted']['baseline'] ?? 0);
        $wasted = (int) ($rows['Wasted']['baseline'] ?? 0);

        return 'Beneficiaries were identified from the class advisers\' baseline weighing. At baseline, '
            .self::count($severely, 'beneficiary was', 'beneficiaries were', 'were')
            .' classified Severely Wasted and '
            .self::count($wasted, 'was', 'were', 'were').' Wasted (the report groups the adviser\'s '
            .'"Underweight" reading under Wasted, as the DepEd assessment sheet does). '
            .$school.' enrolled '.self::count((int) $tally['beneficiaries'], 'of them', 'of them', 'of them')
            .' into the programme.';
    }

    /**
     * @param  array<string, mixed>  $tally
     */
    private static function implementation(FeedingProgramCycle $cycle, array $tally, FeedingAtRiskRule $rule): string
    {
        // `attendance_sessions` counts confirmed MARKS across the roll, not
        // feeding days — it is the denominator the rate was taken over, and
        // calling it "sessions" in a sentence would misstate it.
        $marks = (int) ($tally['attendance_sessions'] ?? 0);
        $rate = $tally['attendance_rate'] ?? null;

        if ($marks === 0 || $rate === null) {
            return 'No feeding attendance has been confirmed for this cycle yet, so no attendance figure can be reported.';
        }

        $sentence = 'The cycle has run to feeding day '.$cycle->day().' of '.$cycle->durationDays()
            .'. Cumulative attendance across the enrolled roll stands at '.self::percent((float) $rate).'%, taken over '
            .self::count($marks, 'confirmed attendance mark', 'confirmed attendance marks')
            .' — an excused absence and a mark nobody has confirmed are excluded from both sides of the figure.';

        $atRisk = (int) ($tally['at_risk'] ?? 0);

        return $sentence.' '.($atRisk === 0
            ? 'No beneficiary currently meets the school\'s at-risk rule ('.$rule->describe().').'
            : self::count($atRisk, 'beneficiary is', 'beneficiaries are', 'are').' currently flagged at risk under the school\'s rule ('
                .$rule->describe().').');
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    private static function results(array $progress): string
    {
        $total = (int) $progress['total'];
        $measured = (int) $progress['measured'];

        if ($total === 0) {
            return 'No learner is enrolled in the programme for this school year, so there is no result to report.';
        }

        if ($measured === 0) {
            return 'The endline weighing has not been recorded for any of the '
                .self::count($total, 'beneficiary', 'beneficiaries')
                .', so no baseline-to-endline comparison can be made yet.';
        }

        $rows = collect($progress['rows'])->keyBy('label');

        return self::count($measured, 'of the '.$total.' beneficiary has', 'of the '.$total.' beneficiaries have', 'have')
            .' been re-measured at endline. '
            .$progress['improved'].' improved on the wasting scale, '
            .$progress['unchanged'].' held the same classification and '
            .$progress['declined'].' declined, giving an improvement rate of '
            .self::percent((float) $progress['rate']).'% of all enrolled beneficiaries. '
            .'Severely Wasted moved from '.(int) ($rows['Severely Wasted']['baseline'] ?? 0).' to '
            .(int) ($rows['Severely Wasted']['endline'] ?? 0).', Wasted from '
            .(int) ($rows['Wasted']['baseline'] ?? 0).' to '.(int) ($rows['Wasted']['endline'] ?? 0)
            .', and Normal from '.(int) ($rows['Normal']['baseline'] ?? 0).' to '
            .(int) ($rows['Normal']['endline'] ?? 0).'.';
    }

    /**
     * @param  array<string, mixed>  $progress
     * @param  array<string, mixed>  $tally
     */
    private static function conclusion(array $progress, array $tally, FeedingAtRiskRule $rule): string
    {
        $total = (int) $progress['total'];
        $outstanding = max(0, $total - (int) $progress['measured']);

        $lines = [];

        if ((int) $progress['measured'] > 0) {
            $lines[] = 'The programme recorded an improvement rate of '.self::percent((float) $progress['rate'])
                .'% across all enrolled beneficiaries.';
        }

        if ($outstanding > 0) {
            $lines[] = self::count($outstanding, 'beneficiary has', 'beneficiaries have', 'have')
                .' still to be weighed at endline; the figures above will move once they are.';
        }

        if ((int) ($tally['at_risk'] ?? 0) > 0) {
            $lines[] = 'Attendance follow-up remains outstanding for '
                .self::count((int) $tally['at_risk'], 'beneficiary', 'beneficiaries')
                .' under the school\'s rule ('.$rule->describe().').';
        }

        // Never an empty section: a form with a blank conclusion reads as an
        // omission, and "nothing outstanding" is a real thing to report.
        if ($lines === []) {
            $lines[] = 'There is not yet enough recorded data to draw a conclusion for this cycle.';
        }

        return implode(' ', $lines);
    }

    private static function count(int $n, string $singular, string $plural, ?string $zero = null): string
    {
        if ($n === 0 && $zero !== null) {
            return 'no beneficiaries '.$zero;
        }

        return $n.' '.($n === 1 ? $singular : $plural);
    }

    private static function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.');
    }
}
