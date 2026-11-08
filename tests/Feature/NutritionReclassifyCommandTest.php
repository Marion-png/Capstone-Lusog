<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\BmiClassifier;
use App\Support\FeedingBeneficiarySummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `nutrition:reclassify` re-reads statuses saved under the adult cut-offs
 * against the WHO 2007 reference, from what is already on each record.
 */
class NutritionReclassifyCommandTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    /**
     * A boy born 10 Mar 2012, weighed 15 Aug 2026 (14 years 5 months):
     * BMI 17.0 is −1.16 SD (Normal) and 145 cm is −2.69 SD (Stunted). The
     * adult cut-offs saved him as Wasted, and the old height line as Normal.
     */
    private function learnerSavedUnderTheAdultCutOffs(array $details = [], bool $enrolled = false): StudentHealthRecord
    {
        $record = new StudentHealthRecord;
        $record->forceFill([
            'student_id' => '400000000'.random_int(100, 999),
            'institution_id' => $this->school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Testcase, Learner',
            'section' => 'Grade 9 / Rizal',
            'student_details' => $details + [
                'gender' => 'Male',
                'birth_year' => 2012, 'birth_month' => 3, 'birth_day' => 10,
                'height_cm' => 145, 'weight_kg' => 35.74,
                'nutritional_status_bmi_for_age' => 'Wasted',
                'nutritional_status_height_for_age' => 'Normal Height-for-Age',
            ],
            'weight' => 35.74,
            'bmi_value' => 17.0,
            'nutritional_status' => 'Wasted',
            'baseline_age' => 14,
            'baseline_height_cm' => 145,
            'baseline_weight_kg' => 35.74,
            'baseline_bmi_value' => 17.0,
            'baseline_nutritional_status' => 'Wasted',
            'baseline_recorded_at' => '2026-08-15',
            'feeding_enrolled_at' => $enrolled ? '2026-08-20 08:00:00' : null,
        ])->save();

        return $record;
    }

    #[Test]
    public function a_dry_run_reports_and_writes_nothing(): void
    {
        $record = $this->learnerSavedUnderTheAdultCutOffs();

        $this->artisan('nutrition:reclassify', ['--dry-run' => true])
            ->expectsOutputToContain('1 would change')
            ->assertSuccessful();

        $fresh = $record->fresh();
        $this->assertSame('Wasted', $fresh->baseline_nutritional_status);
        $this->assertSame('Wasted', $fresh->nutritional_status);
        $this->assertSame('Normal Height-for-Age', $fresh->student_details['nutritional_status_height_for_age']);
    }

    #[Test]
    public function every_stored_status_is_read_again_against_the_who_reference(): void
    {
        $record = $this->learnerSavedUnderTheAdultCutOffs();
        $auditBefore = AuditLog::count();

        $this->artisan('nutrition:reclassify')->assertSuccessful();

        $fresh = $record->fresh();
        $this->assertSame(BmiClassifier::NORMAL, $fresh->baseline_nutritional_status);
        $this->assertSame(BmiClassifier::NORMAL, $fresh->nutritional_status);
        $this->assertSame(BmiClassifier::NORMAL, $fresh->student_details['nutritional_status_bmi_for_age']);
        $this->assertSame(BmiClassifier::STUNTED, $fresh->student_details['nutritional_status_height_for_age']);

        // Nothing but the classifications moved.
        $this->assertEquals(17.0, (float) $fresh->baseline_bmi_value);
        $this->assertEquals(145, (float) $fresh->baseline_height_cm);
        $this->assertSame('Male', $fresh->student_details['gender']);

        // On the audit trail, through the model.
        $this->assertGreaterThan($auditBefore, AuditLog::count());

        // A second run has nothing left to change.
        $this->artisan('nutrition:reclassify', ['--dry-run' => true])
            ->expectsOutputToContain('0 would change')
            ->assertSuccessful();
    }

    #[Test]
    public function an_enrolled_learner_stays_enrolled_and_is_reported(): void
    {
        $record = $this->learnerSavedUnderTheAdultCutOffs(enrolled: true);

        $this->artisan('nutrition:reclassify')
            ->expectsOutputToContain('Enrolled beneficiaries who no longer qualify (still enrolled — the coordinator decides): 1')
            ->assertSuccessful();

        $fresh = $record->fresh();
        $this->assertNotNull($fresh->feeding_enrolled_at, 'Enrolment is the coordinator\'s, never this command\'s.');
        $this->assertTrue(FeedingBeneficiarySummary::isEnrolled($fresh));
    }

    /** With no sex on file the reference cannot be chosen, so nothing is overwritten. */
    #[Test]
    public function a_learner_with_no_sex_on_file_is_left_alone_and_listed(): void
    {
        $record = $this->learnerSavedUnderTheAdultCutOffs(['gender' => null]);

        $this->artisan('nutrition:reclassify')
            ->expectsOutputToContain('Left unchanged: no sex or birth date on file to read the reference by: 1')
            ->assertSuccessful();

        $fresh = $record->fresh();
        $this->assertSame('Wasted', $fresh->baseline_nutritional_status);
        $this->assertSame('Wasted', $fresh->nutritional_status);
    }

    #[Test]
    public function the_endline_is_read_at_the_endline_weigh_in(): void
    {
        $record = $this->learnerSavedUnderTheAdultCutOffs();
        $record->forceFill([
            // Re-weighed 1 Mar 2027 (14 years 11 months): BMI 15.4 is between
            // −3 SD and −2 SD — Wasted, where the adult cut-offs said Severely.
            'endline_age' => 14,
            'endline_height_cm' => 147,
            'endline_weight_kg' => 33.28,
            'endline_bmi_value' => 15.4,
            'endline_nutritional_status' => 'Severely Wasted',
            'endline_recorded_at' => '2027-03-01',
            'bmi_value' => 15.4,
            'nutritional_status' => 'Severely Wasted',
        ])->save();

        $this->artisan('nutrition:reclassify')->assertSuccessful();

        $fresh = $record->fresh();
        $this->assertSame(BmiClassifier::WASTED, $fresh->endline_nutritional_status);
        $this->assertSame(BmiClassifier::WASTED, $fresh->nutritional_status, 'The summary follows the endline its BMI came from.');
        $this->assertSame(BmiClassifier::NORMAL, $fresh->baseline_nutritional_status);
    }
}
