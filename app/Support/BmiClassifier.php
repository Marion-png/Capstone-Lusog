<?php

namespace App\Support;

/**
 * BMI-for-age, on the DepEd scale the feeding programme and its forms use.
 *
 * Severely Wasted / Wasted / Normal / Overweight — the four labels the SBFP
 * BMI report has columns for. The band between 17.0 and 18.5 used to be
 * classified "Underweight", a label the DepEd sheet does not carry and that
 * every report then had to fold into Wasted (BmiAssessmentReport,
 * FeedingBeneficiarySummary::statusOf, the coordinator's panel). It is now
 * classified Wasted at the source; the folding stays for records saved under
 * the old word.
 *
 * One classifier: the adviser's enrolment form, the baseline/endline
 * endpoints and the nurse's feeding page all used to carry their own copy of
 * these thresholds, which is how one screen ends up calling a learner
 * something another does not.
 */
class BmiClassifier
{
    public const NOT_ENOUGH_DATA = 'Not enough data';

    public const SEVERELY_WASTED = 'Severely Wasted';

    public const WASTED = 'Wasted';

    public const NORMAL = 'Normal';

    public const OVERWEIGHT = 'Overweight';

    /** Wasted, not Underweight: the upper bound of the wasting band. */
    public const WASTED_UPPER_BMI = 18.5;

    public static function bmiForAge(?float $bmi, ?int $age): string
    {
        if ($bmi === null || $age === null) {
            return self::NOT_ENOUGH_DATA;
        }

        return self::fromBmi($bmi);
    }

    /** The same scale, for a reading that carries no age. */
    public static function fromBmi(float $bmi): string
    {
        if ($bmi < 16.0) {
            return self::SEVERELY_WASTED;
        }
        if ($bmi < self::WASTED_UPPER_BMI) {
            return self::WASTED;
        }
        if ($bmi >= 25.0) {
            return self::OVERWEIGHT;
        }

        return self::NORMAL;
    }
}
