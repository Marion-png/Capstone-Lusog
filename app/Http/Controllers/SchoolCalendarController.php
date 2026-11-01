<?php

namespace App\Http\Controllers;

use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\ParentalConsentForm;
use App\Models\SchoolCalendarDay;
use App\Models\StudentHealthRecord;
use App\Support\CalendarificClient;
use App\Support\HolidayApiFailure;
use App\Support\PublicHolidaySync;
use App\Support\SchemaCache;
use App\Support\SchoolCalendar;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * The System Admin's School Calendar: reading the public holidays from the
 * Calendarific API, and entering the days only a person can know about — a
 * class suspension, a school break.
 *
 * Every action is the System Admin's alone and every write is audited: the
 * sync through AuditTrail (it is a bulk write), a manual entry through the
 * model's Auditable trait.
 */
class SchoolCalendarController extends Controller
{
    /** Syncs per hour. Each one spends two of the free plan's 500 monthly requests. */
    private const SYNCS_PER_HOUR = 6;

    /**
     * What the Control Center's School Calendar section draws.
     *
     * @return array<string, mixed>
     */
    public static function overview(): array
    {
        $schoolYear = ParentalConsentForm::currentSchoolYear();
        [$from, $to] = SchoolCalendar::periodOf($schoolYear);
        $years = SchoolCalendar::yearsOf($schoolYear);
        $supported = SchoolCalendar::supported();

        return [
            'supported' => $supported,
            'configured' => CalendarificClient::isConfigured(),
            'schoolYear' => $schoolYear,
            'status' => PublicHolidaySync::status($years),
            'entries' => $supported
                ? SchoolCalendarDay::query()
                    ->with('institution:id,name')
                    ->where('starts_on', '<=', $to)
                    ->where('ends_on', '>=', $from)
                    ->orderBy('starts_on')
                    ->orderBy('id')
                    ->get()
                : collect(),
            'manualKinds' => SchoolCalendar::MANUAL_KINDS,
        ];
    }

    /** Read both years of the current school year from Calendarific. */
    public function sync(Request $request, PublicHolidaySync $sync): RedirectResponse
    {
        if (! $this->isSystemAdmin($request)) {
            return redirect()->route('login')->with('error', 'Please sign in as System Admin to sync the school calendar.');
        }

        if (! CalendarificClient::isConfigured()) {
            return $this->back()->with('error', 'Holiday sync is not configured. Set CALENDARIFIC_API_KEY.');
        }

        // Every request counts against the monthly quota, failures included,
        // so a button pressed in a loop is stopped here rather than there.
        if (RateLimiter::tooManyAttempts('calendarific-sync', self::SYNCS_PER_HOUR)) {
            $minutes = (int) ceil(RateLimiter::availableIn('calendarific-sync') / 60);

            return $this->back()->with('error', 'Holidays were synced several times this hour. Try again in '.$minutes.' '.($minutes === 1 ? 'minute' : 'minutes').'.');
        }
        RateLimiter::hit('calendarific-sync', 3600);

        $done = [];
        $failed = [];

        foreach (SchoolCalendar::yearsOf(ParentalConsentForm::currentSchoolYear()) as $year) {
            try {
                $result = $sync->run($year);
                $done[] = $year.': '.$result['saved'].' holidays';
            } catch (HolidayApiFailure $e) {
                $failed[] = $year.': '.$e->getMessage();
            }
        }

        $redirect = $this->back();

        if ($done !== []) {
            $redirect->with('success', 'Public holidays synced from Calendarific — '.implode(' · ', $done).'.');
        }

        if ($failed !== []) {
            $redirect->with('error', implode(' ', $failed));
        }

        return $redirect;
    }

