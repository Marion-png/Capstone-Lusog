<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;

/**
 * The WHO 2007 growth reference for school-age children and adolescents
 * (5–19 years): the tables DepEd reads BMI-for-age and height-for-age against.
 *
 * **The values are WHO's own, never typed.** `resources/data/who2007/` holds
 * `bfawho2007.txt` (BMI-for-age) and `hfawho2007.txt` (height-for-age)
 * byte-for-byte as WHO publishes them in its AnthroPlus package
 * (github.com/WorldHealthOrganization/anthroplus, data-raw/growthstandards),
 * so they can be compared line for line with the source. Each row is one sex
 * (1 = boys, 2 = girls) and one completed month of age, from 60 to 229, with
 * the L (Box-Cox power), M (median) and S (coefficient of variation) of the
 * measurement at that age. Month 229 repeats 228 so the last month can be
 * interpolated, exactly as WHO ships it.
 *
 * Before this the app classified a 15-year-old against the adult cut-offs
 * (BMI < 16 / < 18.5 / ≥ 25), whatever their age or sex. A boy of 15 years 5
 * months with a BMI of 15.22 was called Severely Wasted when the reference
 * puts him between −3 SD (14.9) and −2 SD (16.2) — Wasted — and a boy of 14
 * years 7 months at 16.4 was called Wasted when he is above −2 SD (15.8) and
 * Normal. Every learner under 18.5 was read as worse than they are, and the
 * status decides who the feeding programme qualifies.
 *
 * **The z-score is computed exactly as WHO's AnthroPlus computes it**, so a
 * figure here and a figure from WHO's own software agree:
 *
 * - age is in months, fractional (days ÷ 30.4375), and L, M and S are
 *   interpolated linearly between the two completed months either side of it;
 * - z = ((y / M)^L − 1) / (S × L);
 * - for BMI-for-age only, a z beyond ±3 is restricted: the distance past the
 *   ±3 SD line is measured in units of the gap between ±2 SD and ±3 SD, which
 *   keeps the extreme tail from stretching (WHO, "Computation of centiles and
 *   z-scores", 2007);
 * - the result is rounded to two decimals, which is the figure WHO's software
 *   reports and classifies.
 *
 * `WhoGrowthReferenceTest` checks every z-score against the ones WHO computed
 * for its own sample survey.
 */
final class WhoGrowthReference
{
    public const BMI_FOR_AGE = 'bfa';

    public const HEIGHT_FOR_AGE = 'hfa';

    public const MALE = 1;

    public const FEMALE = 2;

    /** 5 years 0 months: the first month of the reference. */
    public const MIN_MONTHS = 60;

    /**
     * 19 years 0 months: the last month of the reference.
     *
     * A learner past it is read against this month rather than refused. WHO
     * built the 2007 reference so that at 19 years +1 SD and +2 SD meet the
     * adult cut-offs of BMI 25 and 30, so the last month is where a school-age
     * reading hands over to an adult one — and a Grade 10 learner of nineteen
     * still has to be screened for the feeding programme.
     */
    public const MAX_MONTHS = 228;

    /** Days in an average month (365.25 ÷ 12), the convention WHO's software uses. */
    public const DAYS_PER_MONTH = 30.4375;

    private const FILES = [
        self::BMI_FOR_AGE => 'bfawho2007.txt',
        self::HEIGHT_FOR_AGE => 'hfawho2007.txt',
    ];

    /** @var array<string, array<int, array<int, array{0: float, 1: float, 2: float}>>> */
    private static array $tables = [];

    /**
     * One indicator's table: [sex][month] => [L, M, S].
     *
     * Read once per process — the files never change while the app runs.
     *
     * @return array<int, array<int, array{0: float, 1: float, 2: float}>>
     */
    public static function table(string $indicator): array
    {
        if (isset(self::$tables[$indicator])) {
            return self::$tables[$indicator];
        }

        $file = self::FILES[$indicator] ?? throw new RuntimeException("Unknown growth indicator [{$indicator}].");
        $path = resource_path('data/who2007/'.$file);
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            throw new RuntimeException("The WHO 2007 reference file {$path} could not be read.");
        }

        $table = [];
        foreach ($lines as $line) {
            $cells = preg_split('/\s+/', trim($line));

            // The header row ("sex age l m s") and anything else that is not a
            // row of five numbers.
            if (count($cells) !== 5 || ! is_numeric($cells[0])) {
                continue;
            }

            [$sex, $month, $l, $m, $s] = $cells;
            $table[(int) $sex][(int) $month] = [(float) $l, (float) $m, (float) $s];
        }

