<?php

namespace App\Support;

use App\Models\HealthConsentForm;
use App\Models\ParentalConsentForm;

/**
 * Where one learner's consent stands, whichever way it arrived.
 *
 * A consent reaches this school two ways — the digital Sulat-Pahibalo the
 * class adviser sends to the parent (`health_consent_forms`), and the signed
 * paper form the guardian fills in and the adviser scans and uploads
 * (`parental_consent_forms`) — and every screen asking "may this child be
 * given the service?" has to read both. This is the one place that decides,
 * so the adviser's Consent Forms page, their student profile and the counts
 * on their dashboard cannot report three different answers about one child.
 *
 * Two rules it keeps:
 *
 * - **A status is only reported once there is a document behind it.** A
 *   parent-signed online form carries its own e-signature, so it stands on its
 *   own. An uploaded record does not: the signed scan is its evidence, and the
 *   file is nullable on upload, so a record can exist carrying an answer with
 *   no scan attached. Reporting that as Approved would claim a consent the
 *   school cannot produce.
 * - **A letter nobody has answered is pending, not refused.** Drafts and
 *   sent-but-unsigned forms are still waiting on the guardian.
 *
 * `consent_choice` and `consent_type` are both encrypted at rest, so every
 * comparison here is on decrypted values in PHP — never in a WHERE.
 */
final class ConsentStanding
{
    public const APPROVED = 'approved';

    public const PARTIAL = 'partial';

    public const DECLINED = 'declined';

    public const PENDING = 'pending';

    /**
     * A guardian's signed form is on file; nobody has keyed in its answer.
     *
     * Not a standing `for()` can return — see `badge()`. It is what a screen
     * prints, never what decides whether a service may be given.
     */
    public const RETURNED = 'returned';

    /**
     * The short label printed beside a learner, for every value `badge()` can
     * return. Typed once, so the profile's tab badge and the My Students
     * column cannot word one answer two ways.
     *
     * @var array<string, string>
     */
    public const LABELS = [
        self::APPROVED => 'Approved',
        self::PARTIAL => 'Partial',
        self::DECLINED => 'Declined',
        self::RETURNED => 'Returned',
        self::PENDING => 'Pending',
    ];

    /**
     * Where one learner stands for one health service, or — with no service
     * chosen — what their form decided overall. One set of values, so the
     * nurse's list, its counts and the form page cannot word one answer two
     * ways.
     */
    public const SERVICE_CONSENTED = 'consented';

    public const SERVICE_WITH_EXCEPTIONS = 'consented_with_exceptions';

    public const SERVICE_DECLINED = 'declined';

    /**
     * "Yes, except" where the exceptions are only the parent's handwriting:
     * whether this service is one of them is for a person to read, not for
     * this class to guess.
     */
    public const SERVICE_CHECK_EXCEPTIONS = 'check_exceptions';

    public const SERVICE_NO_FORM = 'no_form';

    /**
     * The letter the parent answered did not ask about this service, so it was
     * not consented to. Shown as "Not consented"; the key keeps its old name
     * because it travels on filter links.
     */
    public const SERVICE_NOT_ON_FORM = 'not_on_form';

    public const SERVICE_NOT_ENROLLED = 'not_enrolled';

    public const SERVICE_NOT_APPLICABLE = 'not_applicable';

    /** @var array<string, string> */
    public const SERVICE_LABELS = [
        self::SERVICE_CONSENTED => 'Consented',
        self::SERVICE_WITH_EXCEPTIONS => 'Consented with exceptions',
        self::SERVICE_DECLINED => 'Declined',
        self::SERVICE_CHECK_EXCEPTIONS => 'Check the exceptions',
        self::SERVICE_NO_FORM => 'No form yet',
        self::SERVICE_NOT_ON_FORM => 'Not consented',
        self::SERVICE_NOT_ENROLLED => 'Not enrolled that year',
        self::SERVICE_NOT_APPLICABLE => 'Not applicable',
    ];

    /**
     * Standings that leave a learner out of every count and of the consent
     * rate: the service is not theirs to be asked about.
     *
     * @var list<string>
     */
    public const UNCOUNTED = [
        self::SERVICE_NOT_APPLICABLE,
        self::SERVICE_NOT_ENROLLED,
        self::SERVICE_NOT_ON_FORM,
    ];

    /**
     * Services given to one grade only, keyed to that grade. Grade 7 MR/Td is
     * the one the printed form names.
     *
     * @var array<string, int>
     */
    public const GRADE_ONLY_SERVICES = [
        'immunization_grade7' => 7,
    ];

    /** The standing one learner's consent is in. */
    public static function for(?HealthConsentForm $form, ?ParentalConsentForm $upload = null): string
    {
        return match (self::answer($form, $upload)['choice'] ?? null) {
            HealthConsentForm::CONSENT_DENY => self::DECLINED,
            HealthConsentForm::CONSENT_SPECIFIC => self::PARTIAL,
            HealthConsentForm::CONSENT_ALL => self::APPROVED,
            default => self::PENDING,
        };
    }

