<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Models\StudentIncidentReport;
use App\Support\SchemaCache;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The tester's three asks for Incident Reports.
 *
 * 1. **A revised physical examination area.** The objective half of F-DAR's
 *    Data is charted field by field — vital signs, consciousness, pain, the
 *    body part affected, findings — instead of somewhere inside one paragraph.
 * 2. **AI correction of grammar and terms**, so the clinic needs no paid
 *    writing assistant. It proposes; the nurse decides; nothing is saved by
 *    it, no figure is changed by it and no name leaves the school through it.
 * 3. **A printable Incident Report template signed by the Clinic Nurse, the
 *    Class Adviser, the Curriculum Head and the School Principal.**
 *
 * Names and LRNs here are invented.
 */
class IncidentReportFormTest extends TestCase
{
    use RefreshDatabase;

    private const LRN = '900000000051';

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function nurseSession(array $overrides = []): array
    {
        return array_merge([
            'active_role' => 'school_nurse',
            'active_name' => 'Nurse Liza Bautista',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
        ], $overrides);
    }

    private function adviserSession(array $overrides = []): array
    {
        return array_merge([
            'active_role' => 'class_adviser',
            'active_name' => 'Teacher Marco Villanueva',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
            'assigned_grade_level' => 'Grade 8',
            'assigned_section' => 'Sampaguita',
        ], $overrides);
    }

