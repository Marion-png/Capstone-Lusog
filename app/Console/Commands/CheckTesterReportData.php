<?php

namespace App\Console\Commands;

use App\Models\Consultation;
use App\Models\Medicine;
use App\Models\StudentHealthRecord;
use App\Support\BmiClassifier;
use App\Support\HealthHistory;
use App\Support\MeasurementPlausibility;
use App\Support\MedicineCatalogue;
use App\Support\RxNormService;
use App\Support\SchemaCache;
use Illuminate\Console\Command;

/**
 * Lists the records testers' report (November 2026) found wrong, and repairs
 * the ones a rule can repair.
 *
 * `php artisan data:tester-checks` lists and changes nothing. Then:
 *
 *   --fix                 the repairs that need no judgement:
 *                         #7  clear an endline status / BMI stamped with no
 *                             endline weighing behind it
 *                         #8  tick Allergies / Hospitalization-Surgery where
 *                             their details are written
 *                         #14 replace a placeholder generic name ("Generic
 *                             name") with the one the item's name gives, and
 *                             look it up in RxNorm
 *   --fix-visit-times     #10 move a clinic visit saved in UTC by the old form
 *                             default (its time equals the moment it was
 *                             created) eight hours on, to Philippine time
 *   --generic="Antifungal cream=Clotrimazole"
 *                         #17 set an item's generic name — the one on the
 *                             clinic's product — and look it up in RxNorm
 *
 * Listed only, for a person to correct, because the right value is not on
 * file: #6 measurements no learner could have, and #15 an item stocked twice.
 * Every write goes through the models, so each is in the audit trail.
 */
