<?php

namespace App\Support;

use App\Models\HealthConsentForm;

/** A display of the photo reading, not a replacement for the recorded consent. */
final class ConsentPhotoSummary
{
    public static function fromDraft(array $draft, ?int $grade): array
    {
        $groups = array_fill_keys(['consented', 'declined', 'not_applicable', 'not_offered', 'unclear'], []);
        $choice = $draft['consent_choice'] ?? ConsentFormScanner::UNCLEAR;
        $unreadable = ! empty($draft['unreadable']) || ! empty($draft['learner_mismatch']);
        $written = $draft['written'] ?? [];
        $exceptions = trim((string) ($written['consent_exceptions']['text'] ?? ''));
        $excluded = $choice === HealthConsentForm::CONSENT_SPECIFIC
            ? self::excludedServices($written['consent_exceptions'] ?? [], $draft['field_notes']['consent_exceptions'] ?? null)
            : [];
        $services = $draft['services'] ?? [];
        $offered = array_keys(array_filter($services, fn ($value) => $value === ConsentFormScanner::TICKED));

        foreach (HealthConsentForm::PLANNED_SERVICES as $key => $label) {
            $parent = HealthConsentForm::parentService($key);
            $gradeOnly = ConsentStanding::GRADE_ONLY_SERVICES[$key] ?? null;

            if ($gradeOnly !== null && $grade !== $gradeOnly) {
                $group = 'not_applicable';
            } elseif ($unreadable) {
                $group = 'unclear';
            } elseif (! HealthConsentForm::coversService($offered, $key)) {
                $knownEmpty = ($services[$key] ?? null) === ConsentFormScanner::NOT_TICKED
                    && ($parent === null || ($services[$parent] ?? null) === ConsentFormScanner::NOT_TICKED);
                $group = $knownEmpty ? 'not_offered' : 'unclear';
            } else {
                $group = match ($choice) {
                    HealthConsentForm::CONSENT_ALL => 'consented',
                    HealthConsentForm::CONSENT_DENY => 'declined',
                    HealthConsentForm::CONSENT_SPECIFIC => $excluded === null
                        ? 'unclear'
                        : (HealthConsentForm::coversService($excluded, $key) ? 'declined' : 'consented'),
                    default => 'unclear',
                };
            }

            $groups[$group][] = $label;
        }

        $warnings = array_values(array_filter(array_merge(
            $draft['warnings'] ?? [],
            array_values($draft['field_notes'] ?? []),
        ), 'is_string'));
        if ($unreadable) {
            $warnings[] = ! empty($draft['learner_mismatch'])
                ? (($draft['consent_reason'] ?? '') ?: 'This form appears to belong to another learner.')
                : 'The photo could not be read. Check the signed form.';
        } elseif ($choice === ConsentFormScanner::UNCLEAR) {
            $warnings[] = ($draft['consent_reason'] ?? '') ?: 'The parent\'s decision could not be read.';
        }
        if ($choice === HealthConsentForm::CONSENT_SPECIFIC && $excluded === null) {
            $warnings[] = 'Check the services the parent excluded against the photo before recording consent.';
        }
        if ($choice === HealthConsentForm::CONSENT_DENY && ! ConsentFormScanner::saysSomething((string) ($written['refusal_reason']['text'] ?? ''))) {
            $warnings[] = 'The parent\'s reason for declining was not read.';
        }
        foreach ($draft['unclear'] ?? [] as $field) {
            $warnings[] = 'Check: '.$field.'.';
        }
        if (empty($draft['signature_present'])) {
            $warnings[] = 'No parent or guardian signature was found. Check the signed form.';
        }
        if (trim((string) ($draft['parent_guardian_name'] ?? '')) === '') {
            $warnings[] = 'The parent or guardian\'s name was not read.';
        }
        if (trim((string) ($draft['note'] ?? '')) !== '') {
            $warnings[] = $draft['note'];
        }
        if ($groups['unclear'] !== [] && $warnings === []) {
            $warnings[] = 'Some service decisions need to be checked against the photo.';
        }
        $warnings = array_values(array_unique(array_filter($warnings)));
        $total = count($groups['consented']) + count($groups['declined']);
        $decision = $groups['unclear'] !== [] || $unreadable
            ? 'Parent decision needs review'
            : ($total > 0
                ? 'Consents to '.count($groups['consented']).' of '.$total.' services'
                : 'No applicable services were read');
        $health = [];
        foreach (['allergy_food' => 'Food', 'allergy_medicine' => 'Medicine', 'prev_immunization' => 'Previous immunization reaction'] as $field => $label) {
            $value = self::writtenValue($draft, $field);
            if ($value !== 'None indicated') {
                $health[] = $label.': '.$value;
            }
        }

        return [
            'decision' => $decision,
            'complete' => empty($draft['needs_review']) && $warnings === [] && $total > 0,
            'warnings' => $warnings,
            'groups' => $groups,
            'reason' => $choice === HealthConsentForm::CONSENT_DENY
                ? self::writtenValue($draft, 'refusal_reason', 'Not provided')
                : ($choice === ConsentFormScanner::UNCLEAR ? 'Not determined' : 'Not required'),
            'exceptions' => $exceptions,
            'allergies' => $health === [] ? 'None indicated' : implode('; ', $health),
            'illnesses' => self::writtenValue($draft, 'other_illness'),
            'signatures' => ! empty($draft['signature_present'])
                ? 'Parent or guardian present'
                : 'Parent or guardian not detected — check the photo',
            'guardian' => trim((string) ($draft['parent_guardian_name'] ?? '')) ?: 'Not recorded',
        ];
    }

