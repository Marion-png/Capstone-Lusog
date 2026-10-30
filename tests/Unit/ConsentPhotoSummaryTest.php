<?php

namespace Tests\Unit;

use App\Models\HealthConsentForm;
use App\Support\ConsentFormScanner;
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
