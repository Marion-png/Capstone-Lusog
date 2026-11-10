<?php

namespace App\Support;

use App\Models\HealthConsentForm;

/**
 * What the photo reader proposed for one Sulat-Pahibalo, beside what the
 * adviser saved after checking it against the paper.
 *
 * The reader proposes and the adviser records, so every value on an uploaded
 * form has two versions worth keeping: the one the dialog was pre-filled with,
 * and the one filed. This keeps both, for each service and each field, so the
 * fields advisers had to correct can be counted later. It is built once, when
 * the upload is filed, and stored encrypted on `parental_consent_forms.
 * scan_review` (it holds the parent's handwriting about a child's health).
 *
 * A value the reader did not propose — a sheet it could not read, an answer it
 * could not decide — is `null` on the reader's side and never counts as an
 * edit: the adviser filled it in, they did not correct it.
 */
final class ConsentReviewLog
{
    /** A service's status, in the dialog's words. */
    public const CONSENTED = 'consented';

    public const EXCLUDED = 'excluded';

    public const NOT_OFFERED = 'not_offered';

    /** A row the reader left for the adviser to mark from the paper. */
    public const CHECK = 'check';

    /**
     * The fields the dialog pre-fills from the reading, keyed by the upload's
     * column, each with the line of the reading it comes from.
     *
     * @var array<string, string>
     */
    private const DETAIL_FIELDS = [
        'allergy_food_detail' => 'allergy_food',
        'allergy_medicine_detail' => 'allergy_medicine',
        'prev_immunization_detail' => 'prev_immunization',
        'other_illness_detail' => 'other_illness',
    ];

    /**
     * @param  array<string, mixed>  $draft  the reading the adviser checked (ConsentFormScanner::interpret(), after checkLearner())
     * @param  array{consent_type: string|null, services: list<string>, excluded: list<string>|null, partial_exception: string|null, refused_reason: string|null, allergy_food_detail: string|null, allergy_medicine_detail: string|null, prev_immunization_detail: string|null, other_illness_detail: string|null, incomplete: bool}  $saved
     * @param  list<string>|null  $offered  HealthServicesOffered::for() for the year the form is filed under
     * @return array{services: array<string, array{vlm: string|null, final: string, edited: bool}>, fields: array<string, array{vlm: string|bool|null, final: string|bool, edited: bool}>, edited: int, services_edited: int, fields_edited: int}
     */
    public static function build(array $draft, array $saved, ?int $grade, ?string $sex, ?array $offered): array
    {
        $proposed = self::proposedServices($draft, $grade, $sex, $offered);
        $final = self::savedServices($saved, $grade, $sex, $offered);

        $services = [];
        foreach (array_keys(HealthConsentForm::PLANNED_SERVICES) as $key) {
            $services[$key] = self::entry($proposed[$key], $final[$key]);
        }

        $fields = ['consent_type' => self::entry(self::proposedAnswer($draft), $saved['consent_type'] ?? '')];

        foreach (['partial_exception' => ['consent_exceptions', 'partial'], 'refused_reason' => ['refusal_reason', 'refused']] as $field => [$line, $answer]) {
            // A line belongs to the answer it is written under: the reader's
            // exceptions are its proposal only when it read "yes, except".
            $vlm = self::read($draft) ? (self::proposedAnswer($draft) === $answer ? self::text($draft, $line) : '') : null;
            $fields[$field] = self::entry($vlm, trim((string) ($saved[$field] ?? '')));
        }

        foreach (self::DETAIL_FIELDS as $field => $line) {
            $fields[$field] = self::entry(self::read($draft) ? self::prefilled($draft, $line) : null, trim((string) ($saved[$field] ?? '')));
        }

        $fields['form_complete'] = self::entry(
            self::read($draft) ? (bool) ($draft['form_complete'] ?? false) : null,
            empty($saved['incomplete']),
        );

        $servicesEdited = count(array_filter($services, fn (array $entry): bool => $entry['edited']));
        $fieldsEdited = count(array_filter($fields, fn (array $entry): bool => $entry['edited']));

        return [
            'services' => $services,
            'fields' => $fields,
            'edited' => $servicesEdited + $fieldsEdited,
            'services_edited' => $servicesEdited,
            'fields_edited' => $fieldsEdited,
        ];
    }

