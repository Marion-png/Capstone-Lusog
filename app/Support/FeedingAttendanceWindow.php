<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * When a feeding day's attendance may be written: on that day, and only then.
 *
 * The coordinator takes attendance on the feeding line and puts a wrong mark
 * right before the day is out — a learner tapped Absent who was at the back of
 * the queue, an excuse that arrived after lunch. So a session stays open all
 * day, recorded or not, and saving it again changes it. Once the day has ended
 * its attendance is a record: no screen offers a way to record, change or fill
 * in a mark for it, and no endpoint accepts one. A day that could be rewritten
 * afterwards is a day any at-risk flag could be argued away from.
 *
 * "The day" is the school's, never the server's. The server runs on UTC, eight
 * hours behind Davao: judged on its clock, a session recorded at lunch would
 * stay open until 8 AM the next morning, and a coordinator recording before
 * 8 AM would be writing yesterday.
 *
 * One reading, asked by the Record Attendance dialog on both surfaces, the
 * standalone record screen, the recording endpoint and the correction endpoint
 * — five places that would otherwise each decide "is it still today" for
 * themselves.
 */
final class FeedingAttendanceWindow
{
    /** Today's date on the school's clock, as Y-m-d. */
    public static function today(): string
    {
        return self::now()->toDateString();
    }

    /** Whether a mark for this date may be recorded or changed now. */
    public static function isOpen(CarbonInterface|string $date): bool
    {
        return self::dateOf($date) === self::today();
    }

    /** The moment today's session closes, for the screens that say so. */
    public static function closesAt(): CarbonInterface
    {
        return self::now()->endOfDay();
    }

    /**
     * Why a date is closed, in the words the coordinator needs — or '' while
     * it is still open.
     */
    public static function closedReason(CarbonInterface|string $date): string
    {
        $iso = self::dateOf($date);
        $today = self::today();

        if ($iso === $today) {
            return '';
        }

        $label = Carbon::parse($iso)->format('F j, Y');

        return $iso < $today
            ? 'Attendance for '.$label.' is closed. A day’s attendance can only be recorded or changed on that day.'
            : 'Attendance for '.$label.' can be recorded on that day, not before.';
    }

    private static function now(): CarbonInterface
    {
        return now((string) config('app.school_timezone'));
    }

    private static function dateOf(CarbonInterface|string $date): string
    {
        return $date instanceof CarbonInterface
            ? $date->toDateString()
            : Carbon::parse($date)->toDateString();
    }
}
