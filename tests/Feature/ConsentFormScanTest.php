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
}
