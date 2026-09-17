<?php

namespace App\Support;

use App\Models\StudentHealthRecord;

/**
 * The feeding programme's outlook, projected from its own completed cycles.
 *
 * Two questions a School Head is asked and has had to answer from memory:
 * how many learners will the next cycle have to feed (the budget request), and
 * is the programme working (the effectiveness review). Both are trends across
 * school years — beneficiaries enrolled, the share that improved — and both
 * are read here from the same `SchoolHeadOverview` every other report reads,
 * so the history this projects from is the history the Reports tab prints.
 *
 * **It projects only once there is history to project from.** A cycle counts
 * as completed when it is a *prior* school year with at least one endline on
 * record — the running year is still being measured and would drag every line
 * down. Below `MIN_CYCLES` the outlook reports how many more it needs rather
 * than drawing a trend through one point: a chart with a line on it reads as
 * evidence, and a guess dressed as evidence is what a budget request must not
 * carry. The projection is a least-squares line through the completed cycles,
 * the plainest fit there is, and it is labelled as a projection everywhere it
 * is printed.
 *
 * No peso figure is compiled in. The projected feeding days are the roll times
 * the school's own cycle length (`FeedingProgramCycle::durationForInstitution`),
 * and the Division's per-meal rate is applied by the reader — it changes by
 * memorandum, and a rate typed here would be the wrong one within a year.
 */
class FeedingProgramForecast
{
    /** Completed cycles needed before a projection is drawn. */
    public const MIN_CYCLES = 2;

    /**
     * @return array{
     *   ready: bool,
     *   completed: int,
     *   needed: int,
     *   history: list<array{school_year: string, beneficiaries: int, measured: int, improved: int, improved_rate: float|null, still_wasted: int, completed: bool}>,
     *   projection: array{school_year: string, beneficiaries: int, improved_rate: float|null, feeding_days: int, meals: int, beneficiaries_trend: string, improvement_trend: string}|null
     * }
     */
    public static function for(?int $institutionId): array
    {
        $current = StudentHealthRecord::currentSchoolYear();
        $years = SchoolHeadOverview::schoolYears($institutionId);
        sort($years);

        $history = [];
        foreach ($years as $year) {
            $overview = SchoolHeadOverview::for($institutionId, $year);
            $outcome = $overview->outcome();

            // A prior year with an endline on record is a finished cycle. The
            // running year is history-in-progress and is listed, never fitted.
            $completed = $year < $current && $outcome['measured'] > 0;

            $history[] = [
                'school_year' => $year,
                'beneficiaries' => (int) $outcome['beneficiaries'],
                'measured' => (int) $outcome['measured'],
                'improved' => (int) $outcome['improved'],
                'improved_rate' => $outcome['improved_rate'],
                'still_wasted' => (int) $outcome['still_wasted'],
                'completed' => $completed,
            ];
        }

        $fitted = array_values(array_filter($history, fn (array $row): bool => $row['completed']));
        $completedCount = count($fitted);
        $ready = $completedCount >= self::MIN_CYCLES;

        return [
            'ready' => $ready,
            'completed' => $completedCount,
            'needed' => max(0, self::MIN_CYCLES - $completedCount),
            'history' => $history,
            'projection' => $ready ? self::project($fitted, $current, $institutionId) : null,
        ];
    }

    /**
     * The next cycle, on a straight line through the completed ones.
     *
     * @param  list<array<string, mixed>>  $fitted
     * @return array<string, mixed>
     */
    private static function project(array $fitted, string $current, ?int $institutionId): array
    {
        $x = range(0, count($fitted) - 1);
        $next = count($fitted);

        $beneficiaries = self::trend($x, array_map(fn (array $r): float => (float) $r['beneficiaries'], $fitted), $next);
        $rates = array_map(fn (array $r): float => (float) ($r['improved_rate'] ?? 0.0), $fitted);
        $improvedRate = self::trend($x, $rates, $next);

        $projectedBeneficiaries = max(0, (int) round($beneficiaries['value']));
        $projectedRate = round(max(0.0, min(100.0, $improvedRate['value'])), 1);
        $feedingDays = FeedingProgramCycle::durationForInstitution($institutionId);

        return [
            'school_year' => self::nextSchoolYear($current),
            'beneficiaries' => $projectedBeneficiaries,
            'improved_rate' => $projectedRate,
            'feeding_days' => $feedingDays,
            // One learner, one meal, one feeding day: the figure the Division's
            // per-meal rate is applied to.
            'meals' => $projectedBeneficiaries * $feedingDays,
            'beneficiaries_trend' => $beneficiaries['direction'],
            'improvement_trend' => $improvedRate['direction'],
        ];
    }

    /**
     * Least-squares line through (x, y), read at $at, with the slope's sign as
     * a word. Two points make a line; a flat one is reported as such rather
     * than as a rise of a hundredth.
     *
     * @param  list<int>  $x
     * @param  list<float>  $y
     * @return array{value: float, slope: float, direction: string}
     */
    private static function trend(array $x, array $y, int $at): array
    {
        $n = count($x);
        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;

        $sxx = 0.0;
        $sxy = 0.0;
        foreach ($x as $i => $xi) {
            $sxx += ($xi - $meanX) ** 2;
            $sxy += ($xi - $meanX) * ($y[$i] - $meanY);
        }

        $slope = $sxx > 0 ? $sxy / $sxx : 0.0;
        $intercept = $meanY - $slope * $meanX;

        return [
            'value' => $intercept + $slope * $at,
            'slope' => $slope,
            'direction' => match (true) {
                abs($slope) < 0.5 => 'steady',
                $slope > 0 => 'rising',
                default => 'falling',
            },
        ];
    }

    /** "2026-2027" → "2027-2028". */
    public static function nextSchoolYear(string $schoolYear): string
    {
        if (preg_match('/^(\d{4})-(\d{4})$/', $schoolYear, $m) === 1) {
            return ((int) $m[1] + 1).'-'.((int) $m[2] + 1);
        }

        return $schoolYear;
    }
}
