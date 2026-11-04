<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\SchoolCalendarDay;
use App\Models\StudentHealthRecord;
use App\Support\FeedingAttendanceMark;
use App\Support\FeedingProgramCycle;
use App\Support\SchoolCalendar;
use App\Support\SchoolHeadOverview;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A learner nobody recorded is marked Not Recorded on every screen, for any day
 * that should carry a mark, including a day nobody recorded at all. It is a
 * missing record and never an absence.
 */
class FeedingNotRecordedTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday morning in Davao.
        $this->travelTo(Carbon::parse('2026-10-28 09:00', 'Asia/Manila'));
        $this->school = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    private function sessionFor(string $role = 'feeding_coor'): array
    {
        return [
            'active_role' => $role,
            'active_name' => 'Test Staff',
            'active_username' => 'staff.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->school->id,
        ];
    }

    private function learner(string $name, array $attributes = []): StudentHealthRecord
    {
        return StudentHealthRecord::create($attributes + [
            'institution_id' => $this->school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => $name,
            'student_id' => 'LRN'.random_int(100000, 999999),
            'school_name' => 'Test School',
            'section' => 'Grade 7 / Maabilidad',
            'weight' => 20.0,
            'bmi_value' => 13.5,
            'nutritional_status' => 'Wasted',
            'baseline_nutritional_status' => 'Wasted',
            'student_details' => ['gender' => 'Male'],
            'feeding_enrolled_at' => now(),
        ]);
    }

    private function mark(StudentHealthRecord $record, string $date, bool $present = true): void
    {
        FeedingAttendance::create([
            'student_health_record_id' => $record->id,
            'session_date' => $date,
            'is_present' => $present,
            'needs_review' => false,
            'source' => 'manual_entry',
        ]);
    }

    private function dueDates(array $recorded): array
    {
        return FeedingProgramCycle::forInstitution($this->school->id)->dueDates($recorded);
    }

    #[Test]
    public function the_word_is_declared_once(): void
    {
        $this->assertSame('Not Recorded', FeedingAttendanceMark::NOT_RECORDED);
        $this->assertSame('Not Recorded', FeedingAttendanceMark::label(FeedingAttendanceMark::NOT_MARKED));
    }

    #[Test]
    public function a_day_that_ended_with_nothing_recorded_is_due_and_today_is_not_until_marked(): void
    {
        $ana = $this->learner('Abella, Ana');
        // Monday the 19th, then nothing until the Friday of that week.
        $this->mark($ana, '2026-10-19');
        $this->mark($ana, '2026-10-23');

        $this->assertSame(
            ['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-26', '2026-10-27'],
            $this->dueDates(['2026-10-19', '2026-10-23']),
            'Weekdays since the first session, without the weekend and without today.',
        );

        $this->assertContains('2026-10-28', $this->dueDates(['2026-10-19', '2026-10-28']), 'Once marked, today is due.');
    }

    #[Test]
    public function a_day_the_school_was_closed_is_not_due(): void
    {
        $this->mark($this->learner('Abella, Ana'), '2026-10-19');

        SchoolCalendarDay::create([
            'institution_id' => $this->school->id,
            'starts_on' => '2026-10-21',
            'ends_on' => '2026-10-21',
            'name' => 'Typhoon suspension',
            'kind' => SchoolCalendar::KIND_SUSPENSION,
            'source' => SchoolCalendar::SOURCE_MANUAL,
        ]);
        SchoolCalendar::forget();

        $this->assertNotContains('2026-10-21', $this->dueDates(['2026-10-19']));
    }

    #[Test]
    public function a_school_with_no_session_has_nothing_due_whatever_its_clinic_log_says(): void
    {
        $this->learner('Abella, Ana');

        // The cycle falls back to the first consultation for its start date.
        Consultation::create([
            'institution_id' => $this->school->id,
            'consulted_at' => '2026-09-01 09:00:00',
            'student_name' => 'Abella, Ana',
            'grade_section' => 'Grade 7',
            'condition' => 'Headache',
            'status' => 'treated',
        ]);

        $this->assertSame([], $this->dueDates([]));
    }

    #[Test]
    public function the_dashboard_marks_a_learner_with_no_mark_today(): void
    {
        $this->learner('Abella, Ana');

        $html = $this->withSession($this->sessionFor())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('~Abella, Ana.*?<span class="badge badge-neutral">Not Recorded</span>~s', $html);
        $this->assertStringNotContainsString('att-none', $html);
    }

    #[Test]
    public function the_dashboard_keeps_an_unread_scan_apart_from_it(): void
    {
        $ana = $this->learner('Abella, Ana');
        FeedingAttendance::create([
            'student_health_record_id' => $ana->id,
            'session_date' => '2026-10-28',
            'is_present' => null,
            'needs_review' => true,
            'source' => 'scan',
        ]);

        $html = $this->withSession($this->sessionFor())
            ->get('/dashboard/feedingcor-dashboard')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('~Abella, Ana.*?<span class="badge badge-neutral">Unconfirmed</span>~s', $html);
    }

    #[Test]
    public function the_sheet_for_a_missed_day_marks_every_learner_and_counts_no_absence(): void
    {
        $ana = $this->learner('Abella, Ana');
        $ben = $this->learner('Bautista, Ben');
        $this->mark($ana, '2026-10-26');
        $this->mark($ben, '2026-10-26');

        $response = $this->withSession($this->sessionFor())
            ->get(route('dashboard.feedingcor-attendance', ['date' => '2026-10-27']))
            ->assertOk();

        $this->assertSame(['unmarked', 'unmarked'], $response->viewData('rows')->pluck('status')->all());
        $this->assertSame(0, $response->viewData('tally')['absent']);
        $this->assertSame(2, $response->viewData('tally')['unmarked']);
        $response->assertSee('2 Not Recorded');
    }

    #[Test]
    public function the_roll_counts_a_missed_day_and_the_history_dialog_lists_it(): void
    {
        $ana = $this->learner('Abella, Ana');
        $ben = $this->learner('Bautista, Ben');
        // Monday both, Tuesday nobody (and it has ended), and today Ana alone.
        $this->mark($ana, '2026-10-26');
        $this->mark($ben, '2026-10-26', false);
        $this->mark($ana, '2026-10-28');

        $response = $this->withSession($this->sessionFor())
            ->get(route('dashboard.feedingcor-attendance', ['view' => 'beneficiary', 'date' => '2026-10-26']))
            ->assertOk();

        $rows = collect($response->viewData('beneficiaryRows'))->keyBy('name');

        $this->assertSame(1, $rows['Abella, Ana']['not_marked'], 'Tuesday.');
        $this->assertSame(2, $rows['Bautista, Ben']['not_marked'], 'Tuesday and today.');

        // The rule reads marks: a day nobody recorded moves no rate.
        $this->assertEquals(100, $rows['Abella, Ana']['rate']);
        $this->assertEquals(0, $rows['Bautista, Ben']['rate']);
        $this->assertSame(1, $rows['Bautista, Ben']['absent']);

        $this->assertSame(['2026-10-26', '2026-10-27', '2026-10-28'], $response->viewData('dueDates'));
        $response->assertSee('2026-10-27', false);
    }

    #[Test]
    public function the_history_gives_a_missed_day_its_own_row_and_its_place_in_the_count(): void
    {
        $ana = $this->learner('Abella, Ana');
        $this->mark($ana, '2026-10-26');
        $this->mark($ana, '2026-10-28');

        $response = $this->withSession($this->sessionFor())
            ->get(route('dashboard.feedingcor-attendance', ['view' => 'history']))
            ->assertOk();

        $history = collect($response->viewData('history'))->keyBy('date');

        $this->assertSame(2, $history['2026-10-27']['day']);
        $this->assertSame(3, $history['2026-10-28']['day']);
        $this->assertSame(1, $history['2026-10-27']['unmarked']);
        $this->assertSame(0, $history['2026-10-27']['absent']);
        $this->assertNull($history['2026-10-27']['rate']);

        // What the sheet exports stays the sessions somebody recorded.
        $this->assertSame(['2026-10-26', '2026-10-28'], $response->viewData('sessionDates'));
    }

    #[Test]
    public function the_calendar_lists_the_roll_on_a_due_day_and_nobody_before_the_first_session(): void
    {
        $ana = $this->learner('Abella, Ana');
        $this->mark($ana, '2026-10-26');

        $html = $this->withSession($this->sessionFor())
            ->get(route('dashboard.feedingcor-attendance', ['view' => 'calendar', 'date' => '2026-10-27']))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, preg_match('~<script type="application/json" data-calendar-detail>(.*?)</script>~s', $html, $match));
        $days = json_decode($match[1], true)['days'];

        $this->assertTrue($days['2026-10-27']['listed'], 'A day that ended unrecorded.');
        $this->assertSame(2, $days['2026-10-27']['feeding_day']);
        $this->assertTrue($days['2026-10-28']['listed'], 'Today.');
        $this->assertFalse($days['2026-10-23']['listed'], 'Before the programme started.');

        // The missed day says so on its cell and leads to its sheet.
        $this->assertMatchesRegularExpression('~data-day-open="2026-10-27"[^>]*data-sheet-url="[^"]*date=2026-10-27~', $html);
        $this->assertMatchesRegularExpression('~data-day-open="2026-10-27".*?<span class="fa-cal-tag">Not Recorded</span>~s', $html);
    }

    #[Test]
    public function the_beneficiary_record_lists_a_missed_day(): void
    {
        $ana = $this->learner('Abella, Ana');
        $this->mark($ana, '2026-10-26');
        $this->mark($ana, '2026-10-28');

        $response = $this->withSession($this->sessionFor())
            ->get(route('feedingcor-program.beneficiary', ['record' => $ana->id]))
            ->assertOk();

        $rows = collect($response->viewData('sessionMonths'))->flatMap(fn (array $month) => $month['rows'])->keyBy('date');

        $this->assertSame('unmarked', $rows['2026-10-27']['status']);
        $this->assertSame('present', $rows['2026-10-28']['status']);
        $response->assertSee('Not Recorded');
    }

    #[Test]
    public function a_past_years_record_lists_only_the_sessions_of_that_year(): void
    {
        $past = $this->learner('Abella, Ana', ['school_year' => '2020-2021']);
        $this->mark($past, '2020-10-05');
        $this->mark($past, '2020-10-07');

        $rows = collect($this->withSession($this->sessionFor())
            ->get(route('feedingcor-program.beneficiary', ['record' => $past->id]))
            ->assertOk()
            ->viewData('sessionMonths'))->flatMap(fn (array $month) => $month['rows']);

        $this->assertSame(['2020-10-07', '2020-10-05'], $rows->pluck('date')->all());
    }

    #[Test]
    public function the_school_head_reads_the_same_count_as_the_coordinator(): void
    {
        $ana = $this->learner('Abella, Ana');
        $this->mark($ana, '2026-10-26');
        $this->mark($ana, '2026-10-28');

        $roll = collect($this->withSession($this->sessionFor())
            ->get(route('dashboard.feedingcor-attendance', ['view' => 'beneficiary', 'date' => '2026-10-26']))
            ->viewData('beneficiaryRows'))->keyBy('name');

        $head = SchoolHeadOverview::for($this->school->id)->standings();

        $this->assertSame(1, $head[$ana->id]['not_marked']);
        $this->assertSame($roll['Abella, Ana']['not_marked'], $head[$ana->id]['not_marked']);
    }

    #[Test]
    public function the_beneficiaries_tab_marks_a_beneficiary_nobody_has_recorded(): void
    {
        $ana = $this->learner('Abella, Ana');
        $ben = $this->learner('Bautista, Ben');
        $this->mark($ana, '2026-10-26');

        $response = $this->withSession($this->sessionFor())
            ->get('/dashboard/feedingcor-health-records')
            ->assertOk();

        $rows = collect($response->viewData('records'))->keyBy('student_name');

        $this->assertFalse($rows['Abella, Ana']->attendance_not_recorded);
        $this->assertTrue($rows['Bautista, Ben']->attendance_not_recorded);
        $response->assertSee('Not Recorded');
        $response->assertSee('Not Recorded today');
    }

    #[Test]
    public function a_learner_with_only_an_excused_absence_is_not_called_unrecorded(): void
    {
        $ana = $this->learner('Abella, Ana');
        FeedingAttendance::create([
            'student_health_record_id' => $ana->id,
            'session_date' => '2026-10-26',
            'is_present' => false,
            'is_excused' => true,
            'needs_review' => false,
            'source' => 'manual_entry',
        ]);

        $rows = collect($this->withSession($this->sessionFor())
            ->get('/dashboard/feedingcor-health-records')
            ->assertOk()
            ->viewData('records'))->keyBy('student_name');

        $this->assertFalse($rows['Abella, Ana']->attendance_not_recorded);
        $this->assertNull($rows['Abella, Ana']->attendance_rate);
    }

    #[Test]
    public function the_nurse_and_the_head_read_the_same_word(): void
    {
        $ana = $this->learner('Abella, Ana');
        $this->learner('Bautista, Ben');
        $this->mark($ana, '2026-10-26');

        $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.school-nurse.feeding-program'))
            ->assertOk()
            ->assertSee('Not Recorded')
            ->assertDontSee('Not marked');

        $this->withSession($this->sessionFor('school_head'))
            ->get(route('dashboard.school-head.masterlist'))
            ->assertOk()
            ->assertSee('Not Recorded')
            ->assertDontSee('Not marked');
    }
}
