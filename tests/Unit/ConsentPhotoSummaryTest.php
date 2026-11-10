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
    /** A reading as interpret() returns it: the parent's reply, and no service list. */
    private function draft(string $choice = HealthConsentForm::CONSENT_ALL): array
    {
        return [
            'consent_choice' => $choice,
            'excluded_services' => [],
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

    /**
     * Exceptions that cannot be named as services exclude nothing on their
     * own: every service reads Consented, and the line is put to the adviser,
     * who marks the excepted rows Excluded. The reading is never complete.
     */
    #[Test]
    public function ambiguous_or_uncertain_exceptions_are_put_to_the_adviser(): void
    {
        foreach (['Deworming, unknown treatment', 'Filariasis if sick', 'No deworming', 'Bakuna and something else'] as $text) {
            $draft = $this->draft(HealthConsentForm::CONSENT_SPECIFIC);
            $draft['written']['consent_exceptions'] = ['text' => $text, 'readable' => true, 'confident' => true];
            $summary = ConsentPhotoSummary::fromDraft($draft, 8);
            $this->assertCount(8, $summary['groups']['consented'], $text);
            $this->assertSame([], $summary['groups']['declined'], $text);
            $this->assertSame([], $summary['groups']['unclear'], $text);
            $this->assertContains(
                'The services written after "gawas lamang niini" could not be matched to the list. Mark which ones the parent left out.',
                array_column($summary['checks'], 'message'),
                $text,
            );
            $this->assertFalse($summary['complete']);
        }
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

    /**
     * A refusal excludes every service the learner is given — the school's
     * list with the rules applied. Only a service ruled out for the learner
     * (Grade 7 MR, Td in Grade 8) is not.
     */
    #[Test]
    public function a_refusal_excludes_every_service_the_learner_is_given(): void
    {
        $draft = $this->draft(HealthConsentForm::CONSENT_DENY);

        $summary = ConsentPhotoSummary::fromDraft($draft, 8);

        $this->assertCount(8, $summary['groups']['declined']);
        $this->assertContains(HealthConsentForm::PLANNED_SERVICES['dental'], $summary['groups']['declined']);
        $this->assertContains(HealthConsentForm::PLANNED_SERVICES['iron'], $summary['groups']['declined']);
        $this->assertSame([], $summary['groups']['unclear']);
        $this->assertSame([], $summary['groups']['not_offered']);
        $this->assertSame([HealthConsentForm::PLANNED_SERVICES['immunization_grade7']], $summary['groups']['not_applicable']);
    }

    /**
     * The rules for one grade and one sex are the system's, so a service a
     * learner is never given reads Not offered and nothing about the printed
     * list is put to the adviser: Grade 7 MR, Td in Grade 8, Iron for a boy.
     */
    #[Test]
    public function a_service_ruled_out_for_the_learner_is_not_offered_and_asks_nothing(): void
    {
        $draft = $this->draft();

        $grade7 = ConsentPhotoSummary::fromDraft($draft, 7, 'Female');
        $this->assertContains(HealthConsentForm::PLANNED_SERVICES['immunization_grade7'], $grade7['groups']['consented']);
        $this->assertContains(HealthConsentForm::PLANNED_SERVICES['iron'], $grade7['groups']['consented']);

        $boy = ConsentPhotoSummary::fromDraft($draft, 8, 'Male');
        $this->assertContains(HealthConsentForm::PLANNED_SERVICES['immunization_grade7'], $boy['groups']['not_applicable']);
        $this->assertContains(HealthConsentForm::PLANNED_SERVICES['iron'], $boy['groups']['not_applicable']);
        $this->assertSame([], $boy['checks']);
        $this->assertTrue($boy['complete']);
    }

    /**
     * The school's Health Services Offered: a service it does not give is Not
     * offered, and nothing is asked about it — even on a reading stored while
     * the printed list was still read, which had it shaded.
     */
    #[Test]
    public function a_service_the_school_does_not_offer_is_not_offered_and_asks_nothing(): void
    {
        $offered = array_values(array_diff(
            array_keys(HealthConsentForm::PLANNED_SERVICES),
            ['deworming_schistosomiasis', 'deworming_filariasis', 'tas'],
        ));

        $draft = $this->draft();

        $summary = ConsentPhotoSummary::fromDraft($draft, 8, 'Female', $offered);

        $this->assertContains(HealthConsentForm::PLANNED_SERVICES['deworming_worms'], $summary['groups']['consented']);
        foreach (['deworming_schistosomiasis', 'deworming_filariasis', 'tas'] as $row) {
            $this->assertContains(HealthConsentForm::PLANNED_SERVICES[$row], $summary['groups']['not_offered']);
        }
        $this->assertSame([], $summary['checks']);
        $this->assertTrue($summary['complete']);

        $draft['services'] = ['tas' => 'yes'];
        $this->assertSame([], ConsentPhotoSummary::fromDraft($draft, 8, 'Female', $offered)['checks']);
    }

    /**
     * The adviser's own test sheet: three allergy boxes shaded over empty
     * lines, and a signature the reader was unsure of, said again in its own
     * note. Each allergy box shaded with no details written says so, and the
     * signature is said once, in the reader's words.
     */
    #[Test]
    public function a_plainly_filled_sheet_leaves_only_what_really_needs_a_look(): void
    {
        $draft = $this->draft();
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
            'section' => 'allergies',
            'subject' => 'Medicine allergy',
            'message' => ConsentFormScanner::BOX_TICKED_NO_DETAILS,
        ], [
            'section' => 'allergies',
            'subject' => 'Previous immunization',
            'message' => ConsentFormScanner::BOX_TICKED_NO_DETAILS,
        ], [
            'section' => 'allergies',
            'subject' => 'Other illnesses',
            'message' => ConsentFormScanner::BOX_TICKED_NO_DETAILS,
        ], [
            'section' => 'signature',
            'subject' => 'Parent\'s name and signature',
            'message' => ConsentFormScanner::FORM_INCOMPLETE.'. Parent name is printed on the signature line, but no handwritten signature is present.',
        ]], $summary['checks']);
        // A full consent is every deworming the school offers.
        foreach (['deworming_worms', 'deworming_schistosomiasis', 'deworming_filariasis'] as $row) {
            $this->assertContains(HealthConsentForm::PLANNED_SERVICES[$row], $summary['groups']['consented']);
        }
        $this->assertSame('Food: CRABS; Medicine: Not specified; Previous immunization reaction: Not specified', $summary['allergies']);
        $this->assertSame('Not specified', $summary['illnesses']);
    }

    /** The allergy box shaded with nothing ticked or written under it is asked about. */
    #[Test]
    public function an_allergy_box_shaded_with_no_details_is_asked_about(): void
    {
        $draft = $this->draft();
        $draft['allergy_boxes'] = ['allergy' => 'yes', 'allergy_food' => 'no', 'allergy_medicine' => 'no', 'prev_immunization' => 'no', 'other_illness' => 'no'];

        $this->assertContains([
            'section' => 'allergies',
            'subject' => 'Kon adunay Allergy ang bata',
            'message' => ConsentFormScanner::BOX_TICKED_NO_DETAILS,
        ], ConsentPhotoSummary::fromDraft($draft, 8)['checks']);

        // With the allergy named on its line, there is nothing to ask.
        $draft['written']['allergy_food'] = ['text' => 'Shrimp', 'readable' => true, 'confident' => true];
        $this->assertSame([], ConsentPhotoSummary::fromDraft($draft, 8)['checks']);
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
        // A reading stored while the printed list was still read may name one
        // of its rows unclear; the list decides nothing, so it is not asked about.
        $draft['unclear'] = ['Medicine allergy', 'Current or other illness', ConsentFormScanner::serviceKeys()['iron']];
        $draft['signature_present'] = false;
        $draft['needs_review'] = true;

        $summary = ConsentPhotoSummary::fromDraft($draft, 8);
        $bySubject = array_column($summary['checks'], 'message', 'subject');

        $this->assertSame(['Medicine allergy', 'Other illnesses', 'Parent\'s name and signature'], array_keys($bySubject));
        $this->assertSame('Its box is ticked, but the line reads "wala". Ask the parent which medicine, if any.', $bySubject['Medicine allergy']);
        $this->assertSame('Could not tell whether its box is ticked. Check the paper.', $bySubject['Other illnesses']);
        $this->assertSame(ConsentFormScanner::FORM_INCOMPLETE.'. No handwritten signature was found at the parent\'s signature line.', $bySubject['Parent\'s name and signature']);

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

    /**
     * Tester report (#2): Dentist check-up read "Not consented" because its
     * box in the school's list at the top was empty. That list neither
     * refuses a service nor holds one back — even on a reading stored while
     * it was still read: a full consent consents to every service the school
     * offers, and a row nobody could make out there is nothing to settle.
     */
    #[Test]
    public function the_schools_list_at_the_top_neither_refuses_nor_holds_back_a_service(): void
    {
        $draft = $this->draft();
        $draft['services']['dental'] = 'no';
        $draft['services']['iron'] = 'unclear';
        $summary = ConsentPhotoSummary::fromDraft($draft, 7);

        $this->assertSame(array_values(HealthConsentForm::PLANNED_SERVICES), $summary['groups']['consented']);
        $this->assertSame([], $summary['groups']['not_offered']);
        $this->assertSame([], $summary['groups']['unclear']);
        $this->assertSame([], $summary['groups']['declined']);
        $this->assertSame([], $summary['checks']);
        $this->assertTrue($summary['complete']);
    }

    /**
     * No name at the signature line is the same as no signature: the form is
     * saved as incomplete, and the adviser is told what is missing.
     */
    #[Test]
    public function a_missing_parent_name_is_named_as_an_incomplete_form(): void
    {
        $draft = $this->draft();
        $draft['parent_guardian_name'] = '';
        $draft['parent_name_present'] = false;
        $draft['form_complete'] = false;
        $draft['needs_review'] = true;

        $summary = ConsentPhotoSummary::fromDraft($draft, 8);

        $this->assertSame([[
            'section' => 'signature',
            'subject' => 'Parent\'s name and signature',
            'message' => ConsentFormScanner::FORM_INCOMPLETE.'. No name was read at the signature line.',
        ]], $summary['checks']);
        $this->assertFalse($summary['complete']);

        // A name written there that could not be read is on the form: the
        // form is complete, and the adviser types the name from the paper.
        $draft['parent_name_present'] = true;
        $draft['form_complete'] = true;
        $draft['field_notes']['parent_guardian_name'] = 'The name at the signature line could not be read — type it from the paper.';

        $this->assertSame([[
            'section' => 'signature',
            'subject' => 'Parent or guardian\'s name',
            'message' => 'A name is written at the signature line, but it could not be read. Type it from the paper.',
        ]], ConsentPhotoSummary::fromDraft($draft, 8)['checks']);
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
