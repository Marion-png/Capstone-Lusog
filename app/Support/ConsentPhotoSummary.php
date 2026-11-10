<?php

namespace App\Support;

use App\Models\HealthConsentForm;

/** A display of the photo reading, not a replacement for the recorded consent. */
final class ConsentPhotoSummary
{
    /**
     * @param  string|null  $sex  the learner's, for the services one sex alone is given
     * @param  list<string>|null  $offered  HealthServicesOffered::for(); null when not known
     */
    public static function fromDraft(array $draft, ?int $grade, ?string $sex = null, ?array $offered = null): array
    {
        $groups = array_fill_keys(['consented', 'declined', 'not_applicable', 'not_offered', 'unclear'], []);
        $choice = $draft['consent_choice'] ?? ConsentFormScanner::UNCLEAR;
        $unreadable = ! empty($draft['unreadable']) || ! empty($draft['learner_mismatch']);
        $written = $draft['written'] ?? [];
        $exceptions = trim((string) ($written['consent_exceptions']['text'] ?? ''));
        // Only the parent's own "gawas lamang niini" line excludes anything.
        // Which services the learner is asked about is the system's: the
        // school's list for the year and the rules (ConsentPaperCopy::proposal()
        // starts the dialog's rows the same way). The school's list printed at
        // the top of the paper is not read.
        $excluded = $choice === HealthConsentForm::CONSENT_SPECIFIC ? ConsentPaperCopy::excepted($draft) : [];

        foreach (HealthConsentForm::PLANNED_SERVICES as $key => $label) {
            if (! ConsentStanding::appliesTo($key, $grade, $sex)) {
                $group = 'not_applicable';
            } elseif (! HealthServicesOffered::isOffered($key, $offered)) {
                $group = 'not_offered';
            } elseif ($unreadable) {
                $group = 'unclear';
            } else {
                $group = match ($choice) {
                    HealthConsentForm::CONSENT_ALL => 'consented',
                    HealthConsentForm::CONSENT_DENY => 'declined',
                    // Exceptions that cannot be named exclude nothing yet: the
                    // line is named for the adviser, who marks them.
                    HealthConsentForm::CONSENT_SPECIFIC => HealthConsentForm::coversService($excluded ?? [], $key) ? 'declined' : 'consented',
                    default => 'unclear',
                };
            }

            $groups[$group][] = $label;
        }

        $checks = self::checks($draft, $choice, $excluded, $unreadable);
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
     * @param  list<string>|null  $excluded
     * @return list<array{section: string, subject: string, message: string}>
     */
    private static function checks(array $draft, string $choice, ?array $excluded, bool $unreadable): array
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
        } elseif (trim((string) ($draft['date_note'] ?? '')) !== '') {
            // No year could be read: said softly, as something to look at.
            $add('sheet', 'Date (PETSA)', (string) $draft['date_note']);
        }

