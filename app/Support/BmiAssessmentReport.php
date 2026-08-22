<?php

namespace App\Support;

use App\Models\StudentHealthRecord;
use Illuminate\Support\Collection;

/**
 * The DepEd Nutritional Assessment grid — learners counted by grade, sex,
 * BMI-for-age and height-for-age.
 *
 * This is the form the school actually submits, so it is computed in exactly
 * one place and read by everything that renders it: the Feeding Coordinator's
 * SBFP Forms page (`partials/bmi-table`, which prints it) and the School Head's
 * report export (which writes the same grid into a workbook). A second
 * implementation would mean the printed form and the exported one could report
 * different numbers for the same school and school year, which is the whole
 * failure this class exists to prevent.
 *
 * Nothing here is hand-entered. Every cell is derived at read time from what
 * the class adviser measured and the school nurse examined:
 *
 *   sex                → student_details.gender (encrypted)
 *   baseline BMI-for-age → baseline_nutritional_status, else nutritional_status
 *   baseline HFA       → student_details.nutritional_status_height_for_age
 *   final BMI-for-age  → endline_nutritional_status
 *   final HFA          → recomputed from endline height and age (it is not stored)
 *
 * Because all of those are encrypted at rest, the counting runs in PHP over
 * fetched rows — never as a SQL GROUP BY.
 *
 * Two shapes of the truth are deliberately kept:
 *
 * - The adviser's classifier emits "Underweight", which the DepEd sheet has no
 *   column for, so it is counted under Wasted — the same fold the coordinator's
 *   cards and the head's reports use.
 * - A learner with no sex on file cannot sit in the MALE or FEMALE row, and a
 *   reading nobody took is counted nowhere. Neither is invented into a zero.
 */
final class BmiAssessmentReport
{
    /** The BMI-for-age columns, in the order the printed sheet rules them. */
    public const NS_COLUMNS = [
        'sw' => 'Severely Wasted',
        'w' => 'Wasted',
        'n' => 'Normal',
        'ow' => 'Overweight',
        'ob' => 'Obese',
    ];

    /** The height-for-age columns, likewise. */
    public const HFA_COLUMNS = [
        'ss' => 'Severely Stunted',
        'st' => 'Stunted',
        'hn' => 'Normal',
        't' => 'Tall',
    ];

    /** The two weighings the sheet exists in, and the field prefix each uses. */
    public const PHASES = ['baseline' => 'bmib', 'endline' => 'bmif'];

    /** The most gridlines a count axis is drawn with, the baseline excluded. */
    private const AXIS_INTERVALS = 4;

    /** The rows of each grid. TOTAL is a sum, never an entry. */
    public const SEX_ROWS = ['male' => 'MALE', 'female' => 'FEMALE', 'total' => 'TOTAL'];

    /**
     * Every cell of every grid, flattened to the field keys the Blade partial
     * and the export both address: "{phase}_{grade}_{sex}_{column}".
     *
     * @param  Collection<int, StudentHealthRecord>  $records
     * @return array<string, int|string>
     */
    public static function values(Collection $records): array
    {
        $gradeKeys = self::gradeKeys();
        $sexes = ['male', 'female'];
        $nsCols = array_keys(self::NS_COLUMNS);
        $hfaCols = array_keys(self::HFA_COLUMNS);

        // counts[phase][gradeKey][sex][column]
        $counts = [];
        foreach (self::PHASES as $phase) {
            foreach ($gradeKeys as $gk) {
                foreach ($sexes as $sex) {
                    foreach (array_merge($nsCols, $hfaCols) as $col) {
                        $counts[$phase][$gk][$sex][$col] = 0;
                    }
                }
            }
        }

        foreach ($records as $record) {
            [$gradeLabel] = FeedingBeneficiarySummary::splitSection((string) $record->section);
            $gk = self::gradeKey($gradeLabel);
            if ($gk === null) {
                continue; // Outside the grades the programme covers.
            }

            $details = is_array($record->student_details) ? $record->student_details : [];
            $sex = self::sexKey((string) ($details['gender'] ?? ''));
            if ($sex === null) {
                continue; // No sex on file — cannot place in a MALE/FEMALE row.
            }

            // Baseline: BMI-for-age from the baseline column, HFA from the
            // classification the adviser computed at entry time.
            if ($ns = self::nsColumn((string) ($record->baseline_nutritional_status ?: $record->nutritional_status))) {
                $counts['bmib'][$gk][$sex][$ns]++;
            }
            if ($hfa = self::hfaColumn((string) ($details['nutritional_status_height_for_age'] ?? ''))) {
                $counts['bmib'][$gk][$sex][$hfa]++;
            }

            // Final: only learners with an endline measurement contribute; HFA is
            // recomputed from the endline height/age (endline HFA is not stored).
            if ($ns = self::nsColumn((string) $record->endline_nutritional_status)) {
                $counts['bmif'][$gk][$sex][$ns]++;
            }
            if ($record->endline_height_cm !== null && $record->endline_age !== null) {
                $endlineHfa = self::classifyHeightForAge((float) $record->endline_height_cm, (int) $record->endline_age);
                if ($hfa = self::hfaColumn($endlineHfa)) {
                    $counts['bmif'][$gk][$sex][$hfa]++;
                }
            }
        }

        $values = [];
        foreach (self::PHASES as $phase) {
            foreach ($gradeKeys as $gk) {
                self::flatten($values, $phase.'_'.$gk, $counts[$phase][$gk], $nsCols, $hfaCols);
            }

            // Overall table = column-wise sum of every grade.
            $overall = [];
            foreach ($sexes as $sex) {
                foreach (array_merge($nsCols, $hfaCols) as $col) {
                    $overall[$sex][$col] = array_sum(array_map(
                        fn (string $gk): int => $counts[$phase][$gk][$sex][$col],
                        $gradeKeys
                    ));
                }
            }
            self::flatten($values, $phase.'_overall', $overall, $nsCols, $hfaCols);
        }

        return $values;
    }

