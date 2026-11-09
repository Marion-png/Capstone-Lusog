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
     * @param  string|null  $sex  the learner's, for the services one sex alone is given
     * @param  list<string>|null  $offered  HealthServicesOffered::for(); null when not known
     * @return array<string, array<string, string>>|null
     */
    public static function fromDraft(array $draft, ?int $grade = null, ?string $sex = null, ?array $offered = null): ?array
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
            // The services the upload will store, so the reading the adviser
            // checks is the reading that is filed.
            'services' => self::tickedServices($services, $offered),
            'exceptions' => $choice === HealthConsentForm::CONSENT_SPECIFIC ? trim((string) ($written['consent_exceptions']['text'] ?? '')) : '',
            'excluded' => $choice === HealthConsentForm::CONSENT_SPECIFIC ? self::exceptedFromDraft($draft) : null,
        ] : null;

        return self::copy(
            fn (string $key): string => self::state($services[$key] ?? null),
            fn (string $box): string => self::state($consent[$box] ?? null),
            fn (string $box): string => self::state($boxes[$box] ?? null),
            fn (string $line): string => (string) ($written[$line]['text'] ?? ''),
            $answer,
            $grade,
            self::unsettledServices($services, $offered),
            $sex,
            $offered,
        );
    }

    /**
     * The services a read paper marks — the ones the class adviser shaded at
     * the top, as the School Nurse instructed — as an upload stores them. The
     * upload dialog posts exactly this list.
     *
     * A row counts when its own box is ticked. A shaded heading (Pag Purga,
     * Immunization) with no row under it shaded offers every row under it that
     * the school offers (HealthServicesOffered): at a school that gives worm
     * medicine alone, Pag Purga shaded is Pag Bitok / Worms. Rows shaded under
     * it say which, and then only those count; a row under it the reader could
     * not make out leaves the rows to the adviser (unsettledServices()).
     *
     * @param  array<string, mixed>  $boxes  every box of the letter's list, as the reader reported it
     * @param  list<string>|null  $offered  HealthServicesOffered::for(); null offers every row
     * @return list<string> keys of HealthConsentForm::PLANNED_SERVICES
     */
    public static function tickedServices(array $boxes, ?array $offered = null): array
    {
        return array_values(array_filter(array_keys(HealthConsentForm::PLANNED_SERVICES), function (string $key) use ($boxes, $offered): bool {
            if (($boxes[$key] ?? null) === self::TICKED) {
                return true;
            }

            $parent = HealthConsentForm::parentService($key);

            if ($parent === null || ($boxes[$parent] ?? null) !== self::TICKED || ! HealthServicesOffered::isOffered($key, $offered)) {
                return false;
            }

            $rows = array_map(fn (string $row): mixed => $boxes[$row] ?? null, array_keys(HealthConsentForm::SERVICES[$parent]['children']));

            return ! in_array(self::TICKED, $rows, true) && ! in_array(self::UNCLEAR, $rows, true);
        }));
    }

    /**
     * The rows a person has to settle from the paper: a box the reader could
     * not make out, and the rows under a heading that is marked (or might be)
     * when none of them is ticked and the heading does not settle them on its
     * own. A row the school does not offer has nothing to settle: it is not
     * offered whatever the paper shows.
     *
     * @param  array<string, mixed>  $boxes  every box of the letter's list, as the reader reported it
     * @param  list<string>|null  $offered  HealthServicesOffered::for(); null offers every row
     * @return list<string> keys of HealthConsentForm::PLANNED_SERVICES
     */
    public static function unsettledServices(array $boxes, ?array $offered = null): array
    {
        $ticked = self::tickedServices($boxes, $offered);

        return array_values(array_filter(array_keys(HealthConsentForm::PLANNED_SERVICES), function (string $key) use ($boxes, $ticked, $offered): bool {
            if (in_array($key, $ticked, true) || ! HealthServicesOffered::isOffered($key, $offered)) {
                return false;
            }

            if (($boxes[$key] ?? self::EMPTY) === self::UNCLEAR) {
                return true;
            }

            $parent = HealthConsentForm::parentService($key);

            // A shaded heading with no row unclear has made its offered rows
            // ticked already, so only a doubt is left here.
            return $parent !== null
                && ($boxes[$parent] ?? self::EMPTY) !== self::EMPTY
                && array_intersect(array_keys(HealthConsentForm::SERVICES[$parent]['children']), $ticked) === [];
        }));
    }

    /**
     * Where each service starts in the upload dialog, for each answer an agreed
     * form can be filed under. `approved` starts Consented; `check` waits on
     * the adviser to choose; `excluded` starts Excluded (a shaded row the
     * parent's "gawas lamang niini" names); every other row starts **Not
     * offered**, because it was not shaded at the top. Every doubt lands on
     * "not approved": an adviser can mark a row Consented they can see on the
     * paper, but a consent nobody looked at is an authorisation nobody gave.
     *
     * "Yes, except" whose exceptions cannot be named as services proposes
     * nothing at all, since any shaded row might be the one left out.
     *
     * @param  array<string, mixed>  $draft  ConsentFormScanner::interpret(), after checkLearner()
     * @param  list<string>|null  $offered  HealthServicesOffered::for(); null offers every row
     * @return array{full: array{approved: list<string>, check: list<string>, excluded: list<string>}, partial: array{approved: list<string>, check: list<string>, excluded: list<string>}}
     */
    public static function proposal(array $draft, ?array $offered = null): array
    {
        $boxes = (array) ($draft['services'] ?? []);
        $listed = self::tickedServices($boxes, $offered);
        $unsettled = self::unsettledServices($boxes, $offered);
        $excepted = self::exceptedFromDraft($draft);
        $onPaper = array_values(array_intersect(array_keys(HealthConsentForm::PLANNED_SERVICES), [...$listed, ...$unsettled]));

        $named = fn (array $keys, bool $excluded): array => array_values(array_filter(
            $keys,
            fn (string $key): bool => HealthConsentForm::coversService($excepted ?? [], $key) === $excluded,
        ));

        return [
            'full' => ['approved' => $listed, 'check' => $unsettled, 'excluded' => []],
            'partial' => $excepted === null
                ? ['approved' => [], 'check' => $onPaper, 'excluded' => []]
                : ['approved' => $named($listed, false), 'check' => $named($unsettled, false), 'excluded' => $named($onPaper, true)],
        ];
    }

    /**
     * The services a parent's "gawas lamang niini" line names, or null when
     * that cannot be said for certain. Only an explicit list of service names
     * counts: a sentence, an unfamiliar word or a reading the reader was unsure
     * of stays for a person, because reading "no deworming" as a list is how a
     * refusal becomes a consent.
     *
     * @param  array<string, mixed>  $entry  the written line, as the reader reported it
     * @return list<string>|null keys of HealthConsentForm::SERVICES and their sub-items
     */
    public static function exceptedServices(array $entry, ?string $fieldNote = null): ?array
    {
        if (empty($entry['readable']) || empty($entry['confident']) || $fieldNote) {
            return null;
        }

        $text = (string) ($entry['text'] ?? '');
        $names = self::exceptionNames();

        // The whole line may be one service's name, with an "ug" of its own
        // ("Check Up sa Dentist ug Ibot sa Ngipon").
        if (isset($names[self::plainWords($text)])) {
            return [$names[self::plainWords($text)]];
        }

        $excepted = [];

        foreach ((array) preg_split('/[,;\/\n]+|\s+(?:and|ug|og|at)\s+|\s*&\s*/iu', $text) as $part) {
            $name = self::plainWords((string) $part);

            if ($name === '' || ! isset($names[$name])) {
                return null;
            }

            $excepted[] = $names[$name];
        }

        return $excepted !== [] ? array_values(array_unique($excepted)) : null;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return list<string>|null
     */
    private static function exceptedFromDraft(array $draft): ?array
    {
        return self::exceptedServices(
            (array) ($draft['written']['consent_exceptions'] ?? []),
            $draft['field_notes']['consent_exceptions'] ?? null,
        );
    }

    /**
     * The names a parent writes a service by, in the catalogue's words and the
     * letter's, in English and Cebuano.
     *
     * @return array<string, string>
     */
    private static function exceptionNames(): array
    {
        $names = [];

        foreach (array_keys(HealthConsentForm::PLANNED_SERVICES) as $key) {
            foreach ([
                HealthConsentForm::PLANNED_SERVICES[$key],
                HealthConsentForm::serviceName($key),
                HealthConsentForm::SERVICE_NAMES[$key],
                HealthConsentForm::serviceLabels()[$key],
            ] as $name) {
                $names[self::plainWords($name)] = $key;
            }
        }

        return $names + [
            'check up' => 'checkup', 'checkup' => 'checkup', 'eksaminasyon' => 'checkup', 'examination' => 'checkup',
            'dental' => 'dental', 'dentist' => 'dental', 'ngipon' => 'dental', 'ibot' => 'dental', 'ibot sa ngipon' => 'dental', 'bunot' => 'dental', 'tooth extraction' => 'dental',
            'deworming' => 'deworming', 'purga' => 'deworming', 'pag purga' => 'deworming', 'pagpurga' => 'deworming',
            'bitok' => 'deworming_worms', 'pag bitok' => 'deworming_worms', 'worms' => 'deworming_worms', 'worms bitok' => 'deworming_worms', 'bulate' => 'deworming_worms',
            'schisto' => 'deworming_schistosomiasis',
            'tas' => 'tas', 'transmission assessment survey' => 'tas',
            'iron' => 'iron', 'iron supplementation' => 'iron', 'pula nga dugo' => 'iron',
            'vitamin a' => 'vitamin_a', 'vitamin a supplementation' => 'vitamin_a', 'bitamina a' => 'vitamin_a', 'bitamina' => 'vitamin_a',
            'immunization' => 'immunization', 'bakuna' => 'immunization', 'vaccine' => 'immunization', 'vaccination' => 'immunization', 'mr' => 'immunization', 'td' => 'immunization', 'mr td' => 'immunization',
        ];
    }

    private static function plainWords(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($text)));
    }

    /**
     * An uploaded form, as the adviser recorded it. A heading the paper ticks
     * over its sub-items (Pag Purga, Immunization) reads ticked when any of
     * them is, since only the sub-items are stored.
     *
     * @param  list<string>|null  $offered  HealthServicesOffered::for(); null when not known
     * @return array<string, array<string, string>>
     */
    public static function fromUpload(ParentalConsentForm $upload, ?int $grade = null, ?string $sex = null, ?array $offered = null): array
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
            [],
            $sex,
            $offered,
        );
    }

    /**
     * A letter the parent answered online, or that the adviser recorded from
     * the signed paper. A box beside a line is ticked when its line is
     * filled, as the letter itself draws it.
     *
     * @param  list<string>|null  $offered  HealthServicesOffered::for(); null when not known
     * @return array<string, array<string, string>>
     */
    public static function fromLetter(HealthConsentForm $letter, ?int $grade = null, ?string $sex = null, ?array $offered = null): array
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
            [],
            $sex,
            $offered,
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
     * @param  list<string>  $unsettled  rows a reader could not settle; a saved form has none
     * @param  list<string>|null  $offered
     * @return array<string, array<string, string>>
     */
    private static function copy(callable $service, callable $consent, callable $box, callable $line, ?array $answer, ?int $grade, array $unsettled = [], ?string $sex = null, ?array $offered = null): array
    {
        $state = fn (bool|string $value): string => is_bool($value) ? ($value ? self::TICKED : self::EMPTY) : $value;

        $copy = ['services' => [], 'standings' => [], 'consent' => [], 'boxes' => [], 'lines' => []];

        foreach (array_keys(ConsentFormScanner::serviceKeys()) as $key) {
            $copy['services'][$key] = $state($service($key));
        }

        foreach (array_keys(HealthConsentForm::PLANNED_SERVICES) as $key) {
            $copy['standings'][$key] = self::standing($key, $copy['services'], $answer, $grade, $unsettled, $sex, $offered);
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
     * has to be read instead — a row nobody could settle, or a service the
     * letter lists on a form whose answer is not known yet. A refusal needs no
     * box read: it declines every service that may be on the sheet.
     *
     * @param  array<string, string>  $services  every box of the letter's list
     * @param  array<string, mixed>|null  $answer
     * @param  list<string>  $unsettled
     * @param  list<string>|null  $offered
     */
    private static function standing(string $key, array $services, ?array $answer, ?int $grade, array $unsettled, ?string $sex = null, ?array $offered = null): string
    {
        if (! ConsentStanding::appliesTo($key, $grade, $sex)) {
            return ConsentStanding::SERVICE_NOT_APPLICABLE;
        }

        if (! HealthServicesOffered::isOffered($key, $offered)) {
            return ConsentStanding::SERVICE_NOT_ON_FORM;
        }

        if (in_array($key, $unsettled, true)) {
            return ($answer['choice'] ?? null) === HealthConsentForm::CONSENT_DENY
                ? ConsentStanding::SERVICE_DECLINED
                : self::UNCLEAR;
        }

        $parent = HealthConsentForm::parentService($key);
        $own = $services[$key] ?? self::EMPTY;
        $heading = $parent !== null ? ($services[$parent] ?? self::EMPTY) : self::EMPTY;
        $listed = $own === self::TICKED || $heading === self::TICKED;

        if ($answer === null) {
            return $listed ? self::UNCLEAR : ConsentStanding::SERVICE_NOT_ON_FORM;
        }

        return ConsentStanding::forService($key, $grade, true, $answer, $sex, $offered);
    }

    /** A reader's answer for one box, with anything off the list read as unclear. */
    private static function state(mixed $value): string
    {
        return in_array($value, [self::TICKED, self::EMPTY], true) ? $value : self::UNCLEAR;
    }
}