    private static function writtenValue(array $draft, string $field, string $empty = 'None indicated'): string
    {
        $entry = $draft['written'][$field] ?? null;
        if (! is_array($entry)) {
            return 'Not recorded';
        }
        if (($entry['readable'] ?? false) !== true) {
            return 'Unreadable — check the photo';
        }
        $text = trim((string) ($entry['text'] ?? ''));
        if (! empty($draft['field_notes'][$field])) {
            return ($text !== '' ? $text : 'Not clear').' — check the photo';
        }
        if ($text !== '' && empty($entry['confident'])) {
            return $text.' — check the photo';
        }

        return ConsentFormScanner::saysSomething($text) ? $text : $empty;
    }

    /**
     * Only an explicit list of service names can be grouped automatically.
     * A sentence, an unfamiliar term or uncertain handwriting stays for review.
     *
     * @return list<string>|null
     */
    private static function excludedServices(array $entry, ?string $fieldNote): ?array
    {
        if (empty($entry['readable']) || empty($entry['confident']) || $fieldNote) {
            return null;
        }

        $aliases = [];
        foreach (HealthConsentForm::PLANNED_SERVICES as $key => $label) {
            $aliases[self::normalize($label)] = $key;
            $aliases[self::normalize(HealthConsentForm::serviceName($key))] = $key;
            $aliases[self::normalize(HealthConsentForm::SERVICE_NAMES[$key])] = $key;
        }
        $aliases += [
            'check up' => 'checkup', 'checkup' => 'checkup',
            'dental' => 'dental', 'deworming' => 'deworming', 'pag purga' => 'deworming',
            'bitok' => 'deworming_worms', 'worms bitok' => 'deworming_worms',
            'tas' => 'tas', 'iron' => 'iron', 'vitamin a supplementation' => 'vitamin_a',
            'immunization' => 'immunization', 'bakuna' => 'immunization',
        ];
        $whole = self::normalize((string) ($entry['text'] ?? ''));
        if (isset($aliases[$whole])) {
            return [$aliases[$whole]];
        }
        $parts = preg_split('/[,;\n]+|\s+(?:and|ug)\s+|\s*&\s*/iu', (string) ($entry['text'] ?? ''));
        $excluded = [];
        foreach ($parts as $part) {
            $name = self::normalize($part);
            if ($name === '' || ! isset($aliases[$name])) {
                return null;
            }
            $excluded[] = $aliases[$name];
        }

        return $excluded !== [] ? array_values(array_unique($excluded)) : null;
    }

    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($text)));
    }
}
