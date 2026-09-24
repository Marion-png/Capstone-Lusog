<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\HealthConsentForm;
use App\Models\Institution;
use App\Support\ConsentFormScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Reading a photographed Sulat-Pahibalo to pre-fill the consent form.
 *
 * The whole feature is a typing aid. It proposes; it never records. A parent's
 * consent authorises medical procedures on a child — deworming, an injection,
 * a tooth extraction — and no machine reading of a photograph stands as that
 * authorisation, so the adviser checks the draft against the paper in their
 * hand and saves the form themselves.
 *
 * Two rules the parser enforces on the way back, both erring the same way:
 * anything the model omits is UNCLEAR, never "no" (a missing answer is not a
 * refusal), and an unreadable photo answers nothing at all rather than
 * returning guesses.
 */
class ConsentFormScanTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
        config()->set('services.anthropic.key', 'test-key');
    }

    private function adviserSession(): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Maria Santos',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
            'assigned_grade_level' => 'Grade 10',
            'assigned_section' => 'Dalton',
        ];
    }

    /** Explicit mime rather than fake()->image(): the latter needs GD. */
    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->create('consent.jpg', 120, 'image/jpeg');
    }

    /** @param  array<string, mixed>  $draft */
    private function fakeScanner(array $draft): void
    {
        $this->swap(ConsentFormScanner::class, new class($draft) extends ConsentFormScanner
        {
            public function __construct(private readonly array $draft)
            {
                parent::__construct();
            }

            public function scan(UploadedFile $photo): array
            {
                return $this->draft;
            }
        });
    }

    private function failingScanner(string $message): void
    {
        $this->swap(ConsentFormScanner::class, new class($message) extends ConsentFormScanner
        {
            public function __construct(private readonly string $message)
            {
                parent::__construct();
            }

            public function scan(UploadedFile $photo): array
            {
                throw new RuntimeException($this->message);
            }
        });
    }

    /** @param  array<string, string>  $services */
    private function draft(array $services = [], array $overrides = []): array
    {
        $answers = [];
        foreach (array_keys(ConsentFormScanner::serviceKeys()) as $key) {
            $answers[$key] = $services[$key] ?? ConsentFormScanner::NOT_TICKED;
        }

        return array_merge([
            'consent_choice' => HealthConsentForm::CONSENT_SPECIFIC,
            'services' => $answers,
            'parent_guardian_name' => 'Maria Cruz',
            'signature_present' => true,
            'unclear' => [],
            'unreadable' => false,
            'note' => '',
        ], $overrides);
    }

    private function scanRequest()
    {
        return $this->withSession($this->adviserSession())
            ->post(route('consent-forms.scan'), ['photo' => $this->photo()], ['Accept' => 'application/json']);
    }

    // ── The catalogue ────────────────────────────────────────────────

    /**
     * The model is handed the form's own keys, parents and indented children
     * alike, so it can only answer about services that exist.
     */
    #[Test]
    public function every_box_on_the_paper_form_is_offered_to_the_reader(): void
    {
        $keys = ConsentFormScanner::serviceKeys();

        foreach (array_keys(HealthConsentForm::SERVICES) as $parent) {
            $this->assertArrayHasKey($parent, $keys);
        }

        // The indented sub-items are answerable too.
        $this->assertArrayHasKey('deworming_worms', $keys);
        $this->assertArrayHasKey('deworming_schistosomiasis', $keys);
        $this->assertArrayHasKey('immunization_grade7', $keys);
    }

    // ── It proposes, it never records ────────────────────────────────

    #[Test]
    public function a_scan_returns_a_draft_and_saves_nothing(): void
    {
        $this->fakeScanner($this->draft(['checkup' => ConsentFormScanner::TICKED]));

        $body = $this->scanRequest()->assertOk()->json();

        $this->assertSame(ConsentFormScanner::TICKED, $body['draft']['services']['checkup']);
        $this->assertFalse($body['saved']);
        $this->assertTrue($body['review_required']);

        // Nothing was written.
        $this->assertSame(0, HealthConsentForm::count());
    }

    #[Test]
    public function the_draft_carries_the_labels_and_choices_the_adviser_reviews_against(): void
    {
        $this->fakeScanner($this->draft());

        $body = $this->scanRequest()->assertOk()->json();

        $this->assertArrayHasKey('checkup', $body['labels']);
        $this->assertArrayHasKey(HealthConsentForm::CONSENT_DENY, $body['choices']);
    }

    /**
     * The photograph carried a named child's health decisions past a
     * third-party model, so the read is on the record even though nothing was
     * written.
     */
    #[Test]
    public function scanning_is_audited(): void
    {
        $this->fakeScanner($this->draft());

        $this->scanRequest()->assertOk();

        $this->assertTrue(
            AuditLog::where('subject_type', 'HealthConsentForm')->where('action', 'scanned')->exists(),
        );
    }

    // ── Erring towards unclear ───────────────────────────────────────

    /**
     * A missing answer is not a refusal.
     *
     * Asserted against interpret() rather than through the endpoint: the
     * faked scanner returns its draft verbatim, so a test routed through it
     * would stub out the very rule under test.
     */
    #[Test]
    public function an_omitted_service_comes_back_unclear_not_no(): void
    {
        $payload = $this->draft();
        unset($payload['services']['dental']);

        $draft = ConsentFormScanner::interpret($payload);

        $this->assertSame(ConsentFormScanner::UNCLEAR, $draft['services']['dental']);
        $this->assertContains(
            ConsentFormScanner::serviceKeys()['dental'],
            $draft['unclear'],
            'An omitted answer must be listed for the adviser to check.'
        );
    }

    /** An answer that is not on the list is unclear too, never a "no". */
    #[Test]
    public function an_unrecognised_answer_comes_back_unclear(): void
    {
        $draft = ConsentFormScanner::interpret($this->draft(['iron' => 'probably?']));

        $this->assertSame(ConsentFormScanner::UNCLEAR, $draft['services']['iron']);
    }

    #[Test]
    public function an_unclear_box_stays_unclear(): void
    {
        $draft = ConsentFormScanner::interpret($this->draft(['iron' => ConsentFormScanner::UNCLEAR]));

        $this->assertSame(ConsentFormScanner::UNCLEAR, $draft['services']['iron']);
    }

    /** An unreadable photo answers nothing — no guesses beside the warning. */
    #[Test]
    public function an_unreadable_photo_answers_nothing(): void
    {
        $draft = ConsentFormScanner::interpret($this->draft(
            ['checkup' => ConsentFormScanner::TICKED],
            ['unreadable' => true, 'note' => 'Too dark to read.'],
        ));

        $this->assertTrue($draft['unreadable']);
        $this->assertSame(ConsentFormScanner::UNCLEAR, $draft['consent_choice']);
        $this->assertSame(ConsentFormScanner::UNCLEAR, $draft['services']['checkup']);
        $this->assertSame('', $draft['parent_guardian_name']);
        $this->assertFalse($draft['signature_present']);
    }

    // ── Availability and failure ─────────────────────────────────────

    /** Without a key the feature is simply absent; the manual path is untouched. */
    #[Test]
    public function the_endpoint_reports_itself_unavailable_without_an_api_key(): void
    {
        config()->set('services.anthropic.key', null);

        $this->scanRequest()->assertStatus(503);
    }

    /** The provider's error text never reaches a teacher's screen. */
    #[Test]
    public function a_failed_read_returns_a_usable_message(): void
    {
        $this->failingScanner('anthropic: 429 rate limited on org acct_123');

        $response = $this->scanRequest()->assertStatus(503);

        $this->assertStringNotContainsString('acct_123', $response->getContent());
    }

    #[Test]
    public function a_non_image_is_refused(): void
    {
        $this->fakeScanner($this->draft());

        $this->withSession($this->adviserSession())
            ->post(route('consent-forms.scan'), [
                'photo' => UploadedFile::fake()->create('notes.pdf', 40, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    // ── Who may scan ─────────────────────────────────────────────────

    /** The adviser collects these forms, so the scan is theirs. */
    #[Test]
    public function no_other_role_may_scan(): void
    {
        $this->fakeScanner($this->draft());

        foreach (['school_nurse', 'clinic_staff', 'school_head', 'feeding_coor'] as $role) {
            $this->withSession([
                'active_role' => $role,
                'active_name' => 'Someone',
                'active_school_name' => 'Sta. Ana NHS',
                'active_institution_id' => $this->school->id,
            ])->post(route('consent-forms.scan'), ['photo' => $this->photo()], ['Accept' => 'application/json'])
                ->assertForbidden();
        }
    }
    // ── The handwritten blanks ───────────────────────────────────────

    /**
     * A tick says *whether*; the blanks say *what*. A consent read without
     * them is one the adviser still has to re-type off the paper, so the
     * reader transcribes them — and says which ones it was unsure of.
     */
    #[Test]
    public function the_handwritten_blanks_are_transcribed_with_their_confidence(): void
    {
        $draft = ConsentFormScanner::interpret($this->draft([], [
            'written' => [
                'consent_exceptions' => ['text' => 'Dili ang bakuna', 'confident' => true],
                'allergy_food' => ['text' => 'Lamang dagat', 'confident' => false],
                'other_illness' => ['text' => '', 'confident' => true],
            ],
        ]));

        $this->assertSame('Dili ang bakuna', $draft['written']['consent_exceptions']['text']);
        $this->assertTrue($draft['written']['consent_exceptions']['confident']);

        // Read, but not confidently: the text is kept AND named, because the
        // adviser holding the paper can correct it faster than they can type it.
        $this->assertSame('Lamang dagat', $draft['written']['allergy_food']['text']);
        $this->assertFalse($draft['written']['allergy_food']['confident']);
        $this->assertContains(ConsentFormScanner::WRITTEN_FIELDS['allergy_food'], $draft['unclear']);

        // An empty blank is an answer, and is never flagged.
        $this->assertSame('', $draft['written']['other_illness']['text']);
        $this->assertFalse($draft['written']['other_illness']['confident']);
        $this->assertNotContains(ConsentFormScanner::WRITTEN_FIELDS['other_illness'], $draft['unclear']);

        // Every blank on the form comes back, whether the model answered or not.
        foreach (array_keys(ConsentFormScanner::WRITTEN_FIELDS) as $key) {
            $this->assertArrayHasKey($key, $draft['written']);
        }
    }

    /** An unreadable photograph transcribes nothing, as it answers nothing. */
    #[Test]
    public function an_unreadable_photo_transcribes_no_handwriting(): void
    {
        $draft = ConsentFormScanner::interpret($this->draft([], [
            'unreadable' => true,
            'written' => ['allergy_medicine' => ['text' => 'Amoxicillin', 'confident' => true]],
        ]));

        $this->assertSame('', $draft['written']['allergy_medicine']['text']);
        $this->assertFalse($draft['written']['allergy_medicine']['confident']);
        $this->assertTrue($draft['needs_review']);
    }

    /**
     * `needs_review` is decided once, by the reader, so two screens cannot
     * reach different answers about the same draft. A clean read still goes to
     * the adviser — this only says whether anything on it needs a second look.
     */
    #[Test]
    public function needs_review_is_set_by_anything_the_reader_was_unsure_of(): void
    {
        $clean = ConsentFormScanner::interpret($this->draft(
            array_fill_keys(array_keys(ConsentFormScanner::serviceKeys()), ConsentFormScanner::TICKED),
            ['consent_choice' => HealthConsentForm::CONSENT_ALL, 'written' => []],
        ));
        $this->assertFalse($clean['needs_review']);

        foreach ([
            'an unreadable sheet' => ['unreadable' => true],
            'a missing signature' => ['signature_present' => false],
            'an unclear choice' => ['consent_choice' => 'unclear'],
            'shaky handwriting' => ['written' => ['other_illness' => ['text' => 'ubo', 'confident' => false]]],
        ] as $because => $overrides) {
            $draft = ConsentFormScanner::interpret($this->draft(
                array_fill_keys(array_keys(ConsentFormScanner::serviceKeys()), ConsentFormScanner::TICKED),
                array_merge(['consent_choice' => HealthConsentForm::CONSENT_ALL, 'written' => []], $overrides),
            ));

            $this->assertTrue($draft['needs_review'], 'needs_review must be set by '.$because);
        }
    }

    /**
     * A blank nobody could read reads "Unreadable" — not blank, and not a
     * guess.
     *
     * Three states, three different claims about a child: the parent wrote
     * nothing, the parent wrote this, or the parent wrote something and nobody
     * could make it out. An empty field would say the first when the truth is
     * the third, and once the form is saved the two cannot be told apart.
     */
    #[Test]
    public function handwriting_nobody_could_read_is_marked_unreadable(): void
    {
        $draft = ConsentFormScanner::interpret($this->draft([], [
            'written' => [
                // Blurred beyond reading.
                'allergy_medicine' => ['text' => '', 'confident' => false, 'readable' => false],
                // Nothing written at all.
                'allergy_food' => ['text' => '', 'confident' => true, 'readable' => true],
                // Read, and certain.
                'other_illness' => ['text' => 'Ubo', 'confident' => true, 'readable' => true],
            ],
        ]));

        $this->assertSame(ConsentFormScanner::UNREADABLE_TEXT, $draft['written']['allergy_medicine']['text']);
        $this->assertFalse($draft['written']['allergy_medicine']['readable']);
        $this->assertFalse($draft['written']['allergy_medicine']['confident']);
        $this->assertContains(ConsentFormScanner::WRITTEN_FIELDS['allergy_medicine'], $draft['unclear']);
        $this->assertTrue($draft['needs_review']);

        // An empty blank stays empty: "no food allergy" is an answer, and it
        // must not be reported as unread handwriting.
        $this->assertSame('', $draft['written']['allergy_food']['text']);
        $this->assertTrue($draft['written']['allergy_food']['readable']);
        $this->assertNotContains(ConsentFormScanner::WRITTEN_FIELDS['allergy_food'], $draft['unclear']);

        // And a confident reading is untouched.
        $this->assertSame('Ubo', $draft['written']['other_illness']['text']);
        $this->assertTrue($draft['written']['other_illness']['confident']);
    }

    /**
     * A reading the model returned but did not mark is taken as read.
     *
     * `readable` defaults to true on the way in: a model that omits the key
     * must not silently turn every blank on the form into "Unreadable", which
     * would bury the ones that genuinely are.
     */
    #[Test]
    public function a_blank_with_no_readable_flag_is_taken_as_read(): void
    {
        $draft = ConsentFormScanner::interpret($this->draft([], [
            'written' => ['other_illness' => ['text' => 'Ubo', 'confident' => true]],
        ]));

        $this->assertSame('Ubo', $draft['written']['other_illness']['text']);
        $this->assertTrue($draft['written']['other_illness']['readable']);
    }

    /** An unreadable photograph is unreadable whole: no field claims otherwise. */
    #[Test]
    public function an_unreadable_photo_marks_nothing_unreadable_field_by_field(): void
    {
        $draft = ConsentFormScanner::interpret($this->draft([], [
            'unreadable' => true,
            'written' => ['allergy_food' => ['text' => 'x', 'confident' => true, 'readable' => false]],
        ]));

        // The whole sheet failed, so no single blank is singled out — the
        // adviser fills the form in from the paper, and the screen says so.
        $this->assertSame('', $draft['written']['allergy_food']['text']);
        $this->assertTrue($draft['unreadable']);
    }
}
