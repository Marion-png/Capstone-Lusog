<?php

namespace Tests\Feature;

use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The adviser's Feeding Status page derives everything it shows — eligibility,
 * program state, attendance rate, assessment progress — from the database
 * records; nothing on it is hand-tagged.
 */
class AdviserFeedingStatusTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    /** @param  list<array<string, mixed>>  $roster */
    private function adviserSession(array $roster = []): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Test Adviser',
            'active_username' => 'adviser1',
            'active_institution_id' => $this->institution->id,
            'active_school_name' => 'Test School',
            'assigned_school_name' => 'Test School',
            'assigned_grade_level' => 'Grade 10',
            'assigned_section' => 'Dalton',
            'school_health_card_records' => $roster,
        ];
    }

    /** @param  array<string, mixed>  $attributes */
    private function record(array $attributes): StudentHealthRecord
    {
        return StudentHealthRecord::create(array_merge([
            'institution_id' => $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'school_name' => 'Test School',
            'section' => 'Grade 10 / Dalton',
            'weight' => '',
            'bmi_value' => '',
            'nutritional_status' => '',
            'student_details' => [
                'grade_level' => 'Grade 10',
                'section' => 'Dalton',
            ],
        ], $attributes));
    }

    #[Test]
    public function it_derives_eligibility_program_state_and_attendance_rate(): void
    {
        $wasted = $this->record([
            'student_id' => '100000000001',
            'student_name' => 'Reyes, Maria',
            'weight' => '32.5',
            'bmi_value' => '14.2',
            'nutritional_status' => 'Wasted',
            'baseline_weight_kg' => '32.5',
            'baseline_recorded_at' => now()->subMonths(2),
            'attendance_sessions_count' => 6,
        ]);

        $completed = $this->record([
            'student_id' => '100000000002',
            'student_name' => 'Gomez, Jose',
            'bmi_value' => '13.1',
            'nutritional_status' => 'Severely Wasted',
            'baseline_weight_kg' => '28.0',
            'baseline_recorded_at' => now()->subMonths(3),
            'endline_weight_kg' => '31.0',
            'endline_recorded_at' => now()->subWeek(),
            'attendance_sessions_count' => 10,
        ]);

        $normal = $this->record([
            'student_id' => '100000000003',
            'student_name' => 'Dela Cruz, Juan',
            'bmi_value' => '18.4',
            'nutritional_status' => 'Normal',
        ]);

        foreach ([$wasted->id => 8, $completed->id => 10] as $recordId => $sessions) {
            for ($i = 0; $i < $sessions; $i++) {
                FeedingAttendance::create([
                    'student_health_record_id' => $recordId,
                    'session_date' => now()->subDays($sessions - $i),
                    'is_present' => true,
                ]);
            }
        }

        $roster = [
            ['lrn' => '100000000001', 'grade_level' => 'Grade 10', 'section' => 'Dalton'],
            ['lrn' => '100000000002', 'grade_level' => 'Grade 10', 'section' => 'Dalton'],
            ['lrn' => '100000000003', 'grade_level' => 'Grade 10', 'section' => 'Dalton'],
        ];

        $response = $this->withSession($this->adviserSession($roster))
            ->get('/dashboard/class-adviser/feeding-status')
            ->assertStatus(200);

        $students = $response->viewData('students');
        $stats = $response->viewData('stats');

        // Sorted by learner name, decrypted and ordered in PHP.
        $this->assertSame(
            ['Dela Cruz, Juan', 'Gomez, Jose', 'Reyes, Maria'],
            $students->pluck('name')->all()
        );

        $byLrn = $students->keyBy('lrn');

        $this->assertTrue($byLrn['100000000001']['eligible']);
        $this->assertSame('ongoing', $byLrn['100000000001']['program']);
        $this->assertSame('pending', $byLrn['100000000001']['assessment']);
        $this->assertSame(6, $byLrn['100000000001']['attended']);
        $this->assertSame(8, $byLrn['100000000001']['sessions']);
        $this->assertSame(75, $byLrn['100000000001']['rate']);

        $this->assertSame('completed', $byLrn['100000000002']['program']);
        $this->assertSame('complete', $byLrn['100000000002']['assessment']);
        $this->assertSame('severely-wasted', $byLrn['100000000002']['status_key']);
        $this->assertSame(100, $byLrn['100000000002']['rate']);

        $this->assertFalse($byLrn['100000000003']['eligible']);
        $this->assertSame('not-enrolled', $byLrn['100000000003']['program']);
        $this->assertSame('none', $byLrn['100000000003']['assessment']);
        $this->assertSame(0, $byLrn['100000000003']['sessions']);
        $this->assertSame(0, $byLrn['100000000003']['rate']);

        $this->assertSame(3, $stats['total']);
        $this->assertSame(1, $stats['normal']);
        $this->assertSame(1, $stats['wasted']);
        $this->assertSame(1, $stats['severely_wasted']);
        $this->assertSame(2, $stats['enrolled']);
        $this->assertSame(1, $stats['ongoing']);
        $this->assertSame(1, $stats['completed']);
        // 16 sessions attended out of the 18 recorded across the class.
        $this->assertSame(89, $stats['attendance_rate']);

        $response->assertSee('Severely Wasted')
            ->assertSee('Feeding Attendance &amp; Progress', false)
            ->assertSee('6 / 8');
    }

    #[Test]
    public function the_roster_is_rebuilt_from_the_database_when_the_session_is_lost(): void
    {
        $this->record([
            'student_id' => '100000000009',
            'student_name' => 'Santos, Andrei',
            'nutritional_status' => 'Wasted',
            'student_details' => [
                'last_name' => 'Santos',
                'first_name' => 'Andrei',
                'grade_level' => 'Grade 10',
                'section' => 'Dalton',
            ],
        ]);

        // No school_health_card_records in the session at all.
        $response = $this->withSession($this->adviserSession())
            ->get('/dashboard/class-adviser/feeding-status')
            ->assertStatus(200);

        $this->assertSame(1, $response->viewData('stats')['total']);
        $response->assertSee('Santos, Andrei');
    }

    #[Test]
    public function another_schools_learners_never_appear(): void
    {
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);

        StudentHealthRecord::create([
            'institution_id' => $other->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'school_name' => 'Other School',
            'section' => 'Grade 10 / Dalton',
            'weight' => '',
            'bmi_value' => '',
            'student_id' => '100000000077',
            'student_name' => 'Outsider, Nina',
            'nutritional_status' => 'Wasted',
            'student_details' => ['grade_level' => 'Grade 10', 'section' => 'Dalton'],
        ]);

        $roster = [['lrn' => '100000000077', 'grade_level' => 'Grade 10', 'section' => 'Dalton']];

        $response = $this->withSession($this->adviserSession($roster))
            ->get('/dashboard/class-adviser/feeding-status')
            ->assertStatus(200);

        $this->assertSame(0, $response->viewData('stats')['total']);
        $response->assertDontSee('Outsider, Nina');
    }
}
