<?php

namespace App\Console\Commands;

use App\Models\Institution;
use App\Support\FutureSchoolYearCleanup;
use App\Support\SchoolYear;
use Illuminate\Console\Command;

/**
 * Lists, and with --force removes, test data filed under school years that
 * have not begun (testers' report #4: screens opened on 2027-2028 and
 * 2028-2029 in November 2026).
 *
 * `php artisan school-year:remove-future` lists what would go and deletes
 * nothing. Read the list, then run it again with `--force`. The current year
 * and every past year are never touched; see FutureSchoolYearCleanup.
 */
class RemoveFutureSchoolYearData extends Command
{
    protected $signature = 'school-year:remove-future
        {--force : Delete what the list shows (without it, nothing is deleted)}
        {--institution= : Limit to one institution id}';

    protected $description = 'List, and with --force delete, test data filed under school years that have not begun';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->line(sprintf(
            '%sCurrent school year (Asia/Manila calendar): %s — only later years are touched.',
            $force ? '' : '[DRY RUN] ',
            SchoolYear::calendar(),
        ));

        $institutions = Institution::query()
            ->when($this->option('institution'), fn ($q, $id) => $q->whereKey((int) $id))
            ->orderBy('id')
            ->get(['id', 'name']);

        if ($institutions->isEmpty()) {
            $this->warn('No institutions to scan.');

            return self::SUCCESS;
        }

        $found = false;

        foreach ($institutions as $institution) {
            $plan = FutureSchoolYearCleanup::plan((int) $institution->id);

            if ($plan['years'] === []) {
                $this->line(sprintf('  #%d %-40s nothing filed under a future school year', $institution->id, $institution->name));

                continue;
            }

            $found = true;
            $this->newLine();
            $this->line(sprintf('  #%d %s — school years not yet begun: %s', $institution->id, $institution->name, implode(', ', $plan['years'])));

            $this->table(
                ['Table', $force ? 'Rows to delete' : 'Rows that would be deleted'],
                array_map(fn (string $table): array => [$table, count($plan['rows'][$table])], array_keys($plan['rows'])),
            );
            $this->line(sprintf(
                '    learners with a future-year row: %d (of whom %d exist only in future years, so their LRN-kept rows go too)',
                $plan['learners'],
                $plan['future_only'],
            ));
            $this->line(sprintf('    files: %d', count($plan['files'])));

            if ($plan['accounts'] !== []) {
                $this->line('    Teaching Assignments reset to the calendar\'s year:');
                foreach ($plan['accounts'] as $account) {
                    $this->line(sprintf('      account #%d (%s) — %s', $account['id'], $account['role'], $account['school_year']));
                }
            }

            if (! $force) {
                continue;
            }

            $result = FutureSchoolYearCleanup::run($plan, 'artisan school-year:remove-future');

            $this->info(sprintf(
                '    deleted %d row(s) across %d table(s), %d file(s); reset %d Teaching Assignment(s).',
                array_sum($result['rows']),
                count($result['rows']),
                $result['files'],
                $result['accounts'],
            ));
        }

        $this->newLine();
        $this->line(match (true) {
            ! $found => 'Nothing is filed under a school year that has not begun.',
            $force => 'Done.',
            default => '[DRY RUN] Nothing was deleted. Run again with --force to delete exactly what is listed.',
        });

        return self::SUCCESS;
    }
}
