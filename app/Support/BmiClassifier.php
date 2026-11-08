<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * BMI-for-age and height-for-age, on the DepEd scale the feeding programme and
 * its forms use, read against the WHO 2007 growth reference for the learner's
 * own **sex and age in months** (`WhoGrowthReference`).
 *
 * BMI-for-age (DepEd / WHO 2007):
 *
 *   z < −3            Severely Wasted
 *   −3 ≤ z < −2       Wasted
 *   −2 ≤ z ≤ +1       Normal
 *   +1 < z ≤ +2       Overweight
 *   z > +2            Obese
 *
 * Height-for-age: z < −3 Severely Stunted, −3 ≤ z < −2 Stunted, −2 ≤ z ≤ +2
 * Normal, z > +2 Tall.
 *
 * **A child is never read against the adult cut-offs.** This class used to
 * classify every learner as an adult — BMI < 16 Severely Wasted, < 18.5
 * Wasted, ≥ 25 Overweight — whatever their age or sex, and height-for-age
 * against a straight line (70 cm + 5 cm a year) that no reference draws. A
 * tester comparing three learners with WHO's tables found two of them filed
 * one category worse than they are, and since the status decides who the
 * feeding programme qualifies, every learner under 18.5 was at risk of being
 * fed as wasted while Normal. `BmiForAgeClassificationTest` keeps the tester's
 * three learners as they were reported.
 *
 * **No sex, no age, no classification.** The two tables differ by sex and by
 * month, so a learner with neither on file is "Not enough data" — exactly as
 * WHO's own software returns no z-score when either is missing — rather than
 * a status read off the wrong table.
 *
 * One classifier: the adviser's enrolment form, the baseline/endline
 * endpoints, the profile's Nutritional Health Status tab, the DepEd grid and
 * the forms' live preview (`partials/who-growth-script`, which reads the same
 * tables) all go through it, so no two screens can call one learner two things.
 */
final class BmiClassifier
{
    public const NOT_ENOUGH_DATA = 'Not enough data';

    public const SEVERELY_WASTED = 'Severely Wasted';

    public const WASTED = 'Wasted';

    public const NORMAL = 'Normal';

    public const OVERWEIGHT = 'Overweight';

    public const OBESE = 'Obese';

    public const SEVERELY_STUNTED = 'Severely Stunted';

    public const STUNTED = 'Stunted';

    public const NORMAL_HEIGHT = 'Normal Height-for-Age';

    public const TALL = 'Tall';

    /** The BMI-for-age status of a reading, or NOT_ENOUGH_DATA. */
    public static function bmiForAge(?float $bmi, ?float $ageInMonths, ?string $sex): string
    {
        $z = self::bmiZScore($bmi, $ageInMonths, $sex);

        return $z === null ? self::NOT_ENOUGH_DATA : self::bmiStatusForZ($z);
    }

    /** The height-for-age status of a reading, or NOT_ENOUGH_DATA. */
    public static function heightForAge(?float $heightCm, ?float $ageInMonths, ?string $sex): string
    {
        $z = self::heightZScore($heightCm, $ageInMonths, $sex);

        return $z === null ? self::NOT_ENOUGH_DATA : self::heightStatusForZ($z);
    }

    public static function bmiZScore(?float $bmi, ?float $ageInMonths, ?string $sex): ?float
    {
        return self::zScore(WhoGrowthReference::BMI_FOR_AGE, $bmi, $ageInMonths, $sex);
    }

    public static function heightZScore(?float $heightCm, ?float $ageInMonths, ?string $sex): ?float
    {
        return self::zScore(WhoGrowthReference::HEIGHT_FOR_AGE, $heightCm, $ageInMonths, $sex);
    }

    public static function bmiStatusForZ(float $z): string
    {
        return match (true) {
            $z < -3 => self::SEVERELY_WASTED,
            $z < -2 => self::WASTED,
            $z <= 1 => self::NORMAL,
            $z <= 2 => self::OVERWEIGHT,
            default => self::OBESE,
        };
    }

    public static function heightStatusForZ(float $z): string
    {
        return match (true) {
            $z < -3 => self::SEVERELY_STUNTED,
            $z < -2 => self::STUNTED,
            $z <= 2 => self::NORMAL_HEIGHT,
            default => self::TALL,
        };
    }

    /**
     * A learner's age in months on the day they were measured.
     *
     * From the birth date on their record whenever there is one — which is
     * every learner the adviser's form enrolled, since it requires one. Only a
     * record with no birth date at all falls back to its age in completed
     * years, read at the **middle** of that year of life (years × 12 + 6):
     * the learner is somewhere between their birthday and the next one, and
     * the midpoint is never more than six months out where the start of the
     * year can be eleven.
     *
     * @param  array<string, mixed>  $details  `student_details` (birth_year / birth_month / birth_day, or birth_date)
     */
    public static function ageAtMeasurement(array $details, mixed $measuredOn, mixed $ageInYears = null): ?float
    {
        $birthDate = self::birthDateFrom($details);
        $on = self::date($measuredOn) ?? CarbonImmutable::parse(SchoolClock::today());

        if ($birthDate !== null) {
            return WhoGrowthReference::ageInMonths($birthDate, $on);
        }

        return self::monthsFromYears($ageInYears);
    }

    /** A birth date from the three fields the forms post, or the one the import reads. */
    public static function birthDateFrom(array $details): ?CarbonImmutable
    {
        $year = (int) ($details['birth_year'] ?? 0);
        $month = (int) ($details['birth_month'] ?? 0);
        $day = (int) ($details['birth_day'] ?? 0);

        if ($year > 0 && $month > 0 && $day > 0) {
            return self::birthDate($year, $month, $day);
        }

        return self::date($details['birth_date'] ?? null);
    }

    /** A real calendar date, or null — 30 February is not a birthday. */
    public static function birthDate(int $year, int $month, int $day): ?CarbonImmutable
    {
        if ($year <= 0 || ! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'UTC');
    }

    /** Completed years read at the middle of that year of life. */
    public static function monthsFromYears(mixed $years): ?float
    {
        if ($years === null || $years === '' || ! is_numeric($years) || (float) $years <= 0) {
            return null;
        }

        return floor((float) $years) * 12 + 6;
    }

    private static function zScore(string $indicator, ?float $value, ?float $ageInMonths, ?string $sex): ?float
    {
        $sexCode = WhoGrowthReference::sexCode($sex);

        if ($value === null || $value <= 0 || $ageInMonths === null || $sexCode === null) {
            return null;
        }

        return WhoGrowthReference::zScore($indicator, $sexCode, $ageInMonths, $value);
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value));
        } catch (Throwable) {
            return null;
        }
    }
}
