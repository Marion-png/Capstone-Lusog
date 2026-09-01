<?php

namespace App\Support;

use App\Models\Medicine;
use App\Models\MedicineDispense;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How fast each medicine is actually being consumed.
 *
 * Every draw on stock is a `medicine_dispenses` row, so consumption is a
 * matter of record — this class reads it and nothing else. It replaces a
 * generator that invented six months of "seasonal" figures from a hash of
 * the medicine's name and fed them to a recommended-order calculation: a
 * reorder quantity computed from numbers nobody dispensed is worse than no
 * reorder quantity at all, because it looks like evidence.
 *
 * `medicine_id`, `quantity`, `dispensed_at` and `institution_id` are all
 * plain columns, so this is the rare clinic read that can group in SQL. The
 * learner's name and the reason are encrypted and are never touched here —
 * how much paracetamol the school used is a stock question, not a clinical
 * one, and this class deliberately cannot answer who received any of it.
 */
class MedicineUsage
{
    /** Months of history the trend reads over, including the current one. */
    public const MONTHS = 6;

    /**
     * Usage per calendar month for one medicine, oldest month first.
     *
     * Months with no dispensing are present with a zero — a gap in a trend
     * has to be visible as a gap, not closed up so the line looks smooth.
     *
     * @return list<array{month: string, label: string, used: int}>
     */
    public static function monthlyFor(int $medicineId, mixed $institutionId, int $months = self::MONTHS): array
    {
        $totals = self::monthlyTotals($institutionId, $months)->get($medicineId, collect());

        return self::monthWindow($months)
            ->map(fn (Carbon $month): array => [
                'month' => $month->format('M'),
                'label' => $month->format('M Y'),
                'used' => (int) $totals->get($month->format('Y-m'), 0),
            ])
            ->all();
    }

    /**
     * Usage per medicine per month across the school, keyed by medicine id
     * then by `Y-m`. One query for the whole page.
     *
     * @return Collection<int, Collection<string, int>>
     */
    public static function monthlyTotals(mixed $institutionId, int $months = self::MONTHS): Collection
    {
        if (! SchemaCache::hasTable('medicine_dispenses')) {
            return collect();
        }

        $since = self::monthWindow($months)->first()->copy()->startOfMonth();

        return MedicineDispense::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->where('dispensed_at', '>=', $since)
            ->get(['medicine_id', 'quantity', 'dispensed_at'])
            ->groupBy('medicine_id')
            ->map(fn (Collection $rows): Collection => $rows
                ->groupBy(fn (MedicineDispense $row): string => $row->dispensed_at->format('Y-m'))
                ->map(fn (Collection $inMonth): int => (int) $inMonth->sum('quantity'))
            );
    }

    /**
     * The figures a stock table needs beside each medicine: what went out
     * this month, and the average month over the window.
     *
     * `months_of_history` is how many months actually carry a dispense. A
     * mean taken over a window the clinic has not been running that long is
     * not an average of anything, and the caller needs to be able to say so.
     *
     * @return array{this_month: int, average: float, total: int, months_of_history: int}
     */
    public static function summaryFor(int $medicineId, mixed $institutionId, int $months = self::MONTHS): array
    {
        $rows = self::monthlyFor($medicineId, $institutionId, $months);
        $used = array_column($rows, 'used');

        return [
            'this_month' => (int) (end($used) ?: 0),
            'average' => $used === [] ? 0.0 : round(array_sum($used) / count($used), 1),
            'total' => (int) array_sum($used),
            'months_of_history' => count(array_filter($used, fn (int $n): bool => $n > 0)),
        ];
    }

    /**
     * How long the stock on hand lasts at the recent rate, in months.
     *
     * NULL when nothing has been dispensed: dividing by a rate of zero is
     * not "forever", it is "no idea", and the difference matters to somebody
     * deciding whether to reorder.
     */
    public static function monthsOfCover(Medicine $medicine, mixed $institutionId): ?float
    {
        $average = self::summaryFor((int) $medicine->id, $institutionId)['average'];

        if ($average <= 0) {
            return null;
        }

        return round(((int) $medicine->stock_quantity) / $average, 1);
    }

    /**
     * The months the window covers, oldest first, each as the first of the
     * month so formatting and grouping agree.
     *
     * @return Collection<int, Carbon>
     */
    private static function monthWindow(int $months): Collection
    {
        $months = max(1, $months);
        $start = now()->startOfMonth()->subMonths($months - 1);

        return collect(range(0, $months - 1))
            ->map(fn (int $offset): Carbon => $start->copy()->addMonths($offset));
    }
}