        foreach ([self::MALE, self::FEMALE] as $sex) {
            if (! isset($table[$sex][self::MIN_MONTHS], $table[$sex][self::MAX_MONTHS])) {
                throw new RuntimeException("The WHO 2007 reference file {$path} is incomplete.");
            }
        }

        return self::$tables[$indicator] = $table;
    }

    /**
     * L, M and S at an age, interpolated between the completed months either
     * side of it — or null outside the reference (under 5 years).
     *
     * @return array{0: float, 1: float, 2: float}|null
     */
    public static function lms(string $indicator, int $sex, float $ageInMonths): ?array
    {
        if ($ageInMonths < self::MIN_MONTHS) {
            return null;
        }

        $rows = self::table($indicator)[$sex] ?? null;
        if ($rows === null) {
            return null;
        }

        $age = min($ageInMonths, (float) self::MAX_MONTHS);
        $low = (int) floor($age);
        $fraction = $age - $low;

        $lower = $rows[$low];
        $upper = $rows[$low + 1] ?? $lower;

        return [
            $lower[0] + $fraction * ($upper[0] - $lower[0]),
            $lower[1] + $fraction * ($upper[1] - $lower[1]),
            $lower[2] + $fraction * ($upper[2] - $lower[2]),
        ];
    }

    /**
     * The z-score of a measurement, rounded to two decimals as WHO's software
     * reports it — or null when there is no reference to read it against.
     */
    public static function zScore(string $indicator, int $sex, float $ageInMonths, float $value): ?float
    {
        if ($value <= 0) {
            return null;
        }

        $lms = self::lms($indicator, $sex, $ageInMonths);
        if ($lms === null) {
            return null;
        }

        [$l, $m, $s] = $lms;
        $z = (($value / $m) ** $l - 1) / ($s * $l);

        if ($indicator === self::BMI_FOR_AGE) {
            if ($z > 3) {
                $sd3 = self::valueAtLms($l, $m, $s, 3);
                $z = 3 + ($value - $sd3) / ($sd3 - self::valueAtLms($l, $m, $s, 2));
            } elseif ($z < -3) {
                $sd3 = self::valueAtLms($l, $m, $s, -3);
                $z = -3 + ($value - $sd3) / (self::valueAtLms($l, $m, $s, -2) - $sd3);
            }
        }

        return round($z, 2);
    }

    /**
     * The measurement that sits on a z-score line at an age — the figure a
     * printed field table lists under "−2 SD" (only meaningful within ±3).
     */
    public static function valueAt(string $indicator, int $sex, float $ageInMonths, float $z): ?float
    {
        $lms = self::lms($indicator, $sex, $ageInMonths);

        return $lms === null ? null : self::valueAtLms($lms[0], $lms[1], $lms[2], $z);
    }

    /** 1 for a boy, 2 for a girl, null when the record does not say. */
    public static function sexCode(?string $gender): ?int
    {
        $g = strtolower(trim((string) $gender));

        return match (true) {
            $g === '' => null,
            str_starts_with($g, 'm') => self::MALE,
            str_starts_with($g, 'f') => self::FEMALE,
            default => null,
        };
    }

    /**
     * Age in months, fractional, from two calendar dates — days ÷ 30.4375, as
     * WHO's software counts it. Null when the birth date is after the day.
     */
    public static function ageInMonths(DateTimeInterface $birthDate, DateTimeInterface $measuredOn): ?float
    {
        // Calendar days, read off the two dates rather than the two instants,
        // so a birth date and a weigh-in date held in different time zones
        // still count whole days between them.
        $utc = new DateTimeZone('UTC');
        $from = new DateTimeImmutable($birthDate->format('Y-m-d'), $utc);
        $to = new DateTimeImmutable($measuredOn->format('Y-m-d'), $utc);

        if ($to < $from) {
            return null;
        }

        return $from->diff($to)->days / self::DAYS_PER_MONTH;
    }

    /**
     * Every row of both tables, for the forms that preview a classification
     * as the adviser types: [indicator][sex] => list of [L, M, S] from
     * MIN_MONTHS up. The browser reads the same numbers the server does.
     *
     * @return array<string, array<int, list<array{0: float, 1: float, 2: float}>>>
     */
    public static function forClient(): array
    {
        $out = [];

        foreach (array_keys(self::FILES) as $indicator) {
            foreach (self::table($indicator) as $sex => $rows) {
                ksort($rows);
                $out[$indicator][$sex] = array_values($rows);
            }
        }

        return $out;
    }

    private static function valueAtLms(float $l, float $m, float $s, float $z): float
    {
        return $m * ((1 + $l * $s * $z) ** (1 / $l));
    }
}
