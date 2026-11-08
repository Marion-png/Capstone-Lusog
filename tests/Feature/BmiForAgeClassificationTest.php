<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\BmiAssessmentReport;
use App\Support\BmiClassifier;
use App\Support\NutritionalHealthStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BMI-for-age and height-for-age are read against the WHO 2007 reference for
 * the learner's sex and age in months — never the adult cut-offs.
 *
 * The app used to call every learner under BMI 18.5 Wasted and under 16
 * Severely Wasted, whatever their age or sex. A tester compared three
 * learners with WHO's own tables and found two of them filed a category too
 * low; their figures are kept here exactly as reported. The status decides
 * who the feeding programme qualifies, so this is a learner fed as wasted
 * while Normal.
 */
class BmiForAgeClassificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Joshua: a boy born 9 May 2011, weighed 7 Nov 2026 (15 years 5 months),
     * BMI 15.22. WHO: −3 SD 14.9, −2 SD 16.2 — between the two, so Wasted.
     * The adult cut-offs called him Severely Wasted.
     */
    #[Test]
    public function the_testers_first_learner_is_wasted_not_severely_wasted(): void
    {
        $months = BmiClassifier::ageAtMeasurement(
            ['birth_year' => 2011, 'birth_month' => 5, 'birth_day' => 9],
            '2026-11-07'
        );

        $this->assertSame(185, (int) floor($months));
        $this->assertSame(BmiClassifier::WASTED, BmiClassifier::bmiForAge(15.22, $months, 'Male'));
    }

    /**
     * Rafael: a boy of 14 years 7 months, BMI 16.4. WHO: −2 SD 15.8, −1 SD
     * 17.4 — above −2 SD, so Normal. The adult cut-offs called him Wasted.
     */
    #[Test]
    public function the_testers_second_learner_is_normal_not_wasted(): void
    {
        $this->assertSame(BmiClassifier::NORMAL, BmiClassifier::bmiForAge(16.4, 14 * 12 + 7, 'Male'));
    }

    /**
     * The tester's report of 8 November 2026, all four learners as entered on
     * Enroll Student and read on that day: 16.4 Normal, 15.22 Wasted, 23.7
     * Overweight, and 18.1 Normal with height-for-age Stunted.
     */
    #[Test]
    public function the_testers_four_learners_read_as_who_reads_them(): void
    {
        $on = '2026-11-08';
        $age = fn (int $y, int $m, int $d): float => BmiClassifier::ageAtMeasurement(
            ['birth_year' => $y, 'birth_month' => $m, 'birth_day' => $d], $on
        );

        $this->assertSame(BmiClassifier::NORMAL, BmiClassifier::bmiForAge(16.45, $age(2012, 3, 14), 'Male'));
        $this->assertSame(BmiClassifier::WASTED, BmiClassifier::bmiForAge(15.22, $age(2011, 5, 9), 'Male'));
        $this->assertSame(BmiClassifier::OVERWEIGHT, BmiClassifier::bmiForAge(23.74, $age(2014, 1, 20), 'Female'));
        $this->assertSame(BmiClassifier::NORMAL, BmiClassifier::bmiForAge(18.07, $age(2012, 9, 5), 'Male'));
        $this->assertSame(BmiClassifier::STUNTED, BmiClassifier::heightForAge(145.0, $age(2012, 9, 5), 'Male'));
    }

    /** Andrea: 13 years 2 months, BMI 18.7 — Normal on either sex's table, as before. */
    #[Test]
    public function the_testers_third_learner_stays_normal(): void
    {
        $this->assertSame(BmiClassifier::NORMAL, BmiClassifier::bmiForAge(18.7, 13 * 12 + 2, 'Male'));
        $this->assertSame(BmiClassifier::NORMAL, BmiClassifier::bmiForAge(18.7, 13 * 12 + 2, 'Female'));
    }

    /**
     * The whole DepEd ladder, either side of each WHO line, for a boy of
     * exactly 15 (−3 SD 14.71, −2 SD 16.01, +1 SD 22.69, +2 SD 26.97; height
     * −3 SD 145.5, −2 SD 153.4, +2 SD 184.6).
     */
    #[Test]
    public function every_category_on_the_deped_scale_is_reachable(): void
    {
        $age = 180.0;

        $this->assertSame(BmiClassifier::SEVERELY_WASTED, BmiClassifier::bmiForAge(14.6, $age, 'Male'));
        $this->assertSame(BmiClassifier::WASTED, BmiClassifier::bmiForAge(14.8, $age, 'Male'));
        $this->assertSame(BmiClassifier::WASTED, BmiClassifier::bmiForAge(15.9, $age, 'Male'));
        $this->assertSame(BmiClassifier::NORMAL, BmiClassifier::bmiForAge(16.1, $age, 'Male'));
        $this->assertSame(BmiClassifier::NORMAL, BmiClassifier::bmiForAge(22.6, $age, 'Male'));
        $this->assertSame(BmiClassifier::OVERWEIGHT, BmiClassifier::bmiForAge(22.8, $age, 'Male'));
        $this->assertSame(BmiClassifier::OVERWEIGHT, BmiClassifier::bmiForAge(26.9, $age, 'Male'));
        $this->assertSame(BmiClassifier::OBESE, BmiClassifier::bmiForAge(27.1, $age, 'Male'));

        $this->assertSame(BmiClassifier::SEVERELY_STUNTED, BmiClassifier::heightForAge(145.0, $age, 'Male'));
        $this->assertSame(BmiClassifier::STUNTED, BmiClassifier::heightForAge(146.0, $age, 'Male'));
        $this->assertSame(BmiClassifier::NORMAL_HEIGHT, BmiClassifier::heightForAge(154.0, $age, 'Male'));
        $this->assertSame(BmiClassifier::TALL, BmiClassifier::heightForAge(185.0, $age, 'Male'));

        // The adult line is gone: 18.0 is Normal for a boy of 15, not Wasted.
        $this->assertSame(BmiClassifier::NORMAL, BmiClassifier::bmiForAge(18.0, $age, 'Male'));
    }

    /** The two sexes are read against two tables. */
    #[Test]
    public function the_same_bmi_reads_differently_for_a_boy_and_a_girl(): void
    {
        // At exactly 15, BMI −2 SD is 15.87 for a girl and 16.01 for a boy;
        // height −2 SD is 147.9 cm for a girl and 153.4 cm for a boy.
        $this->assertSame(BmiClassifier::NORMAL, BmiClassifier::bmiForAge(15.95, 180, 'Female'));
        $this->assertSame(BmiClassifier::WASTED, BmiClassifier::bmiForAge(15.95, 180, 'Male'));

        $this->assertSame(BmiClassifier::NORMAL_HEIGHT, BmiClassifier::heightForAge(150.0, 180, 'Female'));
        $this->assertSame(BmiClassifier::STUNTED, BmiClassifier::heightForAge(150.0, 180, 'Male'));
    }

    /** No sex, no age, under five: WHO's software computes no z-score, and neither does this. */
    #[Test]
    public function without_sex_or_age_there_is_no_classification(): void
    {
        $this->assertSame(BmiClassifier::NOT_ENOUGH_DATA, BmiClassifier::bmiForAge(15.0, 180, null));
        $this->assertSame(BmiClassifier::NOT_ENOUGH_DATA, BmiClassifier::bmiForAge(15.0, 180, ''));
        $this->assertSame(BmiClassifier::NOT_ENOUGH_DATA, BmiClassifier::bmiForAge(15.0, null, 'Female'));
        $this->assertSame(BmiClassifier::NOT_ENOUGH_DATA, BmiClassifier::bmiForAge(15.0, 40, 'Female'));
        $this->assertSame(BmiClassifier::NOT_ENOUGH_DATA, BmiClassifier::heightForAge(150.0, 180, null));
    }

    /**
     * Age comes from the birth date; only a record with none falls back to
     * completed years, read at the middle of that year.
     */
    #[Test]
    public function age_is_read_from_the_birth_date_and_only_then_from_years(): void
    {
        $fromDate = BmiClassifier::ageAtMeasurement(
            ['birth_year' => 2012, 'birth_month' => 1, 'birth_day' => 1, 'age' => 99],
            '2026-07-01',
            99
        );
        $this->assertEqualsWithDelta(5295 / 30.4375, $fromDate, 1e-9);

        $this->assertEqualsWithDelta(186.0, BmiClassifier::ageAtMeasurement([], '2026-07-01', 15), 1e-9);
        $this->assertEqualsWithDelta(186.0, BmiClassifier::ageAtMeasurement(['birth_date' => ''], null, '15'), 1e-9);
        $this->assertNull(BmiClassifier::ageAtMeasurement([], '2026-07-01', null));

        // 30 February is not a birthday.
        $this->assertNull(BmiClassifier::birthDate(2012, 2, 30));
    }

    /** The adviser's form stores what WHO says, for the learner's own sex and age. */
    #[Test]
    public function the_enrolment_form_stores_the_who_classification(): void
    {
        $this->travelTo(now('Asia/Manila')->setDate(2026, 11, 7)->setTime(10, 0));

        $school = Institution::create(['name' => 'Test School', 'status' => 'active']);

        $this->withSession([
            'active_role' => 'class_adviser',
            'active_name' => 'Test Adviser',
            'active_school_name' => 'Test School',
            'active_institution_id' => $school->id,
            'assigned_grade_level' => 'Grade 10',
            'assigned_section' => 'Dalton',
        ])->post(route('adviser.store'), [
            'last_name' => 'Testcase',
            'first_name' => 'Joshua',
            'lrn' => '400000000185',
            'birth_month' => 5,
            'birth_day' => 9,
            'birth_year' => 2011,
            'gender' => 'Male',
            'birthplace' => 'Davao City',
            'parent_guardian' => 'Parent Testcase',
            'address' => '1 Example St.',
            'telephone_no' => '09170000000',
            'grade_level' => 'Grade 10',
            'section' => 'Dalton',
            'weight_kg' => 38.96,
            'height_m' => 1.6,
        ])->assertRedirect();

        $record = StudentHealthRecord::where('student_id', '400000000185')->firstOrFail();

        $this->assertEqualsWithDelta(15.22, (float) $record->baseline_bmi_value, 0.001);
        $this->assertSame(BmiClassifier::WASTED, $record->baseline_nutritional_status);
        $this->assertSame(BmiClassifier::WASTED, $record->nutritional_status);
        $this->assertSame(BmiClassifier::WASTED, $record->student_details['nutritional_status_bmi_for_age']);

        // 160 cm at 15 years 5 months: −1.43 SD on the boys' table.
        $this->assertSame(BmiClassifier::NORMAL_HEIGHT, $record->student_details['nutritional_status_height_for_age']);
    }

    /** A status nobody stored is computed from the birth date and sex on the record. */
    #[Test]
    public function the_profile_reading_classifies_from_the_record_s_own_birth_date_and_sex(): void
    {
        $record = new StudentHealthRecord;
        $record->forceFill([
            'student_details' => ['gender' => 'Male', 'birth_year' => 2011, 'birth_month' => 5, 'birth_day' => 9],
            'endline_height_cm' => 160,
            'endline_weight_kg' => 38.96,
            'endline_age' => 15,
            'endline_bmi_value' => 15.22,
            'endline_recorded_at' => '2026-11-07',
        ]);

        $status = NutritionalHealthStatus::forRecord($record);

        $this->assertSame(BmiClassifier::WASTED, $status['endline']['bmi_status']);
        $this->assertSame(BmiClassifier::NORMAL_HEIGHT, $status['endline']['hfa_status']);
        $this->assertSame(
            BmiClassifier::NORMAL_HEIGHT,
            BmiAssessmentReport::classifyHeightForAge(160.0, 185.99, 'Male'),
            'The DepEd grid reads height-for-age through the same classifier.'
        );
    }

    /** The forms' live preview reads the server's table, not a set of thresholds of its own. */
    #[Test]
    public function the_forms_preview_with_the_who_reference(): void
    {
        $school = Institution::create(['name' => 'Test School', 'status' => 'active']);
        $session = [
            'active_role' => 'class_adviser',
            'active_name' => 'Test Adviser',
            'active_school_name' => 'Test School',
            'active_institution_id' => $school->id,
            'assigned_grade_level' => 'Grade 10',
            'assigned_section' => 'Dalton',
        ];

        foreach ([route('dashboard.class-adviser'), route('adviser.create')] as $url) {
            $html = $this->withSession($session)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('window.WhoGrowth', $html, $url);
            $this->assertStringContainsString('window.WhoGrowth.bmiForAge(', $html, $url);
            $this->assertStringNotContainsString('bmi < 18.5', $html, "{$url} still carries the adult cut-off.");
            $this->assertStringNotContainsString('70 + (age * 5)', $html, "{$url} still carries the invented height line.");
            $this->assertMatchesRegularExpression('/<select[^>]*name="gender"/', $html, "{$url} must ask for the learner's sex.");
        }
    }
}
