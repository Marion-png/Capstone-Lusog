<?php

namespace App\Support;

use App\Models\HealthConsentForm;
use App\Models\ParentalConsentForm;

/**
 * A Sulat-Pahibalo's answers laid out as the paper carries them.
 *
 * Every service in the letter's own Cebuano wording with where the learner
 * stands for it, every consent and allergy box ticked, empty or unclear
 * exactly as it is on the paper, and every handwritten line as it was
 * written — never translated, never summarised. One shape for the three
 * places a form is read back (the reader's draft in the upload dialog, an
 * uploaded form, an answered letter), so all three are drawn by the one
 * partial, consent-forms._paper-copy, and cannot read the same paper two
 * ways.
 */
final class ConsentPaperCopy
{
    public const TICKED = ConsentFormScanner::TICKED;

    public const EMPTY = ConsentFormScanner::NOT_TICKED;

    public const UNCLEAR = ConsentFormScanner::UNCLEAR;

    /** The handwritten line under each consent box that has one. */
    public const CONSENT_LINES = [
        HealthConsentForm::CONSENT_SPECIFIC => 'consent_exceptions',
        HealthConsentForm::CONSENT_DENY => 'refusal_reason',
    ];

    /** The three kinds of allergy under the allergy heading. */
    public const ALLERGY_KINDS = ['allergy_food', 'allergy_medicine', 'prev_immunization'];

    /**
     * What the reader saw on a photographed form. Nothing for a photo it could
     * not read, or for another child's sheet: neither is this learner's form.
     *
     * @param  array<string, mixed>  $draft  ConsentFormScanner::interpret(), after checkLearner()
     * @param  int|null  $grade  the learner's grade, for the services one grade alone is given
     * @return array<string, array<string, string>>|null
     */
    public static function fromDraft(array $draft, ?int $grade = null): ?array
    {
        if (($draft['unreadable'] ?? false) || ($draft['learner_mismatch'] ?? false)) {
            return null;
        }

        $services = (array) ($draft['services'] ?? []);
        $consent = (array) ($draft['consent_boxes'] ?? []);
        $boxes = (array) ($draft['allergy_boxes'] ?? []);
        $written = (array) ($draft['written'] ?? []);

        // The reader's answer, shaped as ConsentStanding::answer() shapes a
        // saved one, so each service is judged by the same rule. An answer it
        // could not decide is no answer.
        $choice = (string) ($draft['consent_choice'] ?? '');
        $answer = array_key_exists($choice, HealthConsentForm::CONSENT_TEXT) ? [
            'source' => 'scan',
            'choice' => $choice,
            'services' => array_keys(array_filter($services, fn ($state): bool => $state === self::TICKED)),
            'exceptions' => $choice === HealthConsentForm::CONSENT_SPECIFIC ? trim((string) ($written['consent_exceptions']['text'] ?? '')) : '',
            'excluded' => null,
        ] : null;

        return self::copy(
            fn (string $key): string => self::state($services[$key] ?? null),
            fn (string $box): string => self::state($consent[$box] ?? null),
            fn (string $box): string => self::state($boxes[$box] ?? null),
            fn (string $line): string => (string) ($written[$line]['text'] ?? ''),
            $answer,
            $grade,
        );
    }

    /**
     * An uploaded form, as the adviser recorded it. A heading the paper ticks
     * over its sub-items (Pag Purga, Immunization) reads ticked when any of
     * them is, since only the sub-items are stored.
     *
     * @return array<string, array<string, string>>
     */
    public static function fromUpload(ParentalConsentForm $upload, ?int $grade = null): array
    {
        $services = (array) ($upload->services ?? []);
        $choice = match ((string) $upload->consent_type) {
            'full' => HealthConsentForm::CONSENT_ALL,
            'partial' => HealthConsentForm::CONSENT_SPECIFIC,
            'refused' => HealthConsentForm::CONSENT_DENY,
            default => null,
        };

        $flags = [
            'allergy_food' => (bool) $upload->allergy_food,
            'allergy_medicine' => (bool) $upload->allergy_medicine,
            'prev_immunization' => (bool) $upload->prev_immunization,
            'other_illness' => (bool) $upload->has_other_illness,
        ];

        $lines = [
            'consent_exceptions' => $upload->partial_exception,
            'refusal_reason' => $upload->refused_reason,
            'allergy_food' => $upload->allergy_food_detail,
            'allergy_medicine' => $upload->allergy_medicine_detail,
            'prev_immunization' => $upload->prev_immunization_detail,
            'other_illness' => $upload->other_illness_detail,
        ];

        return self::copy(
            function (string $key) use ($services): bool {
                $children = array_keys(HealthConsentForm::SERVICES[$key]['children'] ?? []);

                return $children !== []
                    ? array_intersect($children, $services) !== []
                    : in_array($key, $services, true);
            },
            fn (string $box): bool => $box === $choice,
            fn (string $box): bool => $box === 'allergy'
                ? $flags['allergy_food'] || $flags['allergy_medicine'] || $flags['prev_immunization']
                : $flags[$box],
            fn (string $line): string => (string) ($lines[$line] ?? ''),
            ConsentStanding::answer(null, $upload),
            $grade,
        );
    }