    /**
     * Where the dialog started each service from the reading: the school's
     * list and the rules first, then ConsentPaperCopy::proposal() under the
     * answer the reader read. Null where it proposed nothing.
     *
     * @return array<string, string|null>
     */
    private static function proposedServices(array $draft, ?int $grade, ?string $sex, ?array $offered): array
    {
        $answer = self::proposedAnswer($draft);
        $proposal = $answer !== null ? ConsentPaperCopy::proposal($draft, $offered)[$answer] : null;

        $states = [];
        foreach (array_keys(HealthConsentForm::PLANNED_SERVICES) as $key) {
            $states[$key] = match (true) {
                ! self::given($key, $grade, $sex, $offered) => self::NOT_OFFERED,
                $proposal === null => null,
                in_array($key, $proposal['approved'], true) => self::CONSENTED,
                in_array($key, $proposal['excluded'], true) => self::EXCLUDED,
                in_array($key, $proposal['check'], true) => self::CHECK,
                default => self::NOT_OFFERED,
            };
        }

        return $states;
    }

    /**
     * Each service as it was filed. A refusal excludes every service the form
     * covers; with no answer recorded, none has a status yet.
     *
     * @return array<string, string>
     */
    private static function savedServices(array $saved, ?int $grade, ?string $sex, ?array $offered): array
    {
        $type = $saved['consent_type'] ?? null;
        $services = (array) ($saved['services'] ?? []);
        $excluded = (array) ($saved['excluded'] ?? []);

        $states = [];
        foreach (array_keys(HealthConsentForm::PLANNED_SERVICES) as $key) {
            $states[$key] = match (true) {
                ! self::given($key, $grade, $sex, $offered) => self::NOT_OFFERED,
                $type === null => 'not_recorded',
                $type === 'refused' => in_array($key, $services, true) || $services === [] ? self::EXCLUDED : self::NOT_OFFERED,
                in_array($key, $excluded, true) => self::EXCLUDED,
                in_array($key, $services, true) => self::CONSENTED,
                default => self::NOT_OFFERED,
            };
        }

        return $states;
    }

    /** The answer the dialog was set to from the reading: full, partial or refused, or none. */
    private static function proposedAnswer(array $draft): ?string
    {
        if (! self::read($draft)) {
            return null;
        }

        return match ($draft['consent_choice'] ?? null) {
            HealthConsentForm::CONSENT_ALL => 'full',
            HealthConsentForm::CONSENT_SPECIFIC => 'partial',
            HealthConsentForm::CONSENT_DENY => 'refused',
            // None marked, more than one, or unclear: the reader proposed no
            // answer, and the adviser chose one.
            default => null,
        };
    }

    /** Whether anything was read off the sheet for this learner. */
    private static function read(array $draft): bool
    {
        return empty($draft['unreadable']) && empty($draft['learner_mismatch']);
    }

    /** The handwriting on one line, as read. */
    private static function text(array $draft, string $line): string
    {
        return trim((string) ($draft['written'][$line]['text'] ?? ''));
    }

    /**
     * An allergy or illness line as the dialog pre-fills it: what is written,
     * or "Not specified" when its box is ticked over an empty line.
     */
    private static function prefilled(array $draft, string $line): string
    {
        $text = self::text($draft, $line);

        if ($text === '' && ($draft['written'][$line]['box'] ?? null) === ConsentFormScanner::TICKED) {
            return LearnerAllergies::NOT_SPECIFIED;
        }

        return $text;
    }

    /** Whether a service is the learner's to be asked about: the school's list and the rules. */
    private static function given(string $key, ?int $grade, ?string $sex, ?array $offered): bool
    {
        return ConsentStanding::appliesTo($key, $grade, $sex) && HealthServicesOffered::isOffered($key, $offered);
    }

    /** @return array{vlm: string|bool|null, final: string|bool, edited: bool} */
    private static function entry(string|bool|null $vlm, string|bool $final): array
    {
        return ['vlm' => $vlm, 'final' => $final, 'edited' => $vlm !== null && $vlm !== $final];
    }
}
