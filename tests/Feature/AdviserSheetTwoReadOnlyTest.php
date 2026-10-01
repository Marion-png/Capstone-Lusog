<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\Sheet2Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sheet 2 — the systems review — belongs to the School Nurse.
 *
 * It is a clinical finding, examined and recorded on the nurse's Fill
 * Medical Record. The class adviser reads it and never writes it: not when
 * editing a learner, and not when enrolling one. The sheet renders locked
 * for every learner, and the server keeps whatever is on file whatever the
 * form posts — a disabled control is only a suggestion, and a stale tab, a
 * replayed form or devtools all reach the endpoint the same way.
 *
 * That also closes a quieter hole. The roster row the browser fills the
 * edit form from is a copy, and it carries no signature image — so every
 * edit used to round-trip a clinical finding through the browser and write
 * back whatever came home.
 *
 * Reviews the adviser recorded before the nurse's form existed are kept and
 * still read everywhere; nothing is migrated away.
 */
class AdviserSheetTwoReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
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
            'school_health_card_records' => [],
        ];
    }

    /** A learner whose systems review is already on file. */
    private function enrolledLearner(): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'student_id' => '800000000001',
            'student_name' => 'Cruz, Juan',
            'school_name' => 'Sta. Ana NHS',
            'grade_level' => 'Grade 10',
            'section' => 'Grade 10 / Dalton',
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'weight' => '40',
            'bmi_value' => '18',
            'nutritional_status' => 'Normal',
            'student_details' => [
                'lrn' => '800000000001',
                'last_name' => 'Cruz',
                'first_name' => 'Juan',
                'birth_year' => 2010, 'birth_month' => 5, 'birth_day' => 14,
                'birthplace' => 'Davao City',
                'gender' => 'Male',
                'parent_guardian' => 'Maria Cruz',
                'address' => '12 Rizal St.',
                'telephone_no' => '09171234567',
                'height_cm' => 150,
                'weight_kg' => 40,
                'grade_level' => 'Grade 10',
                'section' => 'Dalton',
                'systems_review' => [
                    'skin_lesions' => true,
                    'dental_caries' => true,
                    'dental_referral' => true,
                    'notes' => 'Referred to the district dentist.',
                    'examiner_name' => 'Nurse Reyes, RN',
                ],
            ],
        ]);
    }

    /** The fields a valid save needs, so only Sheet 2 is under test. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'last_name' => 'Cruz',
            'first_name' => 'Juan',
            'lrn' => '800000000001',
            'birth_month' => 5, 'birth_day' => 14, 'birth_year' => 2010,
            'birthplace' => 'Davao City',
            'parent_guardian' => 'Maria Cruz',
            'address' => '12 Rizal St.',
            'telephone_no' => '09171234567',
            'gender' => 'Male',
            'height_cm' => 150,
            'weight_kg' => 41,
            'grade_level' => 'Grade 10',
            'section' => 'Dalton',
        ], $overrides);
    }

    private function dashboard(): string
    {
        return $this->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser'))
            ->assertOk()
            ->getContent();
    }

    #[Test]
    public function the_sheet_is_locked_from_the_first_paint_and_says_who_owns_it(): void
    {
        $html = $this->dashboard();

        // Disabled in the markup, not by a script: a page whose JavaScript
        // has not run yet is still not a page the adviser may type Sheet 2 on.
        $this->assertStringContainsString('id="sheet2Fieldset" disabled aria-readonly="true"', $html);
        $this->assertStringContainsString('id="sheet2ReadonlyNote">', $html);
        $this->assertStringContainsString('The systems review is recorded by the School Nurse on Fill Medical Record.', $html);
        $this->assertStringNotContainsString('id="sheet2ReadonlyNote" hidden', $html, 'The notice is not conditional.');
    }

    /** Locked for a new learner exactly as for a saved one. */
    #[Test]
    public function enrolling_a_learner_does_not_unlock_the_sheet(): void
    {
        $html = $this->dashboard();

        $this->assertStringNotContainsString('sheet2.disabled = editing;', $html);
        $this->assertStringNotContainsString('sheet2Note.hidden = !editing;', $html);
        $this->assertStringContainsString('window.setSignaturePadEnabled?.(false);', $html);
    }

    /**
     * The pad is a canvas and the upload area a div — neither is a form
     * control, so the disabled fieldset never reaches them.
     */
    #[Test]
    public function the_signature_pad_is_locked_too(): void
    {
        $html = $this->dashboard();

        $this->assertStringContainsString('window.setSignaturePadEnabled = (enabled) =>', $html);
        $this->assertStringContainsString('if (!padEnabled || !ensureCanvas())', $html);
        $this->assertStringContainsString('if (padEnabled) fileInput?.click();', $html);
        $this->assertStringContainsString('let padEnabled = false;', $html, 'The pad is locked before any script decides.');
    }

    /** Editing a learner leaves the review exactly as it stands. */
    #[Test]
    public function an_edit_keeps_the_stored_systems_review(): void
    {
        $record = $this->enrolledLearner();

        $this->withSession($this->adviserSession())
            ->post(route('adviser.store'), $this->payload())
            ->assertRedirect();

        $review = $record->fresh()->student_details['systems_review'];

        $this->assertTrue((bool) $review['skin_lesions']);
        $this->assertTrue((bool) $review['dental_caries']);
        $this->assertSame('Referred to the district dentist.', $review['notes']);
        $this->assertSame('Nurse Reyes, RN', $review['examiner_name']);
    }

    /**
     * And a posted one is ignored — the sheet is disabled, but the endpoint
     * is what has to refuse.
     */
    #[Test]
    public function a_posted_systems_review_cannot_overwrite_the_stored_one(): void
    {
        $record = $this->enrolledLearner();

        $this->withSession($this->adviserSession())
            ->post(route('adviser.store'), $this->payload([
                'systems_review' => [
                    'skin_normal' => '1',
                    'dental_good' => '1',
                    'notes' => 'Nothing to report.',
                    'examiner_name' => 'Someone Else',
                ],
            ]))
            ->assertRedirect();

        $review = $record->fresh()->student_details['systems_review'];

        // The finding survives…
        $this->assertTrue((bool) $review['skin_lesions']);
        $this->assertTrue((bool) $review['dental_caries']);
        $this->assertSame('Referred to the district dentist.', $review['notes']);
        $this->assertSame('Nurse Reyes, RN', $review['examiner_name']);

        // …and the posted version never lands.
        $this->assertFalse((bool) ($review['skin_normal'] ?? false));
        $this->assertFalse((bool) ($review['dental_good'] ?? false));
    }

    /** The rest of the edit still saves — only Sheet 2 is frozen. */
    #[Test]
    public function the_rest_of_the_form_still_saves_on_an_edit(): void
    {
        $record = $this->enrolledLearner();

        $this->withSession($this->adviserSession())
            ->post(route('adviser.store'), $this->payload([
                'weight_kg' => 44,
                'address' => '99 Bonifacio Ave.',
            ]))
            ->assertRedirect();

        $fresh = $record->fresh();

        $this->assertEqualsWithDelta(44, (float) $fresh->weight, 0.01);
        $this->assertSame('99 Bonifacio Ave.', $fresh->student_details['address']);
    }

    /**
     * A NEW learner still gets a writable Sheet 2. Freezing it everywhere
     * would leave the field with no writer at all.
     */
    /**
     * Enrolling a learner records no systems review either: the adviser
     * enrols, the nurse examines. The learner is still enrolled — the rest of
     * Sheet 1 saves exactly as before — and their review stays empty until the
     * nurse fills it on Fill Medical Record.
     */
    #[Test]
    public function enrolling_a_learner_records_no_systems_review(): void
    {
        $this->withSession($this->adviserSession())
            ->post(route('adviser.store'), $this->payload([
                'lrn' => '800000000009',
                'systems_review' => [
                    'skin_lesions' => '1',
                    'notes' => 'Rash on the left forearm.',
                    'examiner_name' => 'Not the nurse',
                ],
            ]))
            ->assertRedirect();

        $record = StudentHealthRecord::where('student_id', '800000000009')->first();

        $this->assertNotNull($record, 'The learner is enrolled all the same.');
        $review = $record->student_details['systems_review'];

        $this->assertFalse((bool) ($review['skin_lesions'] ?? false));
        $this->assertSame('', (string) ($review['notes'] ?? ''));
        $this->assertSame('', (string) ($review['examiner_name'] ?? ''));
        $this->assertNull($review['examiner_signature'] ?? null, 'The signature is part of Sheet 2 and is the nurse\'s too.');
    }

    /** The signature on file survives an edit, as it always did. */
    #[Test]
    public function the_stored_signature_survives_an_edit(): void
    {
        $record = $this->enrolledLearner();

        $details = $record->student_details;
        $details['systems_review']['examiner_signature'] = 'data:image/png;base64,AAAA';
        $record->forceFill(['student_details' => $details])->save();

        $this->withSession($this->adviserSession())
            ->post(route('adviser.store'), $this->payload())
            ->assertRedirect();

        $this->assertSame(
            'data:image/png;base64,AAAA',
            $record->fresh()->student_details['systems_review']['examiner_signature']
        );
    }

    /** The full-page profile shows the review and offers no way to edit it. */
    #[Test]
    public function the_student_profile_page_only_reads_the_review(): void
    {
        $this->enrolledLearner();

        $html = $this->withSession(array_merge($this->adviserSession(), [
            'school_health_card_records' => [[
                'lrn' => '800000000001',
                'first_name' => 'Juan',
                'last_name' => 'Cruz',
                'grade_level' => 'Grade 10',
                'section' => 'Dalton',
            ]],
        ]))->get(route('dashboard.class-adviser.student-profile', '800000000001'))
            ->assertOk()
            ->getContent();

        $start = strpos($html, 'id="vpTabSheet2"');
        $this->assertNotFalse($start);
        $panel = substr($html, $start, (int) strpos($html, '</div>', $start) - $start);

        $this->assertStringNotContainsString('<input', $panel);
        $this->assertStringNotContainsString('<textarea', $panel);
        $this->assertStringNotContainsString('name="systems_review', $panel);
    }

    /**
     * The nurse fills Sheet 2; the adviser's profile says so.
     *
     * The panel rendered `systems_review` — the adviser's own checklist — and
     * never looked at `examination['sheet2']`, where the nurse's sheet is
     * saved, so a learner the nurse had examined still read "No systems review
     * was recorded for this learner" on the adviser's desk. The standing is
     * derived from the one saved sheet through Sheet2Review, never from a
     * second flag beside it that could drift.
     */
    #[Test]
    public function a_sheet_the_nurse_filled_reads_as_completed_on_the_advisers_profile(): void
    {
        $record = $this->enrolledLearner();

        // The adviser's own checklist is on file, and it is not the nurse's
        // sheet: the tab stays Pending until the nurse has recorded one.
        $meta = $this->profileMeta($record->student_id);
        $this->assertSame('adviser', $meta['sheet2']['source']);
        $this->assertFalse($meta['sheet2']['completed']);
        $this->assertSame('Pending', $meta['sheet2']['status']);
        $this->assertSame('', $meta['sheet2']['examiner']);

        $this->nurseFillsSheetTwo($record);

        $meta = $this->profileMeta($record->student_id);
        $this->assertSame('nurse', $meta['sheet2']['source']);
        $this->assertTrue($meta['sheet2']['completed']);
        $this->assertSame('Completed', $meta['sheet2']['status']);

        // Attributed to whoever examined the learner, on the date they did.
        $this->assertSame('Ana Reyes', $meta['sheet2']['examiner']);
        $this->assertSame('2026-09-15', $meta['sheet2']['date']);
    }

    /** A nurse's correction keeps it completed, and is read on the next load. */
    #[Test]
    public function the_nurses_edit_keeps_it_completed_and_reaches_the_adviser(): void
    {
        $record = $this->enrolledLearner();
        $this->nurseFillsSheetTwo($record);

        $examination = $record->fresh()->examination;
        $examination[Sheet2Review::KEY]['summary']['findings'] = 'Corrected on review';
        $record->update(['examination' => $examination]);

        $meta = $this->profileMeta($record->student_id);
        $this->assertTrue($meta['sheet2']['completed']);
        $this->assertSame('Completed', $meta['sheet2']['status']);
    }

    /** It is display only: the panel renders no control over the nurse's sheet. */
    #[Test]
    public function the_standing_adds_no_way_for_the_adviser_to_write_sheet_two(): void
    {
        $record = $this->enrolledLearner();
        $this->nurseFillsSheetTwo($record);

        $html = $this->flushSession()->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser.student-profile', $record->student_id))
            ->assertOk()
            ->getContent();

        $panel = $this->sheetTwoPanel($html);
        $this->assertStringContainsString('id="vpSheet2Standing"', $panel);
        $this->assertStringNotContainsString('<form', $panel);
        $this->assertStringNotContainsString('name="systems_review', $panel);
        $this->assertStringNotContainsString('<input', $panel);

        // The badge carries the standing rather than a fixed caption.
        $this->assertStringContainsString('id="vpSheet2TabBadge"', $html);
        $this->assertStringNotContainsString('<span class="sp-tab-badge">Systems Review</span>', $html);
    }

    /** A learner in another class is still out of reach, standing or not. */
    #[Test]
    public function another_classes_learner_is_still_out_of_reach(): void
    {
        $record = $this->enrolledLearner();
        $this->nurseFillsSheetTwo($record);

        $session = $this->adviserSession();
        $session['assigned_section'] = 'Rizal';

        $this->flushSession()->withSession($session)
            ->get(route('dashboard.class-adviser.student-profile', $record->student_id))
            ->assertRedirect(route('dashboard.class-adviser', ['tab' => 'saved']));
    }

    /** The nurse's Sheet 2, in the one shape NurseController writes it in. */
    private function nurseFillsSheetTwo(StudentHealthRecord $record): void
    {
        $examination = is_array($record->examination) ? $record->examination : [];
        $examination[Sheet2Review::KEY] = Sheet2Review::fromInput([
            'systems' => ['skin' => ['finding' => 'Normal', 'notes' => '']],
            'vision_result' => 'Pass',
            'hearing_result' => 'Passed Both',
            'summary_findings' => 'Fit for class.',
            'examiner' => 'Ana Reyes',
            'examiner_date' => '2026-09-15',
        ]);

        $record->update(['examination' => $examination]);
    }

    /**
     * The meta payload the adviser's profile renders its Sheet 2 tab from.
     *
     * @return array<string, mixed>
     */
    private function profileMeta(string $lrn): array
    {
        $html = $this->flushSession()->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser.student-profile', $lrn))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            1,
            preg_match('/const STUDENT_PROFILE_META = (.+);$/m', $html, $m),
            'The profile must hand its meta to the browser.'
        );

        $meta = json_decode(trim($m[1]), true);
        $this->assertIsArray($meta);

        return $meta;
    }

    /** The Sheet 2 panel alone, so an assertion cannot catch another tab. */
    private function sheetTwoPanel(string $html): string
    {
        $start = strpos($html, 'id="vpTabSheet2"');
        $this->assertNotFalse($start);
        $end = strpos($html, 'id="vpTabConsent"', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    /**
     * The adviser sees the nurse's sheet, through the one shared renderer.
     *
     * Reporting "Completed" above a panel that still read "No systems review
     * was recorded" is two answers to one question. The nurse's renderer was
     * inline on their own profile and nowhere else, so the only honest fix was
     * to make it one partial both pages read — copying the layout would have
     * given the clinic's sheet a second place to drift.
     */
    #[Test]
    public function the_advisers_panel_renders_the_nurses_sheet_through_the_shared_partial(): void
    {
        $record = $this->enrolledLearner();
        $this->nurseFillsSheetTwo($record);

        $html = $this->flushSession()->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser.student-profile', $record->student_id))
            ->assertOk()
            ->getContent();

        // One renderer, included rather than reimplemented.
        $this->assertStringContainsString('window.Sheet2Render', $html);
        $this->assertSame(
            1,
            substr_count($html, 'const renderSheet2 ='),
            'Sheet 2 is laid out once, in the shared partial.'
        );

        // The panel reaches for the nurse's sheet before the adviser's checklist.
        $this->assertStringContainsString('Sheet2Render.fromExamination(examination)', $html);
        $this->assertStringContainsString('renderSystemsReview(record.systems_review, record.examination)', $html);

        // The clinic's own section captions and grid travel with it.
        $this->assertStringContainsString('F. Evaluation of Body Systems', $html);
        $this->assertStringContainsString('J. Assessment Summary and Recommendations', $html);
        $this->assertStringContainsString('.s2-table', $html, 'The sheet carries its own styling.');
    }

    /**
     * The nurse's profile reads the same one partial — the inline copy is gone,
     * so the two desks cannot show the clinic's sheet two different ways.
     */
    #[Test]
    public function the_nurses_profile_reads_the_same_shared_renderer(): void
    {
        $record = $this->enrolledLearner();
        $this->nurseFillsSheetTwo($record);

        $html = $this->withSession([
            'active_role' => 'school_nurse',
            'active_name' => 'Ana Reyes',
            'active_institution_id' => $this->school->id,
            'active_school_name' => 'Sta. Ana NHS',
        ])->get('/dashboard/student-health-records')->assertOk()->getContent();

        $this->assertStringContainsString('window.Sheet2Render', $html);
        $this->assertSame(1, substr_count($html, 'const renderSheet2 ='));
        $this->assertStringContainsString('Sheet2Render.into(host, sheet)', $html);
    }

    /**
     * A learner the nurse has not examined still shows the adviser's own
     * checklist — those reviews predate the nurse's form and are not migrated
     * away, so the fallback has to keep reading them.
     */
    #[Test]
    public function an_unexamined_learner_still_shows_the_advisers_checklist(): void
    {
        $record = $this->enrolledLearner();

        $html = $this->flushSession()->withSession($this->adviserSession())
            ->get(route('dashboard.class-adviser.student-profile', $record->student_id))
            ->assertOk()
            ->getContent();

        // The fallback path is still wired, and the standing still reads Pending.
        $this->assertStringContainsString('No systems review was recorded for this learner.', $html);

        $meta = $this->profileMeta($record->student_id);
        $this->assertSame('Pending', $meta['sheet2']['status']);
    }
}
