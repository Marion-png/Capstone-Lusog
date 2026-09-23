<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\ClassMasterlistTemplate;
use App\Support\MasterlistSheetScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Enrolling a class from a photographed or scanned CLASS MASTERLIST.
 *
 * The same document the spreadsheet import takes, read by Gemini vision
 * instead. Two things are being guarded here, and they pull in opposite
 * directions on purpose:
 *
 *  - the right document enrols its learners automatically, filling Sheet 1's
 *    name, LRN and sex, through the one enrolment path everything else uses;
 *  - the wrong document enrols nobody at all, and says what it was missing.
 *
 * The provider is faked throughout: no test reaches the network, and the
 * request Gemini would have received is asserted on directly.
 */
class AdviserMasterlistScanTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);

        config()->set('services.gemini.key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.8-flash');
        config()->set('services.gemini.endpoint', 'https://generativelanguage.googleapis.com/v1beta');
    }

    private function adviserSession(array $overrides = []): array
    {
        return array_merge([
            'active_role' => 'class_adviser',
            'active_name' => 'Test Adviser',
            'active_username' => 'adviser1',
            'active_institution_id' => $this->institution->id,
            'active_school_name' => 'Test School',
            'assigned_school_name' => 'Test School',
            'assigned_grade_level' => 'Grade 7',
            'assigned_section' => 'Sampaguita',
        ], $overrides);
    }

    /** The headings a real masterlist carries, as the model would report them. */
    private function document(array $overrides = []): array
    {
        return array_merge([
            'headings' => [
                'Sta. Ana National High School',
                'D. Suazo St., Davao City',
                ClassMasterlistTemplate::TITLE,
                'GRADE 7 - SAMPAGUITA',
                'MASTERLIST',
                'School Year: 2026 - 2027',
            ],
            'name_caption' => ClassMasterlistTemplate::NAME_CAPTION,
            'columns' => ['NO', 'LRN', 'REMARKS'],
            'bands' => ['MALE', 'FEMALE'],
            'seen' => 'A DepEd class masterlist',
        ], $overrides);
    }

    /** One Gemini response, shaped as the structured-output schema pins it. */
    private function fakeScan(array $payload): void
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
     * A stand-in for the photograph.
     *
     * Written rather than drawn: ->image() needs the GD extension, which is not
     * on every machine the suite runs on, and nothing here looks at the pixels.
     * It carries real bytes all the same — the scanner refuses an empty upload,
     * and a fixture that could not tell the difference would not be testing it.
     */
    private function photo(string $name = 'masterlist.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "\xFF\xD8\xFF\xE0".str_repeat('x', 512));
    }

    private function scan(?UploadedFile $file = null, array $session = [])
    {
        return $this->withSession($this->adviserSession($session))
            ->post(route('adviser.import'), ['students_file' => $file ?? $this->photo()]);
    }

    // ── The right document ────────────────────────────────────────────

    /**
     * A photographed masterlist enrols its learners, and Sheet 1 carries the
     * three things the sheet actually holds: name, LRN and sex.
     */
    #[Test]
    public function a_scanned_masterlist_enrols_every_learner_on_it(): void
    {
        $this->fakeScan([
            'document' => $this->document(),
            'is_class_masterlist' => true,
            'learners' => [
                ['last_name' => 'ACALA', 'first_name' => 'ZAIREL', 'middle_name' => 'G.', 'lrn' => '129708190295', 'sex' => 'MALE'],
                ['last_name' => 'ALFORNON', 'first_name' => 'MARIA RAINGIELYN', 'middle_name' => 'A.', 'lrn' => '129697190076', 'sex' => 'FEMALE'],
            ],
            'note' => '',
        ]);

        $this->scan()
            ->assertRedirect(route('dashboard.class-adviser', ['tab' => 'saved']))
            ->assertSessionHas('import_report', fn (array $r) => $r['created'] === 2 && $r['updated'] === 0 && $r['errors'] === []);

        $this->assertSame(2, StudentHealthRecord::count());

        $zairel = StudentHealthRecord::where('student_id', '129708190295')->firstOrFail();
        $this->assertSame('ACALA, ZAIREL G.', $zairel->student_name);
        $this->assertSame('Male', $zairel->student_details['gender']);
        $this->assertSame('Grade 7 / Sampaguita', $zairel->section, "Scoped to the adviser's own class.");
        $this->assertSame($this->institution->id, $zairel->institution_id);

        $maria = StudentHealthRecord::where('student_id', '129697190076')->firstOrFail();
        $this->assertSame('Female', $maria->student_details['gender']);

        // The roster in the session carries both, so My Students lists them.
        $this->assertSame(
            ['129697190076', '129708190295'],
            collect(session('school_health_card_records'))->pluck('lrn')->sort()->values()->all()
        );

        $this->assertTrue(
            AuditLog::query()->where('action', 'imported')->exists(),
            'A scanned enrolment is on the audit trail like any other.'
        );
    }

    /**
     * A masterlist carries no measurement, so nothing is invented for one.
     *
     * The same rule the spreadsheet import keeps: a fabricated weight does not
     * stay in its column — it becomes a BMI, then a status, then a learner the
     * feeding programme qualifies.
     */
    #[Test]
    public function a_scanned_learner_is_stored_unmeasured(): void
    {
        $this->fakeScan([
            'document' => $this->document(),
            'is_class_masterlist' => true,
            'learners' => [
                ['last_name' => 'ACALA', 'first_name' => 'ZAIREL', 'middle_name' => '', 'lrn' => '129708190295', 'sex' => 'MALE'],
            ],
            'note' => '',
        ]);

        $this->scan()->assertSessionHas('import_report');

        $record = StudentHealthRecord::where('student_id', '129708190295')->firstOrFail();
        $this->assertNull($record->baseline_weight_kg);
        $this->assertNull($record->baseline_bmi);
        $this->assertNull($record->baseline_nutritional_status);
    }

    /** The request goes to the configured model, with the sheet inline. */
    #[Test]
    public function the_sheet_is_sent_to_the_configured_gemini_model(): void
    {
        $this->fakeScan([
            'document' => $this->document(),
            'is_class_masterlist' => true,
            'learners' => [],
            'note' => '',
        ]);

        $this->scan();

        Http::assertSent(function (ClientRequest $request): bool {
            $body = $request->data();

            return str_contains($request->url(), 'models/gemini-3.8-flash:generateContent')
                && $request->hasHeader('x-goog-api-key', 'test-key')
                // The key never travels in the URL, which is the part that gets logged.
                && ! str_contains($request->url(), 'test-key')
                && isset($body['contents'][0]['parts'][0]['inline_data']['data'])
                && ($body['generationConfig']['responseMimeType'] ?? null) === 'application/json';
        });
    }

    // ── The wrong document ────────────────────────────────────────────

    /**
     * A document that is not this form enrols nobody and says what was missing.
     *
     * The decision is taken on the headings the model *read*, not on its
     * opinion of them: an SBFP beneficiary list is a masterlist by name, and
     * letting one through would enrol a class's worth of wrong learners.
     */
    #[Test]
    public function a_document_that_is_not_the_masterlist_enrols_nobody(): void
    {
        $this->fakeScan([
            'document' => [
                'headings' => ['Master List of Beneficiaries', 'S.Y. 2026-2027'],
                'name_caption' => 'Name',
                'columns' => ['No.', 'Name', 'Grade', 'Section'],
                'bands' => [],
                'seen' => 'An SBFP master list of beneficiaries',
            ],
            // Even claiming to be one changes nothing: the headings decide.
            'is_class_masterlist' => true,
            'learners' => [
                ['last_name' => 'ACALA', 'first_name' => 'ZAIREL', 'middle_name' => '', 'lrn' => '129708190295', 'sex' => 'MALE'],
            ],
            'note' => '',
        ]);

        $response = $this->scan();

        $response->assertSessionHas('error');
        $response->assertSessionMissing('import_report');

        $error = (string) session('error');
        $this->assertStringContainsString('not a '.ClassMasterlistTemplate::TITLE, $error);
        $this->assertStringContainsString('It is missing', $error);
        $this->assertStringContainsString('An SBFP master list of beneficiaries', $error);

        $this->assertSame(0, StudentHealthRecord::count(), 'Not one row is written from a refused document.');
        $this->assertSame([], session('school_health_card_records', []));
    }

    /**
     * The model's own verdict can refuse, never admit.
     *
     * A sheet whose headings all check out but which the model says is not a
     * masterlist is still refused — two locks on the same door.
     */
    #[Test]
    public function the_models_own_verdict_can_still_refuse_a_matching_sheet(): void
    {
        $this->fakeScan([
            'document' => $this->document(),
            'is_class_masterlist' => false,
            'learners' => [
                ['last_name' => 'ACALA', 'first_name' => 'ZAIREL', 'middle_name' => '', 'lrn' => '129708190295', 'sex' => 'MALE'],
            ],
            'note' => '',
        ]);

        $this->scan()->assertSessionHas('error');

        $this->assertSame(0, StudentHealthRecord::count());
    }

    /** Each missing part of the form is named on its own. */
    #[Test]
    public function the_template_names_what_a_document_is_missing(): void
    {
        $this->assertSame([], ClassMasterlistTemplate::mismatches($this->document()));

        $this->assertSame(
            ['the LRN column'],
            ClassMasterlistTemplate::mismatches($this->document(['columns' => ['NO', 'REMARKS']]))
        );

        $this->assertSame(
            ['the MALE / FEMALE rows'],
            ClassMasterlistTemplate::mismatches($this->document(['bands' => []]))
        );

        // A caption naming only one half of the name cannot divide the three
        // columns under it, which is the same test the spreadsheet reader runs.
        $this->assertSame(
            ['the "'.ClassMasterlistTemplate::NAME_CAPTION.'" column caption'],
            ClassMasterlistTemplate::mismatches($this->document(['name_caption' => "Student's Name"]))
        );

        $this->assertCount(4, ClassMasterlistTemplate::mismatches([]));
    }

    // ── Unreadable is not absent ──────────────────────────────────────

    /**
     * A learner the scan could not read is reported, never guessed at.
     *
     * An LRN is the key of a child's health record: a transposed digit is a new
     * learner nobody notices, so a blank one is handed back to the teacher
     * instead. The rest of the class is still enrolled.
     */
    #[Test]
    public function an_unreadable_learner_is_reported_and_the_rest_are_enrolled(): void
    {
        $this->fakeScan([
            'document' => $this->document(),
            'is_class_masterlist' => true,
            'learners' => [
                ['last_name' => 'ACALA', 'first_name' => 'ZAIREL', 'middle_name' => 'G.', 'lrn' => '129708190295', 'sex' => 'MALE'],
                // The LRN was smudged: left blank rather than completed.
                ['last_name' => 'BASTATAS', 'first_name' => 'KAILE', 'middle_name' => 'F.', 'lrn' => '', 'sex' => 'MALE'],
                // A digit read as a letter is not an identifier.
                ['last_name' => 'DABUAN', 'first_name' => 'DESIREE', 'middle_name' => '', 'lrn' => '12970819O295', 'sex' => 'FEMALE'],
                // A blank numbered line on the form is not a learner at all.
                ['last_name' => '', 'first_name' => '', 'middle_name' => '', 'lrn' => '', 'sex' => 'FEMALE'],
            ],
            'note' => 'The lower half of the sheet is in shadow.',
        ]);

        $this->scan()
            ->assertRedirect(route('dashboard.class-adviser', ['tab' => 'form']))
            ->assertSessionHas('import_report', fn (array $r) => $r['created'] === 1
                && count($r['errors']) === 2
                && $r['total'] === 3);

        $this->assertSame(1, StudentHealthRecord::count());
        $this->assertTrue(StudentHealthRecord::where('student_id', '129708190295')->exists());

        $messages = collect(session('import_report')['errors'])->pluck('message')->implode(' ');
        $this->assertStringContainsString('BASTATAS, KAILE', $messages);
        $this->assertStringContainsString('DABUAN, DESIREE', $messages);
    }

    /** A sheet with nobody readable on it enrols nobody and says so. */
    #[Test]
    public function an_empty_masterlist_enrols_nobody(): void
    {
        $this->fakeScan([
            'document' => $this->document(),
            'is_class_masterlist' => true,
            'learners' => [],
            'note' => 'The photograph is too dark to read names from.',
        ]);

        $this->scan()->assertSessionHas('error');

        $this->assertSame(0, StudentHealthRecord::count());
    }

    // ── When the reader cannot be reached ─────────────────────────────

    /** A provider error is reported, and leaves the roster exactly as it was. */
    #[Test]
    public function a_failed_read_writes_nothing(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => ['message' => 'models/gemini-3.8-flash is not found'],
            ], 404),
        ]);

        $this->scan()->assertSessionHas('error');

        $this->assertSame(0, StudentHealthRecord::count());
        $this->assertStringContainsString('Could not read the masterlist', (string) session('error'));
    }

    /**
     * A busy reader is retried, then said in terms a teacher can act on.
     *
     * Gemini answers 503 "high demand" often enough to be an ordinary Tuesday,
     * and a class of forty learners is too much work to lose to a spike that
     * clears in a second — so the transient statuses are retried before the
     * upload is given up on, and the message that survives says to try again
     * rather than quoting a status code at somebody enrolling a class.
     */
    #[Test]
    public function a_busy_reader_is_retried_and_reported_in_plain_words(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => ['message' => 'This model is currently experiencing high demand.'],
            ], 503),
        ]);

        $this->scan()->assertSessionHas('error');

        $this->assertStringContainsString('busy', (string) session('error'));
        $this->assertStringNotContainsString('503', (string) session('error'));
        $this->assertSame(0, StudentHealthRecord::count());

        // Three attempts in all, then given up on.
        Http::assertSentCount(3);
    }

    /** A wrong model id keeps the provider's own words — it is not transient. */
    #[Test]
    public function a_permanent_failure_keeps_the_providers_message(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => ['message' => 'models/gemini-9-flash is not found'],
            ], 404),
        ]);

        $this->scan()->assertSessionHas('error');

        $this->assertStringContainsString('is not found', (string) session('error'));
        // A 404 is an answer: asking again gets the same one.
        Http::assertSentCount(1);
    }

    /** A refusal that arrives as HTTP 200 is not read as an empty sheet. */
    #[Test]
    public function a_blocked_read_is_not_mistaken_for_an_empty_sheet(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'promptFeedback' => ['blockReason' => 'SAFETY'],
            ], 200),
        ]);

        $this->scan()->assertSessionHas('error');

        $this->assertSame(0, StudentHealthRecord::count());
    }

    /**
     * With no key the feature is simply unavailable.
     *
     * The route refuses regardless of what the page renders — a control that is
     * not drawn is not a guarantee.
     */
    #[Test]
    public function the_scanner_is_not_reached_without_a_key(): void
    {
        config()->set('services.gemini.key', null);
        Http::fake();

        $this->assertFalse(MasterlistSheetScanner::isConfigured());

        $this->scan()->assertSessionHasErrors('students_file');

        Http::assertNothingSent();
        $this->assertSame(0, StudentHealthRecord::count());
    }

    /** Neither a spreadsheet nor a picture: refused before anything is read. */
    #[Test]
    public function a_file_that_is_neither_a_sheet_nor_a_picture_is_refused(): void
    {
        Http::fake();

        $this->withSession($this->adviserSession())
            ->post(route('adviser.import'), [
                'students_file' => UploadedFile::fake()->create('notes.docx', 4, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            ])
            ->assertSessionHasErrors('students_file');

        Http::assertNothingSent();
        $this->assertSame(0, StudentHealthRecord::count());
    }

    /**
     * One picker takes both, so there is one of everything on the panel.
     *
     * A second file input and a second button was two doors into one room —
     * the same masterlist, the same enrolment, chosen by which control the
     * teacher happened to click. The photo formats simply join the accept list.
     */
    #[Test]
    public function the_enrol_panel_offers_one_picker_for_both(): void
    {
        $html = $this->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser', ['tab' => 'form']))
            ->assertOk()
            ->assertSee('name="students_file"', false)
            ->assertSee('spreadsheet, photo or PDF')
            ->assertDontSee('name="masterlist_photo"', false)
            ->assertDontSee('Scan Masterlist')
            ->getContent();

        $this->assertSame(1, substr_count($html, 'name="students_file"'), 'One upload control, not two.');
        $this->assertStringContainsString('.pdf', $html);
    }

    /** With no key the picker takes spreadsheets only, and says so. */
    #[Test]
    public function the_picker_offers_photos_only_when_it_is_configured(): void
    {
        config()->set('services.gemini.key', null);

        $this->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser', ['tab' => 'form']))
            ->assertOk()
            ->assertSee('name="students_file"', false)
            ->assertSee('Choose a .csv or .xlsx file')
            ->assertDontSee('spreadsheet, photo or PDF');
    }

    /**
     * With no key a photograph is refused by the rules, not half-processed.
     *
     * The accept list is narrowed in the view, but a control that is not drawn
     * is not a guarantee — a stale tab or a replayed form reaches the endpoint
     * all the same.
     */
    #[Test]
    public function a_photograph_is_refused_outright_when_scanning_is_off(): void
    {
        config()->set('services.gemini.key', null);
        Http::fake();

        $this->scan()->assertSessionHasErrors('students_file');

        Http::assertNothingSent();
        $this->assertSame(0, StudentHealthRecord::count());
    }

    /** A spreadsheet still goes to the spreadsheet reader, key or no key. */
    #[Test]
    public function a_spreadsheet_never_reaches_the_scanner(): void
    {
        Http::fake();

        $csv = "LRN,Last Name,First Name\r\n100000000001,Dela Cruz,Juan\r\n";

        $this->withSession($this->adviserSession())
            ->post(route('adviser.import'), [
                'students_file' => UploadedFile::fake()->createWithContent('class-list.csv', $csv),
            ])
            ->assertSessionHas('import_report', fn (array $r) => $r['created'] === 1);

        Http::assertNothingSent();
        $this->assertSame(1, StudentHealthRecord::count());
    }
}
