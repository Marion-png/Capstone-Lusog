<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Support\AuditSeal;
use Illuminate\Console\Command;

/**
 * Re-computes every audit entry's seal and reports the ones that no longer
 * match. Read-only: it never writes to the audit trail (the database would
 * refuse it anyway).
 *
 * Exit code 1 when any entry is altered, so a scheduled run can alert.
 */
class VerifyAuditTrail extends Command
{
    protected $signature = 'audit:verify
        {--since= : Only entries with an id above this one}';

    protected $description = 'Check every audit trail entry against its HMAC seal and report any that were altered';

    public function handle(): int
    {
        $counts = [AuditSeal::SEALED => 0, AuditSeal::ALTERED => 0, AuditSeal::UNSEALED => 0];
        $altered = [];

        AuditLog::query()
            ->when((int) $this->option('since') > 0, fn ($q) => $q->where('id', '>', (int) $this->option('since')))
            ->chunkById(500, function ($logs) use (&$counts, &$altered): void {
                foreach ($logs as $log) {
                    $status = AuditSeal::status($log);
                    $counts[$status]++;

                    if ($status === AuditSeal::ALTERED) {
                        $altered[] = $log->id;
                    }
                }
            });

        $this->table(['Sealed', 'Altered', 'Unsealed (before sealing)'], [[
            number_format($counts[AuditSeal::SEALED]),
            number_format($counts[AuditSeal::ALTERED]),
            number_format($counts[AuditSeal::UNSEALED]),
        ]]);

        if ($altered !== []) {
            $this->error('Altered entries: '.implode(', ', array_slice($altered, 0, 100)).(count($altered) > 100 ? ' …' : ''));

            return self::FAILURE;
        }

        $this->info('No sealed entry has been altered.');

        return self::SUCCESS;
    }
}
