<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\HealthConsentForm;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\ConsentFormScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
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
        config()->set('services.gemini.key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.8-flash');
        config()->set('services.gemini.endpoint', 'https://generativelanguage.googleapis.com/v1beta');
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
            public function __construct(private readonly array $draft) {}

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
            public function __construct(private readonly string $message) {}

            public function scan(UploadedFile $photo): array
            {
                throw new RuntimeException($this->message);
            }
        });
    }

    /** One Gemini response, shaped as the structured-output schema pins it. */
    private function fakeGemini(array $payload): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => ['parts' => [['text' => json_encode($payload)]]],
                ]],
            ], 200),
        ]);
    }

    /**
     * A stand-in for the photograph that carries real bytes — the scanner
     * refuses an empty upload — with a JPEG's opening so the type is detected.
     */
    private function jpeg(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('consent.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00".str_repeat('x', 512));
    }

    private function learner(string $lrn, string $first, string $last, string $section = 'Dalton'): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => $last.', '.$first,
            'student_id' => $lrn,
            'school_name' => 'Sta. Ana NHS',
            'section' => 'Grade 10 / '.$section,
            'student_details' => [
                'last_name' => $last,
                'first_name' => $first,
                'middle_name' => 'P.',
                'grade_level' => 'Grade 10',
                'section' => $section,
                'gender' => 'Male',
            ],
        ]);
    }

    /** @param  array<string, string>  $services */
    private function draft(array $services = [], array $overrides = []): array
    {
        $answers = [];
        foreach (array_keys(ConsentFormScanner::serviceKeys()) as $key) {
            $answers[$key] = $services[$key] ?? ConsentFormScanner::NOT_TICKED;
        }

        $draft = array_merge([
            'consent_choice' => HealthConsentForm::CONSENT_SPECIFIC,
            'services' => $answers,
            'parent_guardian_name' => 'Maria Cruz',
            'signature_present' => true,
            'unclear' => [],
            'unreadable' => false,
            'note' => '',
        ], $overrides);

        // The boxes agree with the summary unless a test says otherwise.
        if (! array_key_exists('consent_boxes', $overrides)) {
            $draft['consent_boxes'] = $this->boxes((string) $draft['consent_choice']);
        }

        // No allergy or illness box ticked unless a test says otherwise.
        if (! array_key_exists('allergy_boxes', $overrides)) {
            $draft['allergy_boxes'] = $this->allergyBoxes();
        }

        return $draft;
    }

    /**
     * The allergy and illness boxes, every one empty unless overridden.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function allergyBoxes(array $overrides = []): array
    {
        return array_merge(
            array_fill_keys(array_keys(ConsentFormScanner::ALLERGY_BOXES), ConsentFormScanner::NOT_TICKED),
            $overrides,
        );
    }

    /**
     * The three consent boxes with only `$ticked` marked (none for anything
     * else), each overridable.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function boxes(string $ticked, array $overrides = []): array
    {
        $boxes = [];
        foreach (array_keys(ConsentFormScanner::CONSENT_BOXES) as $box) {
            $boxes[$box] = $box === $ticked ? ConsentFormScanner::TICKED : ConsentFormScanner::NOT_TICKED;
        }

        return array_merge($boxes, $overrides);
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

    // ── Whether the parent consented ─────────────────────────────────

    /**
     * The service list at the top of the letter was ticked by the school
     * before it was printed. A reader that took it for the parent's answer
     * reported "agreed to all" on every letter covering every service — so the
     * answer is read from the parent's own three boxes, and a fully ticked
     * service list decides nothing.
     */
    #[Test]
    public function the_consent_is_read_from_the_parents_boxes_not_the_service_list(): void
    {
        $everyService = array_fill_keys(array_keys(ConsentFormScanner::serviceKeys()), ConsentFormScanner::TICKED);

        $refused = ConsentFormScanner::interpret($this->draft($everyService, [
            'consent_choice' => HealthConsentForm::CONSENT_DENY,
            'written' => ['refusal_reason' => ['text' => 'Takot sa tusok', 'confident' => true, 'readable' => true]],
        ]));
        $this->assertSame(HealthConsentForm::CONSENT_DENY, $refused['consent_choice']);
        $this->assertSame('', $refused['consent_reason']);

        // Every service ticked, no consent box ticked: the parent has not
        // answered, and "all" is the one thing this must not report.
        $silent = ConsentFormScanner::interpret($this->draft($everyService, [
            'consent_choice' => HealthConsentForm::CONSENT_ALL,
            'consent_boxes' => $this->boxes(''),
        ]));
        $this->assertSame(ConsentFormScanner::UNCLEAR, $silent['consent_choice']);
        $this->assertTrue($silent['needs_review']);
    }

    #[Test]
    public function one_ticked_box_is_read_as_its_answer(): void
    {
        foreach (array_keys(ConsentFormScanner::CONSENT_BOXES) as $choice) {
            $written = match ($choice) {
                HealthConsentForm::CONSENT_SPECIFIC => ['consent_exceptions' => ['text' => 'Bakuna', 'confident' => true, 'readable' => true]],
                HealthConsentForm::CONSENT_DENY => ['refusal_reason' => ['text' => 'Relihiyon', 'confident' => true, 'readable' => true]],
                default => [],
            };

            $draft = ConsentFormScanner::interpret($this->draft([], [
                'consent_choice' => $choice,
                'written' => $written,
            ]));

            $this->assertSame($choice, $draft['consent_choice'], 'One tick in the '.$choice.' box is that answer.');
            $this->assertSame('', $draft['consent_reason']);
        }
    }

    /**
     * Anything but one clear tick is unclear, and says why: the reason is what
     * tells the adviser whether to look at the paper again or ask the parent.
     */
    #[Test]
    public function a_sheet_that_does_not_say_one_thing_is_unclear(): void
    {
        $all = HealthConsentForm::CONSENT_ALL;
        $deny = HealthConsentForm::CONSENT_DENY;

        foreach ([
            'no box ticked' => ['consent_boxes' => $this->boxes('')],
            'two boxes ticked' => ['consent_boxes' => $this->boxes($all, [$deny => ConsentFormScanner::TICKED])],
            'a box nobody could make out' => ['consent_boxes' => $this->boxes($all, [$deny => ConsentFormScanner::UNCLEAR])],
            'an answer off the list' => ['consent_boxes' => $this->boxes($all, [$deny => 'maybe'])],
            'a box left out' => ['consent_boxes' => array_diff_key($this->boxes($all), [$deny => true])],
            'no boxes reported at all' => ['consent_boxes' => null],
            'a summary that disagrees with the boxes' => ['consent_boxes' => $this->boxes($all), 'consent_choice' => $deny],
            'a refusal written under an "Oo"' => [
                'consent_boxes' => $this->boxes($all),
                'written' => ['refusal_reason' => ['text' => 'Dili gusto', 'confident' => true, 'readable' => true]],
            ],
            'unreadable writing on another box\'s line' => [
                'consent_boxes' => $this->boxes($deny),
                'consent_choice' => $deny,
                'written' => ['consent_exceptions' => ['text' => '', 'confident' => false, 'readable' => false]],
            ],
        ] as $because => $overrides) {
            $draft = ConsentFormScanner::interpret($this->draft([], array_merge(['consent_choice' => $all], $overrides)));

            $this->assertSame(ConsentFormScanner::UNCLEAR, $draft['consent_choice'], 'Unclear when there is '.$because);
            $this->assertNotSame('', $draft['consent_reason'], 'The adviser is told why for '.$because);
            $this->assertTrue($draft['needs_review'], 'needs_review is set by '.$because);
        }
    }

    /** The reader is made to answer for each of the three boxes. */
    #[Test]
    public function the_reader_is_asked_about_each_consent_box(): void
    {
        $schema = (new \ReflectionMethod(ConsentFormScanner::class, 'responseSchema'))
            ->invoke(new ConsentFormScanner);

        $this->assertContains('consent_boxes', $schema['required']);
        $this->assertSame(
            array_keys(ConsentFormScanner::CONSENT_BOXES),
            $schema['properties']['consent_boxes']['required'],
        );
        $this->assertSame(
            [HealthConsentForm::CONSENT_ALL, HealthConsentForm::CONSENT_SPECIFIC, HealthConsentForm::CONSENT_DENY],
            array_keys(ConsentFormScanner::CONSENT_BOXES),
        );
    }

    /** It describes each box before it answers for it, so it looks before it decides. */
    #[Test]
    public function the_reader_describes_each_consent_box_before_answering(): void
    {
        $schema = (new \ReflectionMethod(ConsentFormScanner::class, 'responseSchema'))
            ->invoke(new ConsentFormScanner);

        $this->assertContains('consent_evidence', $schema['required']);
        $this->assertSame(
            array_keys(ConsentFormScanner::CONSENT_BOXES),
            $schema['properties']['consent_evidence']['required'],
        );

        $order = $schema['propertyOrdering'];
        $this->assertLessThan(
            array_search('consent_boxes', $order, true),
            array_search('consent_evidence', $order, true),
        );
    }

    /**
     * What the reader saw is said to the adviser beside an unclear answer,
     * and never decides one.
     */
    #[Test]
    public function what_the_reader_saw_is_said_but_never_decides(): void
    {
        $all = HealthConsentForm::CONSENT_ALL;
        $deny = HealthConsentForm::CONSENT_DENY;

        $draft = ConsentFormScanner::interpret($this->draft([], [
            'consent_choice' => $all,
            'consent_boxes' => $this->boxes($all, [$deny => ConsentFormScanner::UNCLEAR]),
            'consent_evidence' => [$all => 'dark shaded square', $deny => 'glare covers it'],
        ]));

        $this->assertSame(ConsentFormScanner::UNCLEAR, $draft['consent_choice']);
        $this->assertStringContainsString('"Dili" box: glare covers it', $draft['consent_reason']);
        $this->assertStringNotContainsString('dark shaded square', $draft['consent_reason']);

        // Two marks: both descriptions, so the adviser knows which to ask about.
        $draft = ConsentFormScanner::interpret($this->draft([], [
            'consent_choice' => $all,
            'consent_boxes' => $this->boxes($all, [$deny => ConsentFormScanner::TICKED]),
            'consent_evidence' => [$all => 'dark shaded square', $deny => 'light pencil shading'],
        ]));

        $this->assertStringContainsString('"Oo" box: dark shaded square', $draft['consent_reason']);
        $this->assertStringContainsString('"Dili" box: light pencil shading', $draft['consent_reason']);

        // A description at odds with its own box changes nothing: the boxes rule.
        $draft = ConsentFormScanner::interpret($this->draft([], [
            'consent_choice' => $deny,
            'consent_evidence' => [$deny => 'empty outline'],
        ]));

        $this->assertSame($deny, $draft['consent_choice']);
        $this->assertSame('', $draft['consent_reason']);

        // A reading with no descriptions at all still says why it is unclear.
        $draft = ConsentFormScanner::interpret($this->draft([], [
            'consent_choice' => $all,
            'consent_boxes' => $this->boxes($all, [$deny => ConsentFormScanner::UNCLEAR]),
        ]));

        $this->assertSame('A consent box could not be made out — check which one the parent ticked.', $draft['consent_reason']);
    }

    // ── Availability and failure ─────────────────────────────────────

    /** Without a key the feature is simply absent; the manual path is untouched. */
    #[Test]
    public function the_endpoint_reports_itself_unavailable_without_an_api_key(): void
    {
        config()->set('services.gemini.key', null);
        Http::fake();

        $this->scanRequest()->assertStatus(503);

        Http::assertNothingSent();
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

    // ── Gemini ───────────────────────────────────────────────────────

    /**
     * The photograph goes to the configured Gemini model inline, with the
     * answer shape pinned and the whole printed form — Cebuano and all — in
     * the instructions.
     */
    #[Test]
    public function the_photo_is_sent_to_the_configured_gemini_model(): void
    {
        $this->fakeGemini($this->draft());

        $this->withSession($this->adviserSession())
            ->post(route('consent-forms.scan'), ['photo' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertOk();

        Http::assertSent(function (ClientRequest $request): bool {
            $body = $request->data();
            $image = $body['contents'][0]['parts'][0]['inline_data'] ?? [];
            // Line-wrapping in the prompt is layout, not wording.
            $system = (string) preg_replace('/\s+/', ' ', (string) ($body['system_instruction']['parts'][0]['text'] ?? ''));

            return str_contains($request->url(), 'models/gemini-3.8-flash:generateContent')
                && $request->hasHeader('x-goog-api-key', 'test-key')
                // The key never travels in the URL, which is the part that gets logged.
                && ! str_contains($request->url(), 'test-key')
                && ($image['mime_type'] ?? null) === 'image/jpeg'
                && base64_decode((string) ($image['data'] ?? ''), true) !== false
                && ($body['generationConfig']['responseMimeType'] ?? null) === 'application/json'
                && isset($body['generationConfig']['responseSchema']['properties']['consent_boxes'])
                && isset($body['generationConfig']['responseSchema']['properties']['allergy_boxes'])
                && str_contains($system, 'Cebuano')
                && str_contains($system, 'TIMAAN SA PAGDAWAT')
                && str_contains($system, 'gawas lamang niini');
        });
    }

    /** What Gemini read comes back as the draft, through every rule interpret() applies. */
    #[Test]
    public function a_gemini_reading_becomes_the_draft(): void
    {
        $this->fakeGemini($this->draft([], [
            'consent_choice' => HealthConsentForm::CONSENT_DENY,
            'written' => [
                'refusal_reason' => ['text' => 'Mahadlok sa tusok', 'readable' => true, 'confident' => true],
                'allergy_food' => ['text' => 'Kinhason', 'readable' => true, 'confident' => true],
            ],
            'allergy_boxes' => $this->allergyBoxes(['allergy' => 'yes', 'allergy_food' => 'yes']),
            'student_name' => ['text' => 'DELA CRUZ, JUAN P.', 'readable' => true, 'confident' => true],
            'parent_guardian_name' => ['text' => 'Josefina Dela Cruz', 'readable' => true, 'confident' => true],
        ]));

        $draft = $this->withSession($this->adviserSession())
            ->post(route('consent-forms.scan'), ['photo' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('draft');

        $this->assertSame(HealthConsentForm::CONSENT_DENY, $draft['consent_choice']);
        $this->assertSame('Mahadlok sa tusok', $draft['written']['refusal_reason']['text']);
        $this->assertSame('Kinhason', $draft['written']['allergy_food']['text']);
        $this->assertSame(ConsentFormScanner::TICKED, $draft['written']['allergy_food']['box']);
        $this->assertSame('DELA CRUZ, JUAN P.', $draft['student_name']);
        $this->assertSame('Josefina Dela Cruz', $draft['parent_guardian_name']);
        $this->assertSame([], $draft['warnings']);
        $this->assertEmpty($draft['field_notes']);
        $this->assertFalse($draft['needs_review']);
        $this->assertSame(0, HealthConsentForm::count());
    }

    /** A thought summary is the model's working, not its answer. */
    #[Test]
    public function the_models_working_is_not_read_as_its_answer(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => ['parts' => [
                        ['text' => 'Looking at the three boxes first…', 'thought' => true],
                        ['text' => json_encode($this->draft([], ['consent_choice' => HealthConsentForm::CONSENT_ALL]))],
                    ]],
                ]],
            ], 200),
        ]);

        $draft = $this->withSession($this->adviserSession())
            ->post(route('consent-forms.scan'), ['photo' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('draft');

        $this->assertFalse($draft['unreadable']);
        $this->assertSame(HealthConsentForm::CONSENT_ALL, $draft['consent_choice']);
    }

    /** An answer cut off half-way answers nothing, rather than half a form. */
    #[Test]
    public function an_answer_that_does_not_parse_answers_nothing(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'finishReason' => 'MAX_TOKENS',
                    'content' => ['parts' => [['text' => '{"consent_boxes": {"all": "ye']]],
                ]],
            ], 200),
        ]);

        $draft = $this->withSession($this->adviserSession())
            ->post(route('consent-forms.scan'), ['photo' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('draft');

        $this->assertTrue($draft['unreadable']);
        $this->assertSame(ConsentFormScanner::UNCLEAR, $draft['consent_choice']);
        $this->assertTrue($draft['needs_review']);
    }

    /**
     * A busy reader is retried, then said in terms an adviser can act on —
     * never as a status code.
     */
    #[Test]
    public function a_busy_reader_is_retried_then_said_in_plain_words(): void
    {
        Sleep::fake();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => ['message' => 'This model is currently experiencing high demand.'],
            ], 503),
        ]);

        $message = $this->withSession($this->adviserSession())
            ->post(route('consent-forms.scan'), ['photo' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertStatus(503)
            ->json('message');

        $this->assertStringContainsString('busy', $message);
        $this->assertStringNotContainsString('503', $message);
        Http::assertSentCount(3);
    }

    /**
     * Gemini's 429, naming the quota it hit and how long to wait, as its own
     * error details do. A null delay leaves the RetryInfo out.
     */
    private function quotaRefusal(string $quotaId, ?string $retryDelay): array
    {
        $details = [
            ['@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => [['quotaId' => $quotaId, 'quotaValue' => '20']]],
        ];

        if ($retryDelay !== null) {
            $details[] = ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => $retryDelay];
        }

        return [
            'error' => [
                'code' => 429,
                'message' => 'You exceeded your current quota, please check your plan and billing details.',
                'details' => $details,
            ],
        ];
    }

    /**
     * A spent daily allowance does not come back in a few minutes: it is not
     * retried, and the adviser is told it is gone for the day rather than to
     * try again shortly.
     */
    /** @return array<string, array{0: string, 1: string|null}> */
    public static function spentForTheDay(): array
    {
        return [
            'Google says to wait eight hours' => ['GenerateRequestsPerDayPerProjectPerModel-FreeTier', '29108s'],
            'no wait given, a per-day quota named' => ['GenerateRequestsPerDayPerProjectPerModel-FreeTier', null],
        ];
    }

    #[Test]
    #[DataProvider('spentForTheDay')]
    public function a_spent_daily_allowance_is_said_plainly_and_not_retried(string $quota, ?string $delay): void
    {
        Sleep::fake();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->quotaRefusal($quota, $delay), 429),
        ]);

        $message = $this->withSession($this->adviserSession())
            ->post(route('consent-forms.scan'), ['photo' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertStatus(503)
            ->json('message');

        $this->assertStringContainsString('today', $message);
        $this->assertStringNotContainsString('quota', $message);
        Http::assertSentCount(1);
    }

    /** @return array<string, array{0: string, 1: string|null}> */
    public static function clearsShortly(): array
    {
        return [
            'a per-minute limit' => ['GenerateRequestsPerMinutePerProjectPerModel-FreeTier', '12s'],
            'a per-day name with a short wait' => ['GenerateRequestsPerDayPerProjectPerModel-FreeTier', '20s'],
            'a per-minute limit with no wait given' => ['GenerateRequestsPerMinutePerProjectPerModel-FreeTier', null],
        ];
    }

    /**
     * A limit Google says will clear shortly is retried and called busy —
     * including one naming a per-day quota, which has been seen to clear a
     * minute later. The wait Google gives decides, not the quota's name.
     */
    #[Test]
    #[DataProvider('clearsShortly')]
    public function a_limit_that_clears_shortly_is_retried_and_called_busy(string $quota, ?string $delay): void
    {
        Sleep::fake();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response($this->quotaRefusal($quota, $delay), 429),
        ]);

        $message = $this->withSession($this->adviserSession())
            ->post(route('consent-forms.scan'), ['photo' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertStatus(503)
            ->json('message');

        $this->assertStringContainsString('busy', $message);
        Http::assertSentCount(3);
    }

    /**
     * A permanent failure is not retried, and the provider's words — which
     * can name the project, the key's scope or the model — stay in the log.
     */
    #[Test]
    public function a_permanent_failure_never_shows_the_providers_words(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => ['message' => 'models/gemini-9-flash is not found for project 123456'],
            ], 404),
        ]);

        $message = $this->withSession($this->adviserSession())
            ->post(route('consent-forms.scan'), ['photo' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertStatus(503)
            ->json('message');

        $this->assertStringContainsString('could not be read', $message);
        $this->assertStringNotContainsString('123456', $message);
        Http::assertSentCount(1);
    }

    #[Test]
    public function an_unreachable_reader_does_not_show_the_transport_error(): void
    {
        Sleep::fake();
        Http::fake(function () {
            throw new ConnectionException(
                'cURL error 28: Operation timed out for https://generativelanguage.googleapis.com/v1beta/models/x:generateContent'
            );
        });

        $message = $this->withSession($this->adviserSession())
            ->post(route('consent-forms.scan'), ['photo' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertStatus(503)
            ->json('message');

        $this->assertStringContainsString('could not be sent for reading', $message);
        $this->assertStringNotContainsString('googleapis', $message);
        $this->assertStringNotContainsString('cURL', $message);
    }

    /** A refusal that arrives as HTTP 200 is not read as a sheet with nothing on it. */
    #[Test]
    public function a_blocked_read_is_not_mistaken_for_an_empty_sheet(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'promptFeedback' => ['blockReason' => 'OTHER'],
            ], 200),
        ]);

        $message = $this->withSession($this->adviserSession())
            ->post(route('consent-forms.scan'), ['photo' => $this->jpeg()], ['Accept' => 'application/json'])
            ->assertStatus(503)
            ->json('message');

        $this->assertStringContainsString('clearer photo', $message);
    }

    // ── Reading what a parent writes in Cebuano ──────────────────────

    /**
     * "Wala" is how a parent says there is nothing to write, and they write it
     * on every line that does not apply to them. It is not a refusal reason
     * under an "Oo", and must not make a clear answer unclear — while the
     * parent's own word is still kept exactly as written.
     */
    #[Test]
    public function a_wala_on_another_boxs_line_says_nothing(): void
    {
        $all = HealthConsentForm::CONSENT_ALL;
        $specific = HealthConsentForm::CONSENT_SPECIFIC;
        $deny = HealthConsentForm::CONSENT_DENY;

        foreach ([
            [$all, 'refusal_reason', 'Wala'],
            [$all, 'refusal_reason', "Wala'y"],
            [$all, 'consent_exceptions', 'N/A'],
            [$all, 'consent_exceptions', 'wala po'],
            [$specific, 'refusal_reason', 'None'],
            [$deny, 'consent_exceptions', '-'],
        ] as [$choice, $field, $text]) {
            $written = [$field => ['text' => $text, 'confident' => true, 'readable' => true]];

            // The ticked box's own line carries its answer.
            if ($choice === $specific) {
                $written['consent_exceptions'] = ['text' => 'Bakuna', 'confident' => true, 'readable' => true];
            }
            if ($choice === $deny) {
                $written['refusal_reason'] = ['text' => 'Relihiyon', 'confident' => true, 'readable' => true];
            }

            $draft = ConsentFormScanner::interpret($this->draft([], ['consent_choice' => $choice, 'written' => $written]));

            $this->assertSame($choice, $draft['consent_choice'], '"'.$text.'" on the '.$field.' line says nothing');
            $this->assertSame($text, $draft['written'][$field]['text'], 'The parent\'s own word is kept');
        }
    }

    /** A line that starts like "wala" but says something still refuses. */
    #[Test]
    public function a_reason_that_begins_with_wala_is_still_a_reason(): void
    {
        $draft = ConsentFormScanner::interpret($this->draft([], [
            'consent_choice' => HealthConsentForm::CONSENT_ALL,
            'written' => ['refusal_reason' => ['text' => 'Wala koy tiwala sa bakuna', 'confident' => true, 'readable' => true]],
        ]));

        $this->assertSame(ConsentFormScanner::UNCLEAR, $draft['consent_choice']);
    }

    /**
     * "Oo … gawas lamang niini" with nothing excepted is a consent the nurse
     * cannot act on. The ticked box stays the ticked box; the adviser is told
     * what to ask.
     */
    #[Test]
    public function an_except_with_nothing_excepted_is_put_to_the_adviser(): void
    {
        foreach (['', 'Wala'] as $text) {
            $draft = ConsentFormScanner::interpret($this->draft([], [
                'consent_choice' => HealthConsentForm::CONSENT_SPECIFIC,
                'written' => ['consent_exceptions' => ['text' => $text, 'confident' => true, 'readable' => true]],
            ]));

            $this->assertSame(HealthConsentForm::CONSENT_SPECIFIC, $draft['consent_choice']);
            $this->assertArrayHasKey('consent_exceptions', $draft['field_notes']);
            $this->assertContains(ConsentFormScanner::WRITTEN_FIELDS['consent_exceptions'], $draft['unclear']);
            $this->assertTrue($draft['needs_review']);
        }
    }

    #[Test]
    public function a_refusal_with_no_reason_is_put_to_the_adviser(): void
    {
        $draft = ConsentFormScanner::interpret($this->draft([], [
            'consent_choice' => HealthConsentForm::CONSENT_DENY,
            'written' => [],
        ]));

        $this->assertSame(HealthConsentForm::CONSENT_DENY, $draft['consent_choice']);
        $this->assertArrayHasKey('refusal_reason', $draft['field_notes']);
        $this->assertTrue($draft['needs_review']);
    }

    // ── The allergy and illness boxes ────────────────────────────────

    /**
     * A ticked "Tambal/Medicines" over an empty line says the child reacts to
     * some medicine. Read from the line alone it would be saved as no medicine
     * allergy — the opposite — so it is put in front of the adviser instead.
     */
    #[Test]
    public function a_ticked_allergy_box_over_an_empty_line_is_never_read_as_no_allergy(): void
    {
        $draft = ConsentFormScanner::interpret($this->draft([], [
            'consent_choice' => HealthConsentForm::CONSENT_ALL,
            'allergy_boxes' => $this->allergyBoxes(['allergy' => 'yes', 'allergy_medicine' => 'yes']),
            'written' => ['allergy_medicine' => ['text' => '', 'confident' => false, 'readable' => true]],
        ]));

        $this->assertSame(ConsentFormScanner::TICKED, $draft['written']['allergy_medicine']['box']);
        $this->assertArrayHasKey('allergy_medicine', $draft['field_notes']);
        $this->assertContains(ConsentFormScanner::WRITTEN_FIELDS['allergy_medicine'], $draft['unclear']);
        $this->assertTrue($draft['needs_review']);

        // The ticked kind names it, so the heading box needs no warning of its own.
        $this->assertSame([], $draft['warnings']);
    }

    #[Test]
    public function a_ticked_box_over_a_wala_is_put_to_the_adviser(): void
    {
        $draft = ConsentFormScanner::interpret($this->draft([], [
            'consent_choice' => HealthConsentForm::CONSENT_ALL,
            'allergy_boxes' => $this->allergyBoxes(['allergy' => 'yes', 'allergy_food' => 'yes']),
            'written' => ['allergy_food' => ['text' => 'Wala', 'confident' => true, 'readable' => true]],
        ]));

        $this->assertStringContainsString('Wala', $draft['field_notes']['allergy_food'] ?? '');
        $this->assertSame('Wala', $draft['written']['allergy_food']['text']);
        $this->assertTrue($draft['needs_review']);
    }

    /** Parents often write without ticking: the writing is the answer. */
    #[Test]
    public function writing_without_a_tick_is_an_answer(): void
    {
        $draft = ConsentFormScanner::interpret($this->draft([], [
            'consent_choice' => HealthConsentForm::CONSENT_ALL,
            'written' => ['other_illness' => ['text' => 'Hubak', 'confident' => true, 'readable' => true]],
        ]));

        $this->assertSame('Hubak', $draft['written']['other_illness']['text']);
        $this->assertArrayNotHasKey('other_illness', $draft['field_notes']);
        $this->assertFalse($draft['needs_review']);
    }

    #[Test]
    public function an_allergy_box_nobody_could_make_out_over_an_empty_line_is_flagged(): void
    {
        $draft = ConsentFormScanner::interpret($this->draft([], [
            'consent_choice' => HealthConsentForm::CONSENT_ALL,
            'allergy_boxes' => $this->allergyBoxes(['prev_immunization' => ConsentFormScanner::UNCLEAR]),
        ]));

        $this->assertArrayHasKey('prev_immunization', $draft['field_notes']);
        $this->assertTrue($draft['needs_review']);
    }

    /** The heading box ticked with no allergy named anywhere under it. */
    #[Test]
    public function an_allergy_heading_with_nothing_named_is_a_warning(): void
    {
        $draft = ConsentFormScanner::interpret($this->draft([], [
            'consent_choice' => HealthConsentForm::CONSENT_ALL,
            'allergy_boxes' => $this->allergyBoxes(['allergy' => 'yes']),
        ]));

        $this->assertCount(1, $draft['warnings']);
        $this->assertTrue($draft['needs_review']);

        $named = ConsentFormScanner::interpret($this->draft([], [
            'consent_choice' => HealthConsentForm::CONSENT_ALL,
            'allergy_boxes' => $this->allergyBoxes(['allergy' => 'yes']),
            'written' => ['allergy_food' => ['text' => 'Itlog', 'confident' => true, 'readable' => true]],
        ]));

        $this->assertSame([], $named['warnings']);
    }

    /** A box the reader left out is unclear, never "no" — the same rule as everywhere. */
    #[Test]
    public function an_omitted_allergy_box_is_unclear_not_no(): void
    {
        $draft = ConsentFormScanner::interpret($this->draft([], [
            'consent_choice' => HealthConsentForm::CONSENT_ALL,
            'allergy_boxes' => [],
            'written' => ['allergy_food' => ['text' => 'Mani', 'confident' => true, 'readable' => true]],
        ]));

        $this->assertSame(ConsentFormScanner::UNCLEAR, $draft['written']['allergy_medicine']['box']);
        $this->assertArrayHasKey('allergy_medicine', $draft['field_notes']);
        // A line with writing on it is an answer whatever its box says.
        $this->assertArrayNotHasKey('allergy_food', $draft['field_notes']);
    }

    // ── The document and the learner ─────────────────────────────────

    /** The model's verdict that this is another document can only refuse. */
    #[Test]
    public function a_document_that_is_not_the_sulat_pahibalo_answers_nothing(): void
    {
        $draft = ConsentFormScanner::interpret($this->draft(['checkup' => ConsentFormScanner::TICKED], [
            'consent_choice' => HealthConsentForm::CONSENT_ALL,
            'is_sulat_pahibalo' => false,
        ]));

        $this->assertTrue($draft['unreadable']);
        $this->assertSame(ConsentFormScanner::UNCLEAR, $draft['consent_choice']);
        $this->assertSame(ConsentFormScanner::UNCLEAR, $draft['services']['checkup']);
        $this->assertNotSame('', $draft['note']);
    }

    /** A parent's name the reader could not make out is said, not left blank. */
    #[Test]
    public function an_unsure_parents_name_is_flagged(): void
    {
        $unreadable = ConsentFormScanner::interpret($this->draft([], [
            'consent_choice' => HealthConsentForm::CONSENT_ALL,
            'parent_guardian_name' => ['text' => '', 'readable' => false, 'confident' => false],
        ]));
        $this->assertSame('', $unreadable['parent_guardian_name']);
        $this->assertArrayHasKey('parent_guardian_name', $unreadable['field_notes']);
        $this->assertTrue($unreadable['needs_review']);

        $unsure = ConsentFormScanner::interpret($this->draft([], [
            'consent_choice' => HealthConsentForm::CONSENT_ALL,
            'parent_guardian_name' => ['text' => 'Josefina Dela Crus', 'readable' => true, 'confident' => false],
        ]));
        $this->assertSame('Josefina Dela Crus', $unsure['parent_guardian_name']);
        $this->assertArrayHasKey('parent_guardian_name', $unsure['field_notes']);
    }

    /** "true" and "false" as strings are read as what they say, never cast. */
    #[Test]
    public function a_signature_reported_as_the_string_false_is_not_a_signature(): void
    {
        $draft = ConsentFormScanner::interpret($this->draft([], [
            'consent_choice' => HealthConsentForm::CONSENT_ALL,
            'signature_present' => 'false',
        ]));

        $this->assertFalse($draft['signature_present']);
        $this->assertTrue($draft['needs_review']);
    }

    #[Test]
    public function the_name_on_the_sheet_is_matched_however_it_is_written(): void
    {
        foreach ([
            'DELA CRUZ, JUAN P.',
            'Juan P. Dela Cruz',
            'juan pablo delacruz',
            'De la Cruz, Juan',
            'DELA CRUS, JUAN',
            'Dela Cruz, Juan Pablo Jr.',
        ] as $read) {
            $this->assertTrue(ConsentFormScanner::namesAgree($read, 'Juan Pablo', 'Dela Cruz'), $read.' names Juan Pablo Dela Cruz');
        }
    }

    /** A sibling's sheet carries the same surname; a classmate's, perhaps the same first name. */
    #[Test]
    public function a_siblings_or_classmates_sheet_does_not_match(): void
    {
        $this->assertFalse(ConsentFormScanner::namesAgree('DELA CRUZ, MARIA', 'Juan', 'Dela Cruz'));
        $this->assertFalse(ConsentFormScanner::namesAgree('SANTOS, JUAN', 'Juan', 'Dela Cruz'));

        // Nothing to compare decides nothing.
        $this->assertNull(ConsentFormScanner::namesAgree('', 'Juan', 'Dela Cruz'));
        $this->assertNull(ConsentFormScanner::namesAgree('Juan Dela Cruz', '', ''));
    }

    /**
     * Another child's sheet proposes no consent: its answers would authorise,
     * or withhold, a procedure on a child whose parent never gave them.
     */
    #[Test]
    public function another_learners_sheet_proposes_no_consent(): void
    {
        $read = fn (array $name) => ConsentFormScanner::interpret($this->draft([], [
            'consent_choice' => HealthConsentForm::CONSENT_ALL,
            'student_name' => $name,
        ]));

        $other = ConsentFormScanner::checkLearner(
            $read(['text' => 'SANTOS, PEDRO', 'readable' => true, 'confident' => true]),
            'Juan', 'Dela Cruz', 'Juan Dela Cruz',
        );
        $this->assertTrue($other['learner_mismatch']);
        $this->assertFalse($other['learner']['matches']);
        $this->assertSame(ConsentFormScanner::UNCLEAR, $other['consent_choice']);
        $this->assertStringContainsString('SANTOS, PEDRO', $other['consent_reason']);
        $this->assertTrue($other['needs_review']);

        $own = ConsentFormScanner::checkLearner(
            $read(['text' => 'DELA CRUZ, JUAN', 'readable' => true, 'confident' => true]),
            'Juan', 'Dela Cruz', 'Juan Dela Cruz',
        );
        $this->assertTrue($own['learner']['matches']);
        $this->assertFalse($own['learner_mismatch']);
        $this->assertSame(HealthConsentForm::CONSENT_ALL, $own['consent_choice']);

        // A name nobody could read decides nothing either way.
        $blank = ConsentFormScanner::checkLearner(
            $read(['text' => '', 'readable' => false, 'confident' => false]),
            'Juan', 'Dela Cruz', 'Juan Dela Cruz',
        );
        $this->assertNull($blank['learner']['matches']);
        $this->assertSame(HealthConsentForm::CONSENT_ALL, $blank['consent_choice']);
    }

    /**
     * The endpoint checks the sheet against the learner the adviser is
     * recording for — and only a learner of their own class.
     */
    #[Test]
    public function the_endpoint_checks_the_sheet_against_the_learner_being_recorded(): void
    {
        $this->learner('130000000001', 'Juan', 'Dela Cruz');
        $this->learner('130000000002', 'Maria', 'Santos', 'Newton');

        $this->fakeGemini($this->draft([], [
            'consent_choice' => HealthConsentForm::CONSENT_ALL,
            'student_name' => ['text' => 'SANTOS, PEDRO', 'readable' => true, 'confident' => true],
        ]));

        $scan = fn (string $lrn) => $this->withSession($this->adviserSession())
            ->post(route('consent-forms.scan'), ['photo' => $this->jpeg(), 'lrn' => $lrn], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('draft');

        $mine = $scan('130000000001');
        $this->assertTrue($mine['learner_mismatch']);
        $this->assertSame(ConsentFormScanner::UNCLEAR, $mine['consent_choice']);
        $this->assertStringContainsString('Juan Dela Cruz', $mine['consent_reason']);

        // Another class's learner is never compared against, so the answer
        // says nothing about them.
        $theirs = $scan('130000000002');
        $this->assertNull($theirs['learner']['matches']);
        $this->assertSame(HealthConsentForm::CONSENT_ALL, $theirs['consent_choice']);
    }
}
