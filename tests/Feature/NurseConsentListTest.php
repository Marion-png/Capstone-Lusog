<?php

namespace Tests\Feature;

use App\Models\HealthConsentForm;
use App\Models\Institution;
use App\Models\ParentalConsentForm;
use App\Models\StudentHealthRecord;
use App\Support\AdviserClassScope;
use App\Support\ConsentStanding;
use App\Support\EncryptedFileStorage;
use App\Support\LearnerPlacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The clinic's consent list answers "who may I give this service to, and
 * who still has no form?".
 *
 * Every learner on the year's roll is listed with their standing for the
 * chosen service, read through ConsentStanding; four cards count the same
 * rows over the learners the service applies to. Service, grade and year are
 * scope; the status filter and the name search narrow the list alone. The
 * page reads and never writes, and it sends no full LRN to the browser.
 */
class NurseConsentListTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    private function readerSession(string $role = 'school_nurse'): array
    {
        return [
            'active_role' => $role,
            'active_name' => 'Nurse Reyes',
            'active_institution_id' => $this->institution->id,
        ];
    }

    private function record(string $lrn, string $name, int $grade = 7, string $section = 'Sampaguita', ?string $year = null): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'school_year' => $year ?? StudentHealthRecord::currentSchoolYear(),
            'institution_id' => $this->institution->id,
            'student_id' => $lrn,
            'student_name' => $name,
            'school_name' => 'Test School',
            'section' => 'Grade '.$grade.' / '.$section,
        ]);
    }

    /** @param  list<string>  $services */
    private function letter(string $lrn, string $choice, array $services, string $exceptions = '', ?string $year = null): HealthConsentForm
    {
        return HealthConsentForm::create([
            'institution_id' => $this->institution->id,
            'student_lrn' => $lrn,
            'school_year' => $year ?? HealthConsentForm::currentSchoolYear(),
            'student_name' => 'Learner '.$lrn,
            'division' => HealthConsentForm::DEFAULT_DIVISION,
            'school_name' => 'Test School',
            'school_address' => HealthConsentForm::DEFAULT_SCHOOL_ADDRESS,
            'parent_guardian_name' => 'Maria Cruz',
            'status' => HealthConsentForm::STATUS_SIGNED,
            'consent_choice' => $choice,
            'consent_exceptions' => $exceptions !== '' ? $exceptions : null,
            'refusal_reason' => $choice === HealthConsentForm::CONSENT_DENY ? 'Relihiyon' : null,
            'services' => $services,
            'signature' => 'data:image/png;base64,iVBORw0KGgo=',
            'signed_at' => now(),
        ]);
    }

    private function list(array $query = [], string $role = 'school_nurse'): string
    {
        return $this->withSession($this->readerSession($role))
            ->get(route('consent-forms.nurse-index', $query))
            ->assertOk()
            ->getContent();
    }

    private function rowLink(StudentHealthRecord $record, string $service = ''): string
    {
        return e(route('consent-forms.nurse-learner', array_filter(['record' => $record->id, 'service' => $service ?: null])));
    }

    private function card(string $label, string $value): string
    {
        return '#cf-stat-label">'.preg_quote($label, '#').'</div>\s*<div class="cf-stat-value">'.preg_quote($value, '#').'</div>#';
    }

    // ── Spec test 1 ──────────────────────────────────────────────────

    #[Test]
    public function immunization_is_not_applicable_outside_grade_7_and_is_left_out_of_the_counts(): void
    {
        foreach ([['100000000007', 7], ['100000000008', 8], ['100000000009', 9], ['100000000010', 10]] as [$lrn, $grade]) {
            $this->record($lrn, 'Learner '.$grade, $grade);
            $this->letter($lrn, HealthConsentForm::CONSENT_ALL, ['immunization', 'immunization_grade7']);
        }

        $html = $this->list(['service' => 'immunization_grade7']);

        $this->assertSame(3, substr_count($html, '>Not applicable</span>'));
        $this->assertSame(1, substr_count($html, '>Consented</span>'));
        $this->assertMatchesRegularExpression($this->card('Consented', '1'), $html);
        $this->assertStringContainsString('of 1 learner this service applies to', $html);
        $this->assertMatchesRegularExpression($this->card('Consent rate', '100%'), $html);
    }

    // ── Spec test 2 ──────────────────────────────────────────────────

    #[Test]
    public function the_status_filter_and_the_search_narrow_the_list_but_not_the_cards(): void
    {
        $this->record('200000000001', 'Abad, Ana');
        $this->record('200000000002', 'Bautista, Ben');
        $this->record('200000000003', 'Cruz, Carla');
        $this->letter('200000000001', HealthConsentForm::CONSENT_ALL, ['checkup']);
        $this->letter('200000000002', HealthConsentForm::CONSENT_ALL, ['checkup']);

        $everyone = $this->list(['service' => 'checkup']);
        $narrowed = $this->list(['service' => 'checkup', 'status' => ConsentStanding::SERVICE_NO_FORM]);

        $this->assertStringContainsString('<span id="consentShownWord">3</span> shown', $everyone);
        $this->assertStringContainsString('<span id="consentShownWord">1</span> shown', $narrowed);

        // The cards are the same on both.
        foreach ([$everyone, $narrowed] as $html) {
            $this->assertMatchesRegularExpression($this->card('Consented', '2'), $html);
            $this->assertMatchesRegularExpression($this->card('No form yet', '1'), $html);
            $this->assertMatchesRegularExpression($this->card('Consent rate', '67%'), $html);
        }

        // The name search runs in the browser: the box sends nothing to the
        // server, so a learner's name never reaches a URL or the audit trail.
        $this->assertMatchesRegularExpression('#<input type="search" id="consentSearch"(?![^>]*\bname=)[^>]*>#', $everyone);
        $this->assertStringContainsString('data-name="abad, ana"', $everyone);
    }

    // ── Spec test 3 ──────────────────────────────────────────────────

    #[Test]
    public function the_consent_rate_is_a_whole_percent_and_a_dash_when_nothing_applies(): void
    {
        for ($i = 1; $i <= 16; $i++) {
            $lrn = sprintf('3000000000%02d', $i);
            $this->record($lrn, 'Learner '.$i);

            if ($i <= 10) {
                $this->letter($lrn, HealthConsentForm::CONSENT_ALL, ['checkup']);
            }
        }

        // 10 of 16 is 62.5%, shown as a whole percent.
        $this->assertMatchesRegularExpression($this->card('Consent rate', '63%'), $this->list(['service' => 'checkup']));

        // Grade 7 MR/Td at a grade with no Grade 7 learner applies to nobody.
        $html = $this->list(['service' => 'immunization_grade7', 'grade' => 8]);
        $this->assertMatchesRegularExpression($this->card('Consent rate', '—'), $html);
        $this->assertStringNotContainsString('NaN', $html);
        $this->assertDoesNotMatchRegularExpression($this->card('Consent rate', '0%'), $html);
    }

    // ── Spec test 4 ──────────────────────────────────────────────────

    #[Test]
    public function no_full_lrn_reaches_the_list_or_the_form_page(): void
    {
        $record = $this->record('123456704521', 'Abad, Ana');
        $this->letter('123456704521', HealthConsentForm::CONSENT_ALL, ['checkup']);

        $html = $this->list();
        $this->assertSame(1, preg_match('#<table class="cf-table" id="consentTable">.*?</table>#s', $html, $table));
        $this->assertStringContainsString('•••••••04521', $table[0]);
        $this->assertStringNotContainsString('123456704521', $table[0]);

        $page = $this->withSession($this->readerSession())
            ->get(route('consent-forms.nurse-learner', $record))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('•••••••04521', $page);
        $this->assertStringNotContainsString('123456704521', $page);
    }

    // ── Spec test 5 ──────────────────────────────────────────────────

    #[Test]
    public function the_clinic_reads_and_cannot_write(): void
    {
        $record = $this->record('400000000001', 'Abad, Ana');
        $form = $this->letter('400000000001', HealthConsentForm::CONSENT_ALL, ['checkup']);

        foreach ([
            $this->list(),
            $this->withSession($this->readerSession())->get(route('consent-forms.nurse-learner', $record))->getContent(),
        ] as $html) {
            $this->assertStringNotContainsStringIgnoringCase('method="post"', $html);
            $this->assertStringNotContainsString('type="file"', $html);
        }

        // The write endpoints refuse the nurse whatever a page renders.
        $this->withSession($this->readerSession())
            ->post(route('parental-consent.store'), ['lrn' => '400000000001', 'consent_type' => 'refused'])
            ->assertForbidden();
        $this->assertSame(0, ParentalConsentForm::count());

        $this->withSession($this->readerSession())
            ->post(route('consent-forms.paper.store', $form), ['consent_choice' => 'deny', 'refusal_reason' => 'x', 'parent_guardian_name' => 'x', 'confirmed' => '1']);
        $this->assertSame(HealthConsentForm::CONSENT_ALL, $form->fresh()->consent_choice);
    }

    // ── Spec test 6 ──────────────────────────────────────────────────

    #[Test]
    public function the_form_photo_is_refused_to_other_roles_and_to_a_former_adviser(): void
    {
        Storage::fake('local');
        $lrn = '500000000001';

        // Last year in Grade 7 Matiyaga, promoted; this year in Grade 8 Masipag.
        $old = $this->record($lrn, 'Abad, Ana', 7, 'MATIYAGA', $this->lastSchoolYear());
        $old->forceFill(['left_at' => now()->subMonths(4), 'end_status' => LearnerPlacement::PROMOTED, 'wait_grade' => 'Grade 8'])->save();
        $this->record($lrn, 'Abad, Ana', 8, 'MASIPAG');

        $form = $this->letter($lrn, HealthConsentForm::CONSENT_ALL, ['checkup']);
        // A JPEG's opening bytes, so the photo is served as one without GD.
        $photo = UploadedFile::fake()->createWithContent('form.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00".str_repeat('x', 512));
        $form->forceFill(['paper_form_path' => EncryptedFileStorage::store($photo, 'consent-forms')])->save();

        $image = route('consent-forms.paper.image', $form);

        $response = $this->withSession($this->readerSession())->get($image)->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->assertFalse(
            $this->flushSession()->withSession($this->readerSession('feeding_coor'))->get($image)->isSuccessful(),
            'A role outside the clinic and the class must not open the photo.'
        );

        $this->flushSession()->withSession([
            'active_role' => 'class_adviser',
            'active_name' => 'Former Adviser',
            'active_institution_id' => $this->institution->id,
            'assigned_grade_level' => 'Grade 7',
            'assigned_section' => 'MATIYAGA',
        ])->get($image)->assertForbidden()->assertSee(AdviserClassScope::NO_LONGER_MESSAGE);
    }

    // ── Spec test 7 ──────────────────────────────────────────────────

    #[Test]
    public function clear_filters_resets_every_filter(): void
    {
        $this->record('600000000001', 'Abad, Ana');

        $html = $this->list([
            'school_year' => $this->lastSchoolYear(),
            'service' => 'dental',
            'grade' => 7,
            'status' => ConsentStanding::SERVICE_DECLINED,
        ]);

        $this->assertStringContainsString('href="'.e(route('consent-forms.nurse-index')).'" class="cf-filter-clear"', $html);
        $this->assertStringContainsString('Showing Dental check-up · Grade 7', html_entity_decode($html));
    }

    // ── Spec test 8 ──────────────────────────────────────────────────

    #[Test]
    public function a_learner_with_no_form_opens_on_no_form_yet(): void
    {
        $record = $this->record('700000000001', 'Abad, Ana');

        $this->assertStringContainsString('>No form yet</span>', $this->list());

        $this->withSession($this->readerSession())
            ->get(route('consent-forms.nurse-learner', $record))
            ->assertOk()
            ->assertSee('No form yet.')
            ->assertSee('Follow up with the class adviser.')
            ->assertDontSee('Read from the uploaded photo')
            ->assertDontSee('<img src="'.url('/dashboard/consent-forms'), false);
    }

    // ── The form page ────────────────────────────────────────────────

    #[Test]
    public function the_form_page_reads_back_what_the_adviser_confirmed(): void
    {
        $record = $this->record('800000000001', 'Abad, Ana');
        $this->letter('800000000001', HealthConsentForm::CONSENT_SPECIFIC, ['checkup', 'dental'], 'Dental');

        $html = $this->withSession($this->readerSession())
            ->get(route('consent-forms.nurse-learner', ['record' => $record->id, 'service' => 'dental']))
            ->assertOk()
            ->assertSee('Dental check-up')
            ->assertSee('>Check the exceptions</span>', false)
            ->assertSee('All required fields completed.')
            ->assertSee('Valid for all service cycles in SY')
            ->getContent();

        // The form drawn back as the paper carries it: the "gawas lamang
        // niini" box ticked, with the parent's own words on its line.
        $this->assertBox($html, 'yes', HealthConsentForm::CONSENT_TEXT[HealthConsentForm::CONSENT_SPECIFIC]);
        $this->assertBox($html, 'no', HealthConsentForm::CONSENT_TEXT[HealthConsentForm::CONSENT_DENY]);
        $this->assertStringContainsString('<span class="pcopy-written">Dental</span>', $html);
        $this->assertService($html, HealthConsentForm::SERVICES['dental']['label'], 'check_exceptions', 'Check the exceptions');
    }

    /** A box on the drawn form, in the state given, with the printed words beside it. */
    private function assertBox(string $html, string $state, string $label): void
    {
        $this->assertMatchesRegularExpression(
            '/<span class="pcopy-text" lang="ceb">'.preg_quote(e($label), '/').'(?:<span class="pcopy-written">[^<]*<\/span>)?<\/span><\/span>'
                .'<span class="pcopy-std is-'.['yes' => 'ticked', 'no' => 'not_ticked', 'unclear' => 'unclear'][$state].'">/u',
            $html,
            'Expected "'.$label.'" to read '.$state.'.',
        );
    }

    /** A service row on the drawn form: marked as a service, its printed name, its standing. */
    private function assertService(string $html, string $label, string $standing, string $pill): void
    {
        $this->assertMatchesRegularExpression(
            '/<li class="pcopy-row is-'.$standing.' is-service"><span class="pcopy-label"><span class="pcopy-mark"[^>]*>.*?<\/span>'
                .'<span class="pcopy-text" lang="ceb">'.preg_quote(e($label), '/').'<\/span><\/span>'
                .'<span class="pcopy-std is-'.$standing.'">'.preg_quote(e($pill), '/').'<\/span><\/li>/u',
            $html,
            'Expected the service "'.$label.'" to read '.$pill.'.',
        );
    }

    #[Test]
    public function a_refusal_with_no_reason_is_named_as_missing(): void
    {
        $record = $this->record('800000000002', 'Bautista, Ben');
        $form = $this->letter('800000000002', HealthConsentForm::CONSENT_DENY, ['checkup']);
        $form->forceFill(['refusal_reason' => null])->save();

        $this->withSession($this->readerSession())
            ->get(route('consent-forms.nurse-learner', $record))
            ->assertOk()
            ->assertSee('Missing from this form: Reason given.');
    }

    // ── The header and the rows ──────────────────────────────────────

    #[Test]
    public function each_reader_is_named_as_themselves_and_sent_to_their_own_dashboard(): void
    {
        $html = $this->list([], 'clinic_teacher');

        $this->assertStringContainsString('Signed in as Clinic Teacher · view only', html_entity_decode($html));
        $this->assertStringContainsString('href="'.e(route('dashboard.clinic-teacher')).'" class="cf-back cf-back-start"', $html);
    }

    #[Test]
    public function the_testing_banner_shows_only_when_the_flag_is_on(): void
    {
        $this->assertStringNotContainsString('Testing environment.', $this->list());

        config(['app.simulated_data' => true]);

        $this->assertStringContainsString('Testing environment.', $this->list());
    }

    #[Test]
    public function every_row_names_the_programs_the_parent_agreed_to(): void
    {
        $this->record('900000000001', 'Abad, Ana');
        $this->record('900000000002', 'Bautista, Ben');
        $this->record('900000000003', 'Cruz, Carla');
        $this->letter('900000000001', HealthConsentForm::CONSENT_ALL, ['checkup', 'deworming', 'deworming_worms', 'vitamin_a']);
        $this->letter('900000000002', HealthConsentForm::CONSENT_SPECIFIC, ['checkup', 'dental'], 'Dental');
        $this->letter('900000000003', HealthConsentForm::CONSENT_DENY, ['checkup']);

        $html = $this->list();

        $this->assertStringContainsString('Check-up · Deworming (worms) · Vitamin A', $html);
        $this->assertStringContainsString('Except, as written: &ldquo;Dental&rdquo;', $html);
        $this->assertMatchesRegularExpression('#<td class="cf-programs">\s*None\s*</td>#', $html);
    }

    #[Test]
    public function a_service_the_letter_did_not_ask_about_is_not_on_the_form_and_uncounted(): void
    {
        $this->record('910000000001', 'Abad, Ana');
        $this->letter('910000000001', HealthConsentForm::CONSENT_ALL, ['checkup']);

        $html = $this->list(['service' => 'dental']);

        $this->assertStringContainsString('>Not consented</span>', $html);
        $this->assertMatchesRegularExpression($this->card('Consent rate', '—'), $html);
    }

    #[Test]
    public function a_heading_on_the_letter_covers_its_sub_items(): void
    {
        $record = $this->record('920000000001', 'Abad, Ana');
        $this->letter('920000000001', HealthConsentForm::CONSENT_ALL, ['deworming']);

        $html = $this->list(['service' => 'deworming_worms']);

        $this->assertStringContainsString($this->rowLink($record, 'deworming_worms'), $html);
        $this->assertStringContainsString('>Consented</span>', $html);
    }

    #[Test]
    public function an_uploaded_scan_reads_as_the_program_it_was_filed_for(): void
    {
        $record = $this->record('930000000001', 'Diaz, Dan');

        ParentalConsentForm::create([
            'student_health_record_id' => $record->id,
            'program_type' => 'Deworming',
            'school_year' => HealthConsentForm::currentSchoolYear(),
            'consent_type' => 'full',
            'file_path' => 'parental-consents/'.$record->id.'/scan.pdf',
            'file_original_name' => 'scan.pdf',
            'uploaded_by_name' => 'Test Adviser',
        ]);

        $this->assertStringContainsString('>Consented</span>', $this->list(['service' => 'deworming_worms']));
        $this->assertStringContainsString('>Not consented</span>', $this->list(['service' => 'vitamin_a']));
    }

    #[Test]
    public function an_uploaded_scan_with_its_services_ticked_reads_as_them(): void
    {
        $record = $this->record('930000000002', 'Diaz, Dina');

        ParentalConsentForm::create([
            'student_health_record_id' => $record->id,
            'program_type' => 'Deworming',
            'school_year' => HealthConsentForm::currentSchoolYear(),
            'services' => ['checkup', 'vitamin_a'],
            'consent_type' => 'full',
            'file_path' => 'parental-consents/'.$record->id.'/scan.jpg',
            'file_original_name' => 'scan.jpg',
            'uploaded_by_name' => 'Test Adviser',
        ]);

        $this->assertStringContainsString('>Consented</span>', $this->list(['service' => 'vitamin_a']));
        $this->assertStringContainsString('>Not consented</span>', $this->list(['service' => 'deworming_worms']));

        $html = $this->withSession($this->readerSession())
            ->get(route('consent-forms.nurse-learner', $record))
            ->assertOk()
            ->getContent();

        // The form drawn back as the paper carries it, in its own words.
        $this->assertBox($html, 'yes', HealthConsentForm::CONSENT_TEXT[HealthConsentForm::CONSENT_ALL]);
        $this->assertService($html, HealthConsentForm::SERVICES['checkup']['label'], 'consented', 'Consented');
        $this->assertService($html, HealthConsentForm::SERVICES['vitamin_a']['label'], 'consented', 'Consented');
        $this->assertService($html, 'Pag Bitok / Worms', 'not_on_form', 'Not consented');
    }

    #[Test]
    public function an_unknown_filter_value_narrows_nothing(): void
    {
        $record = $this->record('940000000001', 'Abad, Ana');

        $html = $this->list(['service' => 'not-a-service', 'grade' => 12, 'status' => 'maybe']);

        $this->assertStringContainsString($this->rowLink($record), $html);
        $this->assertStringContainsString('Showing All services · All grade levels', html_entity_decode($html));
    }

    private function lastSchoolYear(): string
    {
        [$start] = explode('-', HealthConsentForm::currentSchoolYear());

        return ((int) $start - 1).'-'.$start;
    }
}
