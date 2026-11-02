<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\FeedingAttendance;
use App\Models\HealthConsentForm;
use App\Models\Institution;
use App\Models\SmsNotification;
use App\Models\StudentHealthRecord;
use App\Support\ParentNotifier;
use App\Support\PhilippineMobileNumber;
use App\Support\SemaphoreClient;
use App\Support\SmsGatewayFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Parent text messages through the Semaphore SMS gateway.
 *
 * The gateway is faked in every test (phpunit.xml blanks the real key), so
 * the suite proves what the application sends, when, to whom and what it
 * does with each answer — without a network or a credit spent.
 */
class ParentSmsNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const LRN = '100000000301';

    private const PARENT_NUMBER = '0917 123 4567';

    private const GATEWAY = '*api.semaphore.co/api/v4/messages';

    private Institution $school;

    private StudentHealthRecord $learner;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        config([
            'services.semaphore.key' => 'test-key-not-real',
            'services.semaphore.dry_run' => false,
            'services.semaphore.endpoint' => 'https://api.semaphore.co/api/v4',
            'services.semaphore.daily_cap' => 300,
        ]);

        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
        $this->learner = $this->learnerAt($this->school, self::LRN);
    }

    // ── Fixtures ───────────────────────────────────────────────────────

    private function learnerAt(Institution $school, string $lrn, array $extra = []): StudentHealthRecord
    {
        return StudentHealthRecord::create(array_merge([
            'institution_id' => $school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_id' => $lrn,
            'student_name' => 'Dimaculangan, Ligaya',
            'school_name' => $school->name,
            'section' => 'Grade 7 / Maingat',
            'weight' => 30,
            'bmi_value' => 15.1,
            'nutritional_status' => 'Wasted',
            'baseline_nutritional_status' => 'Wasted',
            'student_details' => [
                'first_name' => 'Ligaya', 'last_name' => 'Dimaculangan',
                'grade_level' => 'Grade 7', 'section' => 'Maingat', 'gender' => 'Female',
                'telephone_no' => '0918-555-0101',
            ],
        ], $extra));
    }

    private function consentForm(array $attributes = []): HealthConsentForm
    {
        return HealthConsentForm::create(array_merge([
            'token' => Str::random(48),
            'school_year' => HealthConsentForm::currentSchoolYear(),
            'institution_id' => $this->school->id,
            'division' => 'Davao City', 'school_name' => 'Sta. Ana NHS', 'school_address' => 'Davao City',
            'student_lrn' => self::LRN, 'student_name' => 'Dimaculangan, Ligaya',
            'grade_level' => 'Grade 7', 'section' => 'Maingat', 'parent_guardian_name' => 'Dimaculangan, Bayani',
            'services' => ['checkup'], 'status' => HealthConsentForm::STATUS_SENT,
        ], $attributes));
    }

    /** The parent answered the letter and asked to be texted. */
    private function optedIn(string $language = 'en', ?Carbon $signedAt = null): HealthConsentForm
    {
        return $this->consentForm([
            'status' => HealthConsentForm::STATUS_SIGNED,
            'consent_choice' => 'all',
            'signed_at' => $signedAt ?? now(),
            'sms_opt_in' => true,
            'sms_number' => '09171234567',
            'sms_language' => $language,
        ]);
    }

    private function sessionFor(string $role, ?Institution $school = null): array
    {
        return [
            'active_role' => $role,
            'active_name' => 'Staff '.$role,
            'active_username' => 'staff.'.$role,
            'active_school_name' => ($school ?? $this->school)->name,
            'active_institution_id' => ($school ?? $this->school)->id,
        ];
    }

    private function adminSession(): array
    {
        return ['active_role' => 'system_admin', 'active_name' => 'System Admin', 'active_username' => 'systemadmin'];
    }

    private function gatewayAccepts(string $status = 'Pending'): void
    {
        Http::fake([self::GATEWAY => Http::response([[
            'message_id' => 91234567,
            'recipient' => '639171234567',
            'status' => $status,
            'network' => 'Globe',
            'sender_name' => 'SEMAPHORE',
        ]], 200)]);
    }

    private function recordVisit(array $overrides = [], ?array $session = null)
    {
        return $this->withSession($session ?? $this->sessionFor('school_nurse'))
            ->post(route('consultations.store'), array_merge([
                'consulted_at' => now(config('app.school_timezone'))->format('Y-m-d\TH:i'),
                'student_name' => 'Dimaculangan, Ligaya',
                'grade_section' => 'Grade 7 - Maingat',
                'condition' => 'Fever',
                'treatment_given' => 'Paracetamol 500mg, rest in clinic',
                'status' => 'treated',
                'student_lrn' => self::LRN,
            ], $overrides));
    }

    // ── The number ─────────────────────────────────────────────────────

    #[Test]
    public function a_mobile_number_is_read_however_it_was_typed(): void
    {
        foreach (['0917 123 4567', '+63 917 123 4567', '639171234567', '9171234567', '0917-123-4567'] as $typed) {
            $this->assertSame('09171234567', PhilippineMobileNumber::normalize($typed), $typed);
        }

        // A landline, a short number and letters are not a mobile.
        foreach (['(082) 227 1234', '0917123', 'not a number', '', '08171234567'] as $typed) {
            $this->assertNull(PhilippineMobileNumber::normalize($typed), $typed);
        }

        $this->assertSame('•••• 4567', PhilippineMobileNumber::masked('09171234567'));
    }

    // ── Clinic visit ───────────────────────────────────────────────────

    #[Test]
    public function an_opted_in_parent_is_texted_when_their_child_visits_the_clinic(): void
    {
        $this->optedIn();
        $this->gatewayAccepts();

        $this->recordVisit()
            ->assertRedirect(route('dashboard.consultation-log'))
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'A text message is being sent to the parent (•••• 4567)'));

        $row = SmsNotification::sole();
        $this->assertSame(SmsNotification::EVENT_CLINIC_VISIT, $row->event);
        $this->assertSame(SmsNotification::STATUS_ACCEPTED, $row->status);
        $this->assertSame('91234567', $row->provider_message_id);
        $this->assertSame('Globe', $row->network);
        $this->assertSame(1, $row->attempts);
        $this->assertSame(self::LRN, $row->student_lrn);

        Http::assertSent(function (HttpRequest $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.semaphore.co/api/v4/messages'
                && $request['apikey'] === 'test-key-not-real'
                && $request['number'] === '09171234567'
                && str_contains($request['message'], 'Ligaya visited the school clinic');
        });
    }

    /** The load-bearing privacy rule: a text sits unencrypted on a shared phone. */
    #[Test]
    public function the_text_carries_no_health_information(): void
    {
        $this->optedIn();
        $this->gatewayAccepts();

        $this->recordVisit(['condition' => 'Asthma attack', 'treatment_given' => 'Salbutamol nebulisation', 'notes' => 'Wheezing on arrival']);

        $message = (string) SmsNotification::sole()->message;

        foreach (['Asthma', 'Salbutamol', 'Wheezing', 'Dimaculangan', 'Wasted', 'Fever'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $message);
        }
        $this->assertStringContainsString('Please contact the school clinic', $message);
        $this->assertStringStartsWith('Sta. Ana NHS:', $message);
    }

    /**
     * Automatic: the guardian's number on Sheet 1 is reached without the
     * parent having to opt in to anything first.
     */
    #[Test]
    public function the_guardian_on_sheet_one_is_texted_without_opting_in(): void
    {
        $this->consentForm(['status' => HealthConsentForm::STATUS_SIGNED, 'signed_at' => now(), 'sms_opt_in' => false]);
        $this->gatewayAccepts();

        $this->recordVisit()
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'A text message is being sent to the parent (•••• 0101)'));

        $this->assertSame(1, Consultation::count());
        $this->assertSame(1, SmsNotification::count());
        Http::assertSent(fn (HttpRequest $request): bool => $request['number'] === '09185550101'
            && str_contains($request['message'], 'Ligaya visited the school clinic'));
    }

    /** No letter at all: Sheet 1 is enough. */
    #[Test]
    public function a_learner_with_no_consent_form_still_reaches_their_guardian(): void
    {
        $this->gatewayAccepts();

        $this->recordVisit();

        $this->assertSame(1, SmsNotification::count());
        Http::assertSent(fn (HttpRequest $request): bool => $request['number'] === '09185550101');
    }

    #[Test]
    public function the_parents_latest_answer_decides(): void
    {
        $this->optedIn(signedAt: now()->subYear())->forceFill(['school_year' => '2025-2026'])->save();
        // Next year's letter, answered without a number of its own: the text
        // goes back to the Sheet 1 number, not last year's choice.
        $this->consentForm(['status' => HealthConsentForm::STATUS_SIGNED, 'signed_at' => now(), 'sms_opt_in' => false]);
        $this->gatewayAccepts();

        $this->recordVisit();

        $this->assertSame(1, SmsNotification::count());
        Http::assertSent(fn (HttpRequest $request): bool => $request['number'] === '09185550101');
        Http::assertNotSent(fn (HttpRequest $request): bool => $request['number'] === '09171234567');
    }

    /** A number the parent typed themselves outranks the one on Sheet 1. */
    #[Test]
    public function the_parents_own_number_outranks_sheet_one(): void
    {
        $this->optedIn();
        $this->gatewayAccepts();

        $this->recordVisit();

        Http::assertSent(fn (HttpRequest $request): bool => $request['number'] === '09171234567');
        Http::assertNotSent(fn (HttpRequest $request): bool => $request['number'] === '09185550101');
    }

    #[Test]
    public function no_number_on_file_texts_nobody(): void
    {
        $this->learner->forceFill(['student_details' => array_merge($this->learner->student_details, ['telephone_no' => ''])])->save();
        Http::fake();

        $this->recordVisit()
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'No contact number is on file'));

        $this->assertSame(1, Consultation::count());
        $this->assertSame(0, SmsNotification::count());
        Http::assertNothingSent();
    }

    #[Test]
    public function a_landline_on_sheet_one_sends_nothing(): void
    {
        $this->learner->forceFill(['student_details' => array_merge($this->learner->student_details, ['telephone_no' => '(082) 227 1234'])])->save();
        Http::fake();

        $this->recordVisit()
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'not a valid mobile number'));

        $this->assertSame(0, SmsNotification::count());
        Http::assertNothingSent();
    }

    #[Test]
    public function a_name_typed_and_never_chosen_texts_nobody(): void
    {
        $this->optedIn();
        Http::fake();

        $this->recordVisit(['student_lrn' => ''])
            ->assertSessionHas('success', fn (string $m) => ! str_contains($m, 'text'));

        $this->assertSame(0, SmsNotification::count());
    }

    #[Test]
    public function another_schools_lrn_cannot_reach_its_parent(): void
    {
        $this->optedIn();
        Http::fake();

        $other = Institution::create(['name' => 'Other NHS', 'status' => 'active']);

        // A nurse at another school posts this school's LRN.
        $this->recordVisit([], $this->sessionFor('school_nurse', $other));

        $this->assertSame(0, SmsNotification::count());
        Http::assertNothingSent();
    }

    #[Test]
    public function nothing_changes_when_the_feature_is_off(): void
    {
        config(['services.semaphore.key' => '', 'services.semaphore.dry_run' => false]);
        $this->optedIn();
        Http::fake();

        $this->recordVisit()
            ->assertSessionHas('success', 'Consultation saved successfully.');

        $this->assertSame(0, SmsNotification::count());
        Http::assertNothingSent();
    }

    #[Test]
    public function dry_run_logs_the_message_without_calling_the_gateway(): void
    {
        config(['services.semaphore.key' => '', 'services.semaphore.dry_run' => true]);
        $this->optedIn();
        Http::fake();

        $this->recordVisit()
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'was simulated'));

        $row = SmsNotification::sole();
        $this->assertSame(SmsNotification::STATUS_SIMULATED, $row->status);
        $this->assertStringContainsString('Ligaya visited the school clinic', (string) $row->message);
        Http::assertNothingSent();
    }

    #[Test]
    public function the_parents_language_is_used(): void
    {
        $this->optedIn('ceb');
        $this->gatewayAccepts();

        $this->recordVisit();

        $message = (string) SmsNotification::sole()->message;
        $this->assertStringContainsString('Si Ligaya mibisita sa school clinic', $message);
        $this->assertStringContainsString('Palihug kontaka', $message);
    }

    // ── Error handling ─────────────────────────────────────────────────

    #[Test]
    public function a_busy_gateway_is_retried_then_reported_and_the_visit_is_still_saved(): void
    {
        $this->optedIn();
        Http::fake([self::GATEWAY => Http::response(['message' => 'Service Unavailable'], 503)]);

        $this->recordVisit()->assertRedirect(route('dashboard.consultation-log'));

        $this->assertSame(1, Consultation::count(), 'A failed text must never undo the visit.');
        Http::assertSentCount(3);

        $row = SmsNotification::sole();
        $this->assertSame(SmsNotification::STATUS_FAILED, $row->status);
        $this->assertStringContainsString('unavailable at the moment', (string) $row->error);
        $this->assertTrue($row->canRetry());
        $this->assertTrue(AuditLog::where('action', 'sms_failed')->exists());
    }

    #[Test]
    public function a_refusal_is_not_retried(): void
    {
        $this->optedIn();
        Http::fake([self::GATEWAY => Http::response(['number' => ['The number format is invalid.']], 422)]);

        $this->recordVisit();

        Http::assertSentCount(1);
        $this->assertStringContainsString('The number format is invalid.', (string) SmsNotification::sole()->error);
    }

    #[Test]
    public function an_empty_account_and_a_wrong_key_are_said_in_words_staff_can_act_on(): void
    {
        $client = app(SemaphoreClient::class);

        Http::fake([self::GATEWAY => Http::sequence()
            ->push(['message' => 'Your current balance is insufficient.'], 200)
            ->push(['apikey' => ['The selected apikey is invalid.']], 401)]);
        try {
            $client->send('09171234567', 'Sta. Ana NHS: hello');
            $this->fail('An empty account must fail.');
        } catch (SmsGatewayFailure $e) {
            $this->assertStringContainsString('run out of credits', $e->getMessage());
            $this->assertFalse($e->retryable);
        }

        try {
            $client->send('09171234567', 'Sta. Ana NHS: hello');
            $this->fail('A wrong key must fail.');
        } catch (SmsGatewayFailure $e) {
            $this->assertStringContainsString('API key was refused', $e->getMessage());
            // The gateway's own reply rides along, so an admin can tell a
            // typo from an unactivated account.
            $this->assertStringContainsString('Semaphore said: HTTP 401', $e->getMessage());
            $this->assertStringContainsString('The selected apikey is invalid.', $e->getMessage());
            $this->assertStringNotContainsString('test-key-not-real', $e->getMessage());
        }
    }

    /**
     * A valid key on an account Semaphore has not activated, with no credits,
     * is the reply the real gateway gave on 2026-11-02. The console says
     * what is actually wrong instead of reporting a wrong key.
     */
    #[Test]
    public function a_pending_account_is_named_as_such_not_as_a_wrong_key(): void
    {
        Http::fake(['*api.semaphore.co/api/v4/account*' => Http::response([
            'account_id' => 87641, 'account_name' => 'SIGLA', 'status' => 'Pending', 'credit_balance' => 0,
        ])]);

        $this->withSession($this->adminSession())
            ->get(route('dashboard.system-admin.sms'))
            ->assertOk()
            ->assertSee('The API key works, but Semaphore has not activated the account yet (status: Pending).')
            ->assertSee('The account has no credits')
            ->assertSee('SIGLA · Pending')
            ->assertDontSee('API key was refused');

        $this->assertFalse(SemaphoreClient::canSend(['status' => 'Pending', 'credit_balance' => 0]));
        $this->assertFalse(SemaphoreClient::canSend(['status' => 'Active', 'credit_balance' => 0]));
        $this->assertTrue(SemaphoreClient::canSend(['status' => 'Active', 'credit_balance' => 12.5]));
    }

    /** A send refused because the account is not active says so. */
    #[Test]
    public function a_send_refused_for_an_inactive_account_is_not_called_a_wrong_key(): void
    {
        Http::fake([self::GATEWAY => Http::sequence()
            ->push(['message' => 'Your account is still pending approval.'], 403)
            ->push(['message' => 'Forbidden.'], 403)]);
        $client = app(SemaphoreClient::class);

        try {
            $client->send('09171234567', 'Sta. Ana NHS: hello');
            $this->fail('A pending account must fail.');
        } catch (SmsGatewayFailure $e) {
            $this->assertStringContainsString('account is not active yet', $e->getMessage());
            $this->assertStringNotContainsString('API key was refused', $e->getMessage());
        }

        try {
            $client->send('09171234567', 'Sta. Ana NHS: hello');
            $this->fail('A bare 403 must fail.');
        } catch (SmsGatewayFailure $e) {
            $this->assertStringContainsString('Check on semaphore.co that the account is active and has credits', $e->getMessage());
        }
    }

    /** A key pasted into a host's variable editor with its quotes still works. */
    #[Test]
    public function a_quoted_key_is_sent_without_its_quotes(): void
    {
        config(['services.semaphore.key' => ' "test-key-not-real" ']);
        $this->gatewayAccepts();

        app(SemaphoreClient::class)->send('09171234567', 'Sta. Ana NHS: hello');

        Http::assertSent(fn (HttpRequest $request): bool => $request['apikey'] === 'test-key-not-real');
    }

    #[Test]
    public function a_dropped_connection_is_reported_not_repeated(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28'));

        try {
            app(SemaphoreClient::class)->send('09171234567', 'Sta. Ana NHS: hello');
            $this->fail('A dropped connection must fail.');
        } catch (SmsGatewayFailure $e) {
            $this->assertTrue($e->retryable);
            $this->assertStringNotContainsString('cURL', $e->getMessage());
        }
    }

    #[Test]
    public function the_client_refuses_a_message_the_gateway_would_discard(): void
    {
        Http::fake();
        $client = app(SemaphoreClient::class);

        foreach (['TEST message', '', str_repeat('x', 481)] as $message) {
            try {
                $client->send('09171234567', $message);
                $this->fail('Refused: '.substr($message, 0, 20));
            } catch (SmsGatewayFailure) {
            }
        }

        try {
            $client->send('(082) 227 1234', 'Sta. Ana NHS: hello');
            $this->fail('A landline must be refused.');
        } catch (SmsGatewayFailure $e) {
            $this->assertStringContainsString('not a Philippine mobile', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    /** A typo on the letter does not leave a family unreached when Sheet 1 has a number. */
    #[Test]
    public function an_unusable_number_on_the_form_falls_back_to_sheet_one(): void
    {
        $this->optedIn()->forceFill(['sms_number' => '12345'])->save();
        $this->gatewayAccepts();

        $this->recordVisit();

        Http::assertSent(fn (HttpRequest $request): bool => $request['number'] === '09185550101');
    }

    #[Test]
    public function one_event_is_one_message(): void
    {
        $this->optedIn();
        Http::fake();

        $notifier = app(ParentNotifier::class);
        $first = $notifier->clinicVisit($this->learner, 77, now());
        $second = $notifier->clinicVisit($this->learner, 77, now());

        $this->assertSame(ParentNotifier::OUTCOME_QUEUED, $first['outcome']);
        $this->assertSame(ParentNotifier::OUTCOME_DUPLICATE, $second['outcome']);
        $this->assertSame(1, SmsNotification::count());
    }

    #[Test]
    public function the_daily_cap_stops_the_school_from_draining_the_account(): void
    {
        config(['services.semaphore.daily_cap' => 1]);
        $this->optedIn();
        Http::fake();

        $notifier = app(ParentNotifier::class);
        $this->assertSame(ParentNotifier::OUTCOME_QUEUED, $notifier->clinicVisit($this->learner, 1, now())['outcome']);
        $this->assertSame(ParentNotifier::OUTCOME_CAP_REACHED, $notifier->clinicVisit($this->learner, 2, now())['outcome']);

        $this->assertSame(1, SmsNotification::count());
        $this->assertTrue(AuditLog::where('action', 'sms_blocked')->exists());
    }

    // ── Feeding attendance notice ──────────────────────────────────────

    /** A Wednesday morning in Davao, so the sessions before it are weekdays. */
    private function onAWednesday(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 09:00', 'Asia/Manila'));
    }

    private function absentOn(StudentHealthRecord $record, string $date): void
    {
        FeedingAttendance::create([
            'student_health_record_id' => $record->id,
            'session_date' => $date,
            'is_present' => false,
            'needs_review' => false,
            'source' => FeedingAttendance::SOURCE_MANUAL_ENTRY,
        ]);
    }

    #[Test]
    public function the_third_absence_in_a_row_texts_the_parent_once(): void
    {
        $this->onAWednesday();
        $this->learner->forceFill(['feeding_enrolled_at' => now()->subWeek()])->save();
        $this->optedIn();
        $this->gatewayAccepts();

        $this->absentOn($this->learner, '2026-10-26');
        $this->absentOn($this->learner, '2026-10-27');

        $record = fn () => $this->withSession($this->sessionFor('feeding_coor'))
            ->post(route('feedingcor-program.attendance.record.store'), [
                'session_date' => '2026-10-28',
                'marks' => [$this->learner->id => 'absent'],
            ]);

        $record()->assertSessionHas('success', fn (string $m) => str_contains($m, '1 parent was sent a text message'));

        $row = SmsNotification::sole();
        $this->assertSame(SmsNotification::EVENT_ATTENDANCE_NOTICE, $row->event);
        $this->assertStringContainsString('Ligaya has missed 3 school feeding sessions in a row', (string) $row->message);
        $this->assertSame(SmsNotification::STATUS_ACCEPTED, $row->status);

        // Re-saving the same day recomputes every flag; the parent is not
        // texted a second time about the same run.
        $record();
        $this->assertSame(1, SmsNotification::count());
    }

    /** The attendance notice reaches the guardian on Sheet 1 too — no opt-in needed. */
    #[Test]
    public function the_attendance_notice_reaches_the_sheet_one_number(): void
    {
        $this->onAWednesday();
        $this->learner->forceFill(['feeding_enrolled_at' => now()->subWeek()])->save();
        $this->gatewayAccepts();

        foreach (['2026-10-26', '2026-10-27', '2026-10-28'] as $date) {
            $this->absentOn($this->learner, $date);
        }

        $this->assertSame(1, app(ParentNotifier::class)->attendanceNotices($this->school->id));

        $this->assertSame('09185550101', (string) SmsNotification::sole()->recipient);
    }

    #[Test]
    public function an_old_run_of_absences_is_history_not_news(): void
    {
        $this->onAWednesday();
        $this->learner->forceFill(['feeding_enrolled_at' => now()->subMonth()])->save();
        $this->optedIn();
        Http::fake();

        foreach (['2026-10-12', '2026-10-13', '2026-10-14'] as $date) {
            $this->absentOn($this->learner, $date);
        }

        $this->assertSame(0, app(ParentNotifier::class)->attendanceNotices($this->school->id));
        $this->assertSame(0, SmsNotification::count());
    }

    #[Test]
    public function two_absences_are_not_yet_a_notice(): void
    {
        $this->onAWednesday();
        $this->learner->forceFill(['feeding_enrolled_at' => now()->subWeek()])->save();
        $this->optedIn();
        Http::fake();

        $this->absentOn($this->learner, '2026-10-27');
        $this->absentOn($this->learner, '2026-10-28');

        $this->assertSame(0, app(ParentNotifier::class)->attendanceNotices($this->school->id));
    }

    // ── The parent's opt-in ────────────────────────────────────────────

    private function parentAnswer(HealthConsentForm $form, array $sms)
    {
        return $this->post(route('consent-forms.parent-submit', $form->token), array_merge([
            'consent_choice' => 'all',
            'signature' => 'data:image/png;base64,iVBORw0KGgo=',
        ], $sms));
    }

    #[Test]
    public function the_parent_opts_in_on_their_own_letter(): void
    {
        $form = $this->consentForm();

        // The page offers the box with the number the school already holds.
        $this->get(route('consent-forms.parent', $form->token))
            ->assertOk()
            ->assertSee('Text Message Updates')
            ->assertSee('09185550101');

        $this->parentAnswer($form, ['sms_opt_in' => '1', 'sms_number' => '+63 917 123 4567', 'sms_language' => 'ceb'])
            ->assertSessionHasNoErrors();

        $form->refresh();
        $this->assertTrue($form->sms_opt_in);
        $this->assertSame('09171234567', $form->sms_number);
        $this->assertSame('ceb', $form->sms_language);

        // The number is encrypted at rest like every other answer.
        $raw = DB::table('health_consent_forms')->where('id', $form->id)->value('sms_number');
        $this->assertStringNotContainsString('09171234567', (string) $raw);
    }

    #[Test]
    public function an_unusable_number_is_refused_and_no_box_means_no_texts(): void
    {
        $form = $this->consentForm();

        $this->parentAnswer($form, ['sms_opt_in' => '1', 'sms_number' => '(082) 227 1234'])
            ->assertSessionHasErrors('sms_number');
        $this->parentAnswer($form, ['sms_opt_in' => '1', 'sms_number' => ''])
            ->assertSessionHasErrors('sms_number');

        $this->parentAnswer($form, ['sms_number' => '0917 123 4567'])->assertSessionHasNoErrors();

        $form->refresh();
        $this->assertFalse($form->sms_opt_in);
        $this->assertNull($form->sms_number);
    }

    // ── The System Admin's console ─────────────────────────────────────

    #[Test]
    public function only_the_system_admin_opens_the_console(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.system-admin.sms'))
            ->assertRedirect();

        Http::fake(['*api.semaphore.co/api/v4/account*' => Http::response([
            'account_id' => 1, 'account_name' => 'Sta. Ana NHS', 'status' => 'Active', 'credit_balance' => 248.5,
        ])]);

        $this->withSession($this->adminSession())
            ->get(route('dashboard.system-admin.sms'))
            ->assertOk()
            ->assertSee('Parent <span>SMS</span>', false)
            ->assertSee('248.50')
            ->assertSee('Live');
    }

    #[Test]
    public function the_console_never_prints_a_whole_number(): void
    {
        $this->optedIn();
        Http::fake();
        app(ParentNotifier::class)->clinicVisit($this->learner, 5, now());
        $this->assertSame(1, SmsNotification::count());

        $this->withSession($this->adminSession())
            ->get(route('dashboard.system-admin.sms'))
            ->assertOk()
            ->assertSee('•••• 4567')
            ->assertDontSee('09171234567');

        // Encrypted at rest, too.
        $raw = DB::table('sms_notifications')->value('recipient');
        $this->assertStringNotContainsString('09171234567', (string) $raw);
    }

    #[Test]
    public function the_admin_sends_a_sample_and_reads_its_delivery_report(): void
    {
        Http::fake([
            '*api.semaphore.co/api/v4/messages/*' => Http::response([['message_id' => 91234567, 'status' => 'Sent', 'network' => 'Globe']]),
            self::GATEWAY => Http::response([['message_id' => 91234567, 'status' => 'Pending', 'network' => 'Globe']]),
            '*api.semaphore.co/api/v4/account*' => Http::response(['account_name' => 'Sta. Ana NHS', 'credit_balance' => 100]),
        ]);

        $this->withSession($this->adminSession())
            ->post(route('dashboard.system-admin.sms.test'), ['number' => '0917 123 4567'])
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'accepted by Semaphore'));

        $row = SmsNotification::sole();
        $this->assertSame(SmsNotification::EVENT_TEST, $row->event);
        $this->assertSame(SmsNotification::STATUS_ACCEPTED, $row->status);
        $this->assertStringStartsNotWith('TEST', strtoupper((string) $row->message));

        $this->withSession($this->adminSession())
            ->post(route('dashboard.system-admin.sms.refresh'))
            ->assertSessionHas('success', 'Checked 1 message(s): 1 delivered, 0 failed.');

        $this->assertSame(SmsNotification::STATUS_DELIVERED, $row->fresh()->status);

        $this->artisan('sms:refresh-status')
            ->expectsOutput('Checked 0 message(s): 0 delivered, 0 failed.')
            ->assertSuccessful();
    }

    #[Test]
    public function a_failed_message_can_be_retried_once_the_gateway_recovers(): void
    {
        $this->optedIn();
        Http::fake([self::GATEWAY => Http::sequence()
            ->push(['message' => 'busy'], 503)
            ->push(['message' => 'busy'], 503)
            ->push(['message' => 'busy'], 503)
            ->push([['message_id' => 555, 'status' => 'Pending']], 200)]);

        $this->recordVisit();
        $row = SmsNotification::sole();
        $this->assertSame(SmsNotification::STATUS_FAILED, $row->status);

        $this->withSession($this->adminSession())
            ->post(route('dashboard.system-admin.sms.retry', $row))
            ->assertSessionHas('success');

        $row->refresh();
        $this->assertSame(SmsNotification::STATUS_ACCEPTED, $row->status);
        $this->assertSame('555', $row->provider_message_id);
        $this->assertSame(2, $row->attempts);
        $this->assertFalse($row->canRetry(), 'A message the gateway took is never sent twice.');
    }
}