    /**
     * The chart's columns, in the order the grid rules them: the five BMI-for-age
     * columns, their total, the four height-for-age columns, their total.
     *
     * The bar chart under each grid is a picture of that grid, so it reads the
     * same eleven columns in the same order from the same place. Typing the list
     * again in the chart would let the two drift apart the first time a column
     * was added.
     *
     * @return array<string, string>
     */
    public static function chartColumns(): array
    {
        return self::NS_COLUMNS
            + ['nst' => 'Total']
            + self::HFA_COLUMNS
            + ['hfat' => 'Total'];
    }

    /**
     * A clean scale for a count axis: the smallest 1, 2 or 5 × 10ⁿ **step** that
     * covers the tallest column in at most four intervals.
     *
     * The step is chosen before the top, not after it, and that is the whole
     * point. Rounding the top to 1/2/5 × 10ⁿ and then cutting it into quarters
     * gives an axis labelled 5, 4, 3, 1, 0 — four gaps of three different
     * sizes, which is worse than no axis at all. Stepping first makes every
     * gridline a whole multiple of the step, so a column's height can actually
     * be read off the rules: 1700 becomes 0/500/1000/1500/2000, and 4 becomes
     * 0/1/2/3/4.
     *
     * Mirrored in the SBFP Forms page's repaint script, which redraws the same
     * chart when the grade or section changes — keep the two in step.
     *
     * @return array{max: int, step: int, ticks: list<int>} ticks run top down
     */
    public static function axisScale(int $peak): array
    {
        $peak = max(0, $peak);
        $step = 1;

        // 1, 2, 5, 10, 20, 50, 100 … until four of them clear the tallest bar.
        while ((int) ceil($peak / $step) > self::AXIS_INTERVALS) {
            $magnitude = 10 ** (int) floor(log10($step));
            $step = match ((int) ($step / $magnitude)) {
                1 => 2 * $magnitude,
                2 => 5 * $magnitude,
                default => 10 * $magnitude,
            };
        }

        // An axis of nothing still needs one interval to draw a baseline against.
        $intervals = max(1, (int) ceil($peak / $step));
        $max = $step * $intervals;

        $ticks = [];
        for ($t = $intervals; $t >= 0; $t--) {
            $ticks[] = $step * $t;
        }

        return ['max' => $max, 'step' => $step, 'ticks' => $ticks];
    }

    /**
     * The grid keys the sheet prints, in order: one per covered grade, then the
     * overall summary. Read from the one declaration of which grades the
     * programme covers — never re-typed.
     *
     * @return list<string>
     */
    public static function gradeKeys(): array
    {
        return array_map(
            static fn (int $grade): string => 'g'.$grade,
            FeedingBeneficiarySummary::GRADE_LEVELS
        );
    }

    /**
     * The heading above each grid, as the printed form titles it.
     */
    public static function gridTitle(string $gradeKey): string
    {
        return $gradeKey === 'overall'
            ? 'OVERALL BMI'
            : 'GRADE '.substr($gradeKey, 1).' BMI';
    }

