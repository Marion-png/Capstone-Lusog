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

    /** The same sheet as a Grade 8 adviser shades it: Immunization (Grade 7 only) left blank. */
    private function grade8Draft(string $choice = HealthConsentForm::CONSENT_ALL): array
    {
        $draft = $this->draft($choice);
        $draft['services']['immunization'] = 'no';
        $draft['services']['immunization_grade7'] = 'no';

        return $draft;
    }

    #[Test]
    public function named_exceptions_are_separated_from_offered_services(): void
    {
        $draft = $this->grade8Draft(HealthConsentForm::CONSENT_SPECIFIC);
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

    /**
     * A refusal declines what the form asked about. A row not shaded at the
     * top was not offered, so it is Not offered, never a refusal; a box the
     * reader could not make out may be on the sheet, so it is declined.
     */
    #[Test]
    public function a_refusal_declines_the_shaded_rows_and_not_the_unshaded_ones(): void
    {
        $draft = $this->draft(HealthConsentForm::CONSENT_DENY);
        $draft['services']['dental'] = 'no';
        $draft['services']['iron'] = 'unclear';

        $summary = ConsentPhotoSummary::fromDraft($draft, 8);

        $this->assertCount(7, $summary['groups']['declined']);
        $this->assertContains(HealthConsentForm::PLANNED_SERVICES['iron'], $summary['groups']['declined']);
        $this->assertSame([], $summary['groups']['unclear']);
        $this->assertSame([HealthConsentForm::PLANNED_SERVICES['dental']], $summary['groups']['not_offered']);
    }

    /**
     * A shaded heading with no row under it shaded is its rows the school
     * offers, so it asks for nothing more. Shading Immunization on a form for
     * a learner it is never given (Grade 7 only) is named.
     */
    #[Test]
    public function a_heading_shaded_alone_covers_its_rows_and_a_rule_it_breaks_is_named(): void
    {
        $draft = $this->draft();
        $draft['services']['immunization_grade7'] = 'no';

        $checks = fn (int $grade): array => array_values(array_filter(
            ConsentPhotoSummary::fromDraft($draft, $grade)['checks'],
            fn (array $check): bool => $check['subject'] === 'Immunization',
        ));

        $this->assertSame([], $checks(7));
        $this->assertContains(HealthConsentForm::PLANNED_SERVICES['immunization_grade7'], ConsentPhotoSummary::fromDraft($draft, 7)['groups']['consented']);
        $this->assertSame([[
            'section' => 'services',
            'subject' => 'Immunization',
            'message' => 'Shaded, but it is given to Grade 7 learners only and this learner is in Grade 8. It is read as Not applicable.',
        ]], $checks(8));
    }

    /** Iron is for female learners only: shaded for a boy, it is named and read as Not applicable. */
    #[Test]
    public function iron_shaded_for_a_male_learner_is_named(): void
    {
        $draft = $this->grade8Draft();

        $boy = ConsentPhotoSummary::fromDraft($draft, 8, 'Male');
        $girl = ConsentPhotoSummary::fromDraft($draft, 8, 'Female');

        $this->assertContains([
            'section' => 'services',
            'subject' => 'Iron Supplementation',
            'message' => 'Shaded, but it is given to female learners only and this learner is male. It is read as Not applicable.',
        ], $boy['checks']);
        $this->assertContains(HealthConsentForm::PLANNED_SERVICES['iron'], $boy['groups']['not_applicable']);
        $this->assertFalse($boy['complete']);

        $this->assertContains(HealthConsentForm::PLANNED_SERVICES['iron'], $girl['groups']['consented']);
        $this->assertTrue($girl['complete']);
    }

    /**
     * The school's Health Services Offered: a service it does not give is Not
     * offered, and Pag Purga shaded alone is the deworming it does give. A
     * shaded row the school does not give is named.
     */
    #[Test]
    public function a_service_the_school_does_not_offer_is_not_offered_and_shading_it_is_named(): void
    {
        $offered = array_values(array_diff(
            array_keys(HealthConsentForm::PLANNED_SERVICES),
            ['deworming_schistosomiasis', 'deworming_filariasis', 'tas'],
        ));

        $draft = $this->grade8Draft();
        foreach (['deworming_worms', 'deworming_schistosomiasis', 'deworming_filariasis', 'tas'] as $row) {
            $draft['services'][$row] = 'no';
        }

        $summary = ConsentPhotoSummary::fromDraft($draft, 8, 'Female', $offered);

        // Pag Purga shaded on its own: Worms, the only deworming offered, and no warning.
        $this->assertContains(HealthConsentForm::PLANNED_SERVICES['deworming_worms'], $summary['groups']['consented']);
        foreach (['deworming_schistosomiasis', 'deworming_filariasis', 'tas'] as $row) {
            $this->assertContains(HealthConsentForm::PLANNED_SERVICES[$row], $summary['groups']['not_offered']);
        }
        $this->assertSame([], $summary['checks']);
        $this->assertTrue($summary['complete']);

        $draft['services']['tas'] = 'yes';
        $this->assertContains([
            'section' => 'services',
            'subject' => 'Transmission Assessment Survey (TAS) on Lymphatic Filariasis',
            'message' => 'Shaded, but the school does not offer it this school year (Health Services Offered, in the School Nurse\'s Settings). It is read as Not offered.',
        ], ConsentPhotoSummary::fromDraft($draft, 8, 'Female', $offered)['checks']);
    }

    /**
     * The adviser's own test sheet: Pag Purga shaded over empty rows, three
     * allergy boxes shaded over empty lines, and a signature the reader was
     * unsure of, said again in its own note. Pag Purga is its rows and asks
     * nothing more, each allergy box shaded with no details written says so,
     * and the signature is said once, in the reader's words.
     */
    #[Test]
    public function a_plainly_filled_sheet_leaves_only_what_really_needs_a_look(): void
    {
        $draft = $this->grade8Draft();
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
            'subject' => 'Parent\'s signature',
            'message' => 'Parent name is printed on the signature line, but no handwritten signature is present. Check that the parent signed.',
        ]], $summary['checks']);
        // Pag Purga shaded on its own is its rows.
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
        $draft = $this->grade8Draft();
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
        $draft = $this->grade8Draft();
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
        $draft = $this->grade8Draft();
        $draft['note'] = 'The date line is blank.';

        $checks = ConsentPhotoSummary::fromDraft($draft, 8)['checks'];

        $this->assertSame([['section' => 'sheet', 'subject' => 'Reader\'s note', 'message' => 'The date line is blank.']], $checks);
    }

    /** A line the reader was unsure of says what it read, so the adviser checks that. */
    #[Test]
    public function an_unsure_or_unreadable_line_says_what_was_read(): void
    {
        $draft = $this->grade8Draft();
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
