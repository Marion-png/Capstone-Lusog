<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Condition;
use App\Models\Consultation;
use App\Models\Institution;
use App\Models\Medicine;
use App\Models\MedicineDispense;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Clinic Staff can correct a consultation after it was saved. Before this a
 * visit could only be recorded: a mistyped name, a wrong status or a forgotten
 * recommendation stayed on the record for good.
 */
class ConsultationEditTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function sessionFor(string $role = 'clinic_staff', ?Institution $school = null): array
    {
        return [
            'active_role' => $role,
            'active_name' => 'Rosa Clinic',
            'active_username' => 'clinic.test',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => ($school ?? $this->school)->id,
        ];
    }

    private function visit(array $attributes = []): Consultation
    {
        return Consultation::create($attributes + [
            'institution_id' => $this->school->id,
            'consulted_at' => '2026-11-03 09:15:42',
            'student_name' => 'Dimaculangan, Ligaya',
            'grade_section' => 'Grade 7 - Maingat',
            'condition' => 'Headache',
            'treatment_given' => 'Rest',
            'notes' => 'Felt dizzy at flag ceremony',
            'status' => 'treated',
            'recorded_by_name' => 'Nena Nurse',
            'recorded_by_role' => 'school_nurse',
        ]);
    }

    /** @return array<string, mixed> */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'consulted_at' => '2026-11-03T09:15',
            'student_name' => 'Dimaculangan, Ligaya',
            'grade_section' => 'Grade 7 - Maingat',
            'condition' => 'Headache',
            'treatment_given' => 'Rest',
            'notes' => 'Felt dizzy at flag ceremony',
            'status' => 'treated',
        ];
    }

    private function medicineOn(Consultation $visit, int $stock = 20, int $given = 2): Medicine
    {
        $medicine = Medicine::create([
            'institution_id' => $this->school->id,
            'name' => 'Paracetamol 500mg tablet',
            'unit' => 'tablets',
            'stock_quantity' => $stock,
            'minimum_threshold' => 5,
        ]);

        MedicineDispense::create([
            'institution_id' => $this->school->id,
            'medicine_id' => $medicine->id,
            'consultation_id' => $visit->id,
            'student_name' => $visit->student_name,
            'reason' => $visit->condition,
            'quantity' => $given,
            'dispensed_by_name' => 'Nena Nurse',
            'dispensed_by_role' => 'school_nurse',
            'dispensed_at' => now(),
        ]);

        return $medicine;
    }

    #[Test]
    public function clinic_staff_get_an_edit_button_on_the_log_and_their_dashboard(): void
    {
        $visit = $this->visit();

        foreach (['/dashboard/consultation-log', '/dashboard/clinic-staff'] as $page) {
            $html = $this->withSession($this->sessionFor())->get($page)->assertOk()->getContent();

            $this->assertStringContainsString('data-consult-edit-id="'.$visit->id.'"', $html, $page);
            $this->assertStringContainsString('<th>Action</th>', $html, $page);
            $this->assertStringContainsString('id="consultModal"', $html, $page);
        }
    }

    #[Test]
    #[DataProvider('rolesWithoutEdit')]
    public function other_clinic_roles_are_not_offered_it(string $role): void
    {
        $this->visit();

        $html = $this->withSession($this->sessionFor($role))
            ->get('/dashboard/consultation-log')
            ->assertOk()
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/data-consult-edit-id="\d/', $html);
        $this->assertStringNotContainsString('<th>Action</th>', $html);
    }

    /** @return array<string, array{string}> */
    public static function rolesWithoutEdit(): array
    {
        return [
            'school nurse' => ['school_nurse'],
            'clinic teacher' => ['clinic_teacher'],
        ];
    }

    #[Test]
    #[DataProvider('rolesRefused')]
    public function the_endpoint_refuses_every_other_role(string $role): void
    {
        $visit = $this->visit();

        $this->withSession($this->sessionFor($role))
            ->post(route('consultations.update', $visit->id), $this->form(['status' => 'referred']))
            ->assertForbidden();

        $this->assertSame('treated', $visit->fresh()->status);
    }

    /** @return array<string, array{string}> */
    public static function rolesRefused(): array
    {
        return [
            'school nurse' => ['school_nurse'],
            'clinic teacher' => ['clinic_teacher'],
            'class adviser' => ['class_adviser'],
            'school head' => ['school_head'],
        ];
    }

    #[Test]
    public function a_visit_at_another_school_cannot_be_reached(): void
    {
        $other = Institution::create(['name' => 'Other NHS', 'status' => 'active']);
        $visit = $this->visit(['institution_id' => $other->id]);

        $this->withSession($this->sessionFor())
            ->post(route('consultations.update', $visit->id), $this->form(['status' => 'referred']))
            ->assertNotFound();

        $this->assertSame('treated', $visit->fresh()->status);
    }

    #[Test]
    public function saving_changes_the_visit_and_keeps_who_recorded_it(): void
    {
        $visit = $this->visit();

        $this->withSession($this->sessionFor())
            ->post(route('consultations.update', $visit->id), $this->form([
                'consulted_at' => '2026-11-03T10:30',
                'student_name' => 'Dimaculangan, Ligaya Mae',
                'grade_section' => 'Grade 7 - Matiyaga',
                'condition' => 'Migraine',
                'treatment_given' => 'Sent home',
                'notes' => 'Parent fetched her',
                'notes_shared_with_adviser' => '1',
                'status' => 'referred',
                'return_to' => url('/dashboard/clinic-staff'),
            ]))
            ->assertRedirect(url('/dashboard/clinic-staff'))
            ->assertSessionHas('success', 'Consultation updated.');

        $fresh = $visit->fresh();

        $this->assertSame('2026-11-03 10:30', $fresh->consulted_at->format('Y-m-d H:i'));
        $this->assertSame('Dimaculangan, Ligaya Mae', $fresh->student_name);
        $this->assertSame('Grade 7 - Matiyaga', $fresh->grade_section);
        $this->assertSame('Migraine', $fresh->condition);
        $this->assertSame('Sent home', $fresh->treatment_given);
        $this->assertSame('Parent fetched her', $fresh->notes);
        $this->assertTrue($fresh->notes_shared_with_adviser);
        $this->assertSame('referred', $fresh->status);

        $this->assertSame('Nena Nurse', $fresh->recorded_by_name);
        $this->assertSame('school_nurse', $fresh->recorded_by_role);
    }

    #[Test]
    public function the_edit_stays_encrypted_at_rest(): void
    {
        $visit = $this->visit();

        $this->withSession($this->sessionFor())
            ->post(route('consultations.update', $visit->id), $this->form(['treatment_given' => 'Sent home early']));

        $raw = DB::table('consultations')->where('id', $visit->id)->value('treatment_given');

        $this->assertStringNotContainsString('Sent home early', (string) $raw);
        $this->assertSame('Sent home early', $visit->fresh()->treatment_given);
    }

    #[Test]
    public function the_audit_entry_names_only_what_changed_with_its_old_value(): void
    {
        $visit = $this->visit();
        $before = (int) AuditLog::max('id');

        $this->withSession($this->sessionFor())
            ->post(route('consultations.update', $visit->id), $this->form(['status' => 'referred']))
            ->assertRedirect();

        $entry = AuditLog::query()
            ->where('id', '>', $before)
            ->where('action', 'updated')
            ->where('subject_type', 'Consultation')
            ->sole();

        $this->assertSame('Updated Consultation #'.$visit->id.' (fields: status)', $entry->description);
        $this->assertSame(['from' => 'treated', 'to' => 'referred'], $entry->details['changes']['status']);
        $this->assertSame('Rosa Clinic', $entry->actor_name);
    }

    #[Test]
    public function saving_nothing_new_writes_no_audit_entry_and_keeps_the_seconds(): void
    {
        $visit = $this->visit();
        $before = (int) AuditLog::max('id');

        $this->withSession($this->sessionFor())
            ->post(route('consultations.update', $visit->id), $this->form())
            ->assertRedirect();

        $this->assertSame(0, AuditLog::query()->where('id', '>', $before)->where('action', 'updated')->count());
        $this->assertSame('09:15:42', $visit->fresh()->consulted_at->format('H:i:s'));
    }

    #[Test]
    public function a_note_taken_off_the_visit_is_no_longer_shared(): void
    {
        $visit = $this->visit(['notes_shared_with_adviser' => true]);

        $this->withSession($this->sessionFor())
            ->post(route('consultations.update', $visit->id), $this->form(['notes' => '', 'notes_shared_with_adviser' => '1']));

        $this->assertNull($visit->fresh()->notes);
        $this->assertFalse($visit->fresh()->notes_shared_with_adviser);
    }

    #[Test]
    public function a_condition_from_the_catalogue_is_stored_by_its_name(): void
    {
        $visit = $this->visit();
        $fever = Condition::create(['name' => 'Fever', 'category' => 'General']);

        $this->withSession($this->sessionFor())
            ->post(route('consultations.update', $visit->id), $this->form(['condition' => null, 'condition_id' => $fever->id]));

        $this->assertSame('Fever', $visit->fresh()->condition);
        $this->assertSame($fever->id, $visit->fresh()->condition_id);
    }

    #[Test]
    public function a_visit_with_a_medicine_keeps_its_learner_and_its_stock(): void
    {
        $visit = $this->visit();
        $medicine = $this->medicineOn($visit, stock: 20, given: 2);

        $this->withSession($this->sessionFor())
            ->post(route('consultations.update', $visit->id), $this->form([
                'student_name' => 'Somebody Else',
                'grade_section' => 'Grade 8 - Ilang',
                'status' => 'referred',
            ]))
            ->assertRedirect();

        $fresh = $visit->fresh();

        $this->assertSame('referred', $fresh->status, 'The rest of the visit can still be corrected.');
        $this->assertSame('Dimaculangan, Ligaya', $fresh->student_name);
        $this->assertSame('Grade 7 - Maingat', $fresh->grade_section);

        $this->assertSame(20, $medicine->fresh()->stock_quantity);
        $this->assertSame(1, MedicineDispense::query()->where('consultation_id', $visit->id)->count());
    }

    #[Test]
    public function the_dispense_follows_a_corrected_condition(): void
    {
        $visit = $this->visit();
        $this->medicineOn($visit);

        $this->withSession($this->sessionFor())
            ->post(route('consultations.update', $visit->id), $this->form(['condition' => 'Migraine']));

        $this->assertSame('Migraine', MedicineDispense::query()->where('consultation_id', $visit->id)->sole()->reason);
    }

    #[Test]
    public function the_button_carries_the_visit_with_its_medicine(): void
    {
        $visit = $this->visit();
        $this->medicineOn($visit);

        $html = $this->withSession($this->sessionFor())
            ->get('/dashboard/consultation-log')
            ->assertOk()
            ->getContent();

        preg_match('/data-consult-edit="([^"]+)"/', $html, $match);
        $payload = json_decode(html_entity_decode($match[1], ENT_QUOTES), true);

        $this->assertSame($visit->id, $payload['id']);
        $this->assertSame(route('consultations.update', $visit->id), $payload['url']);
        $this->assertSame('2026-11-03T09:15', $payload['consulted_at']);
        $this->assertSame('Dimaculangan, Ligaya', $payload['name']);
        $this->assertSame('Felt dizzy at flag ceremony', $payload['notes']);
        $this->assertStringContainsString('Paracetamol 500mg tablet × 2 tablets', $payload['medicine']);
    }

    #[Test]
    public function a_failed_save_reopens_the_dialog_on_that_visit(): void
    {
        $visit = $this->visit();

        $response = $this->withSession($this->sessionFor())
            ->from('/dashboard/consultation-log')
            ->followingRedirects()
            ->post(route('consultations.update', $visit->id), $this->form([
                'consultation_id' => $visit->id,
                'student_name' => '',
            ]));

        $html = $response->assertOk()->getContent();

        $this->assertStringContainsString('data-bmodal-autoopen', $html);
        $this->assertStringContainsString('action="'.route('consultations.update', $visit->id).'"', $html);
        $this->assertStringContainsString('id="cm_consultation_id" value="'.$visit->id.'"', $html);
        $this->assertSame('Dimaculangan, Ligaya', $visit->fresh()->student_name);
    }

    /** The dashboard corrects visits, so it does not carry the school's roll. */
    #[Test]
    public function the_dashboards_dialog_carries_no_learner_index_and_the_logs_does(): void
    {
        $this->visit();

        StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_id' => '100000000301',
            'student_name' => 'Rollcall, Solana',
            'school_name' => 'Sta. Ana NHS',
            'section' => 'Grade 7 / Maingat',
            'student_details' => ['first_name' => 'Solana', 'last_name' => 'Rollcall', 'grade_level' => 'Grade 7', 'section' => 'Maingat'],
        ]);

        $roster = [['lrn' => '100000000301', 'first_name' => 'Solana', 'last_name' => 'Rollcall', 'grade_level' => 'Grade 7', 'section' => 'Maingat']];
        $session = $this->sessionFor() + ['school_health_card_records' => $roster];

        $this->withSession($session)->get('/dashboard/clinic-staff')->assertOk()->assertDontSee('100000000301');
        $this->withSession($session)->get('/dashboard/consultation-log')->assertOk()->assertSee('100000000301');
    }

    #[Test]
    public function a_new_consultation_still_records_as_before(): void
    {
        $html = $this->withSession($this->sessionFor())
            ->get('/dashboard/consultation-log')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('action="'.route('consultations.store').'"', $html);
        $this->assertStringContainsString('id="cm_consultation_id" value=""', $html);

        $this->withSession($this->sessionFor())
            ->post(route('consultations.store'), $this->form(['student_name' => 'New Learner']))
            ->assertRedirect();

        $this->assertSame(
            'New Learner',
            Consultation::query()->latest('id')->first()->student_name,
        );
    }
}
