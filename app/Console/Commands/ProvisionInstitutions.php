<?php

namespace App\Console\Commands;

use App\Models\Institution;
use App\Support\InstitutionProvisioner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Builds the private database for schools that do not have one yet.
 *
 * Needed because the 58 seeded Davao City schools predate per-institution
 * databases, and because a provisioning run that fails halfway (server
 * restart, lost connection) can simply be re-run — it is idempotent.
 */
class ProvisionInstitutions extends Command
{
    protected $signature = 'institutions:provision
                            {--institution= : Provision a single institution by id}
                            {--all : Provision every institution that has no database yet}';

    protected $description = 'Create and migrate the private database for each institution';

    public function handle(): int
    {
        if (! $this->option('all') && ! $this->option('institution')) {
            $this->error('Pass --all, or --institution=<id> for a single school.');

            return self::FAILURE;
        }

        $institutions = $this->option('institution')
            ? Institution::where('id', (int) $this->option('institution'))->get()
            : Institution::unprovisioned()->orderBy('id')->get();

        if ($institutions->isEmpty()) {
            $this->info('Nothing to provision — every institution already has a database.');

            return self::SUCCESS;
        }

        $this->info("Provisioning {$institutions->count()} institution(s)...");

        $failed = 0;

        foreach ($institutions as $institution) {
            try {
                $created = InstitutionProvisioner::provision($institution);

                $this->line(sprintf(
                    '  [%d] %s — %s',
                    $institution->id,
                    $institution->name,
                    $created ? 'database created and migrated' : 'already existed, brought up to date',
                ));
            } catch (Throwable $e) {
                $failed++;
                $this->error(sprintf('  [%d] %s — FAILED: %s', $institution->id, $institution->name, $e->getMessage()));
            }
        }

        if ($failed > 0) {
            $this->error("{$failed} institution(s) failed. Re-run to retry just those.");

            return self::FAILURE;
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
