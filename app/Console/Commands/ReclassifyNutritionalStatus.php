<?php

namespace App\Console\Commands;

use App\Models\StudentHealthRecord;
use App\Support\FeedingBeneficiarySummary;
use App\Support\NutritionalStatusReclassification;
use Illuminate\Console\Command;

/**
 * Re-reads every stored BMI-for-age and height-for-age status against the
 * WHO 2007 reference (App\Support\BmiClassifier).
 *
 * `php artisan nutrition:reclassify --dry-run` first. Statuses saved before
 * the fix were the adult cut-offs (BMI < 16 / < 18.5 / ≥ 25) applied to
 * children, so most learners under 18.5 were filed one category too low — and
 * the status decides who the feeding programme qualifies. This reads each one
 * again from the BMI, birth date, weigh-in date and sex already on the record
 * (NutritionalStatusReclassification), writes nothing else, and reports:
 *
 * - how each status moved (old → new, with counts);
 * - learners the reference cannot read because their sex or birth date is
 *   missing — their old status is left as it was, for the adviser to complete;
 * - enrolled beneficiaries who no longer qualify, and learners who now
 *   qualify and are not enrolled. Enrolment is never changed here: removing a
 *   learner is the Feeding Coordinator's decision, with a reason.
 *
 * Every write goes through the model, so the audit trail keeps each old and
 * new value.
 */
class ReclassifyNutritionalStatus extends Command
{
    protected $signature = 'nutrition:reclassify
        {--dry-run : Report what would change and write nothing}
        {--institution= : Limit to one institution id}';

    protected $description = 'Re-read stored BMI-for-age and height-for-age statuses against the WHO 2007 growth reference';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $institution = $this->option('institution');

        $query = StudentHealthRecord::query()->orderBy('id');
        if ($institution !== null && $institution !== '') {
            if (! ctype_digit((string) $institution)) {
                $this->error('--institution must be an institution id.');

                return self::FAILURE;
            }
            $query->where('institution_id', (int) $institution);
        }

        $scanned = 0;
        $changed = 0;
        $transitions = [];
        $unreadable = [];
        $noLongerQualify = [];
        $nowQualify = [];

        $query->chunkById(200, function ($records) use ($dryRun, &$scanned, &$changed, &$transitions, &$unreadable, &$noLongerQualify, &$nowQualify): void {
            foreach ($records as $record) {
                $scanned++;
                $result = NutritionalStatusReclassification::for($record);

                if ($result['unreadable'] !== []) {
                    $unreadable[] = sprintf('#%d (%s): %s', $record->id, $record->school_year, implode(', ', $result['unreadable']));
                }

                if ($result['changes'] === []) {
                    continue;
                }

                $changed++;
                $qualifiedBefore = $this->qualifies($record->baseline_nutritional_status, $record->nutritional_status);
                $qualifiedAfter = $this->qualifies(
                    $result['changes']['baseline_nutritional_status'][1] ?? $record->baseline_nutritional_status,
                    $result['changes']['nutritional_status'][1] ?? $record->nutritional_status,
                );
                $enrolled = FeedingBeneficiarySummary::isEnrolled($record);

                if ($enrolled && $qualifiedBefore && ! $qualifiedAfter) {
                    $noLongerQualify[] = sprintf('#%d (%s)', $record->id, $record->school_year);
                }
                if (! $enrolled && ! $qualifiedBefore && $qualifiedAfter) {
                    $nowQualify[] = sprintf('#%d (%s)', $record->id, $record->school_year);
                }

                foreach ($result['changes'] as $field => [$old, $new]) {
                    $key = $field.'|'.($old ?? '(blank)').' → '.$new;
                    $transitions[$key] = ($transitions[$key] ?? 0) + 1;

                    if ($this->output->isVerbose()) {
                        $this->line(sprintf('  #%d %-48s %s → %s', $record->id, $field, $old ?? '(blank)', $new));
                    }
                }

                if (! $dryRun) {
                    NutritionalStatusReclassification::apply($record, $result);
                }
            }
        });

        $this->line(sprintf(
            '%s%d records read, %d %s.',
            $dryRun ? '[DRY RUN] ' : '',
            $scanned,
            $changed,
            $dryRun ? 'would change' : 'changed'
        ));

        if ($transitions !== []) {
            ksort($transitions);
            $this->table(
                ['Field', 'Old → new', 'Records'],
                array_map(function (string $key, int $count): array {
                    [$field, $move] = explode('|', $key, 2);

                    return [$field, $move, $count];
                }, array_keys($transitions), $transitions)
            );
        }

        $this->report('Enrolled beneficiaries who no longer qualify (still enrolled — the coordinator decides)', $noLongerQualify);
        $this->report('Learners who now qualify and are not enrolled (they join the waiting list)', $nowQualify);
        $this->report('Left unchanged: no sex or birth date on file to read the reference by', $unreadable);

        return self::SUCCESS;
    }

    private function qualifies(mixed $baseline, mixed $current): bool
    {
        return FeedingBeneficiarySummary::qualifies((string) $baseline)
            || FeedingBeneficiarySummary::qualifies((string) $current);
    }

    /** @param  list<string>  $records */
    private function report(string $heading, array $records): void
    {
        if ($records === []) {
            return;
        }

        $this->newLine();
        $this->warn(sprintf('%s: %d', $heading, count($records)));
        foreach ($records as $line) {
            $this->line('  '.$line);
        }
    }
}
