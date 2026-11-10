<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\SchoolCalendarDay;
use App\Models\StudentHealthRecord;
use App\Support\CalendarificClient;
use App\Support\FeedingProgramCycle;
use App\Support\HolidayApiFailure;
use App\Support\PublicHolidaySync;
use App\Support\SchoolCalendar;
use Carbon\Carbon;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The school calendar and the Calendarific integration behind it.
 *
 * A weekend used to be the only day the feeding programme knew it could not
 * feed on, so a public holiday counted as a feeding day: "day N of 120" ran
 * ahead, the cycle ended weeks early, and the School Head was told a session
 * was missing on Christmas. These tests pin the integration (what is asked,
 * what is kept, what a failure leaves behind) and every screen it changes.
 *
 * No test reaches Calendarific: phpunit.xml blanks the key and every test that
 * needs one sets it here and fakes the HTTP client.
 */
class SchoolCalendarTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-calendarific-key-123';

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
        config(['services.calendarific.key' => self::KEY]);
        Sleep::fake();
    }

    // ── The API client ──────────────────────────────────────────────────

    #[Test]
    public function a_sync_keeps_only_the_days_that_close_a_school(): void
    {
        $this->fakeCalendarific();

        $result = app(PublicHolidaySync::class)->run(2026);

        $this->assertSame(['year' => 2026, 'saved' => 4, 'replaced' => 0, 'skipped' => 5], $result);
        $this->assertSame(
            ['2026-01-01', '2026-04-04', '2026-11-30', '2026-12-25'],
            SchoolCalendarDay::query()->orderBy('starts_on')->get()->map(fn ($d) => $d->starts_on->toDateString())->all()
        );

        // National holidays close every school, so they carry none.
        $this->assertSame(0, SchoolCalendarDay::query()->whereNotNull('institution_id')->count());
        $this->assertSame('Bonifacio Day', SchoolCalendar::closureOn('2026-11-30', $this->institution->id)['name']);

        // A special working day is not a day off, a season is not a holiday,
        // and BARMM's holiday does not close a school in Davao.
        $this->assertNull(SchoolCalendar::closureOn('2026-01-23', $this->institution->id));
        $this->assertNull(SchoolCalendar::closureOn('2026-03-20', $this->institution->id));
        $this->assertNull(SchoolCalendar::closureOn('2026-05-26', $this->institution->id));
    }

    #[Test]
    public function the_request_carries_a_country_and_a_year_and_nothing_about_anyone(): void
    {
        $this->fakeCalendarific();

        app(CalendarificClient::class)->holidays(2026);

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://calendarific.com/api/v2/holidays')
                && $query === ['api_key' => self::KEY, 'country' => 'PH', 'year' => '2026', 'type' => 'national'];
        });
    }

    #[Test]
    public function a_resync_replaces_the_years_holidays_and_leaves_the_school_entries_alone(): void
    {
        $this->fakeCalendarific();
        $suspension = $this->suspension('2026-11-16', '2026-11-16');

        app(PublicHolidaySync::class)->run(2026);
        $result = app(PublicHolidaySync::class)->run(2026);

        $this->assertSame(4, $result['replaced']);
        $this->assertSame(4, SchoolCalendarDay::query()->where('source', SchoolCalendar::SOURCE_API)->count());
        $this->assertTrue($suspension->fresh() !== null);
    }

    #[Test]
    public function a_sync_is_audited_once(): void
    {
        $this->fakeCalendarific();
        $before = (int) AuditLog::query()->max('id');

        app(PublicHolidaySync::class)->run(2026);

        $entries = AuditLog::query()->where('id', '>', $before)->get();
        $this->assertCount(1, $entries);
        $this->assertSame('synced', $entries->first()->action);
        $this->assertStringContainsString('4 public holidays for 2026', (string) $entries->first()->description);
    }

    #[Test]
    public function a_refused_key_says_so_and_changes_nothing(): void
    {
        $this->holiday('2026-12-25', 'Christmas Day');
        Http::fake(['calendarific.com/*' => Http::response([
            'meta' => ['code' => 401, 'error_type' => 'auth failed', 'error_detail' => 'Missing or invalid api credentials.'],
            'response' => [],
        ], 401)]);

        $this->assertSyncFails(2026, 'Calendarific refused the API key');
        $this->assertSame(1, SchoolCalendarDay::query()->count());
    }

    #[Test]
    public function an_error_reported_inside_a_200_is_still_an_error(): void
    {
        Http::fake(['calendarific.com/*' => Http::response([
            'meta' => ['code' => 401, 'error_type' => 'auth failed', 'error_detail' => 'Missing or invalid api credentials.'],
            'response' => [],
        ], 200)]);

        $this->assertSyncFails(2026, 'Calendarific refused the API key');
    }

    #[Test]
    public function a_spent_monthly_quota_is_not_asked_again(): void
    {
        Http::fake(['calendarific.com/*' => Http::response(['meta' => ['code' => 429, 'error_type' => 'rate_limit_exceeded'], 'response' => []], 429)]);

        $this->assertSyncFails(2026, 'monthly Calendarific request limit');
        // Every request counts against the quota, failures included.
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_provider_outage_is_retried_then_reported_with_the_holidays_kept(): void
    {
        $this->holiday('2026-12-25', 'Christmas Day');
        Http::fake(['calendarific.com/*' => Http::response('Service Unavailable', 503)]);

        $this->assertSyncFails(2026, 'Calendarific is unavailable at the moment');
        Http::assertSentCount(3);
        $this->assertSame(1, SchoolCalendarDay::query()->count());
    }

    #[Test]
    public function an_unreadable_or_empty_answer_never_wipes_a_year(): void
    {
        $this->holiday('2026-12-25', 'Christmas Day');

        Http::fakeSequence('calendarific.com/*')
            ->push(['meta' => ['code' => 200], 'response' => ['holidays' => []]], 200)
            ->push(['meta' => ['code' => 200], 'response' => ['holidays' => 'nothing']], 200)
            ->push(['meta' => ['code' => 200]], 200);

        for ($i = 0; $i < 3; $i++) {
            $this->assertSyncFails(2026, 'Nothing was changed');
            $this->assertSame(1, SchoolCalendarDay::query()->count());
        }
    }

    #[Test]
    public function a_connection_failure_is_logged_without_the_api_key(): void
    {
        Exceptions::fake();
        Http::fake(fn (Request $request) => throw new ConnectException(
            'cURL error 6: Could not resolve host for '.$request->url(),
            new PsrRequest('GET', $request->url())
        ));

        $this->assertSyncFails(2026, 'Calendarific could not be reached');

        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), '[redacted]')
            && ! str_contains($e->getMessage(), self::KEY));
    }

    #[Test]
    public function with_no_key_nothing_is_sent(): void
    {
        config(['services.calendarific.key' => '']);
        Http::fake();

        $this->assertSyncFails(2026, 'Set CALENDARIFIC_API_KEY');
        Http::assertNothingSent();
    }

    // ── The feeding cycle ───────────────────────────────────────────────

    #[Test]
    public function a_weekday_holiday_is_taken_off_the_count_and_a_weekend_one_is_not(): void
    {
        $monday = Carbon::parse('2026-11-23');
        $friday = Carbon::parse('2026-12-04');

        $this->assertSame(10, FeedingProgramCycle::countFeedingDays($monday, $friday, $this->institution->id));

        $this->holiday('2026-11-30', 'Bonifacio Day');      // a Monday
        $this->holiday('2026-11-28', 'A Saturday holiday');  // never counted

        $this->assertFalse(FeedingProgramCycle::isFeedingDay('2026-11-30', $this->institution->id));
        $this->assertSame(9, FeedingProgramCycle::countFeedingDays($monday, $friday, $this->institution->id));
    }

    #[Test]
    public function the_cycle_ends_one_feeding_day_later_for_every_closed_weekday_inside_it(): void
    {
        $learner = $this->beneficiary();
        $start = now()->startOfWeek(Carbon::MONDAY);
        $this->mark($learner, $start->toDateString());

        $before = FeedingProgramCycle::forInstitution($this->institution->id)->endDateIso();

        $this->holiday($start->copy()->addWeeks(2)->toDateString(), 'Test Holiday');
        $this->suspension($start->copy()->addWeeks(3)->addDay()->toDateString(), $start->copy()->addWeeks(3)->addDays(2)->toDateString());

        $after = FeedingProgramCycle::forInstitution($this->institution->id)->endDateIso();

        $this->assertSame($this->weekdaysAfter($before, 3), $after);
    }

    #[Test]
    public function a_schools_suspension_closes_that_school_and_no_other(): void
    {
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $this->suspension('2026-11-16', '2026-11-16');

        $this->assertFalse(FeedingProgramCycle::isFeedingDay('2026-11-16', $this->institution->id));
        $this->assertTrue(FeedingProgramCycle::isFeedingDay('2026-11-16', $other->id));
        $this->assertTrue(FeedingProgramCycle::isFeedingDay('2026-11-16'));
    }

    // ── What the coordinator and the head see ───────────────────────────

    #[Test]
    public function attendance_cannot_be_recorded_on_a_holiday(): void
    {
        $learner = $this->beneficiary();
        $today = now()->toDateString();
        $this->holiday($today, 'Test Holiday Day');

        $this->withSession($this->coordinatorSession())
            ->post('/dashboard/feedingcor-program/attendance/record', [
                'session_date' => $today,
                'marks' => [$learner->id => 'present'],
            ])
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'is Test Holiday Day')
                && str_contains($message, 'There are no feeding sessions on holidays.'));

        $this->assertSame(0, FeedingAttendance::query()->count());
    }

    #[Test]
    public function the_attendance_sheet_names_the_holiday_and_offers_no_recording(): void
    {
        $this->beneficiary();
        $today = now()->toDateString();
        $this->holiday($today, 'Test Holiday Day');

        $response = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-attendance?view=sheet')
            ->assertOk();

        $response->assertSee('No session &mdash; holiday', false);
        $response->assertSee(Carbon::parse($today)->format('F j, Y').' is Test Holiday Day');
        $this->assertDoesNotMatchRegularExpression('~<a[^>]*data-record-link~', $response->getContent());
        // The picker locks the day beside the weekends.
        $response->assertSee('data-closed-dates="{&quot;'.$today.'&quot;:&quot;Test Holiday Day&quot;}"', false);
    }

    #[Test]
    public function the_calendar_draws_a_closed_weekday_as_locked_and_tagged(): void
    {
        $this->beneficiary();
        $today = now()->toDateString();
        $this->holiday($today, 'Test Holiday Day');

        $html = $this->withSession($this->coordinatorSession())
            ->get('/dashboard/feedingcor-attendance?view=calendar')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('~class="fa-cal-day is-closed is-locked[^"]*"[^>]*data-closure-name="Test Holiday Day"~', $html);
        $this->assertStringContainsString('<span class="fa-cal-tag">Holiday</span>', $html);
        // Hovering or focusing the day names it: the tooltip carries the kind
        // and the holiday itself, and the cell is reachable by keyboard.
        $this->assertStringContainsString('<span class="fa-cal-tip-name">Test Holiday Day</span>', $html);
        $this->assertMatchesRegularExpression('~class="fa-cal-day is-closed is-locked[^"]*"[^>]*tabindex="0"~', $html);
        // Still locked: a closed day opens no dialog and no sheet.
        $this->assertDoesNotMatchRegularExpression('~data-day-open="'.$today.'"~', $html);
    }

    #[Test]
    public function the_head_is_not_told_a_session_is_missing_on_a_holiday(): void
    {
        $learner = $this->beneficiary();
        $this->mark($learner, now()->subDay()->toDateString());

        $this->withSession($this->headSession())
            ->get('/dashboard/school-head')
            ->assertOk()
            ->assertSee('feeding session has not been recorded');

        $this->holiday(now()->toDateString(), 'Test Holiday Day');

        $this->withSession($this->headSession())
            ->get('/dashboard/school-head')
            ->assertOk()
            ->assertDontSee('feeding session has not been recorded');
    }

    // ── The System Admin ────────────────────────────────────────────────

    #[Test]
    public function the_admin_syncs_both_years_of_the_school_year(): void
    {
        $this->fakeCalendarific();

        $this->withSession($this->adminSession())
            ->post(route('dashboard.system-admin.calendar.sync'))
            ->assertRedirect(route('dashboard.system-admin').'#school-calendar')
            ->assertSessionHas('success');

        Http::assertSentCount(2);
        $this->assertSame(8, SchoolCalendarDay::query()->count());
    }

    #[Test]
    public function only_the_admin_may_sync_or_change_the_calendar(): void
    {
        Http::fake();

        $this->withSession($this->coordinatorSession())
            ->post(route('dashboard.system-admin.calendar.sync'))
            ->assertRedirect(route('login'));

        $this->withSession($this->coordinatorSession())
            ->post(route('dashboard.system-admin.calendar.store'), $this->suspensionForm())
            ->assertRedirect(route('login'));

        // The head writes nothing at all; the middleware refuses it first.
        $this->withSession($this->headSession())
            ->post(route('dashboard.system-admin.calendar.sync'))
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, SchoolCalendarDay::query()->count());
    }

    #[Test]
    public function syncing_in_a_loop_is_stopped_before_it_spends_the_quota(): void
    {
        $this->fakeCalendarific();

        for ($i = 0; $i < 6; $i++) {
            $this->withSession($this->adminSession())->post(route('dashboard.system-admin.calendar.sync'));
        }

        $this->withSession($this->adminSession())
            ->post(route('dashboard.system-admin.calendar.sync'))
            ->assertSessionHas('error', fn (string $m): bool => str_contains($m, 'several times this hour'));

        Http::assertSentCount(12);
    }

    #[Test]
    public function the_admin_enters_a_suspension_for_one_school(): void
    {
        $before = (int) AuditLog::query()->max('id');

        $this->withSession($this->adminSession())
            ->post(route('dashboard.system-admin.calendar.store'), $this->suspensionForm())
            ->assertSessionHas('success');

        $entry = SchoolCalendarDay::query()->sole();
        $this->assertSame($this->institution->id, $entry->institution_id);
        $this->assertSame(SchoolCalendar::SOURCE_MANUAL, $entry->source);
        $this->assertSame('Test Admin', $entry->created_by_name);
        // Who entered it is a staff name, encrypted at rest.
        $this->assertNotSame('Test Admin', DB::table('school_calendar_days')->value('created_by_name'));
        $this->assertTrue(AuditLog::query()->where('id', '>', $before)->where('subject_type', 'SchoolCalendarDay')->exists());

        $this->assertFalse(FeedingProgramCycle::isFeedingDay('2026-11-17', $this->institution->id));
    }

    #[Test]
    public function a_suspension_is_refused_over_a_day_the_school_fed(): void
    {
        $learner = $this->beneficiary();
        $this->mark($learner, '2026-11-17');

        $this->withSession($this->adminSession())
            ->post(route('dashboard.system-admin.calendar.store'), $this->suspensionForm())
            ->assertSessionHas('error', fn (string $m): bool => str_contains($m, 'Attendance was recorded on November 17, 2026'));

        $this->assertSame(0, SchoolCalendarDay::query()->count());
    }

    #[Test]
    public function a_suspension_is_refused_when_it_is_too_long_backwards_or_overlapping(): void
    {
        $session = $this->adminSession();

        $this->withSession($session)
            ->post(route('dashboard.system-admin.calendar.store'), $this->suspensionForm(['starts_on' => '2026-11-02', 'ends_on' => '2026-12-31']))
            ->assertSessionHas('error', fn (string $m): bool => str_contains($m, 'at most 31 days'));

        $this->withSession($session)
            ->post(route('dashboard.system-admin.calendar.store'), $this->suspensionForm(['starts_on' => '2026-11-18', 'ends_on' => '2026-11-17']))
            ->assertSessionHasErrors('ends_on');

        $this->suspension('2026-11-16', '2026-11-17');
        $this->withSession($session)
            ->post(route('dashboard.system-admin.calendar.store'), $this->suspensionForm())
            ->assertSessionHas('error', fn (string $m): bool => str_contains($m, 'already closed'));

        $this->withSession($session)
            ->post(route('dashboard.system-admin.calendar.store'), $this->suspensionForm(['kind' => SchoolCalendar::KIND_HOLIDAY]))
            ->assertSessionHasErrors('kind');

        $this->assertSame(1, SchoolCalendarDay::query()->count());
    }

    #[Test]
    public function an_entered_day_can_be_removed_and_a_synced_holiday_cannot(): void
    {
        $suspension = $this->suspension('2026-11-16', '2026-11-16');
        $holiday = $this->holiday('2026-12-25', 'Christmas Day');

        $this->withSession($this->adminSession())
            ->delete(route('dashboard.system-admin.calendar.destroy', $holiday->id))
            ->assertSessionHas('error', fn (string $m): bool => str_contains($m, 'return on the next sync'));

        $this->withSession($this->adminSession())
            ->delete(route('dashboard.system-admin.calendar.destroy', $suspension->id))
            ->assertSessionHas('success');

        $this->assertNull($suspension->fresh());
        $this->assertNotNull($holiday->fresh());
        $this->assertTrue(FeedingProgramCycle::isFeedingDay('2026-11-16', $this->institution->id));
    }

    #[Test]
    public function the_control_center_lists_the_calendar(): void
    {
        // Inside the school year on the clock, whenever the suite is run.
        $year = SchoolCalendar::yearsOf(StudentHealthRecord::currentSchoolYear())[0];
        $this->holiday($year.'-12-25', 'Christmas Day');
        $this->suspension($year.'-11-16', $year.'-11-16');

        $this->withSession($this->adminSession())
            ->get(route('dashboard.system-admin'))
            ->assertOk()
            ->assertSee('id="school-calendar"', false)
            ->assertSee('Calendarific connected')
            ->assertSee('Christmas Day')
            ->assertSee('All schools')
            ->assertSee('Typhoon Signal No. 2')
            ->assertSee('Entered by Test Admin')
            ->assertSee('Sync Holidays');
    }

    // ── The command ─────────────────────────────────────────────────────

    #[Test]
    public function the_command_syncs_the_years_it_is_given(): void
    {
        $this->fakeCalendarific();

        $this->artisan('holidays:sync', ['year' => ['2026']])
            ->expectsOutputToContain('2026: 4 public holidays saved')
            ->assertExitCode(0);

        $this->artisan('holidays:sync', ['year' => ['20x6']])->assertExitCode(1);
    }

    #[Test]
    public function the_command_fails_loudly_without_a_key(): void
    {
        config(['services.calendarific.key' => '']);

        $this->artisan('holidays:sync', ['year' => ['2026']])
            ->expectsOutputToContain('Set CALENDARIFIC_API_KEY')
            ->assertExitCode(1);
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    /**
     * Calendarific's answer, shaped as its documentation shows, with the
     * kinds of entry the Philippine list actually carries.
     */
    private function fakeCalendarific(): void
    {
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $year = (int) ($query['year'] ?? 2026);

            return Http::response(['meta' => ['code' => 200], 'response' => ['holidays' => [
                $this->entry("New Year's Day", $year.'-01-01', 'Regular Holiday'),
                $this->entry('First Philippine Republic Day', $year.'-01-23', 'Special Working Day'),
                $this->entry('March Equinox', $year.'-03-20T22:46:00+08:00', 'Season', ['Season']),
                $this->entry('Black Saturday', $year.'-04-04', 'Special Non-working Holiday'),
                $this->entry('Black Saturday', $year.'-04-04', 'Special Non-working Holiday'),
                $this->entry('Day of Arafah (BARMM)', $year.'-05-26', 'Local holiday', ['Local holiday'], 'BARMM'),
                $this->entry('Bonifacio Day', $year.'-11-30', 'Regular Holiday'),
                $this->entry('Christmas Day', $year.'-12-25', 'Regular Holiday'),
                $this->entry('Next Year', ($year + 1).'-01-01', 'Regular Holiday'),
                $this->entry('Not a date', $year.'-02-30', 'Regular Holiday'),
            ]]], 200);
        });
    }

    /** @return array<string, mixed> */
    private function entry(string $name, string $iso, string $primary, array $type = ['National holiday'], string $locations = 'All'): array
    {
        return [
            'name' => $name,
            'description' => $name.' is a holiday in the Philippines',
            'country' => ['id' => 'ph', 'name' => 'Philippines'],
            'date' => ['iso' => $iso],
            'type' => $type,
            'primary_type' => $primary,
            'canonical_url' => 'https://calendarific.com/holiday/philippines/'.str($name)->slug(),
            'urlid' => 'philippines/'.str($name)->slug(),
            'locations' => $locations,
            'states' => $locations,
        ];
    }

    private function assertSyncFails(int $year, string $expected): void
    {
        try {
            app(PublicHolidaySync::class)->run($year);
        } catch (HolidayApiFailure $e) {
            $this->assertStringContainsString($expected, $e->getMessage());

            return;
        }

        $this->fail('The sync should have failed with "'.$expected.'".');
    }

    private function holiday(string $date, string $name): SchoolCalendarDay
    {
        return SchoolCalendarDay::query()->create([
            'starts_on' => $date,
            'ends_on' => $date,
            'name' => $name,
            'kind' => SchoolCalendar::KIND_HOLIDAY,
            'source' => SchoolCalendar::SOURCE_API,
        ]);
    }

    private function suspension(string $from, string $to): SchoolCalendarDay
    {
        return SchoolCalendarDay::query()->create([
            'institution_id' => $this->institution->id,
            'starts_on' => $from,
            'ends_on' => $to,
            'name' => 'Typhoon Signal No. 2',
            'kind' => SchoolCalendar::KIND_SUSPENSION,
            'source' => SchoolCalendar::SOURCE_MANUAL,
            'created_by_name' => 'Test Admin',
        ]);
    }

    /** @return array<string, mixed> */
    private function suspensionForm(array $overrides = []): array
    {
        return array_merge([
            'institution_id' => $this->institution->id,
            'kind' => SchoolCalendar::KIND_SUSPENSION,
            'starts_on' => '2026-11-17',
            'ends_on' => '2026-11-18',
            'name' => 'Typhoon Signal No. 2',
        ], $overrides);
    }

    private function beneficiary(): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Juan Testcase',
            'student_id' => '900000000001',
            'school_name' => 'Test School',
            'section' => 'Grade 7 / Sampaguita',
            'weight' => 30,
            'bmi_value' => 15.1,
            'nutritional_status' => 'Wasted',
            'baseline_nutritional_status' => 'Wasted',
            'student_details' => ['gender' => 'Male'],
            'feeding_enrolled_at' => now(),
        ]);
    }

    private function mark(StudentHealthRecord $learner, string $date): void
    {
        FeedingAttendance::create([
            'student_health_record_id' => $learner->id,
            'session_date' => $date,
            'is_present' => true,
            'needs_review' => false,
            'source' => 'manual_entry',
        ]);
    }

    /** The weekday $count weekdays after $date. */
    private function weekdaysAfter(string $date, int $count): string
    {
        $day = Carbon::parse($date);

        while ($count > 0) {
            $day->addDay();
            if (! $day->isWeekend()) {
                $count--;
            }
        }

        return $day->toDateString();
    }

    private function coordinatorSession(): array
    {
        return [
            'active_role' => 'feeding_coor',
            'active_name' => 'Test Coordinator',
            'active_username' => 'feedcor.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->institution->id,
        ];
    }

    private function headSession(): array
    {
        return [
            'active_role' => 'school_head',
            'active_name' => 'Test Head',
            'active_username' => 'head.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->institution->id,
        ];
    }

    private function adminSession(): array
    {
        return [
            'active_role' => 'system_admin',
            'active_name' => 'Test Admin',
            'active_username' => 'systemadmin',
        ];
    }
}
