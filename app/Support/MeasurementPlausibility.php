<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Whether a height and a weight could belong to a learner at all.
 *
 * A typing slip does not stay in its column: 187 cm and 8.8 kg became a BMI of
 * 2.5, then "Severely Wasted", then a learner the feeding programme qualifies
 * and a cell in the DepEd grid the School Head exports. So every place a
 * measurement is entered — enrolment, the baseline, the coordinator's endline
 * and progress weigh-ins — asks this one class, and the forms ask its mirror
 * in the browser (partials/who-growth-script, WhoGrowth.check).
 *
 * Two tiers (testers' report #6):
 *
 *  - **Impossible — refused**, with which field looks wrong: a height or
 *    weight outside any school learner's range (a height in metres typed into
 *    a centimetre box, a weight in grams), and WHO's own flags for a
 *    biologically implausible reading between 5 and 19 years — BMI-for-age
 *    beyond ±5 SD, height-for-age beyond ±6 SD (AnthroPlus). Without a sex or
 *    an age to read the reference with, a BMI under 10 or over 60.
 *  - **Unusual but possible — saved only once confirmed**: beyond ±4 SD but
 *    inside WHO's flags. A real severely wasted child sits between −3 and
 *    −4 SD and saves without a question; one further out is asked about once.
 *
 * The z-scores are BmiClassifier's, the same ones the status is classified
 * from.
 */
final class MeasurementPlausibility
{
    /** WHO's flag for an implausible BMI-for-age z-score, 5 to 19 years. */
    public const BMI_Z_LIMIT = 5.0;

    /** WHO's flag for an implausible height-for-age z-score, 5 to 19 years. */
    public const HEIGHT_Z_LIMIT = 6.0;

    /** Beyond this many SD a reading is possible but unusual, and is confirmed. */
    public const WARN_Z = 4.0;

    /** Outside these a BMI is not a child's, whatever the age or sex. */
    public const MIN_BMI = 10.0;

    public const MAX_BMI = 60.0;

    /** Outside these no school learner's height or weight falls. */
    public const MIN_HEIGHT_CM = 60.0;

    public const MAX_HEIGHT_CM = 230.0;

    public const MIN_WEIGHT_KG = 8.0;

    public const MAX_WEIGHT_KG = 200.0;

    /** The field a form posts once the person entering has confirmed an unusual reading. */
    public const CONFIRM_FIELD = 'measurement_confirmed';

    /**
     * Every implausible figure, keyed by the field to correct. Empty when the
     * measurements could be a learner's, or when there is nothing to check.
     *
     * @return array<string, string> 'height_cm' / 'weight_kg' => message
     */
    public static function problems(?float $heightCm, ?float $weightKg, ?float $ageInMonths, ?string $sex): array
    {
        return self::check($heightCm, $weightKg, $ageInMonths, $sex)['errors'];
    }

    /**
     * The whole reading: what is impossible (refused) and what is unusual
     * (confirmed before saving).
     *
     * @return array{errors: array<string, string>, warnings: list<string>}
     */
    public static function check(?float $heightCm, ?float $weightKg, ?float $ageInMonths, ?string $sex): array
    {
        $errors = [];
        $warnings = [];
        $heightCm = $heightCm !== null && $heightCm > 0 ? $heightCm : null;
        $weightKg = $weightKg !== null && $weightKg > 0 ? $weightKg : null;

        // The units first: a slip of a unit is a slip of a hundred or a
        // thousand times, and says which box to look at.
        if ($heightCm !== null && $heightCm < self::MIN_HEIGHT_CM) {
            $errors['height_cm'] = $heightCm < 3
                ? sprintf('A height of %s cm looks like metres. Enter the height in centimetres (%s cm).', self::figure($heightCm), self::figure($heightCm * 100))
                : sprintf('A height of %s cm is too short for a learner. Check the height.', self::figure($heightCm));
            $heightCm = null;
        } elseif ($heightCm !== null && $heightCm > self::MAX_HEIGHT_CM) {
            $errors['height_cm'] = sprintf('A height of %s cm is too tall for a learner. Check the height.', self::figure($heightCm));
            $heightCm = null;
        }

        if ($weightKg !== null && $weightKg > self::MAX_WEIGHT_KG) {
            $errors['weight_kg'] = $weightKg >= 1000
                ? sprintf('A weight of %s kg looks like grams. Enter the weight in kilograms (%s kg).', self::figure($weightKg), self::figure($weightKg / 1000))
                : sprintf('A weight of %s kg is too heavy for a learner. Check the weight.', self::figure($weightKg));
            $weightKg = null;
        } elseif ($weightKg !== null && $weightKg < self::MIN_WEIGHT_KG) {
            $errors['weight_kg'] = sprintf('A weight of %s kg is too light for a learner. Check the weight.', self::figure($weightKg));
            $weightKg = null;
        }

        if ($heightCm !== null) {
            $heightZ = BmiClassifier::heightZScore($heightCm, $ageInMonths, $sex);

            if ($heightZ !== null && abs($heightZ) > self::HEIGHT_Z_LIMIT) {
                $errors['height_cm'] = sprintf(
                    'A height of %s cm is not possible for a learner aged %s. Check the height and the date of birth.',
                    self::figure($heightCm),
                    self::ageLabel((float) $ageInMonths),
                );
                $heightCm = null;
            } elseif ($heightZ !== null && abs($heightZ) > self::WARN_Z) {
                $warnings[] = sprintf(
                    'A height of %s cm is very %s for a learner aged %s (more than %d SD from the WHO median).',
                    self::figure($heightCm),
                    $heightZ < 0 ? 'short' : 'tall',
                    self::ageLabel((float) $ageInMonths),
                    (int) self::WARN_Z,
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
                $errors['weight_kg'] = sprintf(
                    'A height of %s cm and a weight of %s kg give a BMI of %s, which is not possible for this learner. Check both measurements.',
                    self::figure($heightCm),
                    self::figure($weightKg),
                    number_format($bmi, 1),
                );
            } elseif ($bmiZ !== null && abs($bmiZ) > self::WARN_Z) {
                $warnings[] = sprintf(
                    'A BMI of %s is very %s for a learner aged %s (more than %d SD from the WHO median).',
                    number_format($bmi, 1),
                    $bmiZ < 0 ? 'low' : 'high',
                    self::ageLabel((float) $ageInMonths),
                    (int) self::WARN_Z,
                );
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Refuse an impossible reading, and an unusual one nobody has confirmed.
     * What every write path calls, so none can save a figure another refuses.
     *
     * @throws ValidationException
     */
    public static function guard(?float $heightCm, ?float $weightKg, ?float $ageInMonths, ?string $sex, bool $confirmed): void
    {
        $check = self::check($heightCm, $weightKg, $ageInMonths, $sex);

        if ($check['errors'] !== []) {
            throw ValidationException::withMessages($check['errors']);
        }

        if ($check['warnings'] !== [] && ! $confirmed) {
            throw ValidationException::withMessages([
                self::CONFIRM_FIELD => implode(' ', $check['warnings']).' If the measurements are right, tick "These measurements are correct" and save again.',
            ]);
        }
    }

    /** The validation rule a measurement field carries: a number, the range is ours to say. */
    public static function rule(): array
    {
        return ['required', 'numeric', 'gt:0', 'max:1000000'];
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
