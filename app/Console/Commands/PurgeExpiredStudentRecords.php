<?php

namespace App\Console\Commands;

use App\Models\Institution;
use App\Support\StudentRecordPurge;
use App\Support\StudentRetention;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Deletes learner records that have passed the retention window.
 *
 * `php artisan students:purge-expired --dry-run` first, always. This is the
 * only destructive command in the application and there is no undo: a learner
 * off the roll for the retention period loses their Sheet 1 and 2, both
 * weighings, their consent forms and every uploaded document, including the
 * files on disk.
 *
 * It runs **per institution**, never across them, and a learner enrolled in
 * the current school year is never a candidate — `StudentRetention` decides
 * that, not this command.
 */
class PurgeExpiredStudentRecords extends Command
{
    protected $signature = 'students:purge-expired
        {--dry-run : List what would be deleted and delete nothing}
        {--institution= : Limit to one institution id}
        {--as-of= : Judge the retention window against this date (YYYY-MM-DD) instead of today}';

    protected $description = 'Delete learner records past the configured retention period, with everything attached to them';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $asOf = $this->asOf();

        if ($asOf === null) {
            $this->error('--as-of must be a date in YYYY-MM-DD form.');

            return self::FAILURE;
        }

        $years = StudentRetention::years();

        if ($years <= 0) {
            // 0 would mean "delete a learner the moment their school year
            // ends", which is not a retention policy and is far more likely to
            // be a misconfigured environment variable than an intention.
            $this->error('retention.years is '.$years.'. Refusing to run: that would delete records as soon as a school year closes.');

            return self::FAILURE;
        }

        $this->line(sprintf(
            '%sRetention: %d years · judged as of %s',
            $dryRun ? '[DRY RUN] ' : '',
            $years,
            $asOf->toDateString()
        ));

        $institutions = $this->institutions();

        if ($institutions->isEmpty()) {
            $this->warn('No institutions to scan.');

            return self::SUCCESS;
        }

        $totalLearners = 0;
        $totalRows = 0;
        $totalChildren = 0;
        $totalFiles = 0;

        foreach ($institutions as $institution) {
            $preview = StudentRecordPurge::preview($institution->id, $asOf);

            if ($preview['learners'] === 0) {
                $this->line(sprintf('  %-40s nothing past retention', $institution->name));

                continue;
            }

            $this->newLine();
            $this->line(sprintf('  %s — %d learner(s) past retention', $institution->name, $preview['learners']));

            $this->table(
                ['LRN', 'Last enrolled', 'Due since', 'Record rows'],
                array_map(fn (array $row) => [
                    $row['lrn'],
                    $row['last_school_year'],
                    $row['deletion_date'] ?? '—',
                    $row['rows'],
                ], $preview['candidates'])
            );

            if ($dryRun) {
                $this->line(sprintf(
                    '    would delete: %d record row(s), %d attached row(s), %d file(s)',
                    $preview['rows'],
                    $preview['children'],
                    $preview['files']
                ));

                $totalLearners += $preview['learners'];
                $totalRows += $preview['rows'];
                $totalChildren += $preview['children'];
                $totalFiles += $preview['files'];

                continue;
            }

            $result = StudentRecordPurge::purge(
                $institution->id,
                array_column($preview['candidates'], 'lrn'),
                'artisan students:purge-expired',
                $asOf,
            );

            $this->info(sprintf(
                '    deleted: %d learner(s), %d record row(s), %d attached row(s), %d file(s)',
                $result['learners'],
                $result['rows'],
                $result['children'],
                $result['files']
            ));

            $totalLearners += $result['learners'];
            $totalRows += $result['rows'];
            $totalChildren += $result['children'];
            $totalFiles += $result['files'];
        }

        $this->newLine();
        $this->line(sprintf(
            '%s%d learner(s), %d record row(s), %d attached row(s), %d file(s)%s',
            $dryRun ? '[DRY RUN] would delete ' : 'Deleted ',
            $totalLearners,
            $totalRows,
            $totalChildren,
            $totalFiles,
            $dryRun ? ' — nothing was deleted.' : '.'
        ));

        return self::SUCCESS;
    }

    /** @return Collection<int, Institution> */
    private function institutions()
    {
        $one = $this->option('institution');

        if ($one !== null && $one !== '') {
            return Institution::query()->whereKey((int) $one)->get();
        }

        // Every school, one at a time. The purge itself re-scopes every
        // statement to the id it is given, so one school's run can never read
        // or delete another's rows.
        return Institution::query()->orderBy('name')->get();
    }

    private function asOf(): ?CarbonImmutable
    {
        $raw = $this->option('as-of');

        if ($raw === null || $raw === '') {
            return CarbonImmutable::now();
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', (string) $raw)->endOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