    /** The field prefix for a phase: "baseline" and "endline" are what the app calls them. */
    public static function prefixFor(string $phase): string
    {
        return self::PHASES[$phase] ?? self::PHASES['baseline'];
    }

    /** The grid key for a "Grade 8" label, or null when the programme does not cover it. */
    public static function gradeKey(string $gradeLabel): ?string
    {
        if (preg_match('/(\d{1,2})/', $gradeLabel, $m)) {
            $grade = (int) $m[1];
            if (in_array($grade, FeedingBeneficiarySummary::GRADE_LEVELS, true)) {
                return 'g'.$grade;
            }
        }

        return null;
    }

    public static function sexKey(string $gender): ?string
    {
        $g = strtolower(trim($gender));

        return match (true) {
            str_starts_with($g, 'm') => 'male',
            str_starts_with($g, 'f') => 'female',
            default => null,
        };
    }

    /**
     * The BMI-for-age column a status belongs in.
     *
     * "Underweight" folds into Wasted: the DepEd sheet has no Underweight
     * column, and both count as undernourished everywhere else in the app.
     */
    public static function nsColumn(string $status): ?string
    {
        $s = strtolower(trim($status));

        if ($s === '') {
            return null;
        }

        return match (true) {
            str_contains($s, 'severe') && str_contains($s, 'wast') => 'sw',
            str_contains($s, 'wast'), str_contains($s, 'underweight') => 'w',
            str_contains($s, 'obes') => 'ob',
            str_contains($s, 'over') => 'ow',
            str_contains($s, 'normal') => 'n',
            default => null,
        };
    }

    public static function hfaColumn(string $hfa): ?string
    {
        $s = strtolower(trim($hfa));

        if ($s === '') {
            return null;
        }

        return match (true) {
            str_contains($s, 'severe') && str_contains($s, 'stunt') => 'ss',
            str_contains($s, 'stunt') => 'st',
            str_contains($s, 'tall') => 't',
            str_contains($s, 'normal') => 'hn',
            default => null,
        };
    }

    /**
     * Height-for-age at endline, which is not stored and so is recomputed.
     * Mirrors AdviserController::classifyHeightForAge, which is what wrote the
     * baseline classification.
     */
    public static function classifyHeightForAge(float $heightCm, int $age): string
    {
        if ($heightCm <= 0 || $age <= 0) {
            return '';
        }

        $minNormalHeight = 70 + ($age * 5);

        return match (true) {
            $heightCm < ($minNormalHeight - 8) => 'Severely Stunted',
            $heightCm < $minNormalHeight => 'Stunted',
            default => 'Normal Height-for-Age',
        };
    }

    /**
     * One grid's counts as flat field values, with the two Total columns and
     * the TOTAL row summed.
     *
     * An empty MALE/FEMALE cell stays blank, exactly as on the printed sheet —
     * a form is written into by hand, and a grid of zeros reads as a count
     * somebody took rather than a line nobody filled. The totals always print
     * an integer, because a total of nothing is nothing.
     *
     * @param  array<string, int|string>  $values
     * @param  array<string, array<string, int>>  $table
     * @param  list<string>  $nsCols
     * @param  list<string>  $hfaCols
     */
    private static function flatten(array &$values, string $prefix, array $table, array $nsCols, array $hfaCols): void
    {
        $totals = [];

        foreach (['male', 'female'] as $sex) {
            $nst = 0;
            $hfat = 0;

            foreach ($nsCols as $col) {
                $v = (int) ($table[$sex][$col] ?? 0);
                $nst += $v;
                $totals[$col] = ($totals[$col] ?? 0) + $v;
                $values[$prefix.'_'.$sex.'_'.$col] = $v > 0 ? $v : '';
            }

            foreach ($hfaCols as $col) {
                $v = (int) ($table[$sex][$col] ?? 0);
                $hfat += $v;
                $totals[$col] = ($totals[$col] ?? 0) + $v;
                $values[$prefix.'_'.$sex.'_'.$col] = $v > 0 ? $v : '';
            }

            $values[$prefix.'_'.$sex.'_nst'] = $nst;
            $values[$prefix.'_'.$sex.'_hfat'] = $hfat;
            $totals['nst'] = ($totals['nst'] ?? 0) + $nst;
            $totals['hfat'] = ($totals['hfat'] ?? 0) + $hfat;
        }

        foreach (array_merge($nsCols, $hfaCols, ['nst', 'hfat']) as $col) {
            $values[$prefix.'_total_'.$col] = $totals[$col] ?? 0;
        }
    }
}
