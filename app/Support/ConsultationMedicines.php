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
     * @return array<int, list<array{medicine: string, quantity: int, unit: string, expiries: list<string>, label: string, dispensed_by: string, dispensed_by_role: string}>>
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
                'medicine_dispenses.dispensed_by_name',
                'medicine_dispenses.dispensed_by_role',
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
                'dispensed_by' => trim((string) $dispense->dispensed_by_name),
                'dispensed_by_role' => (string) $dispense->dispensed_by_role,
            ];
        }

        return $byVisit;
    }

    /**
     * Who recorded the visit, and as what: the name kept on it, or — on a
     * visit from before that was kept and the audit trail could not name —
     * whoever dispensed on it, since a dispense is written by the same save
     * as its visit. Blank when neither is on file; never a guess.
     *
     * @return array{name: string, role: string}
     */
    public static function recordedBy(array $byVisit, Consultation $consultation): array
    {
        $name = trim((string) $consultation->recorded_by_name);
        $role = (string) $consultation->recorded_by_role;

        if ($name === '') {
            foreach ($byVisit[(int) $consultation->id] ?? [] as $given) {
                if ($given['dispensed_by'] !== '') {
                    [$name, $role] = [$given['dispensed_by'], $given['dispensed_by_role']];
                    break;
                }
            }
        }

        return ['name' => $name, 'role' => $name !== '' && $role !== '' ? AccountSettings::roleLabel($role) : ''];
    }

    /**
     * What the consultation dialog needs to open on a recorded visit. It rides
     * the Edit button, which only the roles in Consultation::EDIT_ROLES are
     * drawn, so it reaches nobody who could not already read the visit.
     *
     * @return array<string, mixed>
     */
    public static function forEditing(array $byVisit, Consultation $consultation): array
    {
        return [
            'id' => (int) $consultation->id,
            'url' => route('consultations.update', $consultation->id),
            'consulted_at' => $consultation->consulted_at?->format('Y-m-d\TH:i') ?? '',
            'status' => (string) $consultation->status,
            'name' => (string) $consultation->student_name,
            'section' => (string) $consultation->grade_section,
            'condition_id' => $consultation->condition_id,
            'condition' => (string) $consultation->condition,
            'treatment' => (string) $consultation->treatment_given,
            'notes' => (string) $consultation->notes,
            'notes_shared' => (bool) $consultation->notes_shared_with_adviser,
            'medicine' => self::line($byVisit, (int) $consultation->id),
        ];
    }

    /** The visit's medicines as one line, or '' when nothing was given. */
    public static function line(array $byVisit, int $consultationId): string
    {
        return implode('; ', array_column($byVisit[$consultationId] ?? [], 'label'));
    }
}
