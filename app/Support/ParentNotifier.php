<?php

namespace App\Support;

use App\Jobs\SendParentSms;
use App\Models\HealthConsentForm;
use App\Models\Institution;
use App\Models\SmsNotification;
use App\Models\StudentHealthRecord;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Decides whether a parent is texted, and what the text says.
 *
 * The one place that turns something that happened at school into a message
 * to a parent. SemaphoreClient carries the message; nothing else in the
 * application composes one. Four rules hold:
 *
 *   1. **The parent is reached automatically.** The guardian's contact number
 *      on the learner's Sheet 1 (`student_details.telephone_no`, entered by
 *      the class adviser) is where a text goes — the school already holds it,
 *      so no parent has to answer anything first. A parent who gave a number
 *      of their own on the Sulat-Pahibalo outranks it, with the language they
 *      chose: they told the school where they want to be reached. A learner
 *      with no mobile number on file is simply not texted.
 *   2. **No health information in a text.** A message sits unencrypted on a
 *      phone that may be shared, so it says that the child visited the clinic
 *      or missed feeding sessions, and asks the parent to contact the school.
 *      It never names a complaint, a medicine, a status or a measurement.
 *   3. **One event, one message.** Every message carries a de-duplication key
 *      (the consultation, or the learner's run of absences), unique in the
 *      table, so recomputing flags or replaying a form never texts twice.
 *   4. **A ceiling per school per day** (services.semaphore.daily_cap), so a
 *      bug or a mis-imported sheet cannot drain the account.
 *
 * A message is written as a queued row first and handed to the gateway after
 * the response has gone back (SendParentSms), so a slow gateway never holds
 * up the nurse saving a consultation, and a failed send never undoes it.
 */
class ParentNotifier
{
    public const OUTCOME_QUEUED = 'queued';

    public const OUTCOME_DISABLED = 'disabled';

    public const OUTCOME_NO_NUMBER = 'no_number';

    public const OUTCOME_INVALID_NUMBER = 'invalid_number';

    /** Where a number came from, for the console and the audit trail. */
    public const SOURCE_SHEET_ONE = 'sheet_one';

    public const SOURCE_CONSENT_FORM = 'consent_form';

    public const OUTCOME_DUPLICATE = 'duplicate';

    public const OUTCOME_CAP_REACHED = 'cap_reached';

    public const LANGUAGES = ['en' => 'English', 'ceb' => 'Cebuano'];

    /** The language of a text to a parent who never chose one — the letter's own default. */
    private const DEFAULT_LANGUAGE = 'en';

    /** A run of absences older than this is history, not news, and is not texted. */
    private const NOTICE_FRESH_DAYS = 3;

    public function __construct(private readonly SemaphoreClient $client) {}

    /** The table is migrated and messages have somewhere to go. */
    public function isReady(): bool
    {
        return $this->client->isAvailable()
            && SchemaCache::hasTable('sms_notifications');
    }

    /**
     * Tell a parent their child visited the clinic.
     *
     * @return array{outcome: string, last4: ?string}
     */
    public function clinicVisit(StudentHealthRecord $record, int $consultationId, CarbonInterface $visitedAt): array
    {
        if (! $this->isReady()) {
            return ['outcome' => self::OUTCOME_DISABLED, 'last4' => null];
        }

        $recipient = self::recipientFor($record);

        if ($recipient === null) {
            return ['outcome' => self::OUTCOME_NO_NUMBER, 'last4' => null];
        }

        $at = Carbon::parse($visitedAt)->setTimezone(self::timezone());
        $today = now(self::timezone())->isSameDay($at);

        $message = $this->compose(SmsNotification::EVENT_CLINIC_VISIT, $recipient['language'], [
            'school' => self::schoolName($record->institution_id),
            'name' => self::firstName($record),
            'when' => $today ? $at->format('g:i A') : $at->format('M j, g:i A'),
            'today' => $today,
        ]);

        return $this->queue(
            $recipient,
            $record,
            SmsNotification::EVENT_CLINIC_VISIT,
            'clinic_visit:'.$consultationId,
            $message,
        );
    }

    /**
     * Tell parents whose child has reached the school's attendance notice.
     *
     * Called after every write to the feeding marks. It reads the enrolled
     * learners with a number on file — the roster is the page's shared read,
     * and the parents' own numbers are one more query — and texts a parent
     * once per run of absences, only while that run is recent.
     *
     * @return int how many messages were queued
     */
    public function attendanceNotices(?int $institutionId): int
    {
        if ($institutionId === null || ! $this->isReady()) {
            return 0;
        }

        $forms = self::optedInForms($institutionId);

        $records = StudentHealthRecord::rosterFor($institutionId)
            ->filter(fn (StudentHealthRecord $r): bool => FeedingBeneficiarySummary::isEnrolled($r))
            // Nobody to text is nobody to evaluate.
            ->filter(fn (StudentHealthRecord $r): bool => self::resolveRecipient($r, $forms->get((string) $r->student_id)) !== null)
            ->values();

        if ($records->isEmpty()) {
            return 0;
        }

        $rule = FeedingAtRiskRule::forInstitution($institutionId);
        $standings = FeedingAttendanceFlag::forRecords($records, $rule);
        $dated = FeedingBeneficiarySummary::datedMarksByRecord($records);
        $freshSince = now(self::timezone())->subDays(self::NOTICE_FRESH_DAYS)->toDateString();
        $queued = 0;

        foreach ($records as $record) {
            $standing = $standings[$record->id] ?? null;

            if ($standing === null
                || $standing['run_started_on'] === null
                || $standing['run'] < $rule->absenceNoticeDays()) {
                continue;
            }

            $marks = $dated->get($record->id, []);
            $lastDate = $marks === [] ? null : (string) end($marks)['date'];

            if ($lastDate === null || $lastDate < $freshSince) {
                continue;
            }

            $recipient = self::resolveRecipient($record, $forms->get((string) $record->student_id));

            $message = $this->compose(SmsNotification::EVENT_ATTENDANCE_NOTICE, $recipient['language'], [
                'school' => self::schoolName($institutionId),
                'name' => self::firstName($record),
                'days' => (int) $standing['run'],
            ]);

            $result = $this->queue(
                $recipient,
                $record,
                SmsNotification::EVENT_ATTENDANCE_NOTICE,
                'attendance_notice:'.$record->id.':'.$standing['run_started_on'],
                $message,
            );

            if ($result['outcome'] === self::OUTCOME_QUEUED) {
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * A sample message to a number the System Admin types, to prove the
     * gateway end to end. It is about no learner and names no one.
     */
    public function test(string $number, ?int $institutionId): SmsNotification
    {
        $normalized = PhilippineMobileNumber::normalize($number)
            ?? throw new SmsGatewayFailure('Enter a Philippine mobile number, e.g. 0917 123 4567.');

        $row = SmsNotification::create([
            'institution_id' => $institutionId,
            'student_lrn' => null,
            'event' => SmsNotification::EVENT_TEST,
            'dedupe_key' => null,
            'recipient' => $normalized,
            'recipient_last4' => PhilippineMobileNumber::lastFour($normalized),
            'message' => $this->compose(SmsNotification::EVENT_TEST, 'en', [
                'school' => $institutionId ? self::schoolName($institutionId) : 'SIGLA',
            ]),
            'language' => 'en',
            'status' => SmsNotification::STATUS_QUEUED,
            'requested_by_name' => (string) session('active_name', ''),
            'requested_by_role' => (string) session('active_role', ''),
        ]);

        // Sent now rather than after the response: the admin pressed the
        // button to see what the gateway says.
        SendParentSms::dispatchSync($row->id);

        return $row->refresh();
    }

    /**
     * Where this learner's parent is texted, and in which language.
     *
     * Null when no number is on file at all; a `number` of null when one is
     * on file but is not a mobile a text can reach.
     *
     * @return array{number: ?string, language: string, source: string}|null
     */
    public static function recipientFor(StudentHealthRecord $record): ?array
    {
        return self::resolveRecipient($record, self::optInFor((string) $record->student_id, $record->institution_id));
    }

    /**
     * The one decision behind recipientFor(), given the parent's opted-in
     * letter (or none), so a whole roll can be resolved from one read of the
     * letters rather than one query per learner.
     *
     * A number the parent gave on their own letter outranks the one on
     * Sheet 1 — they chose it. When it cannot be used, the Sheet 1 number is
     * the fallback, because a typo on a form is not a reason to leave a family
     * unreached when the school holds another number for them.
     *
     * @return array{number: ?string, language: string, source: string}|null
     */
    private static function resolveRecipient(StudentHealthRecord $record, ?HealthConsentForm $optedIn): ?array
    {
        $language = $optedIn !== null && array_key_exists((string) $optedIn->sms_language, self::LANGUAGES)
            ? (string) $optedIn->sms_language
            : self::DEFAULT_LANGUAGE;

        $chosen = $optedIn !== null ? PhilippineMobileNumber::normalize((string) $optedIn->sms_number) : null;

        if ($chosen !== null) {
            return ['number' => $chosen, 'language' => $language, 'source' => self::SOURCE_CONSENT_FORM];
        }

        $details = is_array($record->student_details) ? $record->student_details : [];
        $sheetOne = trim((string) ($details['telephone_no'] ?? ''));

        if ($sheetOne === '') {
            // A letter whose number cannot be used, and nothing on Sheet 1.
            return $optedIn !== null
                ? ['number' => null, 'language' => $language, 'source' => self::SOURCE_CONSENT_FORM]
                : null;
        }

        return [
            'number' => PhilippineMobileNumber::normalize($sheetOne),
            'language' => $language,
            'source' => self::SOURCE_SHEET_ONE,
        ];
    }

    /**
     * The parent's latest answered Sulat-Pahibalo, if it says yes to texts.
     */
    public static function optInFor(string $lrn, ?int $institutionId): ?HealthConsentForm
    {
        if ($lrn === '' || $institutionId === null || ! SchemaCache::hasColumn('health_consent_forms', 'sms_opt_in')) {
            return null;
        }

        $latest = HealthConsentForm::query()
            ->where('institution_id', $institutionId)
            ->where('student_lrn', $lrn)
            ->whereIn('status', [HealthConsentForm::STATUS_SIGNED, HealthConsentForm::STATUS_REVIEWED])
            ->orderByDesc('signed_at')
            ->orderByDesc('id')
            ->first();

        return $latest !== null && $latest->sms_opt_in ? $latest : null;
    }

    /**
     * Every learner at a school whose parent's latest answer says yes, keyed by LRN.
     *
     * @return Collection<string, HealthConsentForm>
     */
    public static function optedInForms(int $institutionId): Collection
    {
        if (! SchemaCache::hasColumn('health_consent_forms', 'sms_opt_in')) {
            return collect();
        }

        return HealthConsentForm::query()
            ->where('institution_id', $institutionId)
            ->whereIn('status', [HealthConsentForm::STATUS_SIGNED, HealthConsentForm::STATUS_REVIEWED])
            ->orderBy('signed_at')
            ->orderBy('id')
            ->get(['id', 'student_lrn', 'institution_id', 'sms_opt_in', 'sms_number', 'sms_language', 'signed_at'])
            // Later answers overwrite earlier ones, so the latest decides.
            ->keyBy(fn (HealthConsentForm $f): string => (string) $f->student_lrn)
            ->filter(fn (HealthConsentForm $f): bool => (bool) $f->sms_opt_in);
    }

    /** What a staff member is told after an action that may have texted a parent. */
    public static function describe(array $result): ?string
    {
        $dryRun = (bool) config('services.semaphore.dry_run', false);

        return match ($result['outcome'] ?? null) {
            self::OUTCOME_QUEUED => ($dryRun ? 'A text message to the parent was simulated' : 'A text message is being sent to the parent')
                .' ('.PhilippineMobileNumber::masked($result['last4'] ?? '').').',
            self::OUTCOME_NO_NUMBER => 'No contact number is on file for the parent or guardian (Sheet 1), so no text was sent.',
            self::OUTCOME_INVALID_NUMBER => 'The parent\'s contact number on file is not a valid mobile number, so no text was sent.',
            self::OUTCOME_CAP_REACHED => 'The school\'s daily text message limit has been reached, so no text was sent.',
            default => null,
        };
    }

    /**
     * The message for an event in the parent's language.
     *
     * Every template keeps rule 2: it says what happened in general words and
     * asks the parent to contact the school. None begins with "TEST", which the
     * gateway would silently discard.
     *
     * @param  array<string, mixed>  $p
     */
    public function compose(string $event, string $language, array $p): string
    {
        $ceb = $language === 'ceb';
        $school = (string) ($p['school'] ?? 'Your school');
        $name = (string) ($p['name'] ?? '');

        $text = match ($event) {
            SmsNotification::EVENT_CLINIC_VISIT => $ceb
                ? $school.': Si '.$name.' mibisita sa school clinic '.(($p['today'] ?? false) ? 'karong adlawa, ' : 'niadtong ').$p['when'].'. Palihug kontaka ang school clinic para sa detalye. -SIGLA'
                : $school.': '.$name.' visited the school clinic '.(($p['today'] ?? false) ? 'today at ' : 'on ').$p['when'].'. Please contact the school clinic for details. -SIGLA',
            SmsNotification::EVENT_ATTENDANCE_NOTICE => $ceb
                ? $school.': Si '.$name.' wala makatambong sa '.$p['days'].' ka sunod-sunod nga school feeding session. Palihug kontaka ang Feeding Coordinator. -SIGLA'
                : $school.': '.$name.' has missed '.$p['days'].' school feeding sessions in a row. Please contact the Feeding Coordinator. -SIGLA',
            default => 'SIGLA: Sample message from '.$school.'. Parent text notifications are working.',
        };

        return mb_substr($text, 0, SemaphoreClient::MAX_LENGTH);
    }

    /**
     * Write the message as a queued row and send it once the response is out.
     *
     * @param  array{number: ?string, language: string, source: string}  $recipient
     * @return array{outcome: string, last4: ?string}
     */
    private function queue(array $recipient, StudentHealthRecord $record, string $event, string $dedupeKey, string $message): array
    {
        $number = PhilippineMobileNumber::normalize((string) $recipient['number']);

        if ($number === null) {
            return ['outcome' => self::OUTCOME_INVALID_NUMBER, 'last4' => null];
        }

        $last4 = PhilippineMobileNumber::lastFour($number);

        if (SmsNotification::query()->where('dedupe_key', $dedupeKey)->exists()) {
            return ['outcome' => self::OUTCOME_DUPLICATE, 'last4' => $last4];
        }

        if ($this->sentToday($record->institution_id) >= max(1, (int) config('services.semaphore.daily_cap', 300))) {
            AuditTrail::record('sms_blocked', 'SmsNotification', null, 'Daily text message limit reached; a '.$event.' message was not sent');

            return ['outcome' => self::OUTCOME_CAP_REACHED, 'last4' => $last4];
        }

        try {
            $row = SmsNotification::create([
                'institution_id' => $record->institution_id,
                'student_lrn' => (string) $record->student_id,
                'event' => $event,
                'dedupe_key' => $dedupeKey,
                'recipient' => $number,
                'recipient_last4' => $last4,
                'message' => $message,
                'language' => $recipient['language'],
                'status' => SmsNotification::STATUS_QUEUED,
                'requested_by_name' => (string) session('active_name', ''),
                'requested_by_role' => (string) session('active_role', ''),
            ]);
        } catch (QueryException) {
            // Two requests reached the same event at once; the unique key let
            // one of them write it.
            return ['outcome' => self::OUTCOME_DUPLICATE, 'last4' => $last4];
        }

        SendParentSms::dispatchAfterResponse($row->id);

        return ['outcome' => self::OUTCOME_QUEUED, 'last4' => $last4];
    }

    /** Messages this school has attempted since midnight, school time. */
    private function sentToday(?int $institutionId): int
    {
        $midnight = now(self::timezone())->startOfDay()->setTimezone(config('app.timezone'));

        return SmsNotification::query()
            ->where('institution_id', $institutionId)
            ->where('event', '!=', SmsNotification::EVENT_TEST)
            ->where('created_at', '>=', $midnight)
            ->count();
    }

    /** The given name only: enough for a parent with two children at the school. */
    private static function firstName(StudentHealthRecord $record): string
    {
        $details = is_array($record->student_details) ? $record->student_details : [];
        $first = trim((string) ($details['first_name'] ?? ''));

        if ($first === '') {
            $name = trim((string) $record->student_name);
            $first = str_contains($name, ',')
                ? trim((string) explode(',', $name, 2)[1])
                : $name;
        }

        $first = preg_split('/\s+/', $first)[0] ?? '';

        return $first !== '' ? mb_convert_case($first, MB_CASE_TITLE) : 'Your child';
    }

    private static function schoolName(?int $institutionId): string
    {
        $name = $institutionId ? (string) Institution::query()->whereKey($institutionId)->value('name') : '';

        return $name !== '' ? $name : (string) session('active_school_name', 'Your school');
    }

    private static function timezone(): string
    {
        return (string) config('app.school_timezone', 'Asia/Manila');
    }
}
