<?php

namespace Tests\Feature;

use App\Http\Controllers\AdviserController;
use App\Models\AuditLog;
use App\Models\HealthConsentForm;
use App\Models\Institution;
use App\Models\MedicalCertificate;
use App\Models\SectionClosure;
use App\Models\StudentHealthRecord;
use App\Support\AdviserClassScope;
use App\Support\ClassMasterlistTemplate;
use App\Support\LearnerPlacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The SIGLA School Year Transition spec, end to end: the outgoing adviser
 * marks a status and stops, the incoming adviser uploads a list and confirms,
 * and SIGLA moves every record in between by LRN — never by name, never by
 * re-encoding it.
 *
 * The test names follow the spec's own test cases (1–14), then its edge
 * cases and access rules.
 *
 * Two school years are used throughout: S.Y. 2026-2027 is closed on
 * 15 March 2027, and S.Y. 2027-2028 begins with the first of June.
 */
class SchoolYearTransitionTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_YEAR = '2026-2027';

    private const NEW_YEAR = '2027-2028';

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['retention.years' => 5]);
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
        $this->duringOldYear();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function duringOldYear(): void
    {
        Carbon::setTestNow('2027-03-15 09:00:00');
    }

    private function duringNewYear(): void
    {
        Carbon::setTestNow('2027-07-05 09:00:00');
    }

    private function adviser(string $grade, string $section, string $name = 'Maria Santos'): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => $name,
            'active_username' => strtolower(str_replace(' ', '.', $name)),
            'active_institution_id' => $this->school->id,
            'active_school_name' => 'Sta. Ana NHS',
            'assigned_school_name' => 'Sta. Ana NHS',
            'assigned_grade_level' => $grade,
            'assigned_section' => $section,
        ];
    }

    private function grade7(): array
    {
        return $this->adviser('Grade 7', 'MATIYAGA', 'Maria Santos');
    }

    private function grade8(): array
    {
        return $this->adviser('Grade 8', 'MASIPAG', 'Jose Reyes');
    }

    private function grade10(): array
    {
        return $this->adviser('Grade 10', 'DALTON', 'Ana Villa');
    }

    private function nurse(string $role = 'school_nurse'): array
    {
        return [
            'active_role' => $role,
            'active_name' => 'Nurse Cruz',
            'active_institution_id' => $this->school->id,
            'active_school_name' => 'Sta. Ana NHS',
        ];
    }

    /** As a fresh visitor: no session carried over from the last request. */
    private function as(array $session): self
    {
        $this->flushSession();

        return $this->withSession($session);
    }

    /** One learner in one section for one school year, with a full profile on file. */
    private function learner(string $lrn, string $section, string $year = self::OLD_YEAR, string $last = 'Dela Cruz', string $first = 'Juan'): StudentHealthRecord
    {
        [$grade, $name] = explode(' / ', $section);

        return StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'school_year' => $year,
            'student_id' => $lrn,
            'student_name' => $last.', '.$first.' R.',
            'school_name' => 'Sta. Ana NHS',
            'section' => $section,
            'weight' => 38.5,
            'bmi_value' => 17.2,
            'nutritional_status' => 'Normal',
            'baseline_weight_kg' => 38.5,
            'student_details' => [
                'last_name' => $last,
                'first_name' => $first,
                'middle_name' => 'Reyes',
                'lrn' => $lrn,
                'birth_year' => 2013,
                'birth_month' => 4,
                'birth_day' => 9,
                'birthplace' => 'Davao City',
                'parent_guardian' => 'Pedro '.$last,
                'address' => '12 Mabini St., Davao City',
                'telephone_no' => '09171234567',
                'gender' => 'Male',
                'grade_level' => $grade,
                'section' => $name,
                'health_history' => ['med_allergies' => true, 'allergies_detail' => 'Peanuts'],
            ],
        ]);
    }

    private function closeYear(array $session, array $statuses, string $year = self::OLD_YEAR)
    {
        return $this->as($session)->post(route('class-adviser.close-year.store'), [
            'school_year' => $year,
            'statuses' => $statuses,
        ]);
    }

    /** A master list headed with the class it is for, as the form heads it. */
    private function masterlist(array $rows, string $classLine): UploadedFile
    {
        $lines = [$classLine, 'LRN,Last Name,First Name,Middle Name'];
        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }

        return UploadedFile::fake()->createWithContent('masterlist.csv', implode("\r\n", $lines)."\r\n");
    }

    /**
     * Upload a master list headed with the uploader's own section and return
     * the preview it produced — nothing is written yet.
     */
    private function upload(array $session, array $rows): array
    {
        $classLine = ClassMasterlistTemplate::classLabel($session['assigned_grade_level'], $session['assigned_section']);

        $this->as($session)->post(route('adviser.import'), ['students_file' => $this->masterlist($rows, $classLine)])
            ->assertRedirect(route('dashboard.class-adviser', ['tab' => 'form']));

        $preview = session(AdviserController::PREVIEW_KEY);
        $this->assertIsArray($preview, 'The upload produced no preview.');

        return $preview;
    }

    private function confirm(array $preview, array $rename = [])
    {
        return $this->post(route('adviser.import.confirm'), ['token' => $preview['token'], 'rename' => $rename]);
    }

    private function row(array $preview, string $lrn): array
    {
        $row = collect($preview['rows'])->firstWhere('lrn', $lrn);
        $this->assertNotNull($row, 'LRN '.$lrn.' is not in the preview.');

        return $row;
    }

    private function rowFor(string $lrn, string $year): ?StudentHealthRecord
    {
        return StudentHealthRecord::query()
            ->where('institution_id', $this->school->id)
            ->where('student_id', $lrn)
            ->where('school_year', $year)
            ->first();
    }

    // ── End of school year ──────────────────────────────────────────────

    /** Spec test 1. */
    #[Test]
    public function closing_the_year_with_everyone_promoted_moves_them_to_waiting_and_closes_the_section(): void
    {
        $this->learner('100000000001', 'Grade 7 / MATIYAGA');
        $this->learner('100000000002', 'Grade 7 / MATIYAGA', last: 'Reyes', first: 'Ana');
        $this->learner('100000000009', 'Grade 7 / MAKISIG', last: 'Other', first: 'Class');

        // Every learner is listed, each defaulting to Promoted, with what it will do.
        $this->as($this->grade7())->get(route('dashboard.class-adviser.close-year'))
            ->assertOk()
            ->assertSee('100000000001')
            ->assertSee('100000000002')
            ->assertDontSee('100000000009')
            ->assertSee('value="promoted" data-outcome="Waiting for Grade 8" selected', false);

        $this->closeYear($this->grade7(), [
            '100000000001' => LearnerPlacement::PROMOTED,
            '100000000002' => LearnerPlacement::PROMOTED,
        ])->assertRedirect(route('dashboard.class-adviser.close-year'))->assertSessionHas('success');

        foreach (['100000000001', '100000000002'] as $lrn) {
            $record = $this->rowFor($lrn, self::OLD_YEAR);
            $this->assertNotNull($record->left_at);
            $this->assertSame(LearnerPlacement::PROMOTED, $record->end_status);
            $this->assertSame('Grade 8', $record->wait_grade);
            $this->assertNull($record->archived_at);
        }

        $this->assertTrue(SectionClosure::query()->where('school_year', self::OLD_YEAR)->where('section', 'MATIYAGA')->exists());
        $this->assertNull($this->rowFor('100000000009', self::OLD_YEAR)->left_at, 'Another section was closed.');

        // Read-only from here: the section shows as closed, its learners are
        // no longer the adviser's, and nobody can be added to it.
        $this->as($this->grade7())->get(route('dashboard.class-adviser.close-year'))
            ->assertOk()
            ->assertSee('id="sectionClosedChip"', false)
            ->assertSee('Waiting for Grade 8')
            ->assertDontSee('id="closeYearForm"', false);

        $this->as($this->grade7())->get(route('dashboard.class-adviser', ['tab' => 'saved']))
            ->assertOk()
            ->assertDontSee('100000000001');

        $this->as($this->grade7())->get(route('dashboard.class-adviser.student-profile', '100000000001'))
            ->assertRedirect()
            ->assertSessionHas('error', AdviserClassScope::NO_LONGER_MESSAGE);

        $this->as($this->grade7())->post(route('adviser.add-to-section'), ['add_lrn' => '100000000555', 'add_last_name' => 'New', 'add_first_name' => 'One'])
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'has been closed'));
        $this->assertNull($this->rowFor('100000000555', self::OLD_YEAR));
    }

    /** Spec test 2. */
    #[Test]
    public function a_learner_transferred_out_at_year_end_is_archived_with_a_deletion_date_five_years_later(): void
    {
        $this->learner('100000000001', 'Grade 7 / MATIYAGA');
        $this->learner('100000000002', 'Grade 7 / MATIYAGA', last: 'Reyes', first: 'Ana');

        $this->closeYear($this->grade7(), [
            '100000000001' => LearnerPlacement::PROMOTED,
            '100000000002' => LearnerPlacement::TRANSFERRED_OUT,
        ]);

        $record = $this->rowFor('100000000002', self::OLD_YEAR);
        $this->assertSame(LearnerPlacement::TRANSFERRED_OUT, $record->end_status);
        $this->assertSame('2027-03-15', $record->archived_at->toDateString());
        $this->assertSame('2032-03-15', $record->delete_after->toDateString());
        $this->assertSame('Transferred out', $record->archive_reason);
        $this->assertNull($record->wait_grade);
    }

    /** Spec test 3 — and Grade 10 is never promoted, since Senior High is outside SIGLA. */
    #[Test]
    public function a_grade_10_completer_is_archived_as_completed_grade_10(): void
    {
        $this->learner('100000000010', 'Grade 10 / DALTON');

        $this->as($this->grade10())->get(route('dashboard.class-adviser.close-year'))
            ->assertOk()
            ->assertSee('value="completed_grade_10"', false)
            ->assertDontSee('value="promoted"', false);

        // Promoted is not offered for Grade 10, and asking for it writes nothing.
        $this->closeYear($this->grade10(), ['100000000010' => LearnerPlacement::PROMOTED])
            ->assertSessionHas('error');
        $this->assertNull($this->rowFor('100000000010', self::OLD_YEAR)->left_at);
        $this->assertFalse(SectionClosure::query()->exists());

        $this->closeYear($this->grade10(), ['100000000010' => LearnerPlacement::COMPLETED_GRADE_10])
            ->assertSessionHas('success');

        $record = $this->rowFor('100000000010', self::OLD_YEAR);
        $this->assertSame(LearnerPlacement::COMPLETED_GRADE_10, $record->end_status);
        $this->assertSame('Completed Grade 10', $record->archive_reason);
        $this->assertNotNull($record->archived_at);
        $this->assertSame('2032-03-15', $record->delete_after->toDateString());
    }

    /** One status missing means nothing is written at all: one transaction. */
    #[Test]
    public function the_year_closes_in_one_transaction_or_not_at_all(): void
    {
        $this->learner('100000000001', 'Grade 7 / MATIYAGA');
        $this->learner('100000000002', 'Grade 7 / MATIYAGA', last: 'Reyes', first: 'Ana');

        $this->closeYear($this->grade7(), ['100000000001' => LearnerPlacement::PROMOTED])
            ->assertSessionHas('error');

        $this->assertNull($this->rowFor('100000000001', self::OLD_YEAR)->left_at);
        $this->assertNull($this->rowFor('100000000002', self::OLD_YEAR)->left_at);
        $this->assertFalse(SectionClosure::query()->exists());
    }

    // ── Start of school year ────────────────────────────────────────────

    /** Spec test 4: matched, and the full record and its history come with the learner. */
    #[Test]
    public function a_promoted_learner_on_the_new_masterlist_is_matched_and_carries_the_record_over(): void
    {
        $this->learner('100000000001', 'Grade 7 / MATIYAGA');
        MedicalCertificate::create([
            'student_lrn' => '100000000001',
            'institution_id' => $this->school->id,
            'file_path' => 'documents/asthma.pdf',
            'file_original_name' => 'asthma.pdf',
            'uploaded_by_name' => 'Maria Santos',
        ]);
        $this->closeYear($this->grade7(), ['100000000001' => LearnerPlacement::PROMOTED]);

        $this->duringNewYear();
        $preview = $this->upload($this->grade8(), [['100000000001', 'Dela Cruz', 'Juan', 'R']]);

        $row = $this->row($preview, '100000000001');
        $this->assertSame(LearnerPlacement::MATCHED, $row['outcome']);
        $this->assertSame('Matched', $row['label']);

        // The preview wrote nothing.
        $this->assertNull($this->rowFor('100000000001', self::NEW_YEAR));

        $this->confirm($preview)
            ->assertRedirect(route('dashboard.class-adviser', ['tab' => 'saved']))
            ->assertSessionHas('import_report', fn (array $r) => $r['created'] === 1 && $r['carried_over'] === 1);

        $carried = $this->rowFor('100000000001', self::NEW_YEAR);
        $this->assertNotNull($carried);
        $this->assertSame('Grade 8 / MASIPAG', $carried->section);
        $this->assertSame(LearnerPlacement::CARRIED_OVER, $carried->joined_as);
        // Nothing re-encoded: the profile and the allergies came with the learner.
        $this->assertSame('Pedro Dela Cruz', $carried->student_details['parent_guardian']);
        $this->assertSame('12 Mabini St., Davao City', $carried->student_details['address']);
        $this->assertSame('Peanuts', $carried->student_details['health_history']['allergies_detail']);
        $this->assertSame('Dela Cruz, Juan R.', $carried->student_name);

        // Last year's row is untouched.
        $old = $this->rowFor('100000000001', self::OLD_YEAR);
        $this->assertSame('Grade 7 / MATIYAGA', $old->section);
        $this->assertSame(LearnerPlacement::PROMOTED, $old->end_status);

        // The full record and its history open in the new section.
        $this->as($this->grade8())->get(route('dashboard.class-adviser.student-profile', '100000000001'))
            ->assertOk()
            ->assertSee('asthma.pdf');

        $years = $this->as($this->grade8())->getJson('/api/student-health-history?lrn=100000000001')->assertOk()->json('years');
        $this->assertSame([self::OLD_YEAR, self::NEW_YEAR], array_column($years, 'school_year'));
    }

    /** Spec test 5. */
    #[Test]
    public function an_lrn_sigla_has_never_seen_creates_a_new_enrollee(): void
    {
        $this->duringNewYear();
        $preview = $this->upload($this->grade8(), [['100000000077', 'Bautista', 'Paolo', 'F']]);

        $this->assertSame(LearnerPlacement::NO_MATCH, $this->row($preview, '100000000077')['outcome']);

        $this->confirm($preview)->assertSessionHas('import_report', fn (array $r) => $r['new_enrollee'] === 1);

        $record = $this->rowFor('100000000077', self::NEW_YEAR);
        $this->assertNotNull($record);
        $this->assertSame(LearnerPlacement::NEW_ENROLLEE, $record->joined_as);
        $this->assertSame('Grade 8 / MASIPAG', $record->section);
    }

    /** Spec test 6. */
    #[Test]
    public function an_archived_learners_lrn_is_restored_and_its_deletion_cleared(): void
    {
        $this->learner('100000000003', 'Grade 7 / MATIYAGA');
        $this->closeYear($this->grade7(), ['100000000003' => LearnerPlacement::DROPPED_OUT]);
        $this->assertNotNull($this->rowFor('100000000003', self::OLD_YEAR)->delete_after);

        $this->duringNewYear();
        $preview = $this->upload($this->grade8(), [['100000000003', 'Dela Cruz', 'Juan', 'R']]);
        $this->assertSame(LearnerPlacement::FOUND_IN_ARCHIVED, $this->row($preview, '100000000003')['outcome']);

        $this->confirm($preview)->assertSessionHas('import_report', fn (array $r) => $r['restored'] === 1);

        $restored = $this->rowFor('100000000003', self::NEW_YEAR);
        $this->assertNotNull($restored);
        $this->assertSame(LearnerPlacement::RESTORED, $restored->joined_as);
        $this->assertTrue(LearnerPlacement::isOpen($restored));

        $old = $this->rowFor('100000000003', self::OLD_YEAR);
        $this->assertNull($old->archived_at);
        $this->assertNull($old->delete_after, 'The deletion was not cancelled.');
        $this->assertSame(LearnerPlacement::DROPPED_OUT, $old->end_status, 'The placement keeps how it ended.');
    }

    /** Spec test 7: the message names the grade the previous adviser chose. */
    #[Test]
    public function a_learner_retained_in_a_lower_grade_needs_checking_and_is_not_added(): void
    {
        $this->learner('100000000004', 'Grade 7 / MATIYAGA');
        $this->closeYear($this->grade7(), ['100000000004' => LearnerPlacement::RETAINED]);

        $this->duringNewYear();
        $preview = $this->upload($this->grade8(), [['100000000004', 'Dela Cruz', 'Juan', 'R']]);

        $row = $this->row($preview, '100000000004');
        $this->assertSame(LearnerPlacement::NEEDS_CHECKING, $row['outcome']);
        $this->assertStringContainsString('Grade 7', $row['message']);

        $this->confirm($preview)->assertSessionHas('import_report', fn (array $r) => $r['created'] === 0 && count($r['errors']) === 1);
        $this->assertNull($this->rowFor('100000000004', self::NEW_YEAR));

        // The Grade 7 section of the new year is where they match.
        $preview = $this->upload($this->adviser('Grade 7', 'MATIYAGA'), [['100000000004', 'Dela Cruz', 'Juan', 'R']]);
        $this->assertSame(LearnerPlacement::MATCHED, $this->row($preview, '100000000004')['outcome']);
    }

    /** Spec test 8. */
    #[Test]
    public function a_learner_whose_old_section_is_still_open_needs_checking_and_is_not_added(): void
    {
        $this->learner('100000000005', 'Grade 7 / MATIYAGA');

        $this->duringNewYear();
        $preview = $this->upload($this->grade8(), [['100000000005', 'Dela Cruz', 'Juan', 'R']]);

        $row = $this->row($preview, '100000000005');
        $this->assertSame(LearnerPlacement::NEEDS_CHECKING, $row['outcome']);
        $this->assertStringContainsString('has not closed the school year', $row['message']);

        $this->confirm($preview);
        $this->assertNull($this->rowFor('100000000005', self::NEW_YEAR));
    }

    /** Spec test 9: the same checks, one LRN at a time. */
    #[Test]
    public function adding_a_missing_learner_by_lrn_gives_the_same_result_as_the_masterlist_row(): void
    {
        $this->learner('100000000001', 'Grade 7 / MATIYAGA');
        $this->learner('100000000004', 'Grade 7 / MATIYAGA', last: 'Reyes', first: 'Ana');
        $this->closeYear($this->grade7(), [
            '100000000001' => LearnerPlacement::PROMOTED,
            '100000000004' => LearnerPlacement::RETAINED,
        ]);

        $this->duringNewYear();

        // Matched: carried over, no name needed.
        $this->as($this->grade8())->post(route('adviser.add-to-section'), ['add_lrn' => '100000000001'])
            ->assertSessionHas('success');
        $this->assertSame(LearnerPlacement::CARRIED_OVER, $this->rowFor('100000000001', self::NEW_YEAR)?->joined_as);

        // Needs checking: not added.
        $this->as($this->grade8())->post(route('adviser.add-to-section'), ['add_lrn' => '100000000004'])
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'Grade 7'));
        $this->assertNull($this->rowFor('100000000004', self::NEW_YEAR));

        // No match: the name is required to create a new enrollee.
        $this->as($this->grade8())->post(route('adviser.add-to-section'), ['add_lrn' => '100000000088'])
            ->assertSessionHasErrors('add_last_name');
        $this->assertNull($this->rowFor('100000000088', self::NEW_YEAR));

        $this->as($this->grade8())->post(route('adviser.add-to-section'), [
            'add_lrn' => '100000000088', 'add_last_name' => 'Lim', 'add_first_name' => 'Grace',
        ])->assertSessionHas('success');
        $this->assertSame(LearnerPlacement::NEW_ENROLLEE, $this->rowFor('100000000088', self::NEW_YEAR)?->joined_as);

        // An LRN is twelve digits.
        $this->as($this->grade8())->post(route('adviser.add-to-section'), ['add_lrn' => '12345'])
            ->assertSessionHasErrors('add_lrn');
    }

    /**
     * A learner carried into Grade 8 and then typed again on Enroll Student is
     * reported as already enrolled — by LRN or by name — and their record is
     * not touched. Edit Profile still updates it.
     */
    #[Test]
    public function the_enrol_form_says_a_carried_over_learner_is_already_enrolled(): void
    {
        $this->learner('100000000001', 'Grade 7 / MATIYAGA');
        $this->closeYear($this->grade7(), ['100000000001' => LearnerPlacement::PROMOTED]);

        $this->duringNewYear();
        $this->as($this->grade8())->post(route('adviser.add-to-section'), ['add_lrn' => '100000000001'])
            ->assertSessionHas('success');
        $address = $this->rowFor('100000000001', self::NEW_YEAR)->student_details['address'];

        $form = [
            'form_mode' => 'enrol',
            'last_name' => 'Dela Cruz', 'first_name' => 'Juan', 'middle_name' => 'Reyes', 'lrn' => '100000000001',
            'birth_month' => 4, 'birth_day' => 9, 'birth_year' => 2013,
            'birthplace' => 'Davao City', 'parent_guardian' => 'Pedro Dela Cruz', 'address' => '99 Changed St.',
            'telephone_no' => '09171234567', 'gender' => 'Male',
            'height_cm' => 150, 'weight_kg' => 40, 'grade_level' => 'Grade 8', 'section' => 'MASIPAG',
        ];
        $alreadyEnrolled = fn (string $m) => str_contains($m, 'Dela Cruz, Juan R.') && str_contains($m, 'already enrolled in your section');

        // The same LRN.
        $this->as($this->grade8())->post(route('adviser.store'), $form)
            ->assertSessionHas('error', $alreadyEnrolled);
        $this->assertSame($address, $this->rowFor('100000000001', self::NEW_YEAR)->student_details['address']);

        // The same name under another LRN.
        $this->as($this->grade8())->post(route('adviser.store'), ['lrn' => '100000000077'] + $form)
            ->assertSessionHas('error', $alreadyEnrolled);
        $this->assertNull($this->rowFor('100000000077', self::NEW_YEAR));

        // Edit Profile posts the same form and updates the record.
        $this->as($this->grade8())->post(route('adviser.store'), ['form_mode' => 'edit'] + $form)
            ->assertSessionHas('success', 'Student record updated.');
        $this->assertSame('99 Changed St.', $this->rowFor('100000000001', self::NEW_YEAR)->student_details['address']);
        $this->assertSame(1, StudentHealthRecord::where('student_id', '100000000001')->where('school_year', self::NEW_YEAR)->count());
    }

    // ── Access ──────────────────────────────────────────────────────────

    /** Spec test 10: "No access", and not the learner's new section either. */
    #[Test]
    public function a_previous_adviser_looking_up_a_learner_who_moved_gets_no_access(): void
    {
        $this->learner('100000000001', 'Grade 7 / MATIYAGA');
        $this->closeYear($this->grade7(), ['100000000001' => LearnerPlacement::PROMOTED]);

        $this->duringNewYear();
        $this->confirm($this->upload($this->grade8(), [['100000000001', 'Dela Cruz', 'Juan', 'R']]));

        $this->as($this->grade7())->get(route('dashboard.class-adviser.student-profile', '100000000001'))
            ->assertRedirect(route('dashboard.class-adviser', ['tab' => 'saved']))
            ->assertSessionHas('error', AdviserClassScope::NO_LONGER_MESSAGE);

        $this->as($this->grade7())->followingRedirects()
            ->get(route('dashboard.class-adviser.student-profile', '100000000001'))
            ->assertSee(AdviserClassScope::NO_LONGER_MESSAGE)
            ->assertDontSee('MASIPAG');

        // Not last year's sheet either: the record follows the learner.
        $this->as($this->grade7())->get(route('student-mlat.print', [
            'lrn' => '100000000001', 'sheet' => 1, 'view' => 1, 'school_year' => self::OLD_YEAR,
        ]))->assertForbidden();

        // The new adviser reads both years.
        $this->as($this->grade8())->get(route('student-mlat.print', [
            'lrn' => '100000000001', 'sheet' => 1, 'view' => 1, 'school_year' => self::OLD_YEAR,
        ]))->assertOk()->assertSee('id="mlatViewPrint"', false);
    }

    /** Spec test 11. */
    #[Test]
    public function marking_a_learner_as_left_mid_year_archives_them_into_the_advisers_archived_tab(): void
    {
        Carbon::setTestNow('2026-11-03 10:00:00');
        $this->learner('100000000006', 'Grade 7 / MATIYAGA');
        $this->learner('100000000007', 'Grade 7 / MATIYAGA', last: 'Reyes', first: 'Ana');

        $this->as($this->grade7())->post(route('class-adviser.student.left', '100000000006'), ['reason' => LearnerPlacement::TRANSFERRED_OUT])
            ->assertRedirect(route('dashboard.class-adviser.archived'))
            ->assertSessionHas('success');

        $record = $this->rowFor('100000000006', self::OLD_YEAR);
        $this->assertSame('2026-11-03', $record->archived_at->toDateString());
        $this->assertSame('2031-11-03', $record->delete_after->toDateString());
        $this->assertNotNull($record->left_at);

        // Not a reason the school can mark mid-year.
        $this->as($this->grade7())->post(route('class-adviser.student.left', '100000000007'), ['reason' => LearnerPlacement::PROMOTED])
            ->assertSessionHasErrors('reason');
        $this->assertNull($this->rowFor('100000000007', self::OLD_YEAR)->archived_at);

        // The archived table, row by row — the topbar's search still carries
        // the adviser's own active learners.
        $this->as($this->grade7())->get(route('dashboard.class-adviser.archived'))
            ->assertOk()
            ->assertSee('<td><strong>100000000006</strong></td>', false)
            ->assertSee('3 Nov 2031')
            ->assertDontSee('<td><strong>100000000007</strong></td>', false);

        $this->as($this->grade7())->get(route('dashboard.class-adviser', ['tab' => 'saved']))
            ->assertOk()
            ->assertDontSee('100000000006');

        // Another section's adviser does not see them in their Archived tab.
        $this->as($this->grade8())->get(route('dashboard.class-adviser.archived'))
            ->assertOk()
            ->assertDontSee('100000000006');

        // The nurse sees every archived record.
        $this->as($this->nurse())->get(route('dashboard.school-nurse.archived'))
            ->assertOk()
            ->assertSee('100000000006')
            ->assertSee('3 Nov 2031');
    }

    /** Spec test 12. */
    #[Test]
    public function restoring_an_archived_learner_puts_them_back_and_cancels_the_deletion(): void
    {
        Carbon::setTestNow('2026-11-03 10:00:00');
        $this->learner('100000000006', 'Grade 7 / MATIYAGA');
        $this->as($this->grade7())->post(route('class-adviser.student.left', '100000000006'), ['reason' => LearnerPlacement::DROPPED_OUT]);

        Carbon::setTestNow('2027-01-10 10:00:00');
        $this->as($this->grade7())->post(route('class-adviser.archived.restore', '100000000006'))
            ->assertRedirect(route('dashboard.class-adviser', ['tab' => 'saved']))
            ->assertSessionHas('success');

        $record = $this->rowFor('100000000006', self::OLD_YEAR);
        $this->assertTrue(LearnerPlacement::isOpen($record));
        $this->assertNull($record->delete_after);
        $this->assertSame(LearnerPlacement::RESTORED, $record->joined_as);
        $this->assertSame(1, StudentHealthRecord::where('student_id', '100000000006')->count(), 'A same-year restore reopens the same row.');

        $this->as($this->grade7())->get(route('dashboard.class-adviser', ['tab' => 'saved']))
            ->assertOk()
            ->assertSee('100000000006');

        // Restoring twice is not possible: the learner is no longer archived.
        $this->as($this->grade7())->post(route('class-adviser.archived.restore', '100000000006'))
            ->assertSessionHas('error');
    }

    /** Spec test 13. */
    #[Test]
    public function the_deletion_job_removes_an_archived_learner_past_delete_after_with_every_file(): void
    {
        Carbon::setTestNow('2026-11-03 10:00:00');
        $this->learner('100000000006', 'Grade 7 / MATIYAGA');
        Storage::disk('local')->put('documents/cert.pdf', 'bytes');
        MedicalCertificate::create([
            'student_lrn' => '100000000006',
            'institution_id' => $this->school->id,
            'file_path' => 'documents/cert.pdf',
            'file_original_name' => 'cert.pdf',
            'uploaded_by_name' => 'Maria Santos',
        ]);
        $this->as($this->grade7())->post(route('class-adviser.student.left', '100000000006'), ['reason' => LearnerPlacement::TRANSFERRED_OUT]);

        // The day before, nothing happens.
        Carbon::setTestNow('2031-11-02 02:30:00');
        $this->artisan('students:purge-expired')->assertExitCode(0);
        $this->assertSame(1, StudentHealthRecord::where('student_id', '100000000006')->count());

        $before = AuditLog::query()->max('id') ?? 0;

        Carbon::setTestNow('2031-11-03 02:30:00');
        $this->artisan('students:purge-expired')->assertExitCode(0);

        $this->assertSame(0, StudentHealthRecord::where('student_id', '100000000006')->count());
        $this->assertSame(0, MedicalCertificate::count());
        Storage::disk('local')->assertMissing('documents/cert.pdf');

        $trail = AuditLog::query()->where('id', '>', $before)->get()
            ->map(fn (AuditLog $log) => $log->description.json_encode($log->details))
            ->implode("\n");
        $this->assertStringContainsString('2031-11-03', $trail);
        $this->assertStringNotContainsString('100000000006', $trail);
        $this->assertStringNotContainsString('Dela Cruz', $trail);
    }

    /** Spec test 14. */
    #[Test]
    public function consent_in_the_new_year_starts_at_no_form_yet_and_last_years_form_opens_read_only(): void
    {
        $this->learner('100000000001', 'Grade 7 / MATIYAGA');
        $form = HealthConsentForm::create([
            'institution_id' => $this->school->id,
            'student_lrn' => '100000000001',
            'school_year' => self::OLD_YEAR,
            'student_name' => 'Dela Cruz, Juan R.',
            'grade_level' => 'Grade 7',
            'section' => 'MATIYAGA',
            'division' => HealthConsentForm::DEFAULT_DIVISION,
            'school_name' => 'Sta. Ana NHS',
            'school_address' => HealthConsentForm::DEFAULT_SCHOOL_ADDRESS,
            'status' => HealthConsentForm::STATUS_SIGNED,
            'consent_choice' => 'all',
            'services' => ['deworming'],
            'signed_at' => now(),
        ]);
        $this->closeYear($this->grade7(), ['100000000001' => LearnerPlacement::PROMOTED]);

        $this->duringNewYear();
        $this->confirm($this->upload($this->grade8(), [['100000000001', 'Dela Cruz', 'Juan', 'R']]));

        // This year: no form yet.
        $this->as($this->grade8())->get(route('consent-forms.index'))
            ->assertOk()
            ->assertSee('100000000001')
            ->assertSee('No form yet');

        // Last year's form came with the learner, read only.
        $this->as($this->grade8())->get(route('consent-forms.index', ['school_year' => self::OLD_YEAR]))
            ->assertOk()
            ->assertSee('100000000001')
            ->assertSee('data-status="approved"', false)
            ->assertSee('id="pastYearNotice"', false);

        $this->as($this->grade8())->get(route('consent-forms.show', $form))->assertOk();

        // And it left the adviser the learner moved on from.
        $this->as($this->grade7())->get(route('consent-forms.index', ['school_year' => self::OLD_YEAR]))
            ->assertOk()
            ->assertDontSee('100000000001');
        $this->as($this->grade7())->get(route('consent-forms.show', $form))->assertForbidden();
    }

    // ── Edge cases ──────────────────────────────────────────────────────

    /** Match on LRN, show both names, keep SIGLA's until the adviser confirms the change. */
    #[Test]
    public function a_name_that_differs_on_the_list_keeps_siglas_until_the_adviser_confirms(): void
    {
        $this->learner('100000000001', 'Grade 7 / MATIYAGA');
        $this->learner('100000000002', 'Grade 7 / MATIYAGA', last: 'Reyes', first: 'Ana');
        $this->closeYear($this->grade7(), [
            '100000000001' => LearnerPlacement::PROMOTED,
            '100000000002' => LearnerPlacement::PROMOTED,
        ]);

        $this->duringNewYear();
        $preview = $this->upload($this->grade8(), [
            ['100000000001', 'Dela Cruz', 'Juanito', 'R'],
            ['100000000002', 'Reyes-Santos', 'Ana', 'R'],
        ]);

        $juan = $this->row($preview, '100000000001');
        $this->assertSame(LearnerPlacement::MATCHED, $juan['outcome'], 'Matched on the LRN, whatever the name says.');
        $this->assertTrue($juan['name_differs']);
        $this->assertSame('Dela Cruz, Juan R.', $juan['sigla_name']);

        // The preview waits in the adviser's session, both names side by side.
        $this->get(route('dashboard.class-adviser', ['tab' => 'form']))
            ->assertOk()
            ->assertSee('In SIGLA: Dela Cruz, Juan R.')
            ->assertSee('name="rename[]" value="100000000001"', false);

        // Only Ana's change is confirmed.
        $this->confirm($preview, ['100000000002']);

        $this->assertSame('Juan', $this->rowFor('100000000001', self::NEW_YEAR)->student_details['first_name']);
        $this->assertSame('Reyes-Santos', $this->rowFor('100000000002', self::NEW_YEAR)->student_details['last_name']);
        // Last year keeps the name it had.
        $this->assertSame('Reyes', $this->rowFor('100000000002', self::OLD_YEAR)->student_details['last_name']);
    }

    /** A duplicate LRN and an LRN that is not 12 digits are rejected and named. */
    #[Test]
    public function duplicate_and_malformed_lrns_are_rejected_and_named_in_the_preview(): void
    {
        $this->duringNewYear();
        $preview = $this->upload($this->grade8(), [
            ['100000000031', 'Lim', 'Grace', ''],
            ['100000000031', 'Lim', 'Grace', ''],
            ['12345', 'Short', 'Number', ''],
            ['', 'No', 'Lrn', ''],
        ]);

        // Line 1 is the class line, line 2 the header.
        $rows = collect($preview['rows'])->keyBy('line');
        $this->assertSame(LearnerPlacement::NO_MATCH, $rows[3]['outcome']);
        $this->assertSame(LearnerPlacement::REJECTED, $rows[4]['outcome']);
        $this->assertStringContainsString('100000000031 appears more than once', $rows[4]['message']);
        $this->assertSame(LearnerPlacement::REJECTED, $rows[5]['outcome']);
        $this->assertStringContainsString('12345 is not 12 digits', $rows[5]['message']);
        $this->assertSame(LearnerPlacement::REJECTED, $rows[6]['outcome']);
        $this->assertStringContainsString('LRN is missing', $rows[6]['message']);

        $this->confirm($preview)->assertSessionHas('import_report', fn (array $r) => $r['created'] === 1
            && array_column($r['errors'], 'line') === [4, 5, 6]);

        $this->assertSame(1, StudentHealthRecord::count());
    }

    /** A wrong status can be corrected until the learner is placed; after that, it is the school's. */
    #[Test]
    public function a_wrong_end_of_year_status_can_be_corrected_until_the_learner_is_placed(): void
    {
        $this->learner('100000000001', 'Grade 7 / MATIYAGA');
        $this->closeYear($this->grade7(), ['100000000001' => LearnerPlacement::PROMOTED]);
        $record = $this->rowFor('100000000001', self::OLD_YEAR);

        // Promoted → Transferred out: archived, with a deletion date.
        $this->as($this->grade7())->post(route('class-adviser.close-year.correct', $record), ['status' => LearnerPlacement::TRANSFERRED_OUT])
            ->assertSessionHas('success');
        $record->refresh();
        $this->assertNotNull($record->archived_at);
        $this->assertNull($record->wait_grade);

        // → Retained: waiting again, and the deletion is gone.
        $this->as($this->grade7())->post(route('class-adviser.close-year.correct', $record), ['status' => LearnerPlacement::RETAINED])
            ->assertSessionHas('success');
        $record->refresh();
        $this->assertNull($record->archived_at);
        $this->assertNull($record->delete_after);
        $this->assertSame('Grade 7', $record->wait_grade);

        // After the year flips, the closed section is still on the page to correct.
        $this->duringNewYear();
        $this->as($this->grade7())->get(route('dashboard.class-adviser.close-year'))
            ->assertOk()
            ->assertSee('S.Y. '.self::OLD_YEAR)
            ->assertSee(route('class-adviser.close-year.correct', $record), false);

        // Once placed, the adviser can no longer change it.
        $this->confirm($this->upload($this->adviser('Grade 7', 'MATIYAGA'), [['100000000001', 'Dela Cruz', 'Juan', 'R']]));
        $this->assertNotNull($this->rowFor('100000000001', self::NEW_YEAR));

        $this->as($this->grade7())->post(route('class-adviser.close-year.correct', $record), ['status' => LearnerPlacement::PROMOTED])
            ->assertSessionHas('error');
        $this->assertSame(LearnerPlacement::RETAINED, $record->fresh()->end_status);
    }

    /** A learner waiting from this school year is placed once the next one begins. */
    #[Test]
    public function a_waiting_learner_is_not_placed_before_the_next_school_year_begins(): void
    {
        $this->learner('100000000001', 'Grade 7 / MATIYAGA');
        $this->closeYear($this->grade7(), ['100000000001' => LearnerPlacement::PROMOTED]);

        $preview = $this->upload($this->grade8(), [['100000000001', 'Dela Cruz', 'Juan', 'R']]);
        $this->assertSame(LearnerPlacement::NEEDS_CHECKING, $this->row($preview, '100000000001')['outcome']);

        $this->confirm($preview);
        $this->assertSame(1, StudentHealthRecord::where('student_id', '100000000001')->count());
    }

    /** Nothing is written until Confirm, and a stale preview is never confirmed. */
    #[Test]
    public function nothing_is_written_until_the_preview_is_confirmed(): void
    {
        $this->duringNewYear();
        $preview = $this->upload($this->grade8(), [['100000000041', 'Lim', 'Grace', '']]);

        $this->assertSame(0, StudentHealthRecord::count());

        $this->post(route('adviser.import.confirm'), ['token' => 'not-the-token'])
            ->assertSessionHas('error');
        $this->assertSame(0, StudentHealthRecord::count());

        // Cancelling clears it; the token no longer confirms anything.
        $this->post(route('adviser.import.cancel'))->assertRedirect();
        $this->confirm($preview)->assertSessionHas('error');
        $this->assertSame(0, StudentHealthRecord::count());
    }

    /**
     * A master list is taken only by the adviser of the section it names.
     *
     * A promoted learner waits for a grade, not for a section — so without
     * this the adviser of one Grade 8 section uploading another's list would
     * carry every learner on it into the wrong class.
     */
    #[Test]
    public function another_sections_masterlist_is_refused_and_nobody_is_carried_over(): void
    {
        $this->learner('100000000001', 'Grade 7 / MATIYAGA');
        $this->closeYear($this->grade7(), ['100000000001' => LearnerPlacement::PROMOTED]);

        $this->duringNewYear();
        $makisig = $this->adviser('Grade 8', 'MAKISIG', 'Lea Cruz');

        $this->as($makisig)->post(route('adviser.import'), [
            'students_file' => $this->masterlist([['100000000001', 'Dela Cruz', 'Juan', 'R']], 'GRADE 8 - MASIPAG'),
        ])->assertRedirect(route('dashboard.class-adviser', ['tab' => 'form']))
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'This masterlist is for GRADE 8 - MASIPAG')
                && str_contains($message, 'GRADE 8 - MAKISIG'));

        $this->assertNull(session(AdviserController::PREVIEW_KEY), 'A refused master list was previewed.');
        $this->assertNull($this->rowFor('100000000001', self::NEW_YEAR), 'The learner was carried into the wrong section.');

        // The section the list names takes it.
        $this->confirm($this->upload($this->grade8(), [['100000000001', 'Dela Cruz', 'Juan', 'R']]));
        $this->assertSame('Grade 8 / MASIPAG', $this->rowFor('100000000001', self::NEW_YEAR)?->section);
    }

    /** A learner already in another section this year is not moved: that transfer is not defined yet. */
    #[Test]
    public function a_learner_in_another_section_this_year_is_never_moved(): void
    {
        $this->duringNewYear();
        $this->learner('100000000051', 'Grade 8 / MAKISIG', self::NEW_YEAR);

        $preview = $this->upload($this->grade8(), [['100000000051', 'Dela Cruz', 'Juan', 'R']]);
        $row = $this->row($preview, '100000000051');
        $this->assertSame(LearnerPlacement::NEEDS_CHECKING, $row['outcome']);
        $this->assertStringNotContainsString('MAKISIG', $row['message'], 'Another adviser\'s section was named.');

        $this->confirm($preview);
        $this->assertSame('Grade 8 / MAKISIG', $this->rowFor('100000000051', self::NEW_YEAR)->section);
    }

    // ── Who reads the learners between sections ─────────────────────────

    #[Test]
    public function the_nurse_and_the_clinic_teacher_read_waiting_and_archived_learners(): void
    {
        $this->learner('100000000001', 'Grade 7 / MATIYAGA');
        $this->learner('100000000002', 'Grade 7 / MATIYAGA', last: 'Reyes', first: 'Ana');
        $this->closeYear($this->grade7(), [
            '100000000001' => LearnerPlacement::PROMOTED,
            '100000000002' => LearnerPlacement::TRANSFERRED_OUT,
        ]);

        foreach (['school_nurse' => 'dashboard.school-nurse.archived', 'clinic_teacher' => 'dashboard.clinic-teacher.archived'] as $role => $route) {
            $this->as($this->nurse($role))->get(route($route))
                ->assertOk()
                ->assertSee('100000000001')
                ->assertSee('Grade 8')
                ->assertSee('100000000002')
                ->assertSee('15 Mar 2032');
        }

        // The nurse opens the record read-only, for the year it is filed under.
        $this->as($this->nurse())->get(route('student-mlat.print', [
            'lrn' => '100000000002', 'sheet' => 1, 'view' => 1, 'school_year' => self::OLD_YEAR,
        ]))->assertOk();

        // Clinic staff have no list here, and the School Head closes nothing.
        $this->as($this->nurse('clinic_staff'))->get(route('dashboard.school-nurse.archived'))->assertRedirect();
        $this->as(['active_role' => 'school_head', 'active_name' => 'Head', 'active_username' => 'head', 'active_institution_id' => $this->school->id])
            ->post(route('class-adviser.close-year.store'), ['school_year' => self::OLD_YEAR, 'statuses' => []])
            ->assertForbidden();
    }

    /** Access is checked against where the learner stands on every request. */
    #[Test]
    public function an_adviser_cannot_change_a_learner_who_has_left_their_section(): void
    {
        Carbon::setTestNow('2026-11-03 10:00:00');
        $this->learner('100000000006', 'Grade 7 / MATIYAGA');
        $this->as($this->grade7())->post(route('class-adviser.student.left', '100000000006'), ['reason' => LearnerPlacement::TRANSFERRED_OUT]);

        // The edit form refuses, the record says why, and it was not changed.
        $this->as($this->grade7())->post(route('adviser.store'), [
            'last_name' => 'Hijacked', 'first_name' => 'Juan', 'lrn' => '100000000006',
            'birth_month' => 1, 'birth_day' => 1, 'birth_year' => 2013,
            'birthplace' => 'Davao', 'parent_guardian' => 'X', 'address' => 'Y', 'telephone_no' => '0917',
            'height_cm' => 140, 'weight_kg' => 35, 'grade_level' => 'Grade 7', 'section' => 'MATIYAGA',
        ])->assertSessionHas('error', fn (string $m) => str_contains($m, 'archived'));
        $this->assertSame('Dela Cruz', $this->rowFor('100000000006', self::OLD_YEAR)->student_details['last_name']);

        // The archived record opens read-only for the adviser it left.
        $this->as($this->grade7())->get(route('dashboard.class-adviser.student-profile', '100000000006'))
            ->assertRedirect(route('dashboard.class-adviser.archived'));
        $this->as($this->grade7())->get(route('student-mlat.print', [
            'lrn' => '100000000006', 'sheet' => 1, 'view' => 1, 'school_year' => self::OLD_YEAR,
        ]))->assertOk();

        // Marking them as left again is refused: they are not in the section.
        $this->as($this->grade7())->post(route('class-adviser.student.left', '100000000006'), ['reason' => LearnerPlacement::DROPPED_OUT])
            ->assertSessionHas('error');
        $this->assertSame(LearnerPlacement::TRANSFERRED_OUT, $this->rowFor('100000000006', self::OLD_YEAR)->end_status);
    }
}
