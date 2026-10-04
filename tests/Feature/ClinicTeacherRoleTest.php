<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Institution;
use App\Models\Medicine;
use App\Models\MedicineDispense;
use App\Models\StudentHealthRecord;
use App\Support\DispensingRights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Clinic Teacher — a teacher who covers the school clinic.
 *
 * The role reads the School Nurse's screens, and the one thing that makes it
 * a different role is what it may hand over: **paracetamol and nothing else**.
 * That is a clinical boundary, so these tests care much more about the refusal
 * than about the rail.
 *
 * Two of them are the ones that matter. `a_crafted_request_cannot_dispense_…`
 * posts a medicine id the form never offered, which is the only test that
 * proves the restriction is a rule rather than a greyed-out `<option>`; and
 * `a_refused_dispense_records_nothing` pins that the refusal takes the
 * consultation down with it, so a rejected attempt leaves no visit and no
 * stock movement to reconcile.
 */
class ClinicTeacherRoleTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    private Medicine $paracetamol;

    private Medicine $amoxicillin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);

        $this->paracetamol = Medicine::create([
            'institution_id' => $this->school->id,
            'name' => 'Paracetamol 500mg tablet',
            'stock_quantity' => 100,
            'minimum_threshold' => 20,
            'unit' => 'tablets',
        ]);

        $this->amoxicillin = Medicine::create([
            'institution_id' => $this->school->id,
            'name' => 'Amoxicillin 250mg capsule',
            'stock_quantity' => 50,
            'minimum_threshold' => 10,
            'unit' => 'capsules',
        ]);
    }

    private function sessionFor(string $role): array
    {
        return [
            'active_role' => $role,
            'active_name' => 'Teacher Dela Cruz',
            'active_username' => 'clinicteacher1',
            'active_school_name' => $this->school->name,
            'active_institution_id' => $this->school->id,
        ];
    }

    private function consultation(array $overrides = []): array
    {
        return array_merge([
            'consulted_at' => now()->toDateString(),
            'student_name' => 'Cruz, Juan',
            'grade_section' => 'Grade 10 - Dalton',
            'condition' => 'Headache',
            'status' => 'treated',
        ], $overrides);
    }

    // ── The rule ────────────────────────────────────────────────────────

    /** The reading itself, before any screen is involved. */
    #[Test]
    public function the_rule_names_one_medicine_for_this_role_and_the_whole_shelf_for_the_nurse(): void
    {
        $this->assertTrue(DispensingRights::mayDispense('clinic_teacher'));
        $this->assertTrue(DispensingRights::mayDispense('school_nurse'));
        // Clinic Staff log consultations and are not admitted at all.
        $this->assertFalse(DispensingRights::mayDispense('clinic_staff'));
        $this->assertFalse(DispensingRights::mayDispense('class_adviser'));

        // Matched on the name, so every spelling the catalogue carries counts.
        foreach (['Paracetamol 500mg tablet', 'Paracetamol 250mg/5ml suspension', 'paracetamol (generic)'] as $name) {
            $this->assertTrue(
                DispensingRights::allows('clinic_teacher', $name),
                $name.' is paracetamol and should be allowed.'
            );
        }

        foreach (['Amoxicillin 250mg capsule', 'Antihistamine', 'Vitamin C', 'Mefenamic acid'] as $name) {
            $this->assertFalse(DispensingRights::allows('clinic_teacher', $name));
            $this->assertTrue(DispensingRights::allows('school_nurse', $name), 'The nurse has the whole shelf.');
        }

        // "Restricted" means admitted but not to everything, so a role with
        // no access at all is not a narrower case of it.
        $this->assertTrue(DispensingRights::isRestricted('clinic_teacher'));
        $this->assertFalse(DispensingRights::isRestricted('school_nurse'));
        $this->assertFalse(DispensingRights::isRestricted('clinic_staff'));
    }

    // ── Dispensing ──────────────────────────────────────────────────────

    /** CT-16-11, CT-16-12: paracetamol saves, and the stock drops. */
    #[Test]
    public function a_clinic_teacher_can_dispense_paracetamol_and_the_stock_drops(): void
    {
        $this->withSession($this->sessionFor('clinic_teacher'))
            ->from(route('dashboard.consultation-log'))
            ->post(route('consultations.store'), $this->consultation([
                'medicine_id' => $this->paracetamol->id,
                'medicine_quantity' => 4,
            ]))
            ->assertRedirect();

        $this->assertSame(96, $this->paracetamol->fresh()->stock_quantity);

        $dispense = MedicineDispense::firstOrFail();
        $this->assertSame(4, $dispense->quantity);
        // CT-24-05's sibling: the draw is filed against whoever made it, never
        // defaulted to the nurse who usually does.
        $this->assertSame('clinic_teacher', $dispense->dispensed_by_role);
        $this->assertSame('Teacher Dela Cruz', $dispense->dispensed_by_name);
    }

    /**
     * CT-16-15: the restriction is not the form.
     *
     * This posts an id the dialog renders as a disabled option, which is
     * exactly what a replayed form or an edited request would send.
     */
    #[Test]
    public function a_crafted_request_cannot_dispense_anything_but_paracetamol(): void
    {
        $this->withSession($this->sessionFor('clinic_teacher'))
            ->from(route('dashboard.consultation-log'))
            ->post(route('consultations.store'), $this->consultation([
                'medicine_id' => $this->amoxicillin->id,
                'medicine_quantity' => 1,
            ]))
            ->assertRedirect(route('dashboard.consultation-log'))
            ->assertSessionHasErrors('medicine_id', null, 'consultation');

        $this->assertSame(50, $this->amoxicillin->fresh()->stock_quantity, 'Nothing came off the shelf.');
    }

    /** CT-24-06: a refused attempt leaves no visit and no stock movement. */
    #[Test]
    public function a_refused_dispense_records_nothing(): void
    {
        $this->withSession($this->sessionFor('clinic_teacher'))
            ->from(route('dashboard.consultation-log'))
            ->post(route('consultations.store'), $this->consultation([
                'medicine_id' => $this->amoxicillin->id,
                'medicine_quantity' => 1,
            ]));

        $this->assertSame(0, MedicineDispense::count(), 'No dispense row.');
        $this->assertSame(0, Consultation::count(), 'And the consultation rolled back with it.');
    }

    /** The nurse is unaffected: the whole shelf is still theirs. */
    #[Test]
    public function the_nurse_can_still_dispense_a_medicine_the_teacher_cannot(): void
    {
        $this->withSession(array_merge($this->sessionFor('school_nurse'), ['active_name' => 'Nurse Cruz']))
            ->from(route('dashboard.consultation-log'))
            ->post(route('consultations.store'), $this->consultation([
                'medicine_id' => $this->amoxicillin->id,
                'medicine_quantity' => 2,
            ]))
            ->assertRedirect();

        $this->assertSame(48, $this->amoxicillin->fresh()->stock_quantity);
    }

    /** Clinic Staff were never admitted, and still are not. */
    #[Test]
    public function clinic_staff_still_dispense_nothing(): void
    {
        $this->withSession($this->sessionFor('clinic_staff'))
            ->from(route('dashboard.consultation-log'))
            ->post(route('consultations.store'), $this->consultation([
                'medicine_id' => $this->paracetamol->id,
                'medicine_quantity' => 1,
            ]))
            ->assertRedirect();

        // The visit is logged — that is their job — but no stock moved.
        $this->assertSame(1, Consultation::count());
        $this->assertSame(0, MedicineDispense::count());
        $this->assertSame(100, $this->paracetamol->fresh()->stock_quantity);
    }

    /** CT-16-13, CT-16-14: the shelf is visible, the rest is disabled and says why. */
    #[Test]
    public function the_dialog_lists_the_whole_shelf_and_disables_what_this_role_may_not_give(): void
    {
        $html = $this->withSession($this->sessionFor('clinic_teacher'))
            ->get(route('dashboard.consultation-log'))
            ->assertOk()
            ->getContent();

        // Both are listed, so the teacher can see what the clinic holds.
        $this->assertStringContainsString('Paracetamol 500mg tablet', $html);
        $this->assertStringContainsString('Amoxicillin 250mg capsule', $html);

        // Only one of them is selectable.
        $this->assertMatchesRegularExpression(
            '/<option value="'.$this->amoxicillin->id.'"[^>]*\sdisabled/s',
            $html,
            'A medicine this role may not dispense is offered as a live option.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<option value="'.$this->paracetamol->id.'"[^>]*\sdisabled/s',
            $html,
            'Paracetamol must stay selectable.'
        );

        // And the restriction is stated rather than left to be guessed at.
        $this->assertStringContainsString('may dispense Paracetamol only', $html);
    }

    /** The nurse's own dialog is not narrowed by any of this. */
    #[Test]
    public function the_nurses_dialog_disables_nothing(): void
    {
        $html = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.consultation-log'))
            ->assertOk()
            ->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/<option value="'.$this->amoxicillin->id.'"[^>]*\sdisabled/s',
            $html
        );
        $this->assertStringNotContainsString('may dispense Paracetamol only', $html);
    }

    // ── Inventory: read the shelf, do not change it ─────────────────────

    /** CT-20-11: the list, the cards and the forecast all open. */
    #[Test]
    public function a_clinic_teacher_reads_the_inventory(): void
    {
        $html = $this->withSession($this->sessionFor('clinic_teacher'))
            ->get(route('dashboard.medicine-inventory'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Paracetamol 500mg tablet', $html);
        $this->assertStringContainsString('Total Medicines', $html);
        $this->assertStringContainsString('Clinic Teacher', $html, 'The breadcrumb names the signed-in role.');
    }

    /** CT-20-13, as settled: reading the shelf is not changing it. */
    #[Test]
    public function a_clinic_teacher_is_offered_no_way_to_change_the_stock(): void
    {
        $html = $this->withSession($this->sessionFor('clinic_teacher'))
            ->get(route('dashboard.medicine-inventory'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-add-medicine-open', $html);
        $this->assertStringNotContainsString('data-receive-open', $html);

        // The nurse still has both, so this is a role boundary and not a
        // feature that went missing.
        $nurse = $this->withSession($this->sessionFor('school_nurse'))
            ->get(route('dashboard.medicine-inventory'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-add-medicine-open', $nurse);
        $this->assertStringContainsString('data-receive-open', $nurse);
    }

    /** And the endpoints refuse it, whatever any page renders. */
    #[Test]
    public function the_stock_write_endpoints_refuse_a_clinic_teacher(): void
    {
        $before = Medicine::count();

        $this->withSession($this->sessionFor('clinic_teacher'))
            ->post(route('medicine-inventory.store'), [
                'catalogue_name' => 'Paracetamol 500mg tablet',
                'stock_quantity' => 10,
                'minimum_threshold' => 2,
                'unit' => 'tablets',
            ])
            ->assertRedirect(route('dashboard.medicine-inventory'));

        $this->assertSame($before, Medicine::count(), 'No item was created.');

        $this->withSession($this->sessionFor('clinic_teacher'))
            ->post(route('medicine-inventory.receive', $this->paracetamol), [
                'quantity' => 50,
                'received_at' => now()->toDateString(),
            ])
            ->assertRedirect(route('dashboard.medicine-inventory'));

        $this->assertSame(100, $this->paracetamol->fresh()->stock_quantity, 'No delivery was booked in.');

        // The standalone Add Medicine page is refused too, not merely unlinked.
        $this->withSession($this->sessionFor('clinic_teacher'))
            ->get(route('medicine-inventory.create'))
            ->assertRedirect(route('dashboard.medicine-inventory'));
    }

    // ── The screens the role shares with the nurse ──────────────────────

    /** CT-01, CT-24-02: the nurse's module set, in this role's own rail. */
    #[Test]
    public function the_clinic_teachers_dashboard_is_the_clinic_dashboard_in_its_own_rail(): void
    {
        StudentHealthRecord::create([
            'student_id' => '123456789012',
            'institution_id' => $this->school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'section' => 'Grade 10 - Dalton',
            'school_name' => $this->school->name,
            'student_name' => 'Cruz, Juan',
        ]);

        $html = $this->withSession($this->sessionFor('clinic_teacher'))
            ->get(route('dashboard.clinic-teacher'))
            ->assertOk()
            ->getContent();

        // The four cards CT-01-01 names.
        foreach (['Total Records', 'Consultations Today', 'Low Stock'] as $card) {
            $this->assertStringContainsString($card, $html, $card.' is missing from the dashboard.');
        }

        // Its own rail, pointing at its own dashboard — not the nurse's.
        $this->assertStringContainsString(route('dashboard.clinic-teacher'), $html);
        $this->assertStringContainsString('Clinic Teacher', $html);
        // And the rail offers no stock write.
        $this->assertStringNotContainsString(route('medicine-inventory.create'), $html);
    }

    /** Every screen the matrix walks through opens for this role. */
    #[Test]
    public function every_clinic_screen_opens_for_a_clinic_teacher(): void
    {
        $session = $this->sessionFor('clinic_teacher');

        foreach ([
            route('dashboard.clinic-teacher'),
            route('dashboard.student-health-records'),
            route('nurse.index'),
            route('dashboard.consultation-log'),
            route('dashboard.clinic-teacher.nutritional-status'),
            route('dashboard.clinic-teacher.feeding-program'),
            route('consent-forms.nurse-index'),
            route('dashboard.medicine-inventory'),
            route('dashboard.data-visualization'),
            route('settings'),
        ] as $url) {
            $this->withSession($session)->get($url)->assertOk($url.' did not open.');
        }
    }

    /** CT-18-01: the feeding programme is a read for the clinic. */
    #[Test]
    public function the_feeding_programme_page_is_read_only_for_a_clinic_teacher(): void
    {
        $html = $this->withSession($this->sessionFor('clinic_teacher'))
            ->get(route('dashboard.clinic-teacher.feeding-program'))
            ->assertOk()
            ->getContent();

        // No way in to the attendance write from this role's copy of the page.
        $this->assertStringNotContainsString('data-record-open', $html);
    }

    /** A role that is not the clinic's is sent to its own dashboard. */
    #[Test]
    public function another_role_cannot_open_the_clinic_teachers_dashboard(): void
    {
        $this->withSession([
            'active_role' => 'class_adviser',
            'active_name' => 'Maria Santos',
            'active_username' => 'adviser1',
            'active_school_name' => $this->school->name,
            'active_institution_id' => $this->school->id,
            'assigned_grade_level' => 'Grade 10',
            'assigned_section' => 'Dalton',
        ])
            ->get(route('dashboard.clinic-teacher'))
            ->assertRedirect(route('dashboard.class-adviser'));
    }

    /**
     * A prototype session on this role's URL becomes this role, not the
     * nurse — the rail and the breadcrumb would otherwise say School Nurse on
     * a page reached from the Clinic Teacher's own link.
     */
    #[Test]
    public function the_prototype_session_for_this_url_is_a_clinic_teacher(): void
    {
        $this->get(route('dashboard.clinic-teacher'))
            ->assertOk()
            ->assertSessionHas('active_role', 'clinic_teacher');
    }
}