        // The learner's name on the sheet is checked against the learner the
        // form is being filed for (ConsentFormScanner::checkLearner); one that
        // could not be read is no proof either way, so the adviser is asked.
        $learner = is_array($draft['learner'] ?? null) ? $draft['learner'] : [];
        if (($learner['matches'] ?? null) === null && trim((string) ($learner['name'] ?? '')) !== '') {
            $add('sheet', 'Learner', 'The learner\'s name on the form could not be read. Check that this is '.$learner['name'].'\'s form.');
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

        // ── The parent's answer ────────────────────────────────────────
        // None marked, more than one, or a box nobody could make out: the
        // adviser chooses the answer from the paper.
        if ($choice === ConsentFormScanner::UNCLEAR) {
            $add('consent', 'Parent\'s answer', ($draft['consent_reason'] ?? '') ?: ConsentFormScanner::ANSWER_UNDETERMINED.' Choose it from the paper.');
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
                // Ticked with nothing named: the child has one and the parent
                // did not say which. Filed as "Not specified"
                // (LearnerAllergies::NOT_SPECIFIED), and the adviser asks.
                $add($section, $subject, ConsentFormScanner::BOX_TICKED_NO_DETAILS);
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

        if ($choice === HealthConsentForm::CONSENT_SPECIFIC && ! isset($checks['consent|'.$fields['consent_exceptions']])) {
            $byLine = ConsentPaperCopy::exceptedServices($written['consent_exceptions'] ?? [], $draft['field_notes']['consent_exceptions'] ?? null);

            if ($excluded === null) {
                $add('consent', $fields['consent_exceptions'], 'The services written after "gawas lamang niini" could not be matched to the list. Mark which ones the parent left out.');
            } elseif ($byLine === null) {
                // Matched by the reader, not word for word: said with what it
                // took them to be, so the adviser checks those rows.
                $add('consent', $fields['consent_exceptions'], 'The services written after "gawas lamang niini" were read as '
                    .implode('; ', array_map(fn (string $key): string => self::paperName($key), $excluded))
                    .'. Check which ones the parent left out.');
            }
        }
        if ($choice === HealthConsentForm::CONSENT_DENY && ! ConsentFormScanner::saysSomething((string) ($written['refusal_reason']['text'] ?? '')) && ! isset($checks['consent|'.$fields['refusal_reason']])) {
            $add('consent', $fields['refusal_reason'], 'No reason was read. Ask the parent for the reason.');
        }

        // The allergy heading box against the three kinds under it. Ticked
        // with nothing named under it, the parent said the child has one and
        // not which, so the adviser asks; a heading nobody could make out,
        // with nothing named under it, is the paper's to settle.
        $kinds = ConsentPaperCopy::ALLERGY_KINDS;
        $kindBox = fn (string $kind): ?string => $written[$kind]['box'] ?? ($allergyBoxes[$kind] ?? null);
        $heading = $allergyBoxes['allergy'] ?? null;
        $anyKind = fn (string $state): bool => array_filter($kinds, fn (string $kind): bool => $kindBox($kind) === $state) !== [];
        $anyNamed = array_filter($kinds, fn (string $kind): bool => ConsentFormScanner::saysSomething((string) ($written[$kind]['text'] ?? ''))) !== [];

        if ($heading === ConsentFormScanner::TICKED && ! $anyKind(ConsentFormScanner::TICKED) && ! $anyKind(ConsentFormScanner::UNCLEAR) && ! $anyNamed) {
            $add('allergies', self::allergyHeading(), ConsentFormScanner::BOX_TICKED_NO_DETAILS);
        } elseif ($heading === ConsentFormScanner::UNCLEAR && ! $anyKind(ConsentFormScanner::TICKED) && ! $anyNamed) {
            $add('allergies', self::allergyHeading(), 'Could not tell whether its box is ticked. Check the paper.');
        }

        // ── The parent's name and signature ────────────────────────────
        // The reader's free note often says the same thing about the signature
        // in its own words; said once, in its words, since they are the more
        // particular ("a name is printed on the line, but it is not signed").
        $aboutSignature = $note !== '' && preg_match('/sign|pirma/i', $note) === 1;
        $parent = trim((string) ($draft['parent_guardian_name'] ?? ''));

        $nameNote = (string) ($draft['field_notes']['parent_guardian_name'] ?? '');
        // A reading saved before these were read says it from the name and the
        // signature themselves.
        $named = (bool) ($draft['parent_name_present'] ?? ($parent !== '' || $nameNote !== ''));
        $signed = ! empty($draft['signature_present']);

        // Either one missing: the form is saved as incomplete and gives no
        // service until a complete one is on file. Said in one entry, with
        // which of the two is missing after it.
        if (! $named || ! $signed) {
            $missing = [];
            if (! $named) {
                $missing[] = 'No name was read at the signature line.';
            }
            if (! $signed) {
                $missing[] = $aboutSignature ? rtrim($note, '.').'.' : 'No handwritten signature was found at the parent\'s signature line.';
            }
            $add('signature', 'Parent\'s name and signature', ConsentFormScanner::FORM_INCOMPLETE.'. '.implode(' ', $missing));
        } elseif ($aboutSignature) {
            $add('signature', 'Parent\'s signature', $note);
        }
        if ($named && $parent !== '' && $nameNote !== '') {
            $add('signature', 'Parent or guardian\'s name', 'Read as "'.$parent.'", but the handwriting was hard to read. Check it against the paper.');
        } elseif ($named && $parent === '' && $nameNote !== '') {
            $add('signature', 'Parent or guardian\'s name', 'A name is written at the signature line, but it could not be read. Type it from the paper.');
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
     * A row of the service list by its whole printed name, never shortened —
     * a heading (Pag Purga, Immunization) as printed without its colon, a row
     * with the heading it sits under — as the reading beside it prints it.
     */
    private static function paperName(string $key): string
    {
        return isset(HealthConsentForm::PLANNED_SERVICES[$key])
            ? HealthConsentForm::printedName($key)
            : HealthConsentForm::headingName($key);
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
