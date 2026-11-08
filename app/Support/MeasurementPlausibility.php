<?php

namespace App\Support;

/**
 * Whether a height and a weight could belong to a learner at all.
 *
 * A typing slip does not stay in its column: 187 cm and 8.8 kg became a BMI of
 * 2.5, then "Severely Wasted", then a learner the feeding programme qualifies
 * and a cell in the DepEd grid the School Head exports. So a measurement no
 * child could have is refused at entry, with the figures that make it so.
 *
 * The limits are WHO's own, not ours: the flags its AnthroPlus software raises
 * for a biologically implausible reading between 5 and 19 years — BMI-for-age
 * beyond ±5 SD and height-for-age beyond ±6 SD. A real severely wasted child
 * sits well inside them (Severely Wasted begins at −3 SD), so nothing the
 * scale can hold is refused. The z-scores are BmiClassifier's, the same ones
 * the status is classified from.
 *
 * Where there is no sex or no age to read the reference with, only a BMI no
 * child could have (under 10 or over 60) is refused.
 */
final class MeasurementPlausibility
{
    /** WHO's flag for an implausible BMI-for-age z-score, 5 to 19 years. */
    public const BMI_Z_LIMIT = 5.0;

    /** WHO's flag for an implausible height-for-age z-score, 5 to 19 years. */
    public const HEIGHT_Z_LIMIT = 6.0;

    /** Outside these a BMI is not a child's, whatever the age or sex. */
    public const MIN_BMI = 10.0;

    public const MAX_BMI = 60.0;

    /**
     * Every implausible figure, keyed by the field to correct. Empty when the
     * measurements could be a learner's, or when there is nothing to check.
     *
     * @return array<string, string> 'height_cm' / 'weight_kg' => message
     */
    public static function problems(?float $heightCm, ?float $weightKg, ?float $ageInMonths, ?string $sex): array
    {
        $problems = [];
        $heightCm = $heightCm !== null && $heightCm > 0 ? $heightCm : null;
        $weightKg = $weightKg !== null && $weightKg > 0 ? $weightKg : null;

        if ($heightCm !== null) {
            $heightZ = BmiClassifier::heightZScore($heightCm, $ageInMonths, $sex);

            if ($heightZ !== null && abs($heightZ) > self::HEIGHT_Z_LIMIT) {
                $problems['height_cm'] = sprintf(
                    'A height of %s cm is not possible for a learner aged %s. Check the height and the date of birth.',
                    self::figure($heightCm),
                    self::ageLabel((float) $ageInMonths),
                );
            }
        }

        if ($heightCm !== null && $weightKg !== null) {
            $metres = $heightCm / 100;
            $bmi = $weightKg / ($metres * $metres);
            $bmiZ = BmiClassifier::bmiZScore(round($bmi, 2), $ageInMonths, $sex);

            $implausible = $bmiZ !== null
                ? abs($bmiZ) > self::BMI_Z_LIMIT
                : ($bmi < self::MIN_BMI || $bmi > self::MAX_BMI);

            if ($implausible) {
                $problems['weight_kg'] = sprintf(
                    'A height of %s cm and a weight of %s kg give a BMI of %s, which is not possible for this learner. Check both measurements.',
                    self::figure($heightCm),
                    self::figure($weightKg),
                    number_format($bmi, 1),
                );
            }
        }

        return $problems;
    }

    /** "14 years 3 months", from an age in months. */
    private static function ageLabel(float $months): string
    {
        $whole = (int) floor($months);
        $years = intdiv($whole, 12);
        $rest = $whole % 12;

        return $years.' '.($years === 1 ? 'year' : 'years')
            .($rest > 0 ? ' '.$rest.' '.($rest === 1 ? 'month' : 'months') : '');
    }

    private static function figure(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
