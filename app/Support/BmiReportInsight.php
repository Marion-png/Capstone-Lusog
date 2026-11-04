<?php

namespace App\Support;

/**
 * The few sentences under a BMI chart that say what the chart shows.
 *
 * Read off the very cells the grid above prints — `BmiAssessmentReport::values()`
 * — never off a second count, so the insight, the chart and the grid cannot
 * describe different children. It follows the filter: the whole school when
 * no grade is chosen, one grade, or one section of it.
 *
 * Like the narrative report it reports and does not interpret. Every
 * sentence is a count or a share of what is on file; nothing concludes
 * anything about a child's health or credits a change to the programme. And
 * a weighing nobody took is said to be missing, never read as zero: "0%
 * wasted" over a grade nobody weighed would be good news about children no
 * one has looked at.
 */
final class BmiReportInsight
{
    /** Who a value set counted, as its sentences name them. */
    public const LEARNER = 'learner';

    public const BENEFICIARY = 'beneficiary';

    /**
     * Every insight the forms page can show, keyed the way the page looks up
     * its value sets: [set key][phase][grid key] => sentences.
     *
     * The whole-school set ('') carries the OVERALL grid and each grade's;
     * a section's set carries only its own grade's grid, which is the only
     * one the page reads it for.
     *
     * @param  array<string, array<string, int|string>>  $valueSets  from bmiValueSetsBySection()
     * @param  self::LEARNER|self::BENEFICIARY  $noun  who those sets counted
     * @return array<string, array<string, array<string, list<string>>>>
     */
    public static function forValueSets(array $valueSets, string $noun = self::LEARNER): array
    {
        $insights = [];

        foreach ($valueSets as $setKey => $values) {
            $setKey = (string) $setKey;

            if ($setKey === '') {
                $grids = array_merge(['overall'], BmiAssessmentReport::gradeKeys());
            } else {
                [$gradeLabel] = FeedingBeneficiarySummary::splitSection($setKey);
                $gradeKey = BmiAssessmentReport::gradeKey($gradeLabel);

                if ($gradeKey === null) {
                    continue; // A section outside the grades the programme covers.
                }

                $grids = [$gradeKey];
            }

            foreach (array_keys(BmiAssessmentReport::PHASES) as $phase) {
                foreach ($grids as $gridKey) {
                    $insights[$setKey][$phase][$gridKey] = self::sentences(
                        $values,
                        $phase,
                        $gridKey,
                        self::scopeLabel($setKey, $gridKey),
                        $noun
                    );
                }
            }
        }

        return $insights;
    }

    /**
     * "All Grade Levels", "Grade 7" or "Grade 7 · Matiyaga" — what a set and
     * a grid together describe, as the heading over the insight names it.
     */
    public static function scopeLabel(string $setKey, string $gridKey): string
    {
        if ($setKey !== '') {
            [$grade, $section] = FeedingBeneficiarySummary::splitSection($setKey);

            return trim($grade.($section !== '' ? ' · '.$section : ''));
        }

        return $gridKey === 'overall' ? 'All Grade Levels' : 'Grade '.substr($gridKey, 1);
    }

