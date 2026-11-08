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
            ? ConsentPaperCopy::exceptedServices($written['consent_exceptions'] ?? [], $draft['field_notes']['consent_exceptions'] ?? null)
            : [];
        // The reading of the rows the upload dialog files, so this summary and
        // the list beside it cannot count different services.
        $services = $draft['services'] ?? [];
        $listed = ConsentPaperCopy::tickedServices($services);
        $unsettled = ConsentPaperCopy::unsettledServices($services);
        $refused = $choice === HealthConsentForm::CONSENT_DENY;

        foreach (HealthConsentForm::PLANNED_SERVICES as $key => $label) {
            $gradeOnly = ConsentStanding::GRADE_ONLY_SERVICES[$key] ?? null;

            if ($gradeOnly !== null && $grade !== $gradeOnly) {
                $group = 'not_applicable';
            } elseif ($unreadable) {
                $group = 'unclear';
            } elseif (! $refused && in_array($key, $unsettled, true)) {
                $group = 'unclear';
            } elseif (! $refused && ! in_array($key, $listed, true)) {
                $group = 'not_offered';
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

        $checks = self::checks($draft, $grade, $choice, $excluded, $unreadable, $services);
        if ($groups['unclear'] !== [] && $checks === []) {
            $checks[] = ['section' => 'services', 'subject' => 'Service list', 'message' => 'Some service decisions need to be checked against the photo.'];
        }
        $warnings = array_map(fn (array $check): string => $check['subject'].': '.$check['message'], $checks);
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
            // What to check against the paper, one entry per thing: the part of
            // the sheet it is on (so the dialog can open it), what it is, and
            // what is wrong with it. `warnings` is the same list as sentences.
            'checks' => $checks,
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

    /**
     * What the adviser has to check against the paper, one entry per thing.
     *
     * Read off the draft's own structure — each box, each written line — rather
     * than joined from the reader's sentences, which is how one field came to be
     * reported twice ("The box beside this line is ticked…" and "Check: Medicine
     * allergy."), three fields' identical notes came to be folded into one that
     * named none of them, and a missing signature came to be said twice. So:
     * every entry names the field as the form names it, says what is wrong in
     * words the adviser can act on, and a field is never reported twice. In the
     * order of the paper, top to bottom, so the adviser works down the sheet.
     *
     * @param  array<string, mixed>  $draft
     * @param  array<string, string>  $services
     * @param  list<string>|null  $excluded
     * @return list<array{section: string, subject: string, message: string}>
     */
    private static function checks(array $draft, ?int $grade, string $choice, ?array $excluded, bool $unreadable, array $services): array
    {
        $checks = [];
        $add = function (string $section, string $subject, string $message) use (&$checks): void {
            $key = $section.'|'.$subject;
            if (! isset($checks[$key])) {
                $checks[$key] = ['section' => $section, 'subject' => $subject, 'message' => $message];
            } elseif (! str_contains($checks[$key]['message'], $message)) {
                $checks[$key]['message'] .= ' '.$message;
            }
        };
        $note = trim((string) ($draft['note'] ?? ''));

        // A sheet nothing could be read off, or another child's, is the one
        // thing to say: every other check would be about a reading there is not.
        if ($unreadable) {
            if (! empty($draft['learner_mismatch'])) {
                $add('sheet', 'Learner', ($draft['consent_reason'] ?? '') ?: 'This form appears to belong to another learner.');
            } else {
                $add('sheet', 'Photo', $note !== '' ? rtrim($note, '.').'. Fill the form in from the paper.' : 'The photo could not be read. Fill the form in from the paper.');
            }

            return array_values($checks);
        }

        // ── The header ─────────────────────────────────────────────────
        // A sheet dated in another school year answers for that year, not
        // this one; the PETSA is at the top of the paper, so it is said first.
        if (! empty($draft['date_outside_school_year'])) {
            $add('sheet', 'Date (PETSA)', (string) ($draft['date_warning'] ?? '') ?: ConsentFormScanner::DATE_OUTSIDE_SCHOOL_YEAR);
        }

        $written = $draft['written'] ?? [];
        $fields = ConsentFormScanner::WRITTEN_FIELDS;
        $sectionOf = fn (string $field): string => in_array($field, ['consent_exceptions', 'refusal_reason'], true) ? 'consent' : 'allergies';
        $subjectOf = fn (string $field): string => match ($field) {
            'allergy_food' => 'Food allergy',
            'allergy_medicine' => 'Medicine allergy',
            'prev_immunization' => 'Previous immunization',
            'other_illness' => 'Other illnesses',
            default => $fields[$field],
        };

        // ── The service list ───────────────────────────────────────────
        // The reader's own notes about the list as a whole (a photo too wide to
        // trust its rows, a count of marks that does not match them), then the
        // rows. A refusal declines every row whatever its box reads, so the
        // rows are not worth the adviser's time on one.
        $wide = false;
        foreach ((array) ($draft['warnings'] ?? []) as $warning) {
            if (is_string($warning) && (str_starts_with($warning, 'This photo is wider') || str_starts_with($warning, 'The reader saw'))) {
                $add('services', 'Service list', $warning);
                $wide = $wide || str_starts_with($warning, 'This photo is wider');
            }
        }

        // A photo too wide to trust has every row held back, which that one
        // line already says; listing all eleven would only repeat it.
        if ($choice !== HealthConsentForm::CONSENT_DENY && ! $wide) {
            $unsure = array_values(array_filter(
                array_keys($services, ConsentFormScanner::UNCLEAR, true),
                fn (string $key): bool => (ConsentStanding::GRADE_ONLY_SERVICES[$key] ?? $grade) === $grade,
            ));
            if ($unsure !== []) {
                $add('services', 'Service list', 'Could not tell whether '.(count($unsure) === 1 ? 'this box is' : 'these boxes are').' ticked: '
                    .implode(', ', array_map(self::paperName(...), $unsure)).'. Mark '.(count($unsure) === 1 ? 'it' : 'them').' from the paper.');
            }

            // A heading ticked with none of the rows under it: the rows must be
            // ticked too, and the adviser is told so in the words the letter's
            // Send uses. Grade-aware, so a Grade 8 form is not sent back over a
            // row that class is never given; a row the reader could not settle
            // is already named in the list above.
            foreach (HealthConsentForm::SERVICES as $heading => $service) {
                $rows = array_values(array_filter(
                    array_keys($service['children']),
                    fn (string $row): bool => (ConsentStanding::GRADE_ONLY_SERVICES[$row] ?? $grade) === $grade,
                ));

                if (($services[$heading] ?? null) === ConsentFormScanner::TICKED
                    && $rows !== []
                    && array_filter($rows, fn (string $row): bool => in_array($services[$row] ?? null, [ConsentFormScanner::TICKED, ConsentFormScanner::UNCLEAR], true)) === []
                ) {
                    $add('services', HealthConsentForm::headingName($heading), HealthConsentForm::rowsRequiredNotice($heading, $rows).' Mark the ones that apply from the paper.');
                }
            }
        }

        // ── The parent's answer ────────────────────────────────────────
        if ($choice === ConsentFormScanner::UNCLEAR) {
            $add('consent', 'Parent\'s answer', ($draft['consent_reason'] ?? '') ?: 'The parent\'s answer could not be read. Choose it from the paper.');
        }

        // ── Lines written by hand, and the boxes beside them ───────────
        $allergyBoxes = is_array($draft['allergy_boxes'] ?? null) ? $draft['allergy_boxes'] : [];
        $asks = [
            'allergy_food' => 'which food',
            'allergy_medicine' => 'which medicine',
            'prev_immunization' => 'which vaccine',
            'other_illness' => 'which illness',
        ];
        foreach (array_keys($fields) as $field) {
            $entry = is_array($written[$field] ?? null) ? $written[$field] : ['text' => '', 'readable' => true, 'confident' => false];
            $text = trim((string) ($entry['text'] ?? ''));
            $box = $entry['box'] ?? ($allergyBoxes[$field] ?? null);
            $section = $sectionOf($field);
            $subject = $subjectOf($field);

            if (($entry['readable'] ?? true) === false) {
                $add($section, $subject, 'Something is written on the line, but it could not be read. Type it from the paper.');
            } elseif (isset($asks[$field]) && $text === '' && $box === ConsentFormScanner::TICKED) {
                // Ticked with nothing named: a clear answer, filed as "Not
                // specified" (LearnerAllergies::NOT_SPECIFIED), not a doubt.
                continue;
            } elseif (isset($asks[$field]) && ! ConsentFormScanner::saysSomething($text) && $box === ConsentFormScanner::TICKED) {
                $add($section, $subject, 'Its box is ticked, but the line reads "'.$text.'". Ask the parent '.$asks[$field].', if any.');
            } elseif (isset($asks[$field]) && ! ConsentFormScanner::saysSomething($text) && $box === ConsentFormScanner::UNCLEAR) {
                $add($section, $subject, 'Could not tell whether its box is ticked. Check the paper.');
            } elseif (! empty($draft['field_notes'][$field])) {
                // The reader's own question about a consent line: an "except"
                // with nothing excepted, a refusal with no reason. It names the
                // box it is about, so it reads on its own.
                $add($section, $subject, (string) $draft['field_notes'][$field]);
            } elseif ($text !== '' && empty($entry['confident'])) {
                $add($section, $subject, 'The handwriting was hard to read; it was read as "'.$text.'". Check it against the paper.');
            }
        }

        if ($choice === HealthConsentForm::CONSENT_SPECIFIC && $excluded === null && ! isset($checks['consent|'.$fields['consent_exceptions']])) {
            $add('consent', $fields['consent_exceptions'], 'The services written after "gawas lamang niini" could not be matched to the list. Mark which ones the parent left out.');
        }
        if ($choice === HealthConsentForm::CONSENT_DENY && ! ConsentFormScanner::saysSomething((string) ($written['refusal_reason']['text'] ?? '')) && ! isset($checks['consent|'.$fields['refusal_reason']])) {
            $add('consent', $fields['refusal_reason'], 'No reason was read. Ask the parent for the reason.');
        }

        // The allergy heading box against the three kinds under it. Ticked
        // with none of them ticked, the boxes under it must be ticked too, as
        // the service headings' must; a heading nobody could make out, with
        // nothing named under it, is the paper's to settle.
        $kinds = ConsentPaperCopy::ALLERGY_KINDS;
        $kindBox = fn (string $kind): ?string => $written[$kind]['box'] ?? ($allergyBoxes[$kind] ?? null);
        $heading = $allergyBoxes['allergy'] ?? null;
        $anyKind = fn (string $state): bool => array_filter($kinds, fn (string $kind): bool => $kindBox($kind) === $state) !== [];

        if ($heading === ConsentFormScanner::TICKED && ! $anyKind(ConsentFormScanner::TICKED) && ! $anyKind(ConsentFormScanner::UNCLEAR)) {
            $add('allergies', self::allergyHeading(), self::allergyRowsNotice());
        } elseif ($heading === ConsentFormScanner::UNCLEAR
            && ! $anyKind(ConsentFormScanner::TICKED)
            && array_filter($kinds, fn (string $kind): bool => ConsentFormScanner::saysSomething((string) ($written[$kind]['text'] ?? ''))) === []
        ) {
            $add('allergies', self::allergyHeading(), 'Could not tell whether its box is ticked. Check the paper.');
        }

        // ── The parent's name and signature ────────────────────────────
        // The reader's free note often says the same thing about the signature
        // in its own words; said once, in its words, since they are the more
        // particular ("a name is printed on the line, but it is not signed").
        $aboutSignature = $note !== '' && preg_match('/sign|pirma/i', $note) === 1;
        $parent = trim((string) ($draft['parent_guardian_name'] ?? ''));

        if ($parent === '') {
            $add('signature', 'Parent or guardian\'s name', 'No name was read at the signature line. Type it from the paper.');
        } elseif (! empty($draft['field_notes']['parent_guardian_name'])) {
            $add('signature', 'Parent or guardian\'s name', 'Read as "'.$parent.'", but the handwriting was hard to read. Check it against the paper.');
        }
        if (empty($draft['signature_present'])) {
            $add('signature', 'Parent\'s signature', ($aboutSignature ? rtrim($note, '.') : 'No handwritten signature was found at the parent\'s signature line').'. Check that the parent signed.');
        } elseif ($aboutSignature) {
            $add('signature', 'Parent\'s signature', $note);
        }
        if ($note !== '' && ! $aboutSignature) {
            $add('sheet', 'Reader\'s note', $note);
        }

        // Anything the reader marked unclear that none of the above speaks for.
        $spoken = [...array_values(ConsentFormScanner::serviceKeys()), ...array_values($fields), 'Allergy', 'Parent or guardian\'s name'];
        foreach ((array) ($draft['unclear'] ?? []) as $label) {
            if (is_string($label) && ! in_array($label, $spoken, true)) {
                $add('sheet', $label, 'Check it against the paper.');
            }
        }

        // The paper's order, top to bottom.
        $order = array_flip(['sheet', 'services', 'consent', 'allergies', 'signature']);
        $checks = array_values($checks);
        usort($checks, fn (array $a, array $b): int => $order[$a['section']] <=> $order[$b['section']]);

        return $checks;
    }

    /** The allergy heading by its printed words, up to the comma: "Kon adunay Allergy ang bata". */
    private static function allergyHeading(): string
    {
        return trim(explode(',', HealthConsentForm::ALLERGY_TEXT['allergy'])[0]);
    }

    /**
     * The allergy heading ticked with none of the three kinds under it, said
     * as the service headings' notice is (HealthConsentForm::rowsRequiredNotice),
     * each kind by its printed words without the "(isulat …)" instruction.
     */
    private static function allergyRowsNotice(): string
    {
        $names = array_map(
            fn (string $kind): string => trim(explode(' (', HealthConsentForm::ALLERGY_TEXT[$kind])[0]),
            ConsentPaperCopy::ALLERGY_KINDS,
        );

        return 'Ticked, but none of the boxes under it is. At least one must be ticked: '.implode('; ', $names).'. Ask the parent which, and write it on its line.';
    }

    /**
     * A row of the service list by the first words printed on it — "Pag
     * Purga", "Iron Supplementation" — so the adviser can find it on the sheet
     * they are holding; the full printed line is in the reading beside it.
     */
    private static function paperName(string $key): string
    {
        // The "(sa mga lugar nga common / endemic …)" aside goes first: it has a
        // slash of its own, and cutting at the slash would leave half of it.
        $label = trim((string) preg_replace('/\s*\(sa [^)]*\)/u', '', (string) (ConsentFormScanner::serviceKeys()[$key] ?? $key)));

        return rtrim(trim(explode(' / ', $label)[0]), ': ');
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
        if ($text === '' && ($entry['box'] ?? null) === ConsentFormScanner::TICKED) {
            return LearnerAllergies::NOT_SPECIFIED;
        }
        if (! empty($draft['field_notes'][$field])) {
            return ($text !== '' ? $text : 'Not clear').' — check the photo';
        }
        if ($text !== '' && empty($entry['confident'])) {
            return $text.' — check the photo';
        }

        return ConsentFormScanner::saysSomething($text) ? $text : $empty;
    }
}