    /** Enter a class suspension or a school break for one school. */
    public function store(Request $request): RedirectResponse
    {
        if (! $this->isSystemAdmin($request)) {
            return redirect()->route('login')->with('error', 'Please sign in as System Admin to change the school calendar.');
        }

        if (! SchoolCalendar::supported()) {
            return $this->back()->with('error', 'The school calendar table is not ready. Run migrations first.');
        }

        $validated = $request->validate([
            'institution_id' => ['required', 'integer', Rule::exists('institutions', 'id')],
            'kind' => ['required', Rule::in(array_keys(SchoolCalendar::MANUAL_KINDS))],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'name' => ['required', 'string', 'max:120'],
        ], [], [
            'institution_id' => 'school',
            'starts_on' => 'start date',
            'ends_on' => 'end date',
            'name' => 'reason',
        ]);

        $start = Carbon::parse($validated['starts_on']);
        $end = Carbon::parse($validated['ends_on'] ?? $validated['starts_on']);
        $institutionId = (int) $validated['institution_id'];
        $name = trim(strip_tags($validated['name']));

        if ($name === '') {
            return $this->back()->withInput()->with('error', 'Give the reason the school is closed.');
        }

        if ($start->diffInDays($end) + 1 > SchoolCalendar::MAX_MANUAL_DAYS) {
            return $this->back()->withInput()->with('error', 'An entry can cover at most '.SchoolCalendar::MAX_MANUAL_DAYS.' days. Enter a longer break as more than one.');
        }

        $overlap = SchoolCalendarDay::query()
            ->where('institution_id', $institutionId)
            ->where('starts_on', '<=', $end->toDateString())
            ->where('ends_on', '>=', $start->toDateString())
            ->orderBy('starts_on')
            ->first();

        if ($overlap !== null) {
            return $this->back()->withInput()->with('error', 'That school is already closed for '.$overlap->name.' ('.$this->range($overlap->starts_on, $overlap->ends_on).').');
        }

        // A day the school fed is not a day it was closed. Marking one would
        // take a session off the cycle's count while its marks stayed in
        // every rate, so the two would stop agreeing about the programme.
        $fedOn = $this->firstFeedingDate($institutionId, $start, $end);
        if ($fedOn !== null) {
            return $this->back()->withInput()->with('error', 'Attendance was recorded on '.Carbon::parse($fedOn)->format('F j, Y').', so the school was not closed that day.');
        }

        SchoolCalendarDay::query()->create([
            'institution_id' => $institutionId,
            'starts_on' => $start->toDateString(),
            'ends_on' => $end->toDateString(),
            'name' => mb_substr($name, 0, 120),
            'kind' => $validated['kind'],
            'source' => SchoolCalendar::SOURCE_MANUAL,
            'created_by_name' => (string) $request->session()->get('active_name', 'System Admin'),
        ]);

        $school = Institution::query()->whereKey($institutionId)->value('name');

        return $this->back()->with('success', SchoolCalendar::MANUAL_KINDS[$validated['kind']].' added for '.$school.': '.$this->range($start, $end).'.');
    }

    /** Remove a suspension or break. A synced holiday is the API's to change. */
    public function destroy(Request $request, int $day): RedirectResponse
    {
        if (! $this->isSystemAdmin($request)) {
            return redirect()->route('login')->with('error', 'Please sign in as System Admin to change the school calendar.');
        }

        $entry = SchoolCalendar::supported() ? SchoolCalendarDay::query()->find($day) : null;

        if ($entry === null) {
            return $this->back()->with('error', 'That calendar entry is no longer on file.');
        }

        if ($entry->source !== SchoolCalendar::SOURCE_MANUAL) {
            return $this->back()->with('error', 'A public holiday read from Calendarific cannot be removed here; it would return on the next sync.');
        }

        $label = $entry->name.' ('.$this->range($entry->starts_on, $entry->ends_on).')';
        $entry->delete();

        return $this->back()->with('success', 'Removed '.$label.'.');
    }

    private function isSystemAdmin(Request $request): bool
    {
        return $request->session()->get('active_role') === 'system_admin';
    }

    /** Back to the section, not to the top of the Control Center. */
    private function back(): RedirectResponse
    {
        return redirect()->to(route('dashboard.system-admin').'#school-calendar');
    }

    private function range(Carbon $start, Carbon $end): string
    {
        return $start->isSameDay($end)
            ? $start->format('M j, Y')
            : $start->format('M j, Y').' – '.$end->format('M j, Y');
    }

    private function firstFeedingDate(int $institutionId, Carbon $start, Carbon $end): ?string
    {
        if (! SchemaCache::hasTable('feeding_attendances') || ! SchemaCache::hasTable('student_health_records')) {
            return null;
        }

        $date = FeedingAttendance::query()
            ->whereIn(
                'student_health_record_id',
                StudentHealthRecord::query()->where('institution_id', $institutionId)->select('id')
            )
            ->whereDate('session_date', '>=', $start->toDateString())
            ->whereDate('session_date', '<=', $end->toDateString())
            ->min('session_date');

        return $date === null ? null : Carbon::parse($date)->toDateString();
    }
}