    /**
     * What one grid's chart shows, in a few plain sentences.
     *
     * @param  array<string, int|string>  $values  one value set
     * @param  self::LEARNER|self::BENEFICIARY  $noun  who that set counted
     * @return list<string>
     */
    public static function sentences(array $values, string $phase, string $gridKey, string $scope, string $noun = self::LEARNER): array
    {
        $isEndline = $phase === 'endline';
        $prefix = BmiAssessmentReport::prefixFor($phase).'_'.$gridKey;
        $at = fn (string $sex, string $col): int => (int) ($values[$prefix.'_'.$sex.'_'.$col] ?? 0);
        $where = self::where($scope);
        $plural = self::plural($noun);

        $measured = $at('total', 'nst');

        if ($measured === 0) {
            return [$isEndline
                ? 'No '.$noun.' '.$where.' has been re-measured at endline yet, so there is nothing to compare against the baseline.'
                : 'No '.$noun.' '.$where.' has a baseline BMI-for-age on file yet, so there is nothing to read in this chart.'];
        }

        $sentences = [];

        // Who the chart counts.
        $sentences[] = self::counted($measured, $noun).' '.$where.' '.($measured === 1 ? 'has' : 'have').' '
            .($isEndline ? 'an endline' : 'a baseline').' BMI-for-age on file — '
            .$at('male', 'nst').' male and '.$at('female', 'nst').' female.';

        // The tallest columns: the group most of them fall in.
        $groups = [];
        foreach (BmiAssessmentReport::NS_COLUMNS as $col => $label) {
            $groups[$label] = $at('total', $col);
        }
        $largest = max($groups);
        $leaders = array_keys(array_filter($groups, fn (int $n): bool => $n === $largest));
        $sentences[] = count($leaders) === 1
            ? $leaders[0].' is the largest group, with '.self::counted($largest, $noun).' ('.self::share($largest, $measured).').'
            : self::listOf($leaders).' are tied as the largest groups, with '.self::counted($largest, $noun).' each ('.self::share($largest, $measured).').';

        // Wasting — the reading the feeding programme qualifies on.
        $severe = $at('total', 'sw');
        $wasted = $at('total', 'w');
        $wasting = $severe + $wasted;
        if ($wasting > 0) {
            $femaleWasting = $at('female', 'sw') + $at('female', 'w');
            $maleWasting = $at('male', 'sw') + $at('male', 'w');
            $sentences[] = self::counted($wasting, $noun).' ('.self::share($wasting, $measured).') '
                .($wasting === 1 ? 'is' : 'are').' severely wasted or wasted — '
                .$severe.' severely wasted and '.$wasted.' wasted; '
                .$femaleWasting.' female and '.$maleWasting.' male.';
        } else {
            $sentences[] = 'No '.$noun.' '.$where.' is severely wasted or wasted.';
        }

        // Above the normal range.
        $above = $at('total', 'ow') + $at('total', 'ob');
        if ($above > 0) {
            $sentences[] = self::counted($above, $noun).' ('.self::share($above, $measured).') '
                .($above === 1 ? 'is' : 'are').' overweight or obese.';
        }

        // The other half of the grid: height-for-age.
        $classified = $at('total', 'hfat');
        $stunted = $at('total', 'ss') + $at('total', 'st');
        $tall = $at('total', 't');
        if ($classified === 0) {
            $sentences[] = 'No height-for-age classification is on file for '.($measured === 1 ? 'this '.$noun : 'these '.$plural).'.';
        } elseif ($stunted > 0) {
            $sentences[] = 'By height-for-age, '.$stunted.' of '.self::counted($classified, $noun).' classified ('.self::share($stunted, $classified).') '
                .($stunted === 1 ? 'is' : 'are').' stunted or severely stunted'
                .($tall > 0 ? ', and '.$tall.' '.($tall === 1 ? 'is' : 'are').' tall' : '').'.';
        } elseif ($classified === 1) {
            $sentences[] = 'By height-for-age, the one '.$noun.' classified is '.($tall > 0 ? 'tall' : 'not stunted').'.';
        } else {
            $sentences[] = 'By height-for-age, none of the '.self::counted($classified, $noun).' classified is stunted'
                .($tall > 0 ? '; '.$tall.' '.($tall === 1 ? 'is' : 'are').' tall' : '').'.';
        }

        // The endline is read against the baseline of the same children.
        if ($isEndline) {
            $base = BmiAssessmentReport::prefixFor('baseline').'_'.$gridKey;
            $baseAt = fn (string $col): int => (int) ($values[$base.'_total_'.$col] ?? 0);
            $baseMeasured = $baseAt('nst');

            if ($baseMeasured > 0) {
                $baseWasting = $baseAt('sw') + $baseAt('w');
                $change = $wasting - $baseWasting;
                $sentences[] = 'Against the baseline, severely wasted and wasted '.$plural.' went from '
                    .$baseWasting.' to '.$wasting
                    .($change === 0 ? ' — no change' : ' — '.abs($change).' '.($change < 0 ? 'fewer' : 'more')).'.';

                if ($measured < $baseMeasured) {
                    $sentences[] = 'The endline covers '.$measured.' of the '.$baseMeasured
                        .' '.($baseMeasured === 1 ? $noun : $plural).' weighed at baseline, so the comparison is not yet complete.';
                }
            }
        }

        return $sentences;
    }

    /** "in all grade levels", "in Grade 7", "in Grade 7 · Matiyaga". */
    private static function where(string $scope): string
    {
        return $scope === 'All Grade Levels' ? 'across all grade levels' : 'in '.$scope;
    }

    private static function counted(int $n, string $noun): string
    {
        return $n.' '.($n === 1 ? $noun : self::plural($noun));
    }

    private static function plural(string $noun): string
    {
        return $noun === self::BENEFICIARY ? 'beneficiaries' : 'learners';
    }

    /**
     * A share as a whole percentage. A count too small to round to 1% says
     * "under 1%" rather than 0%, which would read as nobody.
     */
    private static function share(int $part, int $whole): string
    {
        if ($whole <= 0) {
            return '—';
        }

        $percent = $part / $whole * 100;

        return $part > 0 && $percent < 0.5 ? 'under 1%' : (int) round($percent).'%';
    }

    /** @param list<string> $items */
    private static function listOf(array $items): string
    {
        if (count($items) <= 2) {
            return implode(' and ', $items);
        }

        return implode(', ', array_slice($items, 0, -1)).' and '.end($items);
    }
}
