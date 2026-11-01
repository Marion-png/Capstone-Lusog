<?php

namespace App\Support;

use App\Models\SchoolCalendarDay;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * The one reading of which weekdays a school is closed.
 *
 * A weekend was the only day the feeding programme knew it could not feed on,
 * so every public holiday, suspension and break was counted as a feeding day:
 * "day N of 120" ran ahead of the programme, the cycle's last day landed weeks
 * early, and the School Head was told a session was missing on Christmas.
 * `FeedingProgramCycle::isFeedingDay()` asks this class, so every screen that
 * counts, steps over or refuses a day agrees on which days exist.
 *
 * National holidays are read from the Calendarific API (`PublicHolidaySync`)
 * and close every school; suspensions and breaks are entered by the System
 * Admin and close one. All of a school's closed dates are read in one query
 * and memoized for the request, because the cycle asks about one date at a
 * time, sometimes 170 times over.
 */
final class SchoolCalendar
{
    public const KIND_HOLIDAY = 'holiday';

    public const KIND_SUSPENSION = 'suspension';

    public const KIND_BREAK = 'break';

    /** What the System Admin may enter by hand, named as the form names them. */
    public const MANUAL_KINDS = [
        self::KIND_SUSPENSION => 'Class suspension',
        self::KIND_BREAK => 'School break',
    ];

    public const SOURCE_API = 'calendarific';

    public const SOURCE_MANUAL = 'manual';

    /** A manual entry longer than this is a typo, not a break. */
    public const MAX_MANUAL_DAYS = 31;

    /**
     * False until the migration has run: every weekday is then a feeding day,
     * exactly as before this class existed.
     */
    public static function supported(): bool
    {
        return SchemaCache::hasTable('school_calendar_days');
    }

    /** Drop the memoized closed dates after a write that moved them. */
    public static function forget(): void
    {
        RequestMemo::forgetPrefix('calendar:');
    }

    /**
     * Every closed date one school has, keyed by Y-m-d: the national holidays
     * and the school's own suspensions and breaks. NULL reads the national
     * holidays alone.
     *
     * @return array<string, array{name: string, kind: string, source: string, weekday: bool}>
     */
    public static function closures(?int $institutionId): array
    {
        if (! self::supported()) {
            return [];
        }

        return RequestMemo::remember('calendar:closures:'.($institutionId ?? '-'), function () use ($institutionId): array {
            $rows = SchoolCalendarDay::query()
                ->where(function ($query) use ($institutionId): void {
                    $query->whereNull('institution_id');

                    if ($institutionId) {
                        $query->orWhere('institution_id', $institutionId);
                    }
                })
                ->orderBy('starts_on')
                ->get(['institution_id', 'starts_on', 'ends_on', 'name', 'kind', 'source']);

            $closed = [];

            // The school's own entry is the more specific statement about a
            // day, so it is written last and wins over a national holiday on
            // the same date.
            foreach ($rows->sortBy(fn (SchoolCalendarDay $row): int => $row->institution_id === null ? 0 : 1) as $row) {
                $cursor = Carbon::parse($row->starts_on->toDateString());
                $end = $row->ends_on->toDateString();

                for ($guard = 0; $cursor->toDateString() <= $end && $guard < 366; $guard++) {
                    $closed[$cursor->toDateString()] = [
                        'name' => (string) $row->name,
                        'kind' => (string) $row->kind,
                        'source' => (string) $row->source,
                        'weekday' => ! $cursor->isWeekend(),
                    ];
                    $cursor->addDay();
                }
            }

            ksort($closed);

            return $closed;
        });
    }

    /**
     * What closes a school on one date, or null when nothing does. Weekends
     * are not reported here; FeedingProgramCycle::isFeedingDay() asks both.
     *
     * @return array{name: string, kind: string, source: string, weekday: bool}|null
     */
    public static function closureOn(CarbonInterface|string $date, ?int $institutionId): ?array
    {
        return self::closures($institutionId)[self::dateKey($date)] ?? null;
    }

    /**
     * Closed weekdays from $from to $to inclusive: the days a count of school
     * days has to take back off. A closed Saturday was never counted.
     */
    public static function closedWeekdaysBetween(CarbonInterface $from, CarbonInterface $to, ?int $institutionId): int
    {
        $start = $from->toDateString();
        $end = $to->toDateString();
        $count = 0;

        foreach (self::closures($institutionId) as $date => $closure) {
            if ($closure['weekday'] && $date >= $start && $date <= $end) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The closed weekdays, date => name, for the feeding-date picker to lock.
     *
     * @return array<string, string>
     */
    public static function pickerDates(?int $institutionId): array
    {
        return collect(self::closures($institutionId))
            ->filter(fn (array $closure): bool => $closure['weekday'])
            ->map(fn (array $closure): string => $closure['name'])
            ->all();
    }

    /**
     * Why no feeding session is held on a date, or null when one can be.
     *
     * One set of words for every screen that says it, so the sheet, the record
     * screen, the Dashboard's dialog and the endpoint's refusal cannot explain
     * the same day two ways. `label` is the short form for a badge.
     *
     * @return array{kind: string, label: string, title: string, detail: string}|null
     */
    public static function noSession(CarbonInterface|string $date, ?int $institutionId, string $format = 'F j, Y'): ?array
    {
        $day = Carbon::parse(self::dateKey($date));
        $when = $day->format($format);

        if ($day->isWeekend()) {
            return [
                'kind' => 'weekend',
                'label' => 'weekend',
                'title' => $when.' is a '.$day->format('l'),
                'detail' => 'There are no feeding sessions on Saturdays or Sundays.',
            ];
        }

        $closure = self::closureOn($day, $institutionId);

        if ($closure === null) {
            return null;
        }

        $name = rtrim($closure['name'], '. ');

        return match ($closure['kind']) {
            self::KIND_SUSPENSION => [
                'kind' => self::KIND_SUSPENSION,
                'label' => 'class suspension',
                'title' => 'Classes are suspended on '.$when,
                'detail' => $name.'. There is no feeding session.',
            ],
            self::KIND_BREAK => [
                'kind' => self::KIND_BREAK,
                'label' => 'school break',
                'title' => $when.' is during '.$name,
                'detail' => 'There is no feeding session during a school break.',
            ],
            default => [
                'kind' => self::KIND_HOLIDAY,
                'label' => 'holiday',
                'title' => $when.' is '.$name,
                'detail' => 'There are no feeding sessions on holidays.',
            ],
        };
    }

    /** The same answer as one sentence, for a refusal or a flash message. */
    public static function reason(CarbonInterface|string $date, ?int $institutionId, string $format = 'F j, Y'): ?string
    {
        $noSession = self::noSession($date, $institutionId, $format);

        return $noSession === null ? null : $noSession['title'].'. '.$noSession['detail'];
    }

    /**
     * The two calendar years a school year spans: June to December of the
     * first, January to May of the second.
     *
     * @return array{0: int, 1: int}
     */
    public static function yearsOf(string $schoolYear): array
    {
        $first = (int) substr($schoolYear, 0, 4);

        if ($first < 2000) {
            $first = (int) now()->year;
        }

        return [$first, $first + 1];
    }

    /**
     * The first and last day of a school year, June 1 to May 31.
     *
     * @return array{0: string, 1: string}
     */
    public static function periodOf(string $schoolYear): array
    {
        [$first, $second] = self::yearsOf($schoolYear);

        return [$first.'-06-01', $second.'-05-31'];
    }

    private static function dateKey(CarbonInterface|string $date): string
    {
        return is_string($date) ? Carbon::parse($date)->toDateString() : $date->toDateString();
    }
}
