<?php

namespace App\Support;

use App\Models\SchoolCalendarDay;
use Illuminate\Support\Facades\DB;

/**
 * Replaces one year's public holidays with what Calendarific says now.
 *
 * Replace, not merge: a proclamation can move a special non-working day or
 * add one, and a merge would keep the date it replaced. The read happens
 * first and the write second, in one transaction, so a failed or empty answer
 * leaves the year exactly as it was and a half-written year is impossible.
 *
 * Only the API's own rows are touched. A suspension or a break the System
 * Admin entered is never removed by a sync.
 */
class PublicHolidaySync
{
    public function __construct(private readonly CalendarificClient $client) {}

    /**
     * @return array{year: int, saved: int, replaced: int, skipped: int}
     */
    public function run(int $year): array
    {
        if (! SchoolCalendar::supported()) {
            throw new HolidayApiFailure('The school calendar table is not ready. Run migrations first.');
        }

        $result = $this->client->holidays($year);
        $now = now();

        $replaced = DB::transaction(function () use ($result, $year, $now): int {
            $replaced = SchoolCalendarDay::query()
                ->where('source', SchoolCalendar::SOURCE_API)
                ->whereBetween('starts_on', [$year.'-01-01', $year.'-12-31'])
                ->delete();

            // A bulk insert rather than one create() per holiday: nothing here
            // is a fact about a person, so the sync is audited once below
            // instead of twenty times.
            SchoolCalendarDay::query()->insert(array_map(fn (array $holiday): array => [
                'institution_id' => null,
                'starts_on' => $holiday['date'],
                'ends_on' => $holiday['date'],
                'name' => $holiday['name'],
                'kind' => SchoolCalendar::KIND_HOLIDAY,
                'source' => SchoolCalendar::SOURCE_API,
                'external_id' => $holiday['external_id'],
                'created_by_name' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ], $result['holidays']));

            return $replaced;
        });

        // The bulk write fired no model event, so the memo is dropped here.
        SchoolCalendar::forget();

        $saved = count($result['holidays']);

        AuditTrail::record(
            'synced',
            'SchoolCalendarDay',
            null,
            'Synced '.$saved.' public holidays for '.$year.' from Calendarific (replaced '.$replaced.')',
            ['year' => $year, 'saved' => $saved, 'replaced' => $replaced, 'skipped' => $result['skipped']],
        );

        return ['year' => $year, 'saved' => $saved, 'replaced' => $replaced, 'skipped' => $result['skipped']];
    }

    /**
     * When each year's holidays were last read from the API, or null for a
     * year never synced.
     *
     * @param  list<int>  $years
     * @return array<int, array{count: int, synced_at: ?string}>
     */
    public static function status(array $years): array
    {
        $status = [];

        foreach ($years as $year) {
            $status[$year] = ['count' => 0, 'synced_at' => null];
        }

        if (! SchoolCalendar::supported() || $years === []) {
            return $status;
        }

        $rows = SchoolCalendarDay::query()
            ->where('source', SchoolCalendar::SOURCE_API)
            ->whereBetween('starts_on', [min($years).'-01-01', max($years).'-12-31'])
            ->get(['starts_on', 'created_at']);

        foreach ($rows as $row) {
            $year = (int) $row->starts_on->year;

            if (! isset($status[$year])) {
                continue;
            }

            $status[$year]['count']++;
            $stamp = $row->created_at?->toDateTimeString();
            if ($stamp !== null && ($status[$year]['synced_at'] === null || $stamp > $status[$year]['synced_at'])) {
                $status[$year]['synced_at'] = $stamp;
            }
        }

        return $status;
    }
}
