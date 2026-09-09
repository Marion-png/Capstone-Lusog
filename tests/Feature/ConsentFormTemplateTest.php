<?php

namespace Tests\Feature;

use App\Models\HealthConsentForm;
use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The consent form is a facsimile of the DepEd / DOH **Sulat-Pahibalo**
 * (Region XI, Bisaya), and it has to keep reading as that document.
 *
 * A form a parent signs is a record of what they were told, so the wording,
 * the service list and — the part that is easy to get subtly wrong — the
 * *nesting* all carry meaning. Three things this pins:
 *
 *   1. The paper form's own wording and every service on it.
 *   2. The allergy block's shape: three kinds of allergy indented under one
 *      heading, with "Kasamtangang Sakit / Other Illnesses" a sibling of that
 *      heading rather than a fourth kind of allergy.
 *   3. Whose names and seals appear on it — the two government seals, and this
 *      school's own head.
 */
class ConsentFormTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Sta. Ana National High School', 'status' => 'active']);
    }

    private function adviserSession(): array
    {
        return [
            'active_role' => 'class_adviser',
            'active_name' => 'Maria Santos',
            'active_username' => 'maria.santos',
            'active_school_name' => 'Sta. Ana National High School',
            'active_institution_id' => $this->institution->id,
            'assigned_grade_level' => 'Grade 7/SPED',
            'assigned_section' => 'SPED-A',
            'assigned_school_name' => 'Sta. Ana National High School',
            'school_health_card_records' => [
                [
                    'last_name' => 'Dela Cruz',
                    'first_name' => 'Juan',
                    'middle_name' => 'R',
                    'lrn' => '123456789012',
                    'parent_guardian' => 'Pedro Dela Cruz',
                    'address' => '123 Damaso Suazo St., Davao City',
                    'division' => 'DAVAO CITY',
                    'grade_level' => 'Grade 7/SPED',
                    'section' => 'SPED-A',
                ],
            ],
        ];
    }

    private function openForm(): HealthConsentForm
    {
        $this->withSession($this->adviserSession())
            ->post(route('consent-forms.open'), ['lrn' => '123456789012']);

        return HealthConsentForm::firstOrFail();
    }

    private function renderedForm(): string
    {
        $form = $this->openForm();

        return $this->withSession($this->adviserSession())
            ->get(route('consent-forms.show', $form))
            ->assertOk()
            ->getContent();
    }

    /**
     * The rendered document without the page around it. The consent pages
     * inline their stylesheet, so a naive search of the whole response finds
     * CSS selectors and counts them as markup.
     */
    private function documentOnly(string $html): string
    {
        $start = strpos($html, '<div class="doc">');
        $this->assertNotFalse($start, 'The document must render.');

        return substr($html, (int) $start);
    }

    // ── The paper form's own words ──────────────────────────────────────────

    #[Test]
    public function the_document_carries_the_sulat_pahibalo_heading_and_fields(): void
    {
        $html = $this->renderedForm();

        foreach ([
            'Republic of the Philippines',
            'SULAT-PAHIBALO',
            'DIVISION:',
            'PANGALAN SA ISKWELAHAN:',
            'ADDRESS SA ISKWELAHAN:',
            'PETSA:',
            'PANGALAN SA ESTUDYANTE:',
            'PINUY-ANAN SA ESTUDYANTE:',
            'PANGALAN SA GINIKANAN / GUARDIAN:',
            'Tinahud namong Ginikanan / Guardian:',
            'TIMAAN SA PAGDAWAT SA SULAT-PAHIBALO UG PAGTUGOT SA GINIKANAN/GUARDIAN',
            '(Pangalan ug Pirma Sa Principal / School Head)',
            '(Pangalan ug Pirma Sa Ginikanan o Guardian)',
        ] as $line) {
            $this->assertStringContainsString($line, $html, "The form must carry the paper line: {$line}");
        }

        $this->assertStringContainsString(HealthConsentForm::DEFAULT_REGION, $html);
    }

    /**
     * Every service on the paper form, parents and children alike. A service
     * missing from the letter is a service the parent never consented to.
     */
    #[Test]
    public function every_service_on_the_paper_form_is_offered(): void
    {
        $html = $this->renderedForm();

        foreach (HealthConsentForm::SERVICES as $service) {
            $this->assertStringContainsString(e($service['label']), $html);

            foreach ($service['children'] as $childLabel) {
                $this->assertStringContainsString(e($childLabel), $html);
            }
        }
    }

    #[Test]
    public function the_three_consent_answers_are_offered_in_the_paper_forms_wording(): void
    {
        $html = $this->renderedForm();

        // Consent to all, consent with written exceptions, and refusal with a
        // written reason — the form has exactly these three, and a parent who
        // ticks none has answered nothing.
        $this->assertStringContainsString('base sa rekomendasyon sa DOH', $html);
        $this->assertStringContainsString('gawas lamang niini (palihug isulat)', $html);
        $this->assertStringContainsString('tungod niining mosunod nga rason (palihug isulat)', $html);
    }

    // ── The nesting the paper form uses ─────────────────────────────────────

    /**
     * The three allergy kinds are indented under the allergy heading, and
     * "Kasamtangang Sakit / Other Illnesses" is not one of them. Rendering all
     * four flat reads as though an illness were something a child is allergic
     * to, which is not what the parent was asked.
     */
    #[Test]
    public function other_illnesses_is_a_sibling_of_the_allergy_heading_not_a_kind_of_allergy(): void
    {
        // Measured on the document itself, not the whole page: the page inlines
        // consent-form.css, whose own `.doc-allergy-child` rules would be
        // counted as though they were markup.
        $document = $this->documentOnly($this->renderedForm());

        $heading = strpos($document, 'Kon adunay Allergy ang bata');
        $illness = strpos($document, 'Kasamtangang Sakit or Gibati');

        $this->assertNotFalse($heading, 'The allergy heading must render.');
        $this->assertNotFalse($illness, 'Other Illnesses must render.');
        $this->assertGreaterThan($heading, $illness, 'Other Illnesses follows the allergy block.');

        // The three kinds are marked as children; Other Illnesses is not.
        $this->assertSame(
            3,
            substr_count($document, 'doc-allergy-child'),
            'Exactly the three allergy kinds are indented under the heading.'
        );

        $illnessRow = substr($document, $illness - 400, 400);
        $this->assertStringNotContainsString(
            'doc-allergy-child',
            $illnessRow,
            'Other Illnesses must not be indented as a kind of allergy.'
        );
    }

    #[Test]
    public function the_medical_certificate_note_closes_the_health_block(): void
    {
        $this->assertStringContainsString(
            'Kon adunay Medical Certificate nga nagpamatuod sa kahimtang panglawas, palihug attach',
            $this->renderedForm()
        );
    }

    // ── Whose form it is ────────────────────────────────────────────────────

    /**
     * The Sulat-Pahibalo is a joint DepEd / DOH document and heads both seals.
     * The app's own logo is a product mark and has no place on it.
     */
    #[Test]
    public function the_form_heads_the_two_government_seals_and_not_the_apps_own_logo(): void
    {
        $html = $this->renderedForm();

        $this->assertStringContainsString('doc-seal-left', $html);
        $this->assertStringContainsString('doc-seal-right', $html);

        $document = $this->documentOnly($html);
        $this->assertStringNotContainsString(
            'lusog-logo',
            $document,
            'The app logo must never appear on a government form facsimile.'
        );
    }

    /**
     * The letter is signed by this school's own head. It used to print one
     * hardcoded principal on every school's form.
     */
    #[Test]
    public function the_letter_is_signed_by_this_schools_own_head(): void
    {
        DB::table('accounts')->insert([
            'name' => 'Principal Reyes',
            'username' => 'head.reyes',
            'password_hash' => bcrypt('secret'),
            'role' => 'school_head',
            'institution_id' => $this->institution->id,
            'school_name' => 'Sta. Ana National High School',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertStringContainsString('Principal Reyes', $this->renderedForm());
    }

    /**
     * A school whose head the app does not know gets a blank line to sign —
     * never an invented signatory, and never a neighbouring school's head.
     */
    #[Test]
    public function a_school_with_no_head_on_file_prints_a_blank_signature_line(): void
    {
        $otherSchool = Institution::create(['name' => 'Other School', 'status' => 'active']);

        DB::table('accounts')->insert([
            'name' => 'Principal Elsewhere',
            'username' => 'head.elsewhere',
            'password_hash' => bcrypt('secret'),
            'role' => 'school_head',
            'institution_id' => $otherSchool->id,
            'school_name' => 'Other School',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $html = $this->renderedForm();

        $this->assertStringNotContainsString('Principal Elsewhere', $html);
        $this->assertStringContainsString('(Pangalan ug Pirma Sa Principal / School Head)', $html);
    }
}
