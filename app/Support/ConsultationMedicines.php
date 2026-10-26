<?php

namespace App\Support;

use App\Models\Consultation;
use App\Models\MedicineDispense;
use App\Models\MedicineDispenseBatch;
use Illuminate\Support\Carbon;

/**
 * What was handed over at each visit: the medicine, how much, and which
 * expiry date it came out of.
 *
 * A dispense is recorded on the consultation it was given at
 * (`medicine_dispenses.consultation_id`), so a list of visits can print the
 * medicine beside the complaint instead of leaving it to be read out of free
 * text. Read for a whole list at once — two queries however many visits the
 * list holds — because the consultation log and the clinic dashboards list
 * them by the page, and a query per row is a page that slows down every week
 * the clinic uses it.
 *
 * Only the clinic reads this. The medicine a child was given is clinical
 * information and stops at the clinic like the complaint and the treatment.
 */
class ConsultationMedicines
{
    /**
     * @param  iterable<Consultation>  $consultations
     * @return array<int, list<array{medicine: string, quantity: int, unit: string, expiries: list<string>, label: string}>>
     */
    public static function forConsultations(iterable $consultations): array
    {
        // Iterated rather than collect()ed: a paginator is Arrayable, and
        // collect() would read its pagination envelope instead of its rows.
        $ids = [];
        foreach ($consultations as $consultation) {
            if ($consultation instanceof Consultation && $consultation->id) {
                $ids[(int) $consultation->id] = (int) $consultation->id;
            }
        }
        $ids = array_values($ids);

        if ($ids === []
            || ! SchemaCache::hasTable('medicine_dispenses')
            || ! SchemaCache::hasColumn('medicine_dispenses', 'consultation_id')) {
            return [];
        }

        // The medicine's name and unit ride the same query; neither is
        // personal information, and both are plain columns.
        $dispenses = MedicineDispense::query()
            ->whereIn('medicine_dispenses.consultation_id', $ids)
            ->leftJoin('medicines', 'medicines.id', '=', 'medicine_dispenses.medicine_id')
            ->orderBy('medicine_dispenses.id')
            ->get([
                'medicine_dispenses.id',
                'medicine_dispenses.consultation_id',
                'medicine_dispenses.quantity',
                'medicines.name as medicine_name',
                'medicines.unit as medicine_unit',
            ]);

        if ($dispenses->isEmpty()) {
            return [];
        }

        $expiries = SchemaCache::hasTable('medicine_dispense_batches')
            ? MedicineDispenseBatch::query()
                ->whereIn('medicine_dispense_id', $dispenses->pluck('id')->all())
                ->orderByRaw('expiry_date is null')
                ->orderBy('expiry_date')
                ->get(['medicine_dispense_id', 'expiry_date'])
                ->groupBy('medicine_dispense_id')
            : collect();

        $byVisit = [];
        foreach ($dispenses as $dispense) {
            $name = trim((string) $dispense->medicine_name);
            $unit = trim((string) $dispense->medicine_unit);
            $dates = collect($expiries->get($dispense->id, []))
                ->map(fn (MedicineDispenseBatch $row) => $row->expiry_date instanceof Carbon ? $row->expiry_date->format('d M Y') : null)
                ->filter()
                ->unique()
                ->values()
                ->all();

            $label = ($name !== '' ? $name : 'Medicine').' × '.(int) $dispense->quantity.($unit !== '' ? ' '.$unit : '');
            if ($dates !== []) {
                $label .= ' (exp '.implode(', ', $dates).')';
            }

            $byVisit[(int) $dispense->consultation_id][] = [
                'medicine' => $name,
                'quantity' => (int) $dispense->quantity,
                'unit' => $unit,
                'expiries' => $dates,
                'label' => $label,
            ];
        }

        return $byVisit;
    }

    /** The visit's medicines as one line, or '' when nothing was given. */
    public static function line(array $byVisit, int $consultationId): string
    {
        return implode('; ', array_column($byVisit[$consultationId] ?? [], 'label'));
    }
}