    /**
     * A letter the parent answered online, or that the adviser recorded from
     * the signed paper. A box beside a line is ticked when its line is
     * filled, as the letter itself draws it.
     *
     * @return array<string, array<string, string>>
     */
    public static function fromLetter(HealthConsentForm $letter, ?int $grade = null): array
    {
        $lines = [
            'consent_exceptions' => $letter->consent_exceptions,
            'refusal_reason' => $letter->refusal_reason,
            'allergy_food' => $letter->allergy_food,
            'allergy_medicine' => $letter->allergy_medicine,
            'prev_immunization' => $letter->prev_immunization,
            'other_illness' => $letter->other_illness,
        ];
        $filled = fn (string $line): bool => filled($lines[$line] ?? null);

        return self::copy(
            fn (string $key): bool => $letter->hasService($key),
            fn (string $box): bool => $box === $letter->consent_choice,
            fn (string $box): bool => $box === 'allergy'
                ? $filled('allergy_food') || $filled('allergy_medicine') || $filled('prev_immunization')
                : $filled($box),
            fn (string $line): string => (string) ($lines[$line] ?? ''),
            ConsentStanding::answer($letter),
            $grade,
        );
    }

    /**
     * Every box and line on the form, each asked of the source once, and
     * where the learner stands for each service.
     *
     * @param  callable(string): (bool|string)  $service
     * @param  callable(string): (bool|string)  $consent
     * @param  callable(string): (bool|string)  $box
     * @param  callable(string): string  $line
     * @param  array<string, mixed>|null  $answer  ConsentStanding::answer()'s shape
     * @return array<string, array<string, string>>
     */
    private static function copy(callable $service, callable $consent, callable $box, callable $line, ?array $answer, ?int $grade): array
    {
        $state = fn (bool|string $value): string => is_bool($value) ? ($value ? self::TICKED : self::EMPTY) : $value;

        $copy = ['services' => [], 'standings' => [], 'consent' => [], 'boxes' => [], 'lines' => []];

        foreach (array_keys(ConsentFormScanner::serviceKeys()) as $key) {
            $copy['services'][$key] = $state($service($key));
        }

        foreach (array_keys(HealthConsentForm::PLANNED_SERVICES) as $key) {
            $copy['standings'][$key] = self::standing($key, $copy['services'], $answer, $grade);
        }

        foreach (array_keys(HealthConsentForm::CONSENT_TEXT) as $choice) {
            $copy['consent'][$choice] = $state($consent($choice));
        }

        foreach (array_keys(HealthConsentForm::ALLERGY_TEXT) as $key) {
            $copy['boxes'][$key] = $state($box($key));
        }

        foreach ([...array_values(self::CONSENT_LINES), ...self::ALLERGY_KINDS, 'other_illness'] as $key) {
            $copy['lines'][$key] = trim($line($key));
        }

        return $copy;
    }

    /**
     * Where the learner stands for one service: ConsentStanding's own rule
     * whenever the paper and the answer can say, and UNCLEAR when the paper
     * has to be read instead — a box nobody could make out, or a service the
     * letter lists on a form whose answer is not known yet.
     *
     * @param  array<string, string>  $services  every box of the letter's list
     * @param  array<string, mixed>|null  $answer
     */
    private static function standing(string $key, array $services, ?array $answer, ?int $grade): string
    {
        $onlyGrade = ConsentStanding::GRADE_ONLY_SERVICES[$key] ?? null;

        if ($onlyGrade !== null && $grade !== $onlyGrade) {
            return ConsentStanding::SERVICE_NOT_APPLICABLE;
        }

        $parent = HealthConsentForm::parentService($key);
        $own = $services[$key] ?? self::EMPTY;
        $heading = $parent !== null ? ($services[$parent] ?? self::EMPTY) : self::EMPTY;
        $listed = $own === self::TICKED || $heading === self::TICKED;

        if (! $listed && ($own === self::UNCLEAR || $heading === self::UNCLEAR)) {
            return self::UNCLEAR;
        }

        if ($answer === null) {
            return $listed ? self::UNCLEAR : ConsentStanding::SERVICE_NOT_ON_FORM;
        }

        return ConsentStanding::forService($key, $grade, true, $answer);
    }

    /** A reader's answer for one box, with anything off the list read as unclear. */
    private static function state(mixed $value): string
    {
        return in_array($value, [self::TICKED, self::EMPTY], true) ? $value : self::UNCLEAR;
    }
}
