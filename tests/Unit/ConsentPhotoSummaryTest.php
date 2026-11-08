<?php

namespace Tests\Unit;

use App\Models\HealthConsentForm;
use App\Support\ConsentFormScanner;
use App\Support\ConsentPaperCopy;
use App\Support\ConsentPhotoSummary;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ConsentPhotoSummaryTest extends TestCase
{
    private function draft(string $choice = HealthConsentForm::CONSENT_ALL): array
    {
        return [
            'consent_choice' => $choice,
            'services' => array_fill_keys(array_keys(ConsentFormScanner::serviceKeys()), 'yes'),
            'written' => array_fill_keys(array_keys(ConsentFormScanner::WRITTEN_FIELDS), [
                'text' => '', 'readable' => true, 'confident' => false,
            ]),
            'parent_guardian_name' => 'Jocelyn Torres',
            'signature_present' => true,
            'needs_review' => false,
        ];
    }

    #[Test]
    public function named_exceptions_are_separated_from_offered_services(): void
    {
        $draft = $this->draft(HealthConsentForm::CONSENT_SPECIFIC);
        $draft['written']['consent_exceptions'] = [
            'text' => 'Deworming – Worms, Schistosomiasis, Filariasis', 'readable' => true, 'confident' => true,
        ];

        $summary = ConsentPhotoSummary::fromDraft($draft, 8);

        $this->assertSame('Consents to 5 of 8 services', $summary['decision']);
        $this->assertTrue($summary['complete']);
        $this->assertCount(5, $summary['groups']['consented']);
        $this->assertSame([
            HealthConsentForm::PLANNED_SERVICES['deworming_worms'],
            HealthConsentForm::PLANNED_SERVICES['deworming_schistosomiasis'],
            HealthConsentForm::PLANNED_SERVICES['deworming_filariasis'],
        ], $summary['groups']['declined']);
        $this->assertSame([HealthConsentForm::PLANNED_SERVICES['immunization_grade7']], $summary['groups']['not_applicable']);
        $this->assertSame('None indicated', $summary['allergies']);
        $this->assertSame('Not required', $summary['reason']);
        $this->assertSame('Parent or guardian present', $summary['signatures']);
    }

    #[Test]
    public function ambiguous_or_uncertain_exceptions_never_grant_the_remaining_services(): void
    {
        foreach (['Deworming, unknown treatment', 'Filariasis if sick', 'No deworming', '', 'Bakuna and something else'] as $text) {
            $draft = $this->draft(HealthConsentForm::CONSENT_SPECIFIC);
            $draft['written']['consent_exceptions'] = ['text' => $text, 'readable' => true, 'confident' => true];
            $summary = ConsentPhotoSummary::fromDraft($draft, 8);
            $this->assertSame([], $summary['groups']['consented'], $text);
            $this->assertSame([], $summary['groups']['declined'], $text);
            $this->assertCount(8, $summary['groups']['unclear'], $text);
            $this->assertFalse($summary['complete']);
        }

        $draft['written']['consent_exceptions'] = ['text' => 'Deworming', 'readable' => true, 'confident' => false];
        $this->assertCount(8, ConsentPhotoSummary::fromDraft($draft, 8)['groups']['unclear']);
    }

    #[Test]
    public function exceptions_are_recognised_as_parents_write_them(): void
    {
        foreach ([
            'Ibot sa ngipon' => ['dental'],
            'dentist' => ['dental'],
            'Bakuna' => ['immunization'],
            'MR/Td' => ['immunization'],
            'Purga' => ['deworming'],
            'Bitok' => ['deworming_worms'],
            'Bitamina A' => ['vitamin_a'],
            'Eksaminasyon' => ['checkup'],
            'Iron ug Vitamin A' => ['iron', 'vitamin_a'],
            'TAS, schistosomiasis' => ['tas', 'deworming_schistosomiasis'],
            'Check Up sa Dentist ug Ibot sa Ngipon / Paghatag ug tambal kon gikinahanglan' => ['dental'],
        ] as $text => $expected) {
            $this->assertSame(
                $expected,
                ConsentPaperCopy::exceptedServices(['text' => $text, 'readable' => true, 'confident' => true]),
                $text,
            );
        }
    }

    #[Test]
    public function a_refusal_declines_every_row_whatever_its_box_reads(): void
    {
        $draft = $this->draft(HealthConsentForm::CONSENT_DENY);
        $draft['services']['dental'] = 'no';
        $draft['services']['iron'] = 'unclear';

        $summary = ConsentPhotoSummary::fromDraft($draft, 8);

        $this->assertCount(8, $summary['groups']['declined']);
        $this->assertSame([], $summary['groups']['unclear']);
        $this->assertSame([], $summary['groups']['not_offered']);
    }

    #[Test]
    public function a_heading_over_a_row_this_grade_is_never_given_raises_nothing(): void
    {
        // Immunization ticked, its one row left empty: for Grade 7 that row
        // must be ticked too; Grade 8 is never given it, so nothing is asked.
        $draft = $this->draft();
        $draft['services']['immunization_grade7'] = 'no';

        $heading = fn (int $grade): array => array_values(array_filter(
            ConsentPhotoSummary::fromDraft($draft, $grade)['checks'],
            fn (array $check): bool => $check['subject'] === 'Immunization / Bakuna',
        ));

        $this->assertSame([], $heading(8));
        $this->assertSame([[
            'section' => 'services',
            'subject' => 'Immunization / Bakuna',
            'message' => 'Ticked, but the box under it (Grade 7 Male (Lalake) ug Female (Babae) - MR, Td) is not. It must be ticked too. Mark the ones that apply from the paper.',
        ]], $heading(7));
    }

    /**
     * The adviser's own test sheet: Pag Purga shaded over empty rows, three
     * allergy boxes shaded over empty lines, and a signature the reader was
     * unsure of, said again in its own note. It came out as seven lines — "The
     * box beside this line…" naming no line, the same field again as "Check:
     * Medicine allergy.", the signature twice. Now Pag Purga says one of its
     * boxes must be ticked, the ticked allergy boxes are filed as "Not
     * specified", and the signature is said once, in the reader's words.
     */
    #[Test]
    public function a_plainly_filled_sheet_leaves_only_what_really_needs_a_look(): void
    {
        $draft = $this->draft();
        foreach (['deworming_worms', 'deworming_schistosomiasis', 'deworming_filariasis'] as $row) {
            $draft['services'][$row] = 'no';
        }
        foreach (['allergy_medicine', 'prev_immunization', 'other_illness'] as $field) {
            $draft['written'][$field]['box'] = ConsentFormScanner::TICKED;
        }
        $draft['written']['allergy_food'] = ['text' => 'CRABS', 'readable' => true, 'confident' => true, 'box' => ConsentFormScanner::TICKED];
        $draft['allergy_boxes'] = ['allergy' => 'yes', 'allergy_food' => 'yes', 'allergy_medicine' => 'yes', 'prev_immunization' => 'yes', 'other_illness' => 'yes'];
        $draft['signature_present'] = false;
        $draft['note'] = 'Parent name is printed on the signature line, but no handwritten signature is present.';
        $draft['needs_review'] = true;

        $summary = ConsentPhotoSummary::fromDraft($draft, 8);

        $this->assertSame([[
            'section' => 'services',
            'subject' => 'Pag Purga / Pagpainom ug Tambal sa',
            'message' => 'Ticked, but none of the boxes under it is. At least one must be ticked: Pag Bitok / Worms; Schistosomiasis; Filariasis. Mark the ones that apply from the paper.',
        ], [
            'section' => 'signature',
            'subject' => 'Parent\'s signature',
            'message' => 'Parent name is printed on the signature line, but no handwritten signature is present. Check that the parent signed.',
        ]], $summary['checks']);
        // Not approved on the heading's account: the adviser marks them.
        foreach (['deworming_worms', 'deworming_schistosomiasis', 'deworming_filariasis'] as $row) {
            $this->assertContains(HealthConsentForm::PLANNED_SERVICES[$row], $summary['groups']['unclear']);
        }
        $this->assertSame('Food: CRABS; Medicine: Not specified; Previous immunization reaction: Not specified', $summary['allergies']);
        $this->assertSame('Not specified', $summary['illnesses']);
    }

    /** Each thing still to check is named once, by its field, with what to do. */
    #[Test]
    public function each_thing_to_check_is_named_once_with_what_to_do(): void
    {
        $draft = $this->draft();
        $draft['written']['allergy_medicine'] = ['text' => 'wala', 'readable' => true, 'confident' => true, 'box' => ConsentFormScanner::TICKED];
        $draft['field_notes']['allergy_medicine'] = 'The box beside this line is ticked but the line reads "wala" — ask the parent which is right.';
        $draft['written']['other_illness'] = ['text' => '', 'readable' => true, 'confident' => false, 'box' => ConsentFormScanner::UNCLEAR];
        $draft['field_notes']['other_illness'] = 'Could not tell whether the box beside this line is ticked — check the paper.';
        $draft['services']['iron'] = 'unclear';
        $draft['unclear'] = ['Medicine allergy', 'Current or other illness', ConsentFormScanner::serviceKeys()['iron']];
        $draft['signature_present'] = false;
        $draft['needs_review'] = true;

        $summary = ConsentPhotoSummary::fromDraft($draft, 8);
        $bySubject = array_column($summary['checks'], 'message', 'subject');

        $this->assertSame(['Service list', 'Medicine allergy', 'Other illnesses', 'Parent\'s signature'], array_keys($bySubject));
        $this->assertSame('Could not tell whether this box is ticked: Iron Supplementation. Mark it from the paper.', $bySubject['Service list']);
        $this->assertSame('Its box is ticked, but the line reads "wala". Ask the parent which medicine, if any.', $bySubject['Medicine allergy']);
        $this->assertSame('Could not tell whether its box is ticked. Check the paper.', $bySubject['Other illnesses']);
        $this->assertSame('No handwritten signature was found at the parent\'s signature line. Check that the parent signed.', $bySubject['Parent\'s signature']);

        foreach ($summary['warnings'] as $warning) {
            $this->assertStringNotContainsString('this line', $warning);
            $this->assertStringStartsNotWith('Check: ', $warning);
        }
        $this->assertFalse($summary['complete']);
    }

    /** A note about something else is still said, once, as the reader's. */
    #[Test]
    public function a_note_about_something_else_is_kept_as_the_readers_own(): void
    {
        $draft = $this->draft();
        $draft['note'] = 'The date line is blank.';

        $checks = ConsentPhotoSummary::fromDraft($draft, 8)['checks'];

        $this->assertSame([['section' => 'sheet', 'subject' => 'Reader\'s note', 'message' => 'The date line is blank.']], $checks);
    }

    /** A line the reader was unsure of says what it read, so the adviser checks that. */
    #[Test]
    public function an_unsure_or_unreadable_line_says_what_was_read(): void
    {
        $draft = $this->draft();
        $draft['written']['allergy_food'] = ['text' => 'Pasayan', 'readable' => true, 'confident' => false];
        $draft['written']['allergy_medicine'] = ['text' => ConsentFormScanner::UNREADABLE_TEXT, 'readable' => false, 'confident' => false];
        $draft['unclear'] = ['Food allergy', 'Medicine allergy'];

        $bySubject = array_column(ConsentPhotoSummary::fromDraft($draft, 8)['checks'], 'message', 'subject');

        $this->assertSame('The handwriting was hard to read; it was read as "Pasayan". Check it against the paper.', $bySubject['Food allergy']);
        $this->assertSame('Something is written on the line, but it could not be read. Type it from the paper.', $bySubject['Medicine allergy']);
        $this->assertCount(2, $bySubject);
    }

    #[Test]
    public function unticked_services_are_not_reported_as_parent_refusals(): void
    {
        $draft = $this->draft();
        $draft['services']['dental'] = 'no';
        $draft['services']['iron'] = 'unclear';
        $summary = ConsentPhotoSummary::fromDraft($draft, 7);

        $this->assertSame([HealthConsentForm::PLANNED_SERVICES['dental']], $summary['groups']['not_offered']);
        $this->assertSame([HealthConsentForm::PLANNED_SERVICES['iron']], $summary['groups']['unclear']);
        $this->assertSame([], $summary['groups']['declined']);
        $this->assertSame([], $summary['groups']['not_applicable']);
        $this->assertFalse($summary['complete']);
    }

    #[Test]
    public function an_unreadable_or_wrong_learner_form_cannot_show_consent(): void
    {
        foreach (['unreadable', 'learner_mismatch'] as $flag) {
            $draft = $this->draft();
            $draft[$flag] = true;
            $draft['consent_reason'] = 'A different learner is named on the form.';
            $summary = ConsentPhotoSummary::fromDraft($draft, 8);
            $this->assertSame([], $summary['groups']['consented']);
            $this->assertCount(8, $summary['groups']['unclear']);
            $this->assertFalse($summary['complete']);
        }
    }

    #[Test]
    public function refusal_and_health_details_preserve_missing_or_unclear_information(): void
    {
        $draft = $this->draft(HealthConsentForm::CONSENT_DENY);
        $draft['signature_present'] = false;
        $draft['written']['allergy_food'] = ['text' => '', 'readable' => false, 'confident' => false];
        $draft['written']['other_illness'] = ['text' => 'Hubak', 'readable' => true, 'confident' => true];
        $summary = ConsentPhotoSummary::fromDraft($draft, 8);

        $this->assertCount(8, $summary['groups']['declined']);
        $this->assertSame('Not provided', $summary['reason']);
        $this->assertStringContainsString('Unreadable', $summary['allergies']);
        $this->assertSame('Hubak', $summary['illnesses']);
        $this->assertFalse($summary['complete']);
    }
}
