<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Support\StudentDataCompleteness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The adviser's fourth figure counts incomplete student data.
 *
 * It used to be "Needs Follow-up" — at risk, wasted, or a denied consent.
 * Those are real conditions, but none of them is something the adviser
 * fixes from this screen, and the nurse and the Feeding Coordinator each
 * own a screen for them already. Meanwhile a learner whose guardian and
 * contact number were never entered went unreported, which is exactly the
 * work this role is here to do.
 *
 * One rule (App\Support\StudentDataCompleteness) serves both adviser tabs,
 * so the Dashboard count and the My Students count cannot disagree.
 */
class AdviserIncompleteDataTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    /** Everything the School Health Card asks the adviser for. */
    private function completeRow(array $overrides = []): array
    {
        return array_merge([
            'lrn' => '600000000001',
            'last_name' => 'Cruz',
            'first_name' => 'Juan',
            'middle_name' => 'Reyes',
            'birth_year' => 2010,
            'birth_month' => 5,
            'birth_day' => 14,
            'birthplace' => 'Davao City',
            'gender' => 'Male',
            'parent_guardian' => 'Maria Cruz',
            'address' => '12 Rizal St.',
            'telephone_no' => '09171234567',
            'height_cm' => 150,
            'weight_kg' => 42,
            'grade_level' => 'Grade 10',
            'section' => 'Dalton',
        ], $overrides);
    }

    private function adviserSession(array $roster): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Maria Santos',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
            'assigned_grade_level' => 'Grade 10',
            'assigned_section' => 'Dalton',
            'school_health_card_records' => $roster,
        ];
    }

    private function dashboard(array $roster): string
    {
        return $this->withSession($this->adviserSession($roster))
            ->get(route('dashboard.class-adviser'))
            ->assertOk()
            ->getContent();
    }

    #[Test]
    public function a_fully_entered_learner_is_complete(): void
    {
        $this->assertSame([], StudentDataCompleteness::missingFor($this->completeRow()));
        $this->assertFalse(StudentDataCompleteness::isIncomplete($this->completeRow()));
    }

    /** The missing fields are named, in the order the form asks for them. */
    #[Test]
    public function the_rule_names_what_is_missing(): void
    {
        $row = $this->completeRow([
            'parent_guardian' => '',
            'telephone_no' => null,
            'gender' => '   ',
        ]);

        $this->assertSame(
            ['Sex', 'Parent / guardian', 'Contact number'],
            StudentDataCompleteness::missingFor($row)
        );
    }

    /** A measurement of zero is not a measurement. */
    #[Test]
    public function a_zero_height_or_weight_is_missing_not_entered(): void
    {
        $this->assertSame(['Height'], StudentDataCompleteness::missingFor($this->completeRow(['height_cm' => 0])));
        $this->assertSame(['Weight'], StudentDataCompleteness::missingFor($this->completeRow(['weight_kg' => '0'])));
    }

    /**
     * The birth date is three inputs, and a year alone is not a birth date —
     * the age every BMI-for-age reading is taken against cannot be worked
     * out from it.
     */
    #[Test]
    public function a_part_of_the_birth_date_is_not_a_birth_date(): void
    {
        $row = $this->completeRow(['birth_day' => null]);

        $this->assertSame(['Birth date'], StudentDataCompleteness::missingFor($row));
    }

    /**
     * Fields belonging to other desks are not the adviser's incompleteness.
     * Vitals are the nurse's, and a blank health-history checklist is a
     * valid answer, not a gap.
     */
    #[Test]
    public function another_desks_blank_field_does_not_count(): void
    {
        $row = $this->completeRow([
            'temperature_c' => null,
            'pulse_bpm' => null,
            'blood_pressure' => '',
            'health_history' => [],
            'middle_name' => '',
            'region' => null,
            'division' => null,
        ]);

        $this->assertSame([], StudentDataCompleteness::missingFor($row));
    }

    /** The Dashboard reports it under its new name. */
    #[Test]
    public function the_dashboard_card_reads_incomplete_data(): void
    {
        $html = $this->dashboard([$this->completeRow(['address' => ''])]);

        $this->assertStringContainsString('<span>Incomplete Data</span>', $html);
        $this->assertStringContainsString('Missing required card details', $html);
        $this->assertStringContainsString('Incomplete data</span>', $html);
    }

    /**
     * And the old name is gone from both tabs. Anchored on the markup, not
     * the raw document: the page inlines its own stylesheet, whose comments
     * explain what the card used to be.
     */
    #[Test]
    public function needs_follow_up_is_gone(): void
    {
        $html = $this->dashboard([$this->completeRow()]);

        $this->assertStringNotContainsString('<span>Needs Follow-up</span>', $html);
        $this->assertStringNotContainsString('Needs follow-up</span>', $html);
        $this->assertStringNotContainsString('>Needs Follow-up</div>', $html);
        $this->assertStringNotContainsString('<small>Requires medical attention</small>', $html);
    }

    /**
     * The card is no longer painted as a medical alert. It kept the clinical
     * red it wore as Needs Follow-up, and red on a paperwork counter reads as
     * something wrong with the child rather than something left to type.
     */
    #[Test]
    public function the_card_is_not_painted_as_a_medical_alert(): void
    {
        $html = $this->dashboard([$this->completeRow()]);

        $this->assertStringContainsString('dashboard-stat-card dashboard-incomplete', $html);
        $this->assertStringContainsString('dsc-icon dsc-icon-incomplete', $html);
        $this->assertStringContainsString('ms-stat-icon ms-icon-incomplete', $html);

        // The red alert styling still exists — Feeding Status and Consent
        // Forms use it, and those really are alerts.
        $this->assertStringContainsString('.ms-icon-alert{', $html);
    }

    /** The figure is the number of learners with entry left to do. */
    #[Test]
    public function the_count_is_the_learners_with_something_missing(): void
    {
        $roster = [
            $this->completeRow(['lrn' => '600000000001']),
            $this->completeRow(['lrn' => '600000000002', 'address' => '']),
            $this->completeRow(['lrn' => '600000000003', 'telephone_no' => null, 'gender' => '']),
        ];

        $this->assertSame(2, StudentDataCompleteness::countIncomplete($roster));

        $html = $this->dashboard($roster);

        $this->assertStringContainsString('<b>2</b><span>Incomplete Data</span>', $html);
    }

    /**
     * Both tabs read the one rule, so the Dashboard's figure and the
     * My Students figure are the same number for the same class.
     */
    #[Test]
    public function both_adviser_tabs_report_the_same_figure(): void
    {
        $roster = [
            $this->completeRow(['lrn' => '600000000001']),
            $this->completeRow(['lrn' => '600000000002', 'birthplace' => '']),
            $this->completeRow(['lrn' => '600000000003', 'parent_guardian' => '']),
        ];

        $html = $this->dashboard($roster);

        // The Dashboard card and the My Students stat bar are both on the
        // page — the tabs are switched client-side.
        $this->assertStringContainsString('<b>2</b><span>Incomplete Data</span>', $html);
        $this->assertStringContainsString('<div class="ms-stat-number">2</div><div class="ms-stat-label">Incomplete Data</div>', $html);
    }

    /**
     * A wasted or at-risk learner whose card is fully entered is NOT
     * incomplete — that was the old measure, and folding it back in would
     * make the figure mean two things at once.
     */
    #[Test]
    public function a_learner_in_poor_health_with_a_full_card_is_not_incomplete(): void
    {
        $roster = [$this->completeRow([
            'nutritional_status_bmi_for_age' => 'Severely Wasted',
        ])];

        $this->assertSame(0, StudentDataCompleteness::countIncomplete($roster));

        $html = $this->dashboard($roster);

        $this->assertStringContainsString('<b>0</b><span>Incomplete Data</span>', $html);
    }

    /** An empty class reports nothing outstanding, not an error. */
    #[Test]
    public function an_empty_class_reports_zero(): void
    {
        $html = $this->dashboard([]);

        $this->assertStringContainsString('<b>0</b><span>Incomplete Data</span>', $html);
    }
}
