<?php

namespace Tests\Feature;

use App\Support\WhoGrowthReference;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The WHO 2007 growth reference the BMI-for-age and height-for-age statuses
 * are read against (App\Support\WhoGrowthReference).
 *
 * The oracle is WHO's own: tests/Fixtures/who2007-survey-zscores.csv is the
 * sample survey WHO ships with AnthroPlus (github.com/WorldHealthOrganization/
 * anthroplus, data-raw/survey_who2007_z.csv), trimmed to sex, age, weight,
 * height and the z-scores WHO's software computed for each child. Every
 * z-score here must be the one WHO reported, to the hundredth.
 */
class WhoGrowthReferenceTest extends TestCase
{
    #[Test]
    public function every_z_score_is_the_one_who_computed_for_its_own_survey(): void
    {
        $handle = fopen(base_path('tests/Fixtures/who2007-survey-zscores.csv'), 'r');
        $head = fgetcsv($handle, null, ',', '"', '');
        $compared = ['bfa' => 0, 'hfa' => 0];

        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $row = array_combine($head, $cells);
            $sex = (int) $row['sex'];
            $age = (float) $row['age_months'];

            if ($row['zbfa'] !== '' && $row['zbfa'] !== 'NA') {
                $bmi = (float) $row['weight_kg'] / (((float) $row['height_cm'] / 100) ** 2);
                $this->assertEqualsWithDelta(
                    (float) $row['zbfa'],
                    WhoGrowthReference::zScore(WhoGrowthReference::BMI_FOR_AGE, $sex, $age, $bmi),
                    0.0001,
                    "BMI-for-age z-score for sex {$sex}, {$age} months, BMI {$bmi}."
                );
                $compared['bfa']++;
            }

            if ($row['zhfa'] !== '' && $row['zhfa'] !== 'NA') {
                $this->assertEqualsWithDelta(
                    (float) $row['zhfa'],
                    WhoGrowthReference::zScore(WhoGrowthReference::HEIGHT_FOR_AGE, $sex, $age, (float) $row['height_cm']),
                    0.0001,
                    "Height-for-age z-score for sex {$sex}, {$age} months, {$row['height_cm']} cm."
                );
                $compared['hfa']++;
            }
        }

        fclose($handle);

        // Both indicators were actually exercised, beyond ±3 SD included.
        $this->assertSame(919, $compared['bfa']);
        $this->assertSame(924, $compared['hfa']);
    }

    /**
     * The values on WHO's simplified field table for boys that the tester
     * checked the app against (BMI-for-age, 5–19 years).
     */
    #[Test]
    public function the_cut_offs_are_the_ones_on_who_s_printed_field_table(): void
    {
        $at = fn (int $months, float $z): float => round(WhoGrowthReference::valueAt(
            WhoGrowthReference::BMI_FOR_AGE, WhoGrowthReference::MALE, $months, $z
        ), 1);

        // 15 years 5 months.
        $this->assertSame(14.9, $at(185, -3));
        $this->assertSame(16.2, $at(185, -2));

        // 14 years 7 months.
        $this->assertSame(15.8, $at(175, -2));
        $this->assertSame(17.4, $at(175, -1));
    }

    /** The tables are WHO's, byte for byte — nobody may "correct" a value. */
    #[Test]
    public function the_reference_files_are_who_s_unaltered(): void
    {
        $this->assertSame(
            '70ef3260df08e48ce560c28cff4e733db78fb952cbd881eba63c9d3a15f38a6a',
            hash_file('sha256', resource_path('data/who2007/bfawho2007.txt')),
            'bfawho2007.txt must stay exactly as WHO publishes it.'
        );
        $this->assertSame(
            '9ac02aa4908f8ec3c6ba2362a4c7e1356043408b2873548ca22eedc016c235ad',
            hash_file('sha256', resource_path('data/who2007/hfawho2007.txt')),
            'hfawho2007.txt must stay exactly as WHO publishes it.'
        );

        foreach ([WhoGrowthReference::BMI_FOR_AGE, WhoGrowthReference::HEIGHT_FOR_AGE] as $indicator) {
            foreach ([WhoGrowthReference::MALE, WhoGrowthReference::FEMALE] as $sex) {
                $months = array_keys(WhoGrowthReference::table($indicator)[$sex]);
                $this->assertSame(range(60, 229), $months, "{$indicator} sex {$sex} covers every month.");
            }
        }
    }

    #[Test]
    public function age_in_months_counts_days_the_way_who_does(): void
    {
        // Born 9 May 2011, weighed 7 Nov 2026: 5,661 days ÷ 30.4375.
        $months = WhoGrowthReference::ageInMonths(
            new \DateTimeImmutable('2011-05-09'),
            new \DateTimeImmutable('2026-11-07')
        );

        $this->assertEqualsWithDelta(5661 / 30.4375, $months, 1e-9);
        $this->assertSame(185, (int) floor($months), '15 years 5 months completed.');

        $this->assertNull(WhoGrowthReference::ageInMonths(
            new \DateTimeImmutable('2026-11-08'),
            new \DateTimeImmutable('2026-11-07')
        ), 'A birth date after the weigh-in is no age at all.');
    }

    #[Test]
    public function outside_the_reference_there_is_no_reading_below_five_and_the_last_month_above_nineteen(): void
    {
        $this->assertNull(WhoGrowthReference::zScore(WhoGrowthReference::BMI_FOR_AGE, 1, 59.9, 15.0));

        $this->assertSame(
            WhoGrowthReference::zScore(WhoGrowthReference::BMI_FOR_AGE, 1, 228, 17.5),
            WhoGrowthReference::zScore(WhoGrowthReference::BMI_FOR_AGE, 1, 250, 17.5)
        );
    }

    #[Test]
    public function the_browser_is_handed_the_same_table(): void
    {
        $client = WhoGrowthReference::forClient();

        foreach ([WhoGrowthReference::BMI_FOR_AGE, WhoGrowthReference::HEIGHT_FOR_AGE] as $indicator) {
            foreach ([WhoGrowthReference::MALE, WhoGrowthReference::FEMALE] as $sex) {
                $rows = WhoGrowthReference::table($indicator)[$sex];
                $this->assertCount(count($rows), $client[$indicator][$sex]);
                $this->assertSame($rows[185], $client[$indicator][$sex][185 - WhoGrowthReference::MIN_MONTHS]);
            }
        }
    }
}
