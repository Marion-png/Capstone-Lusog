<?php

namespace App\Support;

use App\Models\Consultation;
use App\Models\Medicine;
use App\Models\StudentHealthRecord;

/**
 * The clinic dashboard's figures, read once.
 *
 * The School Nurse and the Clinic Teacher open the same dashboard — four
 * cards, this month's top conditions, the medicine stock monitor and the
 * recent consultations — because they are both reading the same clinic. Two
 * routes computing it separately is how two desks end up reporting different
 * numbers for one school on the same afternoon, so both read this.
 *
 * It is a reading and nothing else: every figure is derived at read time from
 * the live tables, nothing here is stored, and no card is backed by a counter
 * somebody has to keep up to date.
 */
class ClinicDashboard
{
    /** How many rows each panel shows. */
    private const RECENT_CONSULTATIONS = 8;

    private const TOP_CONDITIONS = 4;

    private const LOW_STOCK_ITEMS = 4;

    /**
     * Everything the dashboard view needs, keyed as the view names it.
     *
     * Scoped by institution, and a null institution reads nothing per-school
     * rather than falling through to every school's children — the same lock
     * `InstitutionScope` puts on the session.
     *
     * @return array<string, mixed>
     */
    public static function read(?int $institutionId): array
    {
        return [
            'totalRecords' => self::totalRecords($institutionId),
            'atRiskCount' => self::atRiskCount($institutionId),
            'consultationsToday' => self::consultationsToday($institutionId),
            'recentConsultations' => self::recentConsultations($institutionId),
            'topConditions' => self::topConditions($institutionId),
            'lowStockCount' => self::lowStockCount($institutionId),
            'lowStockMedicines' => self::lowStockMedicines($institutionId),
        ];
    }

    private static function totalRecords(?int $institutionId): int
    {
        if (! SchemaCache::hasTable('student_health_records')) {
            return 0;
        }

        return StudentHealthRecord::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->forCurrentSchoolYear()
            ->count();
    }

    private static function atRiskCount(?int $institutionId): int
    {
        if (! SchemaCache::hasTable('student_health_records')) {
            return 0;
        }

        // `is_at_risk` is deliberately a plain column, so this one is a count
        // in SQL rather than a decryption of the whole school.
        return StudentHealthRecord::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->forCurrentSchoolYear()
            ->where('is_at_risk', true)
            ->count();
    }

    private static function consultationsToday(?int $institutionId): int
    {
        if (! SchemaCache::hasTable('consultations')) {
            return 0;
        }

        return Consultation::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->whereDate('consulted_at', now()->toDateString())
            ->count();
    }

    private static function recentConsultations(?int $institutionId)
    {
        if (! SchemaCache::hasTable('consultations')) {
            return collect();
        }

        return Consultation::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->latest('consulted_at')->latest('id')
            ->limit(self::RECENT_CONSULTATIONS)
            ->get();
    }

    private static function topConditions(?int $institutionId)
    {
        if (! SchemaCache::hasTable('consultations')) {
            return collect();
        }

        // `condition` is encrypted at rest, so this month's rows are fetched
        // and tallied in PHP — a SQL GROUP BY would group ciphertext, giving
        // one "condition" per row.
        return Consultation::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->whereMonth('consulted_at', now()->month)
            ->whereYear('consulted_at', now()->year)
            ->get()
            ->groupBy(fn (Consultation $c) => strtolower(trim((string) $c->condition)))
            ->reject(fn ($group, $name) => $name === '')
            ->map(fn ($group, $name) => ['name' => $name, 'total' => $group->count()])
            ->sortByDesc('total')
            ->values()
            ->take(self::TOP_CONDITIONS);
    }

    private static function lowStockCount(?int $institutionId): int
    {
        if (! SchemaCache::hasTable('medicines')) {
            return 0;
        }

        return self::lowStockQuery($institutionId)->count();
    }

    private static function lowStockMedicines(?int $institutionId)
    {
        if (! SchemaCache::hasTable('medicines')) {
            return collect();
        }

        return self::lowStockQuery($institutionId)
            ->orderBy('stock_quantity')
            ->limit(self::LOW_STOCK_ITEMS)
            ->get();
    }

    /**
     * At or below the item's **own** reorder line, never a constant: out of
     * stock is what that medicine's `minimum_threshold` says it is.
     */
    private static function lowStockQuery(?int $institutionId)
    {
        return Medicine::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->whereColumn('stock_quantity', '<=', 'minimum_threshold');
    }
}