    /**
     * The record that answers for one learner, read once: the parent's
     * answered letter, else a signed scan whose answer the adviser keyed in.
     * Null when nothing on file answers yet — a draft, a letter still out, or
     * a scan nobody has recorded the answer of.
     *
     * @return array{source: string, choice: string, services: list<string>, exceptions: string, excluded: list<string>|null}|null
     */
    public static function answer(?HealthConsentForm $form, ?ParentalConsentForm $upload = null): ?array
    {
        $answered = [HealthConsentForm::STATUS_SIGNED, HealthConsentForm::STATUS_REVIEWED];

        if ($form !== null && in_array($form->status, $answered, true)) {
            $choice = match ($form->consent_choice) {
                HealthConsentForm::CONSENT_DENY => HealthConsentForm::CONSENT_DENY,
                HealthConsentForm::CONSENT_SPECIFIC => HealthConsentForm::CONSENT_SPECIFIC,
                default => HealthConsentForm::CONSENT_ALL,
            };

            return [
                'source' => 'letter',
                'choice' => $choice,
                'services' => array_values(array_filter((array) ($form->services ?? []), 'is_string')),
                'exceptions' => $choice === HealthConsentForm::CONSENT_SPECIFIC ? trim((string) $form->consent_exceptions) : '',
                // Which services "yes, except" left out, as services. Not
                // recorded yet: the exceptions are the parent's handwriting.
                'excluded' => null,
            ];
        }

        if ($upload !== null && $upload->file_path !== null) {
            $choice = match ($upload->consent_type) {
                'refused' => HealthConsentForm::CONSENT_DENY,
                'partial' => HealthConsentForm::CONSENT_SPECIFIC,
                'full' => HealthConsentForm::CONSENT_ALL,
                // The upload dialog no longer asks what the parent answered, so
                // a scan usually carries none. The form is in and the school
                // holds it; what it says has not been recorded, and an answer
                // nobody has read authorises nothing.
                default => null,
            };

            if ($choice === null) {
                return null;
            }

            // The services the letter lists, as the adviser ticked them off
            // the paper. A scan filed before they were recorded covers the one
            // program it was filed against, named as the catalogue names it.
            $services = array_values(array_filter((array) ($upload->services ?? []), 'is_string'));
            $program = array_search(
                strtolower(trim((string) $upload->program_type)),
                array_map('strtolower', HealthConsentForm::SERVICE_NAMES),
                true,
            );

            return [
                'source' => 'upload',
                'choice' => $choice,
                'services' => $services !== [] ? $services : ($program !== false ? [$program] : []),
                'exceptions' => $choice === HealthConsentForm::CONSENT_SPECIFIC ? trim((string) $upload->partial_exception) : '',
                'excluded' => null,
            ];
        }

        return null;
    }

    /**
     * Where one learner stands for one service. The order is the rule, and
     * the first that holds wins.
     *
     * @param  array<string, mixed>|null  $answer  what answer() returned
     */
    public static function forService(string $service, ?int $grade, bool $enrolled, ?array $answer): string
    {
        $onlyGrade = self::GRADE_ONLY_SERVICES[$service] ?? null;

        if ($onlyGrade !== null && $grade !== $onlyGrade) {
            return self::SERVICE_NOT_APPLICABLE;
        }

        if (! $enrolled) {
            return self::SERVICE_NOT_ENROLLED;
        }

        if ($answer === null) {
            return self::SERVICE_NO_FORM;
        }

        if ($answer['choice'] === HealthConsentForm::CONSENT_DENY) {
            return self::SERVICE_DECLINED;
        }

        if (! HealthConsentForm::coversService($answer['services'], $service)) {
            return self::SERVICE_NOT_ON_FORM;
        }

        if ($answer['choice'] === HealthConsentForm::CONSENT_SPECIFIC) {
            if ($answer['excluded'] === null) {
                return self::SERVICE_CHECK_EXCEPTIONS;
            }

            if (HealthConsentForm::coversService($answer['excluded'], $service)) {
                return self::SERVICE_DECLINED;
            }
        }

        return self::SERVICE_CONSENTED;
    }

    /**
     * What one learner's form decided, across every service.
     *
     * @param  array<string, mixed>|null  $answer  what answer() returned
     */
    public static function overall(bool $enrolled, ?array $answer): string
    {
        if (! $enrolled) {
            return self::SERVICE_NOT_ENROLLED;
        }

        return match ($answer['choice'] ?? null) {
            HealthConsentForm::CONSENT_DENY => self::SERVICE_DECLINED,
            HealthConsentForm::CONSENT_SPECIFIC => self::SERVICE_WITH_EXCEPTIONS,
            HealthConsentForm::CONSENT_ALL => self::SERVICE_CONSENTED,
            default => self::SERVICE_NO_FORM,
        };
    }

    /**
     * What to print beside a learner: the standing, plus one value of its own.
     *
     * A guardian's signed form on file whose answer nobody has keyed in
     * authorises nothing, so `for()` keeps it PENDING and every gate reads it
     * that way. But telling the adviser who has just filed that form that the
     * consent is still outstanding is simply wrong — the guardian has filled it
     * in and the school holds it. So the two questions are answered separately
     * and both from here, rather than each screen deciding for itself.
     */
    public static function badge(?HealthConsentForm $form, ?ParentalConsentForm $upload = null): string
    {
        $standing = self::for($form, $upload);

        return $standing === self::PENDING && self::guardianReturnedPaperForm($upload)
            ? self::RETURNED
            : $standing;
    }

    /**
     * Whether the guardian has returned a filled-in paper form for this
     * learner — the scan is the evidence, so a record with no file is not one.
     */
    public static function guardianReturnedPaperForm(?ParentalConsentForm $upload): bool
    {
        return $upload !== null && $upload->file_path !== null;
    }

    /** How the adviser recorded the parent's answer on a scanned form. */
    public static function uploadAnswerLabel(?ParentalConsentForm $upload): ?string
    {
        return match ($upload?->consent_type) {
            'full' => 'Consented to all health services',
            'partial' => 'Consented with exceptions',
            'refused' => 'Consent declined',
            default => null,
        };
    }
}