    private function learner(string $lrn = self::LRN, string $section = 'Grade 8 / Sampaguita'): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'student_id' => $lrn,
            'student_name' => 'Dimasalang, Tomas Rizal',
            'school_name' => 'Sta. Ana NHS',
            'grade_level' => 'Grade 8',
            'section' => $section,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'weight' => '40',
            'bmi_value' => '18',
            'nutritional_status' => 'Normal',
            'student_details' => [
                'first_name' => 'Tomas',
                'middle_name' => 'Rizal',
                'last_name' => 'Dimasalang',
                'gender' => 'Male',
                'birth_year' => 2012,
                'birth_month' => 3,
                'birth_day' => 14,
                'parent_guardian' => 'Corazon Dimasalang',
                'grade_level' => 'Grade 8',
                'section' => 'Sampaguita',
            ],
        ]);
    }

    private function exam(array $overrides = []): array
    {
        return array_merge([
            'temperature_c' => '37.80',
            'pulse_bpm' => '96',
            'respiratory_rate' => '20',
            'blood_pressure' => '110 / 70',
            'oxygen_saturation' => '98',
            'consciousness' => 'alert',
            'pain_score' => '3',
            'body_site' => 'Left knee',
            'findings' => '2 cm abrasion, minimal bleeding, no swelling.',
        ], $overrides);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'occurred_at' => now()->subDay()->toDateString(),
            'occurred_time' => '10:05',
            'severity' => 'moderate',
            'location' => 'Covered court',
            'category' => 'injury',
            'focus' => 'Abrasion, left knee',
            'description' => 'Fell during PE and hit the left knee on the floor.',
            'action_taken' => 'Wound cleaned with sterile saline and dressed.',
            'response' => 'Pain settled. Returned to class at 10:20.',
            'witnesses' => 'Ligaya Santos',
            'guardian_notified' => '1',
            'exam' => $this->exam(),
        ], $overrides);
    }

    private function file(array $overrides = [], ?array $session = null)
    {
        return $this->withSession($session ?? $this->nurseSession())
            ->postJson(route('student-incidents.store', self::LRN), $this->payload($overrides));
    }

    private function account(string $role, string $name, array $extra = []): void
    {
        DB::table('accounts')->insert(array_merge([
            'name' => $name,
            'username' => strtolower(str_replace(' ', '.', $name)),
            'password_hash' => null,
            'role' => $role,
            'institution_id' => $this->school->id,
            'school_name' => 'Sta. Ana NHS',
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }

    // ── 1. The physical examination ─────────────────────────────────────────

    #[Test]
    public function the_form_charts_the_physical_examination_under_data(): void
    {
        $this->learner();

        $html = $this->withSession($this->nurseSession())
            ->get(route('dashboard.student-health-records'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Physical Examination', $html);

        foreach (['temperature_c', 'pulse_bpm', 'respiratory_rate', 'blood_pressure', 'oxygen_saturation', 'consciousness', 'pain_score', 'body_site', 'findings'] as $key) {
            $this->assertStringContainsString('name="exam['.$key.']"', $html, "The examination must ask for {$key}.");
        }

        // The section sits inside the Data row, before the Action.
        $this->assertLessThan(strpos($html, 'id="incidentAction"'), strpos($html, 'id="incidentExam"'));
        $this->assertGreaterThan(strpos($html, 'id="incidentDescription"'), strpos($html, 'id="incidentExam"'));
    }

    #[Test]
    public function the_examination_is_stored_in_the_form_it_prints_in_and_read_back(): void
    {
        $this->learner();

        $response = $this->file()->assertCreated();

        $response->assertJsonPath('report.physical_exam.temperature_c', '37.8')
            ->assertJsonPath('report.physical_exam.blood_pressure', '110/70')
            ->assertJsonPath('report.physical_exam.pain_score', '3')
            ->assertJsonPath('report.physical_exam.consciousness', 'alert')
            ->assertJsonPath('report.physical_exam.body_site', 'Left knee');

        $summary = $response->json('report.exam_summary');
        $this->assertSame(
            ['37.8 °C', '96 bpm', '20 breaths/min', '110/70 mmHg', '98%'],
            array_column($summary['vitals'], 'value')
        );
        $this->assertSame(
            ['Consciousness' => 'Alert', 'Pain' => '3 / 10', 'Body part affected' => 'Left knee', 'Findings' => '2 cm abrasion, minimal bleeding, no swelling.'],
            array_column($summary['lines'], 'value', 'label')
        );
    }

    #[Test]
    public function an_examination_is_optional_and_a_reading_nobody_took_is_blank(): void
    {
        $this->learner();

        $this->file(['exam' => []])
            ->assertCreated()
            ->assertJsonPath('report.physical_exam.temperature_c', '')
            ->assertJsonPath('report.exam_summary.vitals', [])
            ->assertJsonPath('report.exam_summary.lines', []);

        $this->assertNull(StudentIncidentReport::query()->latest('id')->first()->physical_exam);

        // One reading on its own is charted on its own.
        $this->file(['exam' => ['temperature_c' => '38']])
            ->assertCreated()
            ->assertJsonPath('report.physical_exam.temperature_c', '38')
            ->assertJsonPath('report.physical_exam.pulse_bpm', '')
            ->assertJsonPath('report.exam_summary.vitals.0.value', '38 °C');
    }

    /** @return array<string, array{0: array<string, string>, 1: string}> */
    public static function impossibleReadings(): array
    {
        return [
            'a temperature no learner has' => [['temperature_c' => '73'], 'exam.temperature_c'],
            'a pulse typed with an extra digit' => [['pulse_bpm' => '960'], 'exam.pulse_bpm'],
            'a respiratory rate of nothing' => [['respiratory_rate' => '0'], 'exam.respiratory_rate'],
            'a blood pressure in words' => [['blood_pressure' => 'normal'], 'exam.blood_pressure'],
            'a blood pressure the wrong way round' => [['blood_pressure' => '70/110'], 'exam.blood_pressure'],
            'a saturation over 100' => [['oxygen_saturation' => '101'], 'exam.oxygen_saturation'],
            'a level of consciousness off the scale' => [['consciousness' => 'drowsy'], 'exam.consciousness'],
            'a pain score past ten' => [['pain_score' => '11'], 'exam.pain_score'],
        ];
    }

    #[Test]
    #[DataProvider('impossibleReadings')]
    public function an_impossible_reading_is_refused(array $exam, string $field): void
    {
        $this->learner();

        $this->file(['exam' => $this->exam($exam)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertSame(0, StudentIncidentReport::query()->count());
    }

    #[Test]
    public function the_examination_is_encrypted_at_rest(): void
    {
        $this->learner();
        $this->file()->assertCreated();

        $raw = (string) DB::table('student_incident_reports')->value('physical_exam');

        $this->assertStringStartsWith('eyJpdiI6', $raw);
        $this->assertStringNotContainsString('Left knee', $raw);
        $this->assertStringNotContainsString('37.8', $raw);
    }

    #[Test]
    public function an_edit_changes_the_examination_and_saving_it_unchanged_stamps_nothing(): void
    {
        $this->learner();
        $id = (int) $this->file()->assertCreated()->json('report.id');
        $update = fn (array $payload) => $this->withSession($this->nurseSession())
            ->postJson(route('student-incidents.update', [self::LRN, $id]), $payload);

        // The same examination posted back as the form would post it.
        $update($this->payload())->assertOk()->assertJsonPath('report.edited_label', '');

        $update($this->payload(['exam' => $this->exam(['temperature_c' => '38.5', 'pain_score' => '5'])]))
            ->assertOk()
            ->assertJsonPath('report.physical_exam.temperature_c', '38.5')
            ->assertJsonPath('report.physical_exam.pain_score', '5')
            ->assertJsonPath('report.physical_exam.body_site', 'Left knee');

        $this->assertNotSame('', StudentIncidentReport::find($id)->edited_at?->toDateTimeString() ?? '');
    }

    #[Test]
    public function before_the_column_is_migrated_the_form_draws_no_examination_and_still_files(): void
    {
        Schema::table('student_incident_reports', function (Blueprint $table) {
            $table->dropColumn('physical_exam');
        });
        SchemaCache::flush();

        $this->learner();

        $html = $this->withSession($this->nurseSession())->get(route('dashboard.student-health-records'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="incidentExam"', $html);

        $this->file()->assertCreated()->assertJsonPath('report.exam_summary.vitals', []);
    }

    // ── 2. The printable form and its signatories ───────────────────────────

    #[Test]
    public function a_filed_report_prints_on_the_school_form_with_the_four_signatories(): void
    {
        $this->learner();
        $this->account('class_adviser', 'Marco Villanueva', ['assigned_grade_level' => 'Grade 8', 'assigned_section' => 'sampaguita ']);
        $this->account('school_head', 'Dr. Felicidad Ramos');
        $id = (int) $this->file()->assertCreated()->json('report.id');

        $html = $this->withSession($this->nurseSession())
            ->get(route('student-incidents.print', [self::LRN, $id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-incident-sheet', $html);
        $this->assertStringContainsString('INCIDENT REPORT', $html);

        // The learner, the incident, the examination and the chart.
        foreach (['Dimasalang, Tomas R.', self::LRN, 'Grade 8 – Sampaguita', 'Male', 'Corazon Dimasalang',
            'Covered court', 'Ligaya Santos', '10:05 AM',
            '37.8', '110/70', '98', 'Left knee', '2 cm abrasion, minimal bleeding, no swelling.',
            'Abrasion, left knee', 'Fell during PE and hit the left knee on the floor.',
            'Wound cleaned with sterile saline and dressed.', 'Pain settled. Returned to class at 10:20.'] as $text) {
            $this->assertStringContainsString(e($text), $html, "The printed form must carry {$text}.");
        }

        // The four signatories the tester asked for, in order.
        $positions = [];
        foreach (['Clinic Nurse', 'Class Adviser', 'Curriculum Head', 'School Principal'] as $title) {
            $positions[] = strpos($html, '<div class="ir-sign-title">'.$title.'</div>');
        }
        $this->assertNotContains(false, $positions);
        $this->assertSame($positions, collect($positions)->sort()->values()->all());

        // Named from the accounts the app holds: the nurse who charted it,
        // the learner's own adviser and the school head. Nobody holds a
        // curriculum head's account, so that line is left to sign on.
        $this->assertStringContainsString('<div class="ir-sign-name">Nurse Liza Bautista</div>', $html);
        $this->assertStringContainsString('<div class="ir-sign-name">Marco Villanueva</div>', $html);
        $this->assertStringContainsString('<div class="ir-sign-name">Dr. Felicidad Ramos</div>', $html);
        $this->assertSame(1, substr_count($html, '<div class="ir-sign-name"></div>'));

        // The ticked boxes are the answers charted.
        $this->assertMatchesRegularExpression('/<span class="ir-box">√<\/span>Injury \/ Accident/', $html);
        $this->assertMatchesRegularExpression('/<span class="ir-box">√<\/span>Moderate/', $html);
        $this->assertMatchesRegularExpression('/<span class="ir-box">√<\/span>Alert/', $html);
        $this->assertMatchesRegularExpression('/<span class="ir-box"><\/span>Serious/', $html);
    }

    #[Test]
    public function another_classs_adviser_is_never_named_as_the_learners(): void
    {
        $this->learner();
        $this->account('class_adviser', 'Other Adviser', ['assigned_grade_level' => 'Grade 8', 'assigned_section' => 'Rosal']);
        $id = (int) $this->file()->assertCreated()->json('report.id');

        $html = $this->withSession($this->nurseSession())->get(route('student-incidents.print', [self::LRN, $id]))->assertOk()->getContent();

        $this->assertStringNotContainsString('Other Adviser', $html);
    }

    #[Test]
    public function a_clinic_teachers_report_is_prepared_for_the_school_nurse_and_keeps_its_charter(): void
    {
        $this->learner();
        $this->account('school_nurse', 'Nurse Liza Bautista');

        $id = (int) $this->file([], $this->nurseSession([
            'active_role' => 'clinic_teacher',
            'active_name' => 'Teacher Ramon Aquino',
        ]))->assertCreated()->json('report.id');

        $html = $this->withSession($this->nurseSession())->get(route('student-incidents.print', [self::LRN, $id]))->assertOk()->getContent();

        $this->assertStringContainsString('<div class="ir-sign-name">Nurse Liza Bautista</div>', $html);
        $this->assertStringContainsString('Charted by Teacher Ramon Aquino, Clinic Teacher', $html);
    }

    #[Test]
    public function printing_is_audited_and_open_to_every_reader_of_the_report(): void
    {
        $this->learner();
        $id = (int) $this->file()->assertCreated()->json('report.id');
        $baseline = (int) AuditLog::max('id');

        // The adviser signs the form, so the adviser may print it.
        $this->withSession($this->adviserSession())->get(route('student-incidents.print', [self::LRN, $id]))->assertOk();
        $this->withSession($this->nurseSession(['active_role' => 'clinic_staff']))->get(route('student-incidents.print', [self::LRN, $id]))->assertOk();

        $printed = AuditLog::query()->where('id', '>', $baseline)->where('action', 'printed')->get();
        $this->assertCount(2, $printed);
        $this->assertSame('StudentIncidentReport', $printed->first()->subject_type);
        $this->assertSame($id, (int) $printed->first()->subject_id);
    }

    #[Test]
    public function the_print_is_scoped_like_every_other_read(): void
    {
        $this->learner();
        $id = (int) $this->file()->assertCreated()->json('report.id');

        // An adviser of another class, a school head and an outside school.
        $this->withSession($this->adviserSession(['assigned_section' => 'Rosal']))
            ->get(route('student-incidents.print', [self::LRN, $id]))->assertForbidden();
        $this->withSession($this->nurseSession(['active_role' => 'school_head']))
            ->get(route('student-incidents.print', [self::LRN, $id]))->assertForbidden();

        $other = Institution::create(['name' => 'Other NHS', 'status' => 'active']);
        $this->withSession($this->nurseSession(['active_institution_id' => $other->id]))
            ->get(route('student-incidents.print', [self::LRN, $id]))->assertForbidden();

        // A report id that belongs to another learner is not this learner's.
        $this->learner('900000000052');
        $this->withSession($this->nurseSession())
            ->get(route('student-incidents.print', ['900000000052', $id]))->assertNotFound();
    }

    #[Test]
    public function the_blank_form_carries_the_learner_and_the_signatories_and_nothing_charted(): void
    {
        $this->learner();
        $this->account('school_head', 'Dr. Felicidad Ramos');

        $html = $this->withSession($this->nurseSession())
            ->get(route('student-incidents.blank', self::LRN))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('INCIDENT REPORT', $html);
        $this->assertStringContainsString('Dimasalang, Tomas R.', $html);
        $this->assertStringContainsString('<div class="ir-sign-name">Dr. Felicidad Ramos</div>', $html);
        $this->assertStringContainsString('class="ir-write"', $html);
        // Every box is left to tick by hand.
        $this->assertStringNotContainsString('√', $html);
        $this->assertStringNotContainsString('Charted by', $html);

        // The clinic's form: an adviser reads reports but does not chart them.
        $this->withSession($this->adviserSession())->get(route('student-incidents.blank', self::LRN))->assertForbidden();
    }

    #[Test]
    public function the_panel_offers_the_blank_form_to_the_clinic_only(): void
    {
        $this->learner();

        $nurse = $this->withSession($this->nurseSession())->get(route('dashboard.student-health-records'))->assertOk()->getContent();
        $this->assertStringContainsString('id="incidentBlankBtn"', $nurse);
        $this->assertStringContainsString('Print Blank Form', $nurse);

        $adviser = $this->withSession($this->adviserSession([
            'school_health_card_records' => [[
                'lrn' => self::LRN,
                'first_name' => 'Tomas',
                'last_name' => 'Dimasalang',
                'grade_level' => 'Grade 8',
                'section' => 'Sampaguita',
            ]],
        ]))
            ->get(route('dashboard.class-adviser.student-profile', self::LRN))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('id="incidentBlankBtn"', $adviser);
    }

    /**
     * While the form is open — filing or editing — the history is put away,
     * so the report being written is never confused with one already filed.
     * The list stays inside the wrapper the form hides, and the script hides
     * it with the form and shows it again when the form closes.
     */
    #[Test]
    public function the_history_is_put_away_while_the_form_is_open(): void
    {
        $this->learner();

        $html = $this->withSession($this->nurseSession())->get(route('dashboard.student-health-records'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<div class="incident-history" id="incidentHistory">\s*<div class="sp-subhead">.*?Incident History.*?<div id="vpIncidentsList"/s',
            $html
        );
        $this->assertStringContainsString('historyBox.hidden = open;', $html);
    }

    /**
     * Leaving the Incident Reports tab, or coming back to it, returns the
     * panel to its default — the form closed — so a nurse is never dropped
     * back into a half-filed or half-edited report. Read in the capture
     * phase, before the page's own tab handler moves the selection.
     */
    #[Test]
    public function leaving_or_returning_to_the_tab_closes_the_form(): void
    {
        $this->learner();

        $html = $this->withSession($this->nurseSession())->get(route('dashboard.student-health-records'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            "/closest\('\.sp-tab'\).*?panel\.contains\(list\).*?aria-selected'\) !== 'true'\) closeForm\(\);\s*\}, true\);/s",
            $html
        );
    }

    /**
     * A browser prints its own header and footer — the date, the page title,
     * the web address, the page number — in the page margin. The form has no
     * page margin, so there is nowhere to print them; its own margins come
     * from the body and from an empty head and foot repeated on every sheet.
     * The tab title no longer names the learner either.
     */
    #[Test]
    public function the_printout_leaves_the_browser_no_margin_for_a_date_title_or_address(): void
    {
        $this->learner();
        $id = (int) $this->file()->assertCreated()->json('report.id');

        foreach ([
            route('student-incidents.print', [self::LRN, $id]),
            route('student-incidents.blank', self::LRN),
        ] as $url) {
            $html = $this->withSession($this->nurseSession())->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('@page { size: A4 portrait; margin: 0; }', $html);
            $this->assertStringContainsString('<title>Incident Report</title>', $html);
            $this->assertStringNotContainsString('Dimasalang', strstr($html, '</title>', true));
            $this->assertMatchesRegularExpression('/<table class="ir-page" role="presentation">\s*<thead><tr><td><div class="ir-page-space"><\/div><\/td><\/tr><\/thead>\s*<tfoot><tr><td><div class="ir-page-space"><\/div><\/td><\/tr><\/tfoot>/', $html);
        }
    }

    // ── 3. The AI grammar and terms check ───────────────────────────────────

    private function gemini(array $answer, int $status = 200): void
    {
        config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-test-model', 'services.gemini.text_model' => null]);

        Http::fake([
            '*' => $status === 200
                ? Http::response(['candidates' => [[
                    'content' => ['parts' => [['text' => json_encode($answer)]]],
                    'finishReason' => 'STOP',
                ]]])
                : Http::response(['error' => ['message' => 'busy']], $status),
        ]);
    }

    private function proofread(array $fields, array $session = [], string $witnesses = 'Ligaya Santos')
    {
        return $this->withSession($this->nurseSession($session))
            ->postJson(route('student-incidents.proofread', self::LRN), ['fields' => $fields, 'witnesses' => $witnesses]);
    }

    #[Test]
    public function without_a_key_the_check_is_not_offered_and_the_endpoint_refuses(): void
    {
        config(['services.gemini.key' => '']);
        $this->learner();

        $html = $this->withSession($this->nurseSession())->get(route('dashboard.student-health-records'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="incidentProofBtn"', $html);

        $this->proofread(['description' => 'Fel during PE.'])->assertStatus(503);
    }

    #[Test]
    public function with_a_key_the_check_is_offered_beside_save(): void
    {
        config(['services.gemini.key' => 'test-key']);
        $this->learner();

        $html = $this->withSession($this->nurseSession())->get(route('dashboard.student-health-records'))->assertOk()->getContent();
        $this->assertStringContainsString('id="incidentProofBtn"', $html);
        $this->assertStringContainsString('Grammar &amp; Terms', $html);
    }

    #[Test]
    public function the_check_proposes_corrections_and_saves_nothing(): void
    {
        $this->learner();
        $this->gemini([
            'description' => 'Fell during PE and hit the left knee on the floor.',
            'exam_findings' => '2 cm abrasion with minimal bleeding.',
            'action_taken' => 'Wound cleaned and dressed.',
        ]);

        $this->proofread([
            'description' => 'fel durng PE and hit the left knee on the flor',
            'exam_findings' => '2 cm abraision w/ minimal bleding',
            'action_taken' => 'Wound cleaned and dressed.',
        ])
            ->assertOk()
            ->assertJsonPath('suggestions.description.original', 'fel durng PE and hit the left knee on the flor')
            ->assertJsonPath('suggestions.description.suggested', 'Fell during PE and hit the left knee on the floor.')
            ->assertJsonPath('suggestions.exam_findings.suggested', '2 cm abrasion with minimal bleeding.')
            // Already right: nothing to suggest.
            ->assertJsonMissingPath('suggestions.action_taken')
            ->assertJsonPath('checked', ['description', 'exam_findings', 'action_taken']);

        $this->assertSame(0, StudentIncidentReport::query()->count());

        // One request for every field, to the configured model.
        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/models/gemini-test-model:generateContent')
            && $request->hasHeader('x-goog-api-key', 'test-key'));
    }

    #[Test]
    public function the_learners_and_the_witnesses_names_never_leave_the_school_and_come_back_as_written(): void
    {
        $this->learner();
        $this->gemini(['description' => '[[N1]] [[N2]] fell during PE, as seen by [[N3]] [[N4]].']);

        $this->proofread(['description' => 'Tomas Dimasalang fel durng PE, as seen by Ligaya Santos'])
            ->assertOk()
            ->assertJsonPath('suggestions.description.suggested', 'Tomas Dimasalang fell during PE, as seen by Ligaya Santos.');

        Http::assertSent(function (ClientRequest $request): bool {
            $body = (string) $request->body();

            return ! str_contains($body, 'Tomas')
                && ! str_contains($body, 'Dimasalang')
                && ! str_contains($body, 'Ligaya')
                && ! str_contains($body, 'Santos')
                && str_contains($body, '[[N1]]');
        });
    }

    #[Test]
    public function a_correction_that_changes_a_figure_or_loses_a_name_is_dropped(): void
    {
        $this->learner();
        $this->gemini([
            // 37.8 became 38.7: a reading, not grammar.
            'exam_findings' => 'Temperature 38.7 °C on arrival.',
            // The learner's name was dropped.
            'description' => 'The learner fell during PE.',
            'response' => 'Returned to class at 10:20.',
        ]);

        $this->proofread([
            'exam_findings' => 'temprature 37.8 °C on arival',
            'description' => 'Tomas fel during PE',
            'response' => 'returned to class at 10:20',
        ])
            ->assertOk()
            ->assertJsonMissingPath('suggestions.exam_findings')
            ->assertJsonMissingPath('suggestions.description')
            ->assertJsonPath('suggestions.response.suggested', 'Returned to class at 10:20.');
    }

    #[Test]
    public function a_busy_checker_is_said_plainly_and_changes_nothing(): void
    {
        $this->learner();
        $this->gemini([], 503);

        $this->proofread(['description' => 'fel during PE'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'The checker is busy at the moment. Try again in a few minutes.');
    }

    #[Test]
    public function the_check_is_the_charting_roles_and_is_audited_without_the_text(): void
    {
        $this->learner();
        $this->gemini(['description' => 'Fell during PE.']);

        $this->withSession($this->adviserSession())
            ->postJson(route('student-incidents.proofread', self::LRN), ['fields' => ['description' => 'fel']])
            ->assertForbidden();

        $baseline = (int) AuditLog::max('id');
        $this->proofread(['description' => 'fel during PE'], ['active_role' => 'clinic_teacher'])->assertOk();

        $entry = AuditLog::query()->where('id', '>', $baseline)->where('action', 'proofread')->first();
        $this->assertNotNull($entry);
        $this->assertSame(['description'], $entry->details['fields'] ?? null);
        $this->assertStringNotContainsString('fel during PE', json_encode($entry->details).$entry->description);
    }

    #[Test]
    public function the_check_asks_the_text_model_when_one_is_named(): void
    {
        $this->learner();
        $this->gemini(['description' => 'Fell during PE.']);
        config(['services.gemini.text_model' => 'gemini-text-lite']);

        $this->proofread(['description' => 'fel during PE'])->assertOk();

        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/models/gemini-text-lite:generateContent'));
    }

    #[Test]
    public function nothing_written_means_nothing_sent(): void
    {
        $this->learner();
        $this->gemini(['description' => 'x']);

        $this->proofread(['description' => '   ', 'focus' => ''])->assertOk()->assertJsonPath('checked', []);

        Http::assertNothingSent();
    }
}
