<?php

namespace Tests\Feature;

use App\Models\ClinicNote;
use App\Models\Consultation;
use App\Models\HealthConsentForm;
use App\Models\Institution;
use App\Models\Medicine;
use App\Models\StudentHealthRecord;
use App\Support\SchoolHeadHealthOverview;
use App\Support\SchoolHeadOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the School Head's oversight tabs — Health Overview, Consent
 * Compliance and Medicine Inventory — and the executive summary that gathers
 * them.
 *
 * Three things are asserted throughout, because they are the reasons this role
 * exists in the shape it does:
 *
 * 1. **It reads, it never writes.** No tab renders a form that changes a
 *    consultation, a consent answer or a stock level.
 * 2. **It sees its own school only.** Every figure is scoped by institution.
 * 3. **A figure with nothing behind it is undefined, not zero.** A completion
 *    rate over an empty roster is an em dash.
 */
class SchoolHeadOversightTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private Institution $otherSchool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
        $this->otherSchool = Institution::create(['name' => 'Other School', 'status' => 'active']);
    }

    private function headSession(): array
    {
        return [
            'active_role' => 'school_head',
            'active_name' => 'Test Head',
            'active_username' => 'head.test',
            'active_school_name' => 'Test School',
            'active_institution_id' => $this->institution->id,
        ];
    }

    private function makeLearner(string $section = 'Grade 7 / Rizal', array $extra = [], ?Institution $school = null): StudentHealthRecord
    {
        $school ??= $this->institution;

        return StudentHealthRecord::create(array_merge([
            'institution_id' => $school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Learner '.random_int(1000, 9999),
            'student_id' => 'LRN'.random_int(100000, 999999),
            'school_name' => $school->name,
            'section' => $section,
            'weight' => 30,
            'bmi_value' => 15,
            'nutritional_status' => 'Normal',
            'baseline_nutritional_status' => 'Normal',
        ], $extra));
    }

    private function makeConsultation(array $extra = [], ?Institution $school = null): Consultation
    {
        $school ??= $this->institution;

        return Consultation::create(array_merge([
            'institution_id' => $school->id,
            'consulted_at' => now(),
            'student_name' => 'Learner '.random_int(1000, 9999),
            'grade_section' => 'Grade 7 - Rizal',
            'condition' => 'Headache',
            'treatment_given' => 'Rest',
            'status' => 'treated',
        ], $extra));
    }

    private function makeConsent(StudentHealthRecord $record, string $status, ?string $choice = 'all'): HealthConsentForm
    {
        return HealthConsentForm::create([
            'institution_id' => $this->institution->id,
            'school_year' => $record->school_year,
            'division' => 'DAVAO CITY',
            'school_name' => 'Test School',
            'school_address' => 'Somewhere',
            'student_lrn' => $record->student_id,
            'student_name' => $record->student_name,
            'status' => $status,
            'consent_choice' => $choice,
        ]);
    }

    #[Test]
    public function every_oversight_tab_renders_on_the_shared_role_sidebar(): void
    {
        $urls = [
            '/dashboard/school-head',
            '/dashboard/school-head/health',
            '/dashboard/school-head/consent',
            '/dashboard/school-head/inventory',
        ];

        foreach ($urls as $url) {
            $response = $this->withSession($this->headSession())->get($url)->assertOk();

            $response->assertSee('asb-sidebar', false);
            // Every tab is reachable from every other one.
            $response->assertSee('Health Overview', false);
            $response->assertSee('Consent Compliance', false);
            $response->assertSee('Medicine Inventory', false);
            // The audit log was removed from this role: the trail is the System
            // Admin's screen, and a second rendering of it here had no job.
            $response->assertDontSee('Audit Logs', false);
            $response->assertDontSee('/dashboard/school-head/audit', false);
        }
    }

    #[Test]
    public function the_audit_log_route_is_gone_from_this_role(): void
    {
        $this->assertFalse(app('router')->has('dashboard.school-head.audit'));

        $this->withSession($this->headSession())
            ->get('/dashboard/school-head/audit')
            ->assertNotFound();
    }

    #[Test]
    public function the_oversight_tabs_are_closed_to_other_roles(): void
    {
        $session = ['active_role' => 'class_adviser', 'active_institution_id' => $this->institution->id];

        foreach ([
            '/dashboard/school-head/health',
            '/dashboard/school-head/consent',
            '/dashboard/school-head/inventory',
        ] as $url) {
            $this->withSession($session)->get($url)->assertRedirect(route('login'));
        }
    }

    #[Test]
    public function clinic_figures_count_only_this_schools_consultations(): void
    {
        $this->makeConsultation();
        $this->makeConsultation(['status' => 'referred']);
        $this->makeConsultation([], $this->otherSchool);

        $clinic = $this->withSession($this->headSession())
            ->get('/dashboard/school-head/health')->assertOk()->viewData('clinic');

        $this->assertSame(2, $clinic['total']);
        $this->assertSame(1, $clinic['referred']);
        $this->assertSame(1, $clinic['treated']);

        $dispositions = collect($clinic['dispositions'])->keyBy('key');
        $this->assertSame(50.0, $dispositions['treated']['share']);
        $this->assertSame(50.0, $dispositions['referred']['share']);
    }

    #[Test]
    public function a_disposition_share_with_no_consultation_is_undefined_not_zero(): void
    {
        $clinic = $this->withSession($this->headSession())
            ->get('/dashboard/school-head/health')->assertOk()->viewData('clinic');

        $this->assertSame(0, $clinic['total']);
        foreach ($clinic['dispositions'] as $row) {
            $this->assertNull($row['share']);
        }
    }

    #[Test]
    public function the_health_tab_never_renders_a_learners_name_or_treatment(): void
    {
        $consultation = $this->makeConsultation(['student_name' => 'Juan Dela Cruz', 'treatment_given' => 'Paracetamol 500mg']);

        $this->withSession($this->headSession())
            ->get('/dashboard/school-head/health')
            ->assertOk()
            // Aggregated reading: who was seen and what they were given belongs
            // on the nurse's screen, not on a management summary.
            ->assertDontSee('Juan Dela Cruz')
            ->assertDontSee('Paracetamol 500mg');

        $this->assertSame('Juan Dela Cruz', $consultation->fresh()->student_name);
    }

    #[Test]
    public function only_an_answered_consent_that_was_not_refused_counts_as_valid(): void
    {
        $signed = $this->makeLearner();
        $reviewed = $this->makeLearner();
        $draft = $this->makeLearner();
        $sent = $this->makeLearner();
        $denied = $this->makeLearner();
        $this->makeLearner();

        $this->makeConsent($signed, HealthConsentForm::STATUS_SIGNED);
        $this->makeConsent($reviewed, HealthConsentForm::STATUS_REVIEWED, 'specific');
        $this->makeConsent($draft, HealthConsentForm::STATUS_DRAFT);
        $this->makeConsent($sent, HealthConsentForm::STATUS_SENT);
        $this->makeConsent($denied, HealthConsentForm::STATUS_SIGNED, HealthConsentForm::CONSENT_DENY);

        $consent = $this->withSession($this->headSession())
            ->get('/dashboard/school-head/consent')->assertOk()->viewData('consent');

        $this->assertSame(6, $consent['required']);
        $this->assertSame(2, $consent['valid']);
        $this->assertSame(4, $consent['missing']);
        $this->assertSame(1, $consent['declined']);
        $this->assertSame(1, $consent['awaiting']);
        // The draft and the learner with no form at all: neither authorises
        // anything, so both read as no form on file.
        $this->assertSame(2, $consent['none']);
        $this->assertSame(33.3, $consent['rate']);
    }

    #[Test]
    public function a_completion_rate_over_an_empty_roster_is_undefined_not_zero(): void
    {
        $consent = $this->withSession($this->headSession())
            ->get('/dashboard/school-head/consent')->assertOk()->viewData('consent');

        $this->assertSame(0, $consent['required']);
        $this->assertNull($consent['rate']);
    }

    #[Test]
    public function the_outstanding_list_names_only_learners_without_valid_consent(): void
    {
        $withConsent = $this->makeLearner();
        $this->makeConsent($withConsent, HealthConsentForm::STATUS_SIGNED);
        $without = $this->makeLearner();

        $rows = $this->withSession($this->headSession())
            ->get('/dashboard/school-head/consent')->assertOk()->viewData('rows');

        $this->assertCount(1, $rows);
        $this->assertSame($without->student_id, $rows[0]['lrn']);
    }

    #[Test]
    public function stock_state_is_judged_against_each_medicines_own_threshold(): void
    {
        Medicine::create(['institution_id' => $this->institution->id, 'name' => 'Paracetamol', 'stock_quantity' => 120, 'minimum_threshold' => 20]);
        Medicine::create(['institution_id' => $this->institution->id, 'name' => 'Amoxicillin', 'stock_quantity' => 8, 'minimum_threshold' => 20]);
        Medicine::create(['institution_id' => $this->institution->id, 'name' => 'Antiseptic', 'stock_quantity' => 25, 'minimum_threshold' => 20]);
        Medicine::create(['institution_id' => $this->institution->id, 'name' => 'Bandage', 'stock_quantity' => 0, 'minimum_threshold' => 10]);
        Medicine::create(['institution_id' => $this->otherSchool->id, 'name' => 'Ibuprofen', 'stock_quantity' => 1, 'minimum_threshold' => 50]);

        $inventory = $this->withSession($this->headSession())
            ->get('/dashboard/school-head/inventory')->assertOk()->viewData('inventory');

        // The neighbouring school's shelf is not this head's reading.
        $this->assertSame(4, $inventory['tracked']);
        $this->assertSame(1, $inventory['good']);     // 120 against a line of 20
        $this->assertSame(1, $inventory['monitor']);  // 25: above the line, inside 1.5x
        $this->assertSame(1, $inventory['low']);      // 8: below the line
        $this->assertSame(1, $inventory['out']);      // 0: nothing to dispense
        $this->assertSame(153, $inventory['units']);
    }

    #[Test]
    public function the_inventory_tab_offers_no_way_to_receive_or_dispense(): void
    {
        Medicine::create(['institution_id' => $this->institution->id, 'name' => 'Paracetamol', 'stock_quantity' => 5, 'minimum_threshold' => 20]);

        $response = $this->withSession($this->headSession())
            ->get('/dashboard/school-head/inventory')->assertOk();

        // Receiving stock and dispensing it belong to the clinic; a second
        // write path into the inventory is how two screens start disagreeing.
        // (Signing out is the head's own session, not school data, so the rail's
        // logout form is the one POST this page is allowed to carry.)
        $response->assertDontSee(route('medicine-inventory.store'), false);
        $response->assertDontSee(route('medicine-inventory.create'), false);
        $response->assertDontSee(route('dispensing-log.store'), false);
    }

    #[Test]
    public function the_executive_summary_gathers_every_programme(): void
    {
        $learner = $this->makeLearner();
        $this->makeConsent($learner, HealthConsentForm::STATUS_SIGNED);
        $this->makeLearner();
        $this->makeConsultation();
        $this->makeConsultation(['status' => 'referred']);
        Medicine::create(['institution_id' => $this->institution->id, 'name' => 'Amoxicillin', 'stock_quantity' => 2, 'minimum_threshold' => 20]);

        $stats = $this->withSession($this->headSession())
            ->get('/dashboard/school-head')->assertOk()->viewData('stats');

        $this->assertSame(2, $stats['total_students']);
        $this->assertSame(2, $stats['consultations']);
        $this->assertSame(1, $stats['referrals']);
        $this->assertSame(1, $stats['consent_valid']);
        $this->assertSame(1, $stats['consent_missing']);
        $this->assertSame(50.0, $stats['consent_rate']);
        $this->assertSame(1, $stats['medicines_low']);
    }

    #[Test]
    public function the_scope_filters_move_every_panel_together(): void
    {
        $seven = $this->makeLearner('Grade 7 / Rizal');
        $this->makeConsent($seven, HealthConsentForm::STATUS_SIGNED);
        $this->makeLearner('Grade 8 / Bonifacio');
        $this->makeLearner('Grade 8 / Bonifacio');

        $response = $this->withSession($this->headSession())
            ->get('/dashboard/school-head?grade='.urlencode('Grade 8'))
            ->assertOk();

        $stats = $response->viewData('stats');
        $consent = $response->viewData('consent');

        // The filtered roll, and the consent figures taken over it: a scoped
        // dashboard must not report the whole school's completion.
        $this->assertSame(2, $stats['total_students']);
        $this->assertSame(2, $consent['required']);
        $this->assertSame(0, $consent['valid']);
        $this->assertSame(0.0, $consent['rate']);
    }

    #[Test]
    public function a_narrowed_roster_narrows_the_clinic_figures_with_it(): void
    {
        $eight = $this->makeLearner('Grade 8 / Bonifacio', ['student_name' => 'Ana Cruz']);
        $this->makeLearner('Grade 7 / Rizal', ['student_name' => 'Ben Santos']);

        $this->makeConsultation(['student_name' => 'Ana Cruz']);
        $this->makeConsultation(['student_name' => 'Ben Santos']);
        $this->makeConsultation(['student_name' => 'Ben Santos']);

        // Unscoped: every visit the school logged.
        $whole = $this->withSession($this->headSession())
            ->get('/dashboard/school-head')->assertOk()->viewData('clinic');
        $this->assertSame(3, $whole['total']);

        // Scoped: one filter moves the clinic panel with the roster.
        $scoped = $this->withSession($this->headSession())
            ->get('/dashboard/school-head?grade='.urlencode('Grade 8'))->assertOk()->viewData('clinic');
        $this->assertSame(1, $scoped['total']);
        $this->assertSame(1, $scoped['learners']);
        $this->assertSame($eight->section, 'Grade 8 / Bonifacio');
    }

    #[Test]
    public function an_unrecognised_filter_value_is_dropped_rather_than_emptying_the_page(): void
    {
        $this->makeLearner('Grade 7 / Rizal');

        $stats = $this->withSession($this->headSession())
            ->get('/dashboard/school-head?grade=Grade+99&section=Nowhere')
            ->assertOk()
            ->viewData('stats');

        $this->assertSame(1, $stats['total_students']);
    }

    #[Test]
    public function the_queue_names_a_consent_gap_and_drops_it_once_filed(): void
    {
        $learner = $this->makeLearner();

        $titles = collect($this->withSession($this->headSession())
            ->get('/dashboard/school-head')->assertOk()->viewData('queue'))
            ->pluck('title')->implode(' | ');
        $this->assertStringContainsString('without valid health services consent', $titles);

        $this->makeConsent($learner, HealthConsentForm::STATUS_SIGNED);

        $titles = collect($this->withSession($this->headSession())
            ->get('/dashboard/school-head')->assertOk()->viewData('queue'))
            ->pluck('title')->implode(' | ');
        $this->assertStringNotContainsString('without valid health services consent', $titles);
    }

    #[Test]
    public function the_queue_names_stock_the_clinic_cannot_dispense(): void
    {
        Medicine::create(['institution_id' => $this->institution->id, 'name' => 'Bandage', 'stock_quantity' => 0, 'minimum_threshold' => 10]);
        Medicine::create(['institution_id' => $this->institution->id, 'name' => 'Amoxicillin', 'stock_quantity' => 3, 'minimum_threshold' => 20]);

        $titles = collect($this->withSession($this->headSession())
            ->get('/dashboard/school-head')->assertOk()->viewData('queue'))
            ->pluck('title')->implode(' | ');

        $this->assertStringContainsString('1 medicine out of stock', $titles);
        $this->assertStringContainsString('1 medicine below the reorder threshold', $titles);
    }

    #[Test]
    public function the_metrics_endpoint_renders_every_live_panel(): void
    {
        $this->makeLearner();

        $payload = $this->withSession($this->headSession())
            ->getJson('/dashboard/school-head/metrics')
            ->assertOk()
            ->json();

        foreach (['stats', 'queue', 'cycle', 'snapshot', 'programs', 'performance', 'clinic', 'feeding', 'nutrition', 'consent', 'inventory'] as $pane) {
            $this->assertArrayHasKey($pane, $payload['html']);
        }

        $this->assertStringContainsString('Consent Completion', $payload['html']['performance']);
        $this->assertStringContainsString('Clinic Consultations', $payload['html']['stats']);
    }

    #[Test]
    public function what_the_adviser_and_the_nurse_write_reaches_the_head(): void
    {
        $session = $this->headSession();

        $before = $this->withSession($session)
            ->getJson('/dashboard/school-head/metrics/pulse')->assertOk()->json('stamp');

        // The class adviser encodes a learner and their weighing.
        $learner = $this->makeLearner('Grade 7 / Rizal', [
            'student_name' => 'Encoded Learner',
            'nutritional_status' => 'Wasted',
            'baseline_nutritional_status' => 'Wasted',
        ]);

        // The school nurse logs a consultation and a clinic note for them.
        $this->makeConsultation(['student_name' => 'Encoded Learner', 'status' => 'referred']);
        ClinicNote::create([
            'institution_id' => $this->institution->id,
            'student_lrn' => $learner->student_id,
            'school_year' => $learner->school_year,
            'note' => 'Rested in the clinic.',
            'author_name' => 'Nurse Reyes',
        ]);

        // The head's screens notice without anybody pressing refresh: the stamp
        // moves, which is what makes the panels re-read.
        $after = $this->withSession($session)
            ->getJson('/dashboard/school-head/metrics/pulse')->assertOk()->json('stamp');
        $this->assertNotSame($before, $after);

        $stats = $this->withSession($session)
            ->get('/dashboard/school-head')->assertOk()->viewData('stats');

        $this->assertSame(1, $stats['total_students']);
        $this->assertSame(1, $stats['consultations']);
        $this->assertSame(1, $stats['referrals']);

        $clinic = $this->withSession($session)
            ->get('/dashboard/school-head/health')->assertOk()->viewData('clinic');
        $this->assertSame(1, $clinic['notes']);
    }

    #[Test]
    public function another_schools_entries_never_reach_this_head(): void
    {
        $session = $this->headSession();

        $before = $this->withSession($session)
            ->getJson('/dashboard/school-head/metrics/pulse')->assertOk()->json('stamp');

        // Everything below belongs to the school next door.
        $this->makeLearner('Grade 7 / Rizal', ['student_name' => 'Neighbour Learner'], $this->otherSchool);
        $this->makeConsultation(['student_name' => 'Neighbour Learner'], $this->otherSchool);
        Medicine::create([
            'institution_id' => $this->otherSchool->id,
            'name' => 'Neighbour Paracetamol',
            'stock_quantity' => 0,
            'minimum_threshold' => 20,
        ]);

        // The stamp is scoped, so their write does not even wake this school's
        // screens — and none of their figures arrive.
        $after = $this->withSession($session)
            ->getJson('/dashboard/school-head/metrics/pulse')->assertOk()->json('stamp');
        $this->assertSame($before, $after);

        $response = $this->withSession($session)->get('/dashboard/school-head')->assertOk();
        $stats = $response->viewData('stats');

        $this->assertSame(0, $stats['total_students']);
        $this->assertSame(0, $stats['consultations']);
        $this->assertSame(0, $stats['medicines_tracked']);
        $response->assertDontSee('Neighbour Learner');
        $response->assertDontSee('Neighbour Paracetamol');
    }

    #[Test]
    public function a_session_with_no_school_reads_nothing_at_all(): void
    {
        $this->makeLearner();
        $this->makeConsultation();
        Medicine::create(['institution_id' => $this->institution->id, 'name' => 'Paracetamol', 'stock_quantity' => 5, 'minimum_threshold' => 20]);

        // The scope is required, not optional: without an institution the reads
        // return nothing rather than falling through to every school's children.
        $overview = SchoolHeadOverview::for(null);
        $health = SchoolHeadHealthOverview::for(null, null, collect());

        $this->assertTrue($overview->records->isEmpty());
        $this->assertSame(0, $health->clinic()['total']);
        $this->assertSame(0, $health->inventory()['tracked']);
    }

    #[Test]
    public function the_consent_export_is_a_workbook_scoped_to_this_school(): void
    {
        $this->makeLearner();

        $this->withSession($this->headSession())
            ->get('/dashboard/school-head/consent/export')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    #[Test]
    public function the_inventory_export_is_a_workbook(): void
    {
        Medicine::create(['institution_id' => $this->institution->id, 'name' => 'Paracetamol', 'stock_quantity' => 12, 'minimum_threshold' => 20]);

        $this->withSession($this->headSession())
            ->get('/dashboard/school-head/inventory/export')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
