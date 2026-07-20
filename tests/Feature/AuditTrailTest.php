<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The audit trail must record who accessed, created, modified, or deleted
 * personal and sensitive personal information, and when.
 */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    private function adviserSession(): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Test Adviser',
            'active_username' => 'adviser1',
            'active_institution_id' => $this->institution->id,
            'assigned_grade_level' => 'Grade 1',
            'assigned_section' => 'Sampaguita',
        ];
    }

    private function makeRecord(): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'institution_id' => $this->institution->id,
            'student_id' => 'LRN001',
            'student_name' => 'Dela Cruz, Juan A.',
            'section' => 'Grade 1 / Sampaguita',
            'weight' => 30.0,
            'bmi_value' => 16.5,
            'nutritional_status' => 'Normal',
        ]);
    }

    /** @test */
    public function creating_a_health_record_is_audited(): void
    {
        $record = $this->makeRecord();

        $log = AuditLog::where('action', 'created')->where('subject_type', 'StudentHealthRecord')->first();
        $this->assertNotNull($log);
        $this->assertSame($record->id, (int) $log->subject_id);
    }

    /** @test */
    public function updating_a_health_record_logs_the_changed_fields_with_old_and_new_values(): void
    {
        $record = $this->makeRecord();
        $record->update(['weight' => 32.5, 'nutritional_status' => 'Wasted']);

        $log = AuditLog::where('action', 'updated')->where('subject_type', 'StudentHealthRecord')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('weight', $log->description);

        $changes = $log->details['changes'];
        $this->assertSame('30', (string) $changes['weight']['from']);
        $this->assertSame('32.5', (string) $changes['weight']['to']);
        $this->assertSame('Normal', $changes['nutritional_status']['from']);
        $this->assertSame('Wasted', $changes['nutritional_status']['to']);

        // The forensic payload itself must be ciphertext in the database.
        $raw = DB::table('audit_logs')->where('id', $log->id)->value('details');
        $this->assertStringNotContainsString('Wasted', (string) $raw);
    }

    /** @test */
    public function deleting_a_health_record_logs_a_snapshot(): void
    {
        $record = $this->makeRecord();
        $record->delete();

        $log = AuditLog::where('action', 'deleted')->where('subject_type', 'StudentHealthRecord')->first();
        $this->assertNotNull($log);
        $this->assertSame('Dela Cruz, Juan A.', $log->details['snapshot']['student_name']);
    }

    /** @test */
    public function viewing_a_sensitive_page_is_audited_with_actor_and_ip(): void
    {
        $this->withSession($this->adviserSession())->get('/dashboard/class-adviser');

        $log = AuditLog::where('action', 'viewed')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('adviser1', $log->actor_username);
        $this->assertSame('class_adviser', $log->actor_role);
        $this->assertNotNull($log->ip_address);
        $this->assertStringContainsString('dashboard/class-adviser', (string) $log->url);
    }

    /** @test */
    public function unauthenticated_public_pages_are_not_audited(): void
    {
        $this->get('/login');

        $this->assertSame(0, AuditLog::where('action', 'viewed')->count());
    }

    /** @test */
    public function failed_and_successful_logins_are_audited(): void
    {
        DB::table('accounts')->insert([
            'name' => 'Test Adviser',
            'username' => 'adviser1',
            'password_hash' => Hash::make('secret123'),
            'role' => 'class_adviser',
            'institution_id' => $this->institution->id,
            'school_name' => 'Test School',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->post('/login', ['email' => 'adviser1', 'password' => 'wrong']);
        $this->assertSame(1, AuditLog::where('action', 'login_failed')->count());

        $this->post('/login', ['email' => 'adviser1', 'password' => 'secret123']);
        $this->assertSame(1, AuditLog::where('action', 'login')->count());
    }

    /** @test */
    public function only_the_system_admin_can_open_the_audit_trail_viewer(): void
    {
        $this->withSession($this->adviserSession())
            ->get(route('dashboard.system-admin.audit-logs'))
            ->assertRedirect(route('login'));

        $this->makeRecord();

        $this->withSession(['active_role' => 'system_admin', 'active_name' => 'System Admin'])
            ->get(route('dashboard.system-admin.audit-logs'))
            ->assertOk()
            ->assertSee('Audit Trail')
            ->assertSee('created');
    }
}
