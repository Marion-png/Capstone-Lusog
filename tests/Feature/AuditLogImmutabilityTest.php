<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Support\AuditSeal;
use App\Support\AuditTrail;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The audit trail is append-only, and the database says so rather than only
 * the application: the model refuses to change or delete an entry, the
 * table's triggers refuse UPDATE and DELETE from any query, and each entry's
 * HMAC seal exposes one altered behind the triggers' back.
 */
class AuditLogImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private function entry(): AuditLog
    {
        AuditTrail::record('viewed', 'StudentHealthRecord', 7, 'Viewed a learner', ['route_parameters' => ['lrn' => '1234', 'weight' => 35.5]]);

        return AuditLog::query()->latest('id')->firstOrFail();
    }

    #[Test]
    public function every_new_entry_is_sealed_and_reads_back_as_sealed(): void
    {
        $log = $this->entry();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $log->row_hash);
        $this->assertSame(AuditSeal::SEALED, $log->sealStatus());
        $this->assertSame(AuditSeal::SEALED, AuditLog::query()->find($log->id)->sealStatus());
    }

    #[Test]
    public function the_model_refuses_to_change_or_delete_an_entry(): void
    {
        $log = $this->entry();

        try {
            $log->update(['description' => 'Nothing happened here']);
            $this->fail('An audit entry was updated through the model.');
        } catch (LogicException) {
        }

        try {
            $log->delete();
            $this->fail('An audit entry was deleted through the model.');
        } catch (LogicException) {
        }

        $this->assertSame('Viewed a learner', AuditLog::query()->find($log->id)->description);
    }

    #[Test]
    public function the_database_refuses_an_update_from_any_query(): void
    {
        $log = $this->entry();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');

        DB::table('audit_logs')->where('id', $log->id)->update(['description' => 'Nothing happened here']);
    }

    #[Test]
    public function the_database_refuses_a_delete_from_any_query(): void
    {
        $this->entry();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');

        AuditLog::query()->delete();
    }

    #[Test]
    public function an_entry_altered_behind_the_triggers_back_is_reported_altered(): void
    {
        $log = $this->entry();
        $untouched = $this->entry();

        // Somebody with ownership of the database drops the trigger first.
        DB::unprepared('DROP TRIGGER audit_logs_no_update');
        DB::table('audit_logs')->where('id', $log->id)->update(['actor_username' => 'somebody-else']);

        $this->assertSame(AuditSeal::ALTERED, AuditLog::query()->find($log->id)->sealStatus());
        $this->assertSame(AuditSeal::SEALED, AuditLog::query()->find($untouched->id)->sealStatus());

        $this->artisan('audit:verify')
            ->expectsOutputToContain('Altered entries: '.$log->id)
            ->assertFailed();
    }

    #[Test]
    public function a_clean_trail_verifies(): void
    {
        $this->entry();
        $this->entry();

        $this->artisan('audit:verify')
            ->expectsOutputToContain('No sealed entry has been altered.')
            ->assertSuccessful();
    }

    #[Test]
    public function the_system_admin_sees_each_entrys_seal(): void
    {
        $this->entry();

        $this->withSession(['active_role' => 'system_admin', 'active_name' => 'Admin'])
            ->get(route('dashboard.system-admin.audit-logs'))
            ->assertOk()
            ->assertSee('Seal')
            ->assertSee('Sealed');
    }
}