class CheckTesterReportData extends Command
{
    protected $signature = 'data:tester-checks
        {--fix : Apply the repairs that need no judgement (#7, #8, #14)}
        {--fix-visit-times : Move clinic visits saved in UTC to Philippine time (#10)}
        {--generic=* : Set an item\'s generic name, as "Item name=Generic" (#17)}
        {--institution= : Limit to one institution id}';

    protected $description = 'List (and optionally repair) the records the November 2026 testers\' report found wrong';

    public function handle(RxNormService $rx): int
    {
        $fix = (bool) $this->option('fix');
        $institution = $this->option('institution') !== null ? (int) $this->option('institution') : null;
        $scoped = fn ($query) => $query->when($institution, fn ($q) => $q->where('institution_id', $institution));

        $this->line($fix ? 'Applying the repairs that need no judgement.' : '[DRY RUN] Listing only — nothing is changed without --fix.');

        if (SchemaCache::hasTable('student_health_records')) {
            $records = $scoped(StudentHealthRecord::query())->orderBy('id')->get();
            $this->measurements($records);
            $this->stampedEndlines($records, $fix);
            $this->healthHistory($records, $fix);
        }

        if (SchemaCache::hasTable('consultations')) {
            $this->visitTimes($scoped(Consultation::query())->orderBy('id')->get(), (bool) $this->option('fix-visit-times'));
        }

        if (SchemaCache::hasTable('medicines')) {
            $medicines = $scoped(Medicine::query())->orderBy('id')->get();
            $this->genericNames($medicines, $fix, $rx);
            $this->duplicates($medicines);
            $this->setGenerics($medicines, (array) $this->option('generic'), $rx);
        }

        return self::SUCCESS;
    }

    /** #6: a baseline or endline no learner could have. Listed, to re-measure. */
    private function measurements($records): void
    {
        $rows = [];

        foreach ($records as $record) {
            $details = is_array($record->student_details) ? $record->student_details : [];
            $sex = isset($details['gender']) ? (string) $details['gender'] : null;

            foreach ([
                'baseline' => [$record->baseline_height_cm ?? ($details['height_cm'] ?? null), $record->baseline_weight_kg ?? ($details['weight_kg'] ?? null), $record->baseline_recorded_at, $record->baseline_age],
                'endline' => [$record->endline_height_cm, $record->endline_weight_kg, $record->endline_recorded_at, $record->endline_age],
            ] as $phase => [$height, $weight, $on, $age]) {
                if (! is_numeric($height) && ! is_numeric($weight)) {
                    continue;
                }

                $problems = MeasurementPlausibility::problems(
                    is_numeric($height) ? (float) $height : null,
                    is_numeric($weight) ? (float) $weight : null,
                    BmiClassifier::ageAtMeasurement($details, $on, $age),
                    $sex,
                );

                if ($problems !== []) {
                    $rows[] = [$record->id, $record->school_year, $record->section, $phase, $height.' cm / '.$weight.' kg', implode(' ', $problems)];
                }
            }
        }

        $this->section('#6 Measurements no learner could have (re-measure, then correct on the learner\'s record)', $rows, ['Record', 'S.Y.', 'Section', 'Weighing', 'Figures', 'Why']);
    }

    /** #7: an endline status or BMI with no endline weighing behind it. */
    private function stampedEndlines($records, bool $fix): void
    {
        $rows = [];

        foreach ($records as $record) {
            $stamped = $record->getRawOriginal('endline_nutritional_status') !== null || $record->getRawOriginal('endline_bmi_value') !== null;

            if (! $stamped || $record->hasEndlineMeasurement()) {
                continue;
            }

            $rows[] = [$record->id, $record->school_year, $record->section];

            if ($fix) {
                $record->forceFill(['endline_nutritional_status' => null, 'endline_bmi_value' => null])->save();
            }
        }

        $this->section('#7 Endline status stamped with no endline weighing'.($fix ? ' — cleared' : ' (--fix clears it)'), $rows, ['Record', 'S.Y.', 'Section']);
    }

    /** #8: a detail written under an unticked Allergies / Hospitalization box. */
    private function healthHistory($records, bool $fix): void
    {
        $rows = [];

        foreach ($records as $record) {
            $details = is_array($record->student_details) ? $record->student_details : [];
            $history = is_array($details['health_history'] ?? null) ? $details['health_history'] : null;

            if ($history === null || ! HealthHistory::needsRepair($history)) {
                continue;
            }

            $rows[] = [$record->id, $record->school_year, $record->section];

            if ($fix) {
                $details['health_history'] = HealthHistory::normalize($history);
                $record->forceFill(['student_details' => $details])->save();
            }
        }

        $this->section('#8 Health history details under an unticked box'.($fix ? ' — ticked' : ' (--fix ticks it)'), $rows, ['Record', 'S.Y.', 'Section']);
    }

    /**
     * #10: a clinic visit whose time is the moment it was created, in UTC —
     * the old form's default, eight hours behind. One typed in Philippine
     * time sits about eight hours after its creation stamp, never on it.
     */
    private function visitTimes($consultations, bool $fix): void
    {
        $rows = [];

        foreach ($consultations as $visit) {
            if ($visit->consulted_at === null || $visit->created_at === null) {
                continue;
            }

            if (abs($visit->consulted_at->getTimestamp() - $visit->created_at->getTimestamp()) > 15 * 60) {
                continue;
            }

            $moved = $visit->consulted_at->copy()->addHours(8);
            $rows[] = [$visit->id, $visit->consulted_at->format('M j, Y g:i A'), $moved->format('M j, Y g:i A')];

            if ($fix) {
                $visit->consulted_at = $moved;
                $visit->save();
            }
        }

        $this->section('#10 Clinic visits saved in UTC'.($fix ? ' — moved to Philippine time' : ' (--fix-visit-times moves them)'), $rows, ['Visit', 'Shows', 'Philippine time']);
    }

    /** #14 and #17: placeholder generics, and a generic the item cannot have. */
    private function genericNames($medicines, bool $fix, RxNormService $rx): void
    {
        $placeholders = [];
        $mismatches = [];

        foreach ($medicines as $medicine) {
            if ($medicine->isSupply()) {
                continue;
            }

            $generic = trim((string) $medicine->generic_name);

            if ($generic !== '' && RxNormService::isPlaceholder($generic)) {
                $implied = MedicineCatalogue::impliedGeneric((string) $medicine->name);
                $placeholders[] = [$medicine->id, $medicine->name, $generic, $implied];

                if ($fix && $implied !== '' && RxNormService::isValidName($implied)) {
                    $this->link($medicine, $implied, $rx);
                }

                continue;
            }

            if ($why = MedicineCatalogue::genericMismatch((string) $medicine->name, $generic)) {
                $mismatches[] = [$medicine->id, $medicine->name, $why];
            }
        }

        $this->section('#14 Placeholder generic names'.($fix ? ' — replaced and looked up' : ' (--fix replaces them)'), $placeholders, ['Item', 'Name', 'Saved as', 'Will be']);
        $this->section('#17 Generic names this kind of item cannot have (set with --generic="Name=Generic")', $mismatches, ['Item', 'Name', 'Why']);
    }

    /** #15: one item stocked twice at a school. Listed, to merge by hand. */
    private function duplicates($medicines): void
    {
        $groups = [];

        foreach ($medicines as $medicine) {
            $generic = MedicineCatalogue::comparableName((string) ($medicine->generic_name ?: MedicineCatalogue::impliedGeneric((string) $medicine->name)));
            $form = MedicineCatalogue::strengthAndForm((string) $medicine->name);
            $key = $form !== '' && $generic !== ''
                ? $medicine->institution_id.'|'.$generic.'|'.$form
                : $medicine->institution_id.'|'.MedicineCatalogue::comparableName((string) $medicine->name);
            $groups[$key][] = $medicine;
        }

        $rows = [];
        foreach ($groups as $items) {
            if (count($items) > 1) {
                $rows[] = [implode(', ', array_map(fn (Medicine $m): string => '#'.$m->id.' '.$m->name.' ('.$m->stock_quantity.' '.$m->unit.')', $items))];
            }
        }

        $this->section('#15 Items stocked more than once (merge with Receive Stock, then remove the extra)', $rows, ['Items']);
    }

    /** #17: set the generic the clinic's product carries, as the user named it. */
    private function setGenerics($medicines, array $assignments, RxNormService $rx): void
    {
        foreach ($assignments as $assignment) {
            [$name, $generic] = array_map('trim', explode('=', (string) $assignment, 2) + [1 => '']);

            if ($name === '' || $generic === '' || ! RxNormService::isValidName($generic) || RxNormService::isPlaceholder($generic)) {
                $this->error('Skipped "'.$assignment.'": write it as "Item name=Generic name".');

                continue;
            }

            $matched = $medicines->filter(fn (Medicine $m): bool => MedicineCatalogue::comparableName((string) $m->name) === MedicineCatalogue::comparableName($name));

            if ($matched->isEmpty()) {
                $this->error('No item named "'.$name.'".');

                continue;
            }

            foreach ($matched as $medicine) {
                $this->link($medicine, $generic, $rx);
                $this->info('#'.$medicine->id.' '.$medicine->name.': generic set to '.$generic.' (RxNorm: '.$medicine->rx_status.').');
            }
        }
    }

    private function link(Medicine $medicine, string $generic, RxNormService $rx): void
    {
        if (RxNormService::supported()) {
            $medicine->applyRxNorm($generic, $rx->lookup($generic));

            return;
        }

        $medicine->forceFill(['generic_name' => $generic])->save();
    }

    /** @param  list<list<mixed>>  $rows */
    private function section(string $title, array $rows, array $headers): void
    {
        $this->newLine();
        $this->line($title.': '.count($rows));

        if ($rows !== []) {
            $this->table($headers, $rows);
        }
    }
}
