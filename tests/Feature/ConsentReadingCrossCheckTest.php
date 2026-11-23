<?php

namespace Tests\Feature;

use App\Models\HealthConsentForm;
use App\Models\Institution;
use App\Support\ConsentFormScanner;
use App\Support\ConsentPaperCopy;
use App\Support\ConsentPhotoSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every photographed Sulat-Pahibalo is read twice, side by side, and only what
 * both readings agree on is proposed (ConsentFormScanner::agree()).
 *
 * One reading of a photograph can be confidently wrong, and nothing in it says
 * so. Two readings rarely go wrong the same way, so a box they read
 * differently is UNCLEAR, a line they read differently is shown both ways,
 * and a reading the second one did not check says so.
 */
class ConsentReadingCrossCheckTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
        config()->set('services.gemini.key', 'test-key');
        config()->set('services.gemini.model', 'gemini-main');
        config()->set('services.gemini.consent_check_model', 'gemini-check');
        config()->set('services.gemini.consent_readings', 2);
        config()->set('services.gemini.endpoint', 'https://generativelanguage.googleapis.com/v1beta');
    }

    // ── The readings, as the model returns them ───────────────────────

    /**
     * A clear "yes, to every service" reading, grouped as the schema returns
     * it, every part overridable.
     *
     * @param  array<string, mixed>  $reply  merged into parent_reply
     * @param  array<string, mixed>  $top  merged into the top level
     * @return array<string, mixed>
     */
    private function reading(array $reply = [], array $top = []): array
    {
        $blank = ['text' => '', 'readable' => true, 'confident' => true];

        return array_replace_recursive([
            'sheet_orientation' => 'upright',
            'is_sulat_pahibalo' => true,
            'form_date' => ['text' => 'August 12, 2026', 'year' => 2026, 'month' => 8, 'day' => 12, 'readable' => true],
            'student_name' => ['text' => 'Dela Paz, Lito R.', 'readable' => true, 'confident' => true],
            'parent_reply' => [
                'consent_evidence' => array_fill_keys(array_keys(ConsentFormScanner::CONSENT_BOXES), 'empty outline'),
                'consent_boxes' => [
                    HealthConsentForm::CONSENT_ALL => ConsentFormScanner::TICKED,
                    HealthConsentForm::CONSENT_SPECIFIC => ConsentFormScanner::NOT_TICKED,
                    HealthConsentForm::CONSENT_DENY => ConsentFormScanner::NOT_TICKED,
                ],
                'consent_choice' => HealthConsentForm::CONSENT_ALL,
                'allergy_evidence' => array_fill_keys(array_keys(ConsentFormScanner::ALLERGY_BOXES), 'empty outline'),
                'allergy_boxes' => array_fill_keys(array_keys(ConsentFormScanner::ALLERGY_BOXES), ConsentFormScanner::NOT_TICKED),
                'written' => array_fill_keys(array_keys(ConsentFormScanner::WRITTEN_FIELDS), $blank),
                'excluded_services' => [],
                'parent_guardian_name' => ['text' => 'Rosa Dela Paz', 'readable' => true, 'confident' => true],
                'signature_present' => true,
            ],
            'unreadable' => false,
            'note' => '',
        ], ['parent_reply' => $reply], $top);
    }

    /** Both readings, merged and ruled on as the scanner rules on them. */
    private function read(array $first, array $second): array
    {
        return ConsentFormScanner::interpret(ConsentFormScanner::agree($first, $second));
    }

    private function gemini(array $payload): array
    {
        return [
            'candidates' => [[
                'finishReason' => 'STOP',
                'content' => ['parts' => [['text' => json_encode($payload)]]],
            ]],
        ];
    }

    private function scan()
    {
        return $this->withSession([
            'active_role' => 'class_adviser',
            'active_name' => 'Maria Santos',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
            'assigned_grade_level' => 'Grade 10',
            'assigned_section' => 'Dalton',
        ])->post(route('consent-forms.scan'), [
            'photo' => UploadedFile::fake()->createWithContent('consent.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00".str_repeat('x', 512)),
        ], ['Accept' => 'application/json']);
    }

    // ── Two readings that agree ───────────────────────────────────────

    #[Test]
    public function a_photo_is_read_by_both_models_and_what_they_agree_on_is_proposed(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->gemini($this->reading()), 200)]);

        $draft = $this->scan()->assertOk()->json('draft');

        $this->assertSame(HealthConsentForm::CONSENT_ALL, $draft['consent_choice']);
        $this->assertSame(['readings' => 2, 'disagreed' => []], $draft['cross_check']);
        $this->assertFalse($draft['needs_review']);

        Http::assertSentCount(2);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/models/gemini-main:generateContent'));
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/models/gemini-check:generateContent'));
    }

    #[Test]
    public function with_no_check_model_named_the_same_model_reads_it_twice(): void
    {
        config()->set('services.gemini.consent_check_model', null);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->gemini($this->reading()), 200)]);

        $this->scan()->assertOk();

        Http::assertSentCount(2);
        $this->assertCount(2, Http::recorded(fn (ClientRequest $request): bool => str_contains($request->url(), '/models/gemini-main:')));
    }

    // ── Two readings that do not ──────────────────────────────────────

    /** The one decision that authorises a procedure rests on both readings. */
    #[Test]
    public function readings_that_tick_different_consent_boxes_propose_no_answer(): void
    {
        $other = $this->reading([
            'consent_boxes' => [
                HealthConsentForm::CONSENT_ALL => ConsentFormScanner::NOT_TICKED,
                HealthConsentForm::CONSENT_DENY => ConsentFormScanner::TICKED,
            ],
            'consent_choice' => HealthConsentForm::CONSENT_DENY,
        ]);

        $draft = $this->read($this->reading(), $other);

        $this->assertSame(ConsentFormScanner::UNCLEAR, $draft['consent_choice']);
        $this->assertSame(ConsentFormScanner::UNCLEAR, $draft['answer']);
        $this->assertStringContainsString('do not agree on which consent box', $draft['consent_reason']);
        $this->assertContains('consent_answer', $draft['unclear_fields']);
        $this->assertTrue($draft['needs_review']);
    }

    #[Test]
    public function readings_whose_summaries_differ_propose_no_answer(): void
    {
        $draft = $this->read($this->reading(), $this->reading(['consent_choice' => ConsentFormScanner::UNCLEAR]));

        $this->assertSame(ConsentFormScanner::UNCLEAR, $draft['consent_choice']);
    }

    /** An allergy one reading saw is never dropped because the other missed it. */
    #[Test]
    public function an_allergy_only_one_reading_saw_is_kept_and_put_to_the_adviser(): void
    {
        $seen = $this->reading(['written' => ['allergy_food' => ['text' => 'hipon', 'readable' => true, 'confident' => true]]]);

        foreach ([[$seen, $this->reading()], [$this->reading(), $seen]] as [$first, $second]) {
            $draft = $this->read($first, $second);
            $food = $draft['written']['allergy_food'];

            $this->assertSame('hipon', $food['text']);
            $this->assertFalse($food['confident']);
            $this->assertSame('Read two ways: "hipon" and nothing written — check it against the paper.', $draft['field_notes']['allergy_food']);
            $this->assertContains('allergy_food', $draft['unclear_fields']);
            $this->assertTrue($draft['needs_review']);
        }
    }

    #[Test]
    public function a_line_read_two_ways_shows_both(): void
    {
        $draft = $this->read(
            $this->reading(['written' => ['allergy_medicine' => ['text' => 'Amoxicillin', 'readable' => true, 'confident' => true]]]),
            $this->reading(['written' => ['allergy_medicine' => ['text' => 'Ampicillin', 'readable' => true, 'confident' => true]]]),
        );

        $this->assertSame('Amoxicillin', $draft['written']['allergy_medicine']['text']);
        $this->assertFalse($draft['written']['allergy_medicine']['confident']);
        $this->assertStringContainsString('"Amoxicillin" and "Ampicillin"', $draft['field_notes']['allergy_medicine']);
        $this->assertContains('allergy_medicine', $draft['cross_check']['disagreed']);
    }

    /** Case, spacing and punctuation are not a disagreement; "wala" and "N/A" are one answer. */
    #[Test]
    public function the_same_words_written_differently_still_agree(): void
    {
        $draft = $this->read(
            $this->reading(['written' => [
                'allergy_food' => ['text' => 'Sea foods.', 'readable' => true, 'confident' => true],
                'other_illness' => ['text' => 'wala', 'readable' => true, 'confident' => true],
            ]]),
            $this->reading(['written' => [
                'allergy_food' => ['text' => 'seafoods', 'readable' => true, 'confident' => true],
                'other_illness' => ['text' => 'N/A', 'readable' => true, 'confident' => true],
            ]]),
        );

        $this->assertTrue($draft['written']['allergy_food']['confident']);
        $this->assertArrayNotHasKey('allergy_food', $draft['field_notes']);
        $this->assertSame([], $draft['cross_check']['disagreed']);
        $this->assertFalse($draft['needs_review']);
    }

    /** One saw writing it could not read, the other an empty line: nobody read it. */
    #[Test]
    public function writing_one_reading_could_not_make_out_is_never_taken_for_an_empty_line(): void
    {
        $draft = $this->read(
            $this->reading(['written' => ['allergy_medicine' => ['text' => '', 'readable' => false, 'confident' => false]]]),
            $this->reading(),
        );

        $this->assertFalse($draft['written']['allergy_medicine']['readable']);
        $this->assertSame(ConsentFormScanner::UNREADABLE_TEXT, $draft['written']['allergy_medicine']['text']);
        $this->assertTrue($draft['needs_review']);
    }

    #[Test]
    public function an_allergy_box_the_readings_differ_on_is_put_to_the_adviser(): void
    {
        $draft = $this->read(
            $this->reading(['allergy_boxes' => ['allergy_medicine' => ConsentFormScanner::TICKED]]),
            $this->reading(),
        );

        $this->assertSame(ConsentFormScanner::UNCLEAR, $draft['allergy_boxes']['allergy_medicine']);
        $this->assertArrayHasKey('allergy_medicine', $draft['field_notes']);
        $this->assertTrue($draft['needs_review']);
    }

    /** A signature counts only when both readings saw it. */
    #[Test]
    public function a_signature_only_one_reading_saw_does_not_make_the_form_complete(): void
    {
        $draft = $this->read($this->reading(), $this->reading(['signature_present' => false]));

        $this->assertFalse($draft['signature_present']);
        $this->assertFalse($draft['form_complete']);
        $this->assertContains('signature', $draft['cross_check']['disagreed']);

        $summary = ConsentPhotoSummary::fromDraft($draft, 10);
        $this->assertStringContainsString('did not agree that it is signed', implode(' ', $summary['warnings']));
    }

    /** A service either reading named off the "except" line starts Excluded. */
    #[Test]
    public function a_service_either_reading_named_as_excepted_is_listed(): void
    {
        $except = fn (array $services): array => $this->reading([
            'consent_boxes' => [
                HealthConsentForm::CONSENT_ALL => ConsentFormScanner::NOT_TICKED,
                HealthConsentForm::CONSENT_SPECIFIC => ConsentFormScanner::TICKED,
            ],
            'consent_choice' => HealthConsentForm::CONSENT_SPECIFIC,
            'written' => ['consent_exceptions' => ['text' => 'bakuna ug purga', 'readable' => true, 'confident' => true]],
            'excluded_services' => $services,
        ]);
        $keys = array_keys(ConsentFormScanner::serviceKeys());

        $draft = $this->read($except([$keys[0]]), $except([$keys[0], $keys[1]]));

        $this->assertSame(HealthConsentForm::CONSENT_SPECIFIC, $draft['consent_choice']);
        $this->assertEqualsCanonicalizing([$keys[0], $keys[1]], $draft['excluded_services']);
        $this->assertFalse($draft['written']['consent_exceptions']['confident']);
        $this->assertNull(ConsentPaperCopy::exceptedServices($draft['written']['consent_exceptions']));
        $this->assertTrue($draft['needs_review']);
    }

    #[Test]
    public function a_date_the_readings_differ_on_is_not_ruled_on(): void
    {
        $draft = ConsentFormScanner::checkSchoolYear($this->read(
            $this->reading(top: ['form_date' => ['year' => 2026]]),
            $this->reading(top: ['form_date' => ['year' => 2024]]),
        ), '2026-2027');

        $this->assertNull($draft['form_date']['year']);
        $this->assertFalse($draft['date_outside_school_year']);
        $this->assertSame(ConsentFormScanner::DATE_UNREAD, $draft['date_note']);
    }

    #[Test]
    public function either_reading_calling_the_photo_unreadable_decides_it(): void
    {
        $draft = $this->read($this->reading(), $this->reading(top: ['unreadable' => true, 'note' => 'Too blurred.']));

        $this->assertTrue($draft['unreadable']);
        $this->assertSame(ConsentFormScanner::UNCLEAR, $draft['consent_choice']);
        $this->assertSame('Too blurred.', $draft['note']);
    }

    #[Test]
    public function either_reading_calling_it_another_document_decides_it(): void
    {
        $draft = $this->read($this->reading(top: ['is_sulat_pahibalo' => false]), $this->reading());

        $this->assertTrue($draft['unreadable']);
    }

    #[Test]
    public function readings_that_disagree_on_which_way_the_sheet_lies_propose_nothing(): void
    {
        $draft = $this->read($this->reading(), $this->reading(top: ['sheet_orientation' => 'top_points_left']));

        $this->assertTrue($draft['unreadable']);
        $this->assertSame(0, $draft['turn']);
    }

    #[Test]
    public function a_sheet_both_readings_found_turned_is_turned(): void
    {
        $turned = $this->reading(top: ['sheet_orientation' => 'top_points_right']);

        $this->assertSame(270, $this->read($turned, $turned)['turn']);
    }

    #[Test]
    public function a_reading_that_left_out_a_consent_box_is_not_merged(): void
    {
        $partial = $this->reading();
        unset($partial['parent_reply']['consent_boxes'][HealthConsentForm::CONSENT_DENY]);

        $draft = $this->read($this->reading(), $partial);

        $this->assertTrue($draft['incomplete_reading']);
        $this->assertSame(ConsentFormScanner::UNCLEAR, $draft['consent_choice']);
    }

    // ── A reading nobody checked ──────────────────────────────────────

    /** A failed check leaves the first reading standing, said to be unchecked. */
    #[Test]
    public function a_reading_the_check_could_not_confirm_is_said_to_be_unchecked(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models/gemini-check:*' => Http::response(['error' => ['message' => 'not found']], 404),
            'generativelanguage.googleapis.com/*' => Http::response($this->gemini($this->reading()), 200),
        ]);

        $response = $this->scan()->assertOk();

        $this->assertSame(HealthConsentForm::CONSENT_ALL, $response->json('draft.consent_choice'));
        $this->assertSame(1, $response->json('draft.cross_check.readings'));
        $this->assertTrue($response->json('draft.needs_review'));
        $this->assertFalse($response->json('summary.complete'));
        $this->assertStringContainsString('read once instead of twice', implode(' ', $response->json('summary.warnings')));
    }

    #[Test]
    public function the_check_alone_still_reads_the_form_when_the_first_reader_is_down(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models/gemini-main:*' => Http::response(['error' => ['message' => 'not found']], 404),
            'generativelanguage.googleapis.com/*' => Http::response($this->gemini($this->reading()), 200),
        ]);

        $this->assertSame(1, $this->scan()->assertOk()->json('draft.cross_check.readings'));
    }

    #[Test]
    public function a_school_can_choose_a_single_reading_and_is_told_so(): void
    {
        config()->set('services.gemini.consent_readings', 1);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->gemini($this->reading()), 200)]);

        $response = $this->scan()->assertOk();

        Http::assertSentCount(1);
        $this->assertSame(1, $response->json('draft.cross_check.readings'));
        $this->assertTrue($response->json('draft.needs_review'));
    }
}
