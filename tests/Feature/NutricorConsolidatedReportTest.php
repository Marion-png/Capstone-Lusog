<?php

namespace Tests\Feature;

use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NutricorConsolidatedReportTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTE = '/dashboard/nutricor-consolidated';

    /** Simulate a logged-in nutricor session for the given school. */
    private function nutricorSession(string $schoolName = 'Test School'): array
    {
        return [
            'active_role'        => 'nutricor',
            'active_name'        => 'Test Nutricor',
            'active_username'    => 'nutricor.test',
            'active_school_name' => $schoolName,
        ];
    }

    /** Simulate a non-nutricor session. */
    private function otherRoleSession(string $role = 'school_nurse'): array
    {
        return [
            'active_role'        => $role,
            'active_name'        => 'Other User',
            'active_username'    => 'other.user',
            'active_school_name' => 'Test School',
        ];
    }

    // ── Access control ─────────────────────────────────────────────────────

    #[Test]
    public function unauthenticated_request_redirects_to_login(): void
    {
        $response = $this->get(self::ROUTE);

        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function non_nutricor_role_is_forbidden(): void
    {
        foreach (['school_nurse', 'clinic_staff', 'school_head', 'class_adviser', 'feeding_coor'] as $role) {
            $response = $this->withSession($this->otherRoleSession($role))
                             ->get(self::ROUTE);

            $response->assertStatus(403, "Expected 403 for role: $role");
        }
    }

    #[Test]
    public function nutricor_can_access_consolidated_report(): void
    {
        $response = $this->withSession($this->nutricorSession())
                         ->get(self::ROUTE);

        $response->assertOk();
        $response->assertViewIs('nutricor.consolidated-report');
    }

    // ── Empty state ────────────────────────────────────────────────────────

    #[Test]
    public function report_shows_zeros_when_no_records_exist(): void
    {
        $response = $this->withSession($this->nutricorSession())
                         ->get(self::ROUTE);

        $response->assertOk();
        $response->assertViewHas('totalStudents', 0);
        $response->assertViewHas('baselineTotal', 0);
        $response->assertViewHas('endlineTotal', 0);
    }

    // ── School scoping ─────────────────────────────────────────────────────

    #[Test]
    public function report_is_scoped_to_the_nutricors_school(): void
    {
        StudentHealthRecord::create([
            'student_name'                => 'Student A',
            'student_id'                  => 'LRN001',
            'school_name'                 => 'Test School',
            'section'                     => 'Grade 1 / Sampaguita',
            'weight'                      => 20,
            'bmi_value'                   => 15.0,
            'nutritional_status'          => 'Severely Wasted',
            'baseline_nutritional_status' => 'Severely Wasted',
        ]);

        // Record for a different school — must NOT be counted
        StudentHealthRecord::create([
            'student_name'                => 'Student B',
            'student_id'                  => 'LRN002',
            'school_name'                 => 'Other School',
            'section'                     => 'Grade 1 / Rose',
            'weight'                      => 22,
            'bmi_value'                   => 18.5,
            'nutritional_status'          => 'Normal',
            'baseline_nutritional_status' => 'Normal',
        ]);

        $response = $this->withSession($this->nutricorSession('Test School'))
                         ->get(self::ROUTE);

        $response->assertOk();
        $response->assertViewHas('totalStudents', 1);
        $response->assertViewHas('baselineTotal', 1);
    }

    // ── Baseline aggregation ───────────────────────────────────────────────

    #[Test]
    public function baseline_counts_aggregate_correctly_across_classes(): void
    {
        $rows = [
            ['LRN001', 'Grade 1 / A', 'Severely Wasted'],
            ['LRN002', 'Grade 1 / A', 'Wasted'],
            ['LRN003', 'Grade 2 / B', 'Normal'],
            ['LRN004', 'Grade 2 / B', 'Normal'],
            ['LRN005', 'Grade 3 / C', 'Overweight'],
        ];

        foreach ($rows as [$lrn, $section, $status]) {
            StudentHealthRecord::create([
                'student_name'                => "Student $lrn",
                'student_id'                  => $lrn,
                'school_name'                 => 'Test School',
                'section'                     => $section,
                'weight'                      => 30,
                'bmi_value'                   => 17.0,
                'nutritional_status'          => $status,
                'baseline_nutritional_status' => $status,
            ]);
        }

        $response = $this->withSession($this->nutricorSession())
                         ->get(self::ROUTE);

        $response->assertOk();
        $response->assertViewHas('baselineTotal', 5);

        $bl = $response->viewData('baselineCounts');
        $this->assertSame(1, $bl['Severely Wasted']['count']);
        $this->assertSame(1, $bl['Wasted']['count']);
        $this->assertSame(0, $bl['Underweight']['count']);
        $this->assertSame(2, $bl['Normal']['count']);
        $this->assertSame(1, $bl['Overweight']['count']);
    }

    // ── Endline aggregation ────────────────────────────────────────────────

    #[Test]
    public function endline_counts_aggregate_correctly(): void
    {
        StudentHealthRecord::create([
            'student_name'                => 'Student A',
            'student_id'                  => 'LRN001',
            'school_name'                 => 'Test School',
            'section'                     => 'Grade 1 / A',
            'weight'                      => 25,
            'bmi_value'                   => 18.5,
            'nutritional_status'          => 'Normal',
            'baseline_nutritional_status' => 'Wasted',
            'endline_nutritional_status'  => 'Normal',  // improved
        ]);
        StudentHealthRecord::create([
            'student_name'                => 'Student B',
            'student_id'                  => 'LRN002',
            'school_name'                 => 'Test School',
            'section'                     => 'Grade 1 / A',
            'weight'                      => 22,
            'bmi_value'                   => 16.5,
            'nutritional_status'          => 'Wasted',
            'baseline_nutritional_status' => 'Severely Wasted',
            'endline_nutritional_status'  => 'Wasted',  // improved
        ]);

        $response = $this->withSession($this->nutricorSession())
                         ->get(self::ROUTE);

        $response->assertOk();
        $response->assertViewHas('endlineTotal', 2);

        $el = $response->viewData('endlineCounts');
        $this->assertSame(0, $el['Severely Wasted']['count']);
        $this->assertSame(1, $el['Wasted']['count']);
        $this->assertSame(1, $el['Normal']['count']);
    }

    // ── Baseline-only (no endline yet) ────────────────────────────────────

    #[Test]
    public function student_with_only_baseline_is_counted_in_total_but_not_endline(): void
    {
        StudentHealthRecord::create([
            'student_name'                => 'Student A',
            'student_id'                  => 'LRN001',
            'school_name'                 => 'Test School',
            'section'                     => 'Grade 1 / A',
            'weight'                      => 20,
            'bmi_value'                   => 15.0,
            'nutritional_status'          => 'Severely Wasted',
            'baseline_nutritional_status' => 'Severely Wasted',
            'endline_nutritional_status'  => null,
        ]);

        $response = $this->withSession($this->nutricorSession())
                         ->get(self::ROUTE);

        $response->assertOk();
        $response->assertViewHas('totalStudents', 1);
        $response->assertViewHas('baselineTotal', 1);
        $response->assertViewHas('endlineTotal', 0);
    }

    // ── Endline-only (no baseline) ─────────────────────────────────────────

    #[Test]
    public function student_with_only_endline_is_counted_in_total_but_not_baseline(): void
    {
        StudentHealthRecord::create([
            'student_name'                => 'Student A',
            'student_id'                  => 'LRN001',
            'school_name'                 => 'Test School',
            'section'                     => 'Grade 1 / A',
            'weight'                      => 22,
            'bmi_value'                   => 16.5,
            'nutritional_status'          => 'Wasted',
            'baseline_nutritional_status' => null,
            'endline_nutritional_status'  => 'Normal',
        ]);

        $response = $this->withSession($this->nutricorSession())
                         ->get(self::ROUTE);

        $response->assertOk();
        $response->assertViewHas('totalStudents', 1);
        $response->assertViewHas('baselineTotal', 0);
        $response->assertViewHas('endlineTotal', 1);
    }

    // ── "Not enough data" is excluded from counts ─────────────────────────

    #[Test]
    public function records_with_not_enough_data_status_are_excluded_from_counts(): void
    {
        StudentHealthRecord::create([
            'student_name'                => 'Student A',
            'student_id'                  => 'LRN001',
            'school_name'                 => 'Test School',
            'section'                     => 'Grade 1 / A',
            'weight'                      => 20,
            'bmi_value'                   => 0,    // NOT NULL column; status string is the signal
            'nutritional_status'          => 'Not enough data',
            'baseline_nutritional_status' => 'Not enough data',
            'endline_nutritional_status'  => 'Not enough data',
        ]);

        $response = $this->withSession($this->nutricorSession())
                         ->get(self::ROUTE);

        $response->assertOk();
        $response->assertViewHas('totalStudents', 0);
        $response->assertViewHas('baselineTotal', 0);
        $response->assertViewHas('endlineTotal', 0);
    }

    // ── Section breakdown ──────────────────────────────────────────────────

    #[Test]
    public function section_breakdown_groups_records_by_section(): void
    {
        StudentHealthRecord::create([
            'student_name'                => 'Student A',
            'student_id'                  => 'LRN001',
            'school_name'                 => 'Test School',
            'section'                     => 'Grade 1 / Sampaguita',
            'weight'                      => 20,
            'bmi_value'                   => 15.0,
            'nutritional_status'          => 'Severely Wasted',
            'baseline_nutritional_status' => 'Severely Wasted',
        ]);
        StudentHealthRecord::create([
            'student_name'                => 'Student B',
            'student_id'                  => 'LRN002',
            'school_name'                 => 'Test School',
            'section'                     => 'Grade 2 / Rosal',
            'weight'                      => 30,
            'bmi_value'                   => 18.5,
            'nutritional_status'          => 'Normal',
            'baseline_nutritional_status' => 'Normal',
        ]);

        $response = $this->withSession($this->nutricorSession())
                         ->get(self::ROUTE);

        $breakdown = $response->viewData('sectionBreakdown');
        $this->assertCount(2, $breakdown);
        $this->assertArrayHasKey('Grade 1 / Sampaguita', $breakdown->toArray());
        $this->assertArrayHasKey('Grade 2 / Rosal', $breakdown->toArray());
        $this->assertSame(1, $breakdown['Grade 1 / Sampaguita']['baseline']['Severely Wasted']);
        $this->assertSame(1, $breakdown['Grade 2 / Rosal']['baseline']['Normal']);
    }

    // ── Percentage calculation ─────────────────────────────────────────────

    #[Test]
    public function percentages_are_computed_correctly(): void
    {
        // 1 Severely Wasted + 3 Normal → 25% vs 75%
        foreach (['LRN001' => 'Severely Wasted', 'LRN002' => 'Normal', 'LRN003' => 'Normal', 'LRN004' => 'Normal'] as $lrn => $status) {
            StudentHealthRecord::create([
                'student_name'                => "Student $lrn",
                'student_id'                  => $lrn,
                'school_name'                 => 'Test School',
                'section'                     => 'Grade 1 / A',
                'weight'                      => 30,
                'bmi_value'                   => 18.5,
                'nutritional_status'          => $status,
                'baseline_nutritional_status' => $status,
            ]);
        }

        $response = $this->withSession($this->nutricorSession())
                         ->get(self::ROUTE);

        $bl = $response->viewData('baselineCounts');
        $this->assertSame(25.0, $bl['Severely Wasted']['percent']);
        $this->assertSame(75.0, $bl['Normal']['percent']);
    }
}
