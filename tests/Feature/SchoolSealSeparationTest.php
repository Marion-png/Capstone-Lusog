<?php

namespace Tests\Feature;

use App\Models\HealthConsentForm;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\SchoolLetterhead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * A school's seal is printed on its own reports and never on another
 * school's. Every report, print sheet, workbook and PDF that carries a school
 * seal is opened at each registration school, and must carry that school's
 * seal and not the other's. A seal reaches a document three ways — by file
 * name in a page, as the file's own bytes inside a workbook, base64-encoded
 * inside a PDF — so all three are looked for. Every name and LRN is invented.
 */
class SchoolSealSeparationTest extends TestCase
{
    use RefreshDatabase;

    private const DAVAO_CITY = 'Davao City National High School';

    /** @var array<string, Institution> */
    private array $schools = [];

    /** @var array<string, string> school key => LRN of its learner */
    private array $lrns = [];

    /** @var array<string, int> school key => id of its signed consent form */
    private array $consentForms = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->schools = [
            'staana' => Institution::create(['name' => Institution::REGISTRATION_SCHOOL, 'status' => 'active']),
            'davao' => Institution::create(['name' => self::DAVAO_CITY, 'status' => 'active']),
        ];

        foreach (['staana' => ['Salvador', '900000000301', 'MAAYOS'], 'davao' => ['Montecillo', '900000000401', 'SAMPAGUITA']] as $key => [$surname, $lrn, $section]) {
            $school = $this->schools[$key];
            $this->lrns[$key] = $lrn;

            StudentHealthRecord::create([
                'institution_id' => $school->id,
                'school_year' => StudentHealthRecord::currentSchoolYear(),
                'school_name' => $school->name,
                'student_name' => "{$surname}, Ignacio P.",
                'student_id' => $lrn,
                'section' => "Grade 7 / {$section}",
                'weight' => 30,
                'height' => 150,
                'bmi_value' => 13.3,
                'nutritional_status' => 'Severely Wasted',
                'baseline_nutritional_status' => 'Severely Wasted',
                'baseline_weight_kg' => '30',
                'baseline_height_cm' => '150',
                'baseline_bmi_value' => '13.3',
                'baseline_recorded_at' => now()->subWeeks(2)->toDateString(),
                'feeding_enrolled_at' => now()->subWeeks(2),
                'student_details' => ['gender' => 'Female', 'birth_date' => '2013-03-04'],
            ]);

            $this->consentForms[$key] = HealthConsentForm::create([
                'institution_id' => $school->id,
                'student_lrn' => $lrn,
                'school_year' => HealthConsentForm::currentSchoolYear(),
                'student_name' => "{$surname}, Ignacio P.",
                'division' => HealthConsentForm::DEFAULT_DIVISION,
                'school_name' => $school->name,
                'school_address' => 'Davao City',
                'status' => HealthConsentForm::STATUS_SIGNED,
                'consent_choice' => HealthConsentForm::CONSENT_ALL,
                'services' => [],
                'signed_at' => now(),
            ])->id;
        }
    }

    #[Test]
    public function both_schools_have_their_own_seal_file_and_they_differ(): void
    {
        $staAna = $this->sealFile('staana');
        $davao = $this->sealFile('davao');

        $this->assertFileExists($staAna);
        $this->assertFileExists($davao);
        $this->assertNotSame(md5_file($staAna), md5_file($davao), 'The two schools must not share one seal image.');
    }

    /**
     * Every report a school prints or downloads: [role, method, uri, posted
     * fields, whether it carries a school seal]. A document without a school
     * seal slot — the Sulat-Pahibalo heads DepEd and DOH, and the plain data
     * exports head the school by name only — must still never carry another
     * school's. `{lrn}` and `{form}` are filled with the reader's own school's
     * learner and consent form.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: array<string, mixed>, 5: bool}>
     */
    public static function documents(): array
    {
        $documents = [
            'SBFP forms page' => ['feeding_coor', 'GET', 'dashboard/feedingcor-sbfp-forms', [], true],
            'BMI report workbook' => ['feeding_coor', 'POST', 'dashboard/feedingcor-sbfp-forms/download', ['template' => 'bmi-report', 'phase' => 'both'], true],
            'Master List of Beneficiaries workbook' => ['feeding_coor', 'POST', 'dashboard/feedingcor-sbfp-forms/download', ['template' => 'feeding-beneficiaries'], true],
            'Masterlist of Qualified Recipients workbook' => ['feeding_coor', 'POST', 'dashboard/feedingcor-sbfp-forms/download', ['template' => 'feeding-masterlist'], true],
            'Coordinator health-records masterlist workbook' => ['feeding_coor', 'POST', 'dashboard/feedingcor-health-records/masterlist', ['list' => 'beneficiaries'], true],
            'Coordinator health-records masterlist print' => ['feeding_coor', 'GET', 'dashboard/feedingcor-health-records/masterlist/print', [], true],
            'Nurse SBFP forms page' => ['school_nurse', 'GET', 'dashboard/school-nurse/forms', [], true],
            'Nurse BMI report workbook' => ['school_nurse', 'POST', 'dashboard/school-nurse/forms/download', ['template' => 'bmi-report'], true],
            'Nurse nutritional status workbook' => ['school_nurse', 'GET', 'dashboard/school-nurse/nutritional-status/print', [], true],
            'Nurse nutritional status data export' => ['school_nurse', 'GET', 'dashboard/school-nurse/nutritional-status/export', [], false],
            'Clinic Teacher SBFP forms page' => ['clinic_teacher', 'GET', 'dashboard/clinic-teacher/forms', [], true],
            'Clinic Teacher BMI report workbook' => ['clinic_teacher', 'POST', 'dashboard/clinic-teacher/forms/download', ['template' => 'bmi-report'], true],
            'Clinic Teacher nutritional status workbook' => ['clinic_teacher', 'GET', 'dashboard/clinic-teacher/nutritional-status/print', [], true],
            'Incident report blank form' => ['school_nurse', 'GET', 'health-records/students/{lrn}/incidents/blank-form', [], true],
            'Consent form print (nurse)' => ['school_nurse', 'GET', 'dashboard/consent-forms/{form}/print', [], false],
            'Consent form print (School Head)' => ['school_head', 'GET', 'dashboard/school-head/consent-forms/{form}/print', [], false],
            'School Head nutritional status workbook' => ['school_head', 'GET', 'dashboard/school-head/masterlist/print', [], true],
            'School Head masterlist data export' => ['school_head', 'GET', 'dashboard/school-head/masterlist/export', [], false],
            'School Head reports export' => ['school_head', 'GET', 'dashboard/school-head/reports/export', [], false],
            'School Head consent export' => ['school_head', 'GET', 'dashboard/school-head/consent/export', [], false],
            'School Head inventory export' => ['school_head', 'GET', 'dashboard/school-head/inventory/export', [], false],
        ];

        $cases = [];
        foreach ($documents as $label => $document) {
            foreach (['staana' => 'Sta. Ana', 'davao' => 'Davao City'] as $school => $name) {
                $cases["{$label} at {$name}"] = [$school, ...$document];
            }
        }

        return $cases;
    }

    /** @param  array<string, mixed>  $fields */
    #[Test]
    #[DataProvider('documents')]
    public function each_document_carries_its_own_schools_seal_and_never_the_others(string $school, string $role, string $method, string $uri, array $fields, bool $sealed): void
    {
        $other = $school === 'davao' ? 'staana' : 'davao';
        $uri = strtr($uri, ['{lrn}' => $this->lrns[$school], '{form}' => (string) $this->consentForms[$school]]);

        $response = $this->withSession($this->sessionFor($role, $school))->call($method, '/'.$uri, $fields);

        $this->assertSame(200, $response->getStatusCode(), "{$uri} as {$role}");

        $body = $this->bodyOf($response->baseResponse);

        $this->assertNull($this->sealIn($body, $other), "{$uri} printed the other school's seal ({$this->sealIn($body, $other)}).");

        if ($sealed) {
            $this->assertNotNull($this->sealIn($body, $school), "{$uri} did not print its own school's seal.");
        }
    }

    #[Test]
    public function the_narrative_pdf_is_handed_its_own_schools_seal_and_never_the_others(): void
    {
        foreach (['staana' => 'davao', 'davao' => 'staana'] as $school => $other) {
            $seals = null;
            View::composer('feedingcor-dashboard.narrative-pdf', function ($view) use (&$seals): void {
                $seals = $view->getData()['seals'] ?? null;
            });

            $response = $this->withSession($this->sessionFor('feeding_coor', $school))
                ->post('/dashboard/feedingcor-sbfp-forms/narrative-pdf', []);

            $response->assertOk();
            $this->assertIsArray($seals);

            // Without GD the PDF draws no seal for anybody; with it, the seal
            // it embeds is this school's own file, byte for byte.
            if (function_exists('imagecreatefrompng')) {
                $this->assertSame('data:image/png;base64,'.base64_encode((string) file_get_contents($this->sealFile($school))), $seals['school']);
            }
            $this->assertNull($this->sealIn((string) ($seals['school'] ?? ''), $other));
            $this->assertNull($this->sealIn((string) $response->getContent(), $other));

            $this->flushSession();
        }
    }

    #[Test]
    public function every_seal_lookup_answers_with_the_signed_in_school(): void
    {
        foreach (['staana' => 'davao', 'davao' => 'staana'] as $school => $other) {
            $this->withSession($this->sessionFor('feeding_coor', $school))
                ->get('/dashboard/feedingcor-sbfp-forms')
                ->assertOk();

            // The workbooks and the PDF ask with no school named: the session's.
            $this->assertSame(realpath($this->sealFile($school)), realpath((string) SchoolLetterhead::sealFiles()['school']));
            $this->assertStringContainsString($this->sealName($school), (string) SchoolLetterhead::seals()['school']);
            $this->assertStringNotContainsString($this->sealName($other), (string) SchoolLetterhead::seals()['school']);

            $this->flushSession();
        }
    }

    /** @return array<string, array{0: string}> */
    public static function schoolRoles(): array
    {
        $roles = [];
        foreach (['school_nurse', 'clinic_staff', 'clinic_teacher', 'class_adviser', 'school_head', 'feeding_coor', 'school_admin'] as $role) {
            $roles[$role] = [$role];
        }

        return $roles;
    }

    #[Test]
    #[DataProvider('schoolRoles')]
    public function a_signed_in_account_is_served_its_own_schools_seal_and_never_the_others(string $role): void
    {
        foreach (['staana' => 'davao', 'davao' => 'staana'] as $school => $other) {
            $own = $this->withSession($this->sessionFor($role, $school))->get('/school-seal/'.$this->sealName($school));
            $own->assertOk();
            $this->assertSame(file_get_contents($this->sealFile($school)), file_get_contents($own->baseResponse->getFile()->getPathname()));
            $this->assertStringContainsString('no-cache', (string) $own->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', (string) $own->headers->get('Cache-Control'));

            // Typing the other school's address gives nothing.
            $this->withSession($this->sessionFor($role, $school))
                ->get('/school-seal/'.$this->sealName($other))
                ->assertNotFound();

            $this->flushSession();
        }
    }

    #[Test]
    public function a_cached_seal_is_not_confirmed_to_the_next_school_on_the_same_browser(): void
    {
        $staAna = $this->withSession($this->sessionFor('school_nurse', 'staana'))->get('/school-seal/'.$this->sealName('staana'));
        $etag = (string) $staAna->headers->get('ETag');
        $this->assertNotSame('', $etag);
        $this->flushSession();

        // The same account asking again is told its copy still stands.
        $this->withSession($this->sessionFor('school_nurse', 'staana'))
            ->get('/school-seal/'.$this->sealName('staana'), ['If-None-Match' => $etag])
            ->assertStatus(304);
        $this->flushSession();

        // Davao City's account, on the browser that cached Sta. Ana's seal,
        // is never told that copy is good to show.
        $this->withSession($this->sessionFor('school_nurse', 'davao'))
            ->get('/school-seal/'.$this->sealName('staana'), ['If-None-Match' => $etag])
            ->assertNotFound();
    }

    #[Test]
    public function nobody_signed_out_or_without_a_school_is_served_a_seal(): void
    {
        foreach (['staana', 'davao'] as $school) {
            $this->get('/school-seal/'.$this->sealName($school))->assertNotFound();
        }

        $this->withSession(['active_role' => 'system_admin', 'active_name' => 'Admin', 'active_username' => 'admin'])
            ->get('/school-seal/'.$this->sealName('staana'))
            ->assertNotFound();
    }

    #[Test]
    public function no_school_seal_is_left_where_anybody_can_open_it(): void
    {
        $this->assertDirectoryDoesNotExist(public_path('images/school-logos'));

        $seals = [md5_file($this->sealFile('staana')) => 'Sta. Ana', md5_file($this->sealFile('davao')) => 'Davao City'];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(public_path(), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'], true)) {
                $this->assertArrayNotHasKey(md5_file($file->getPathname()), $seals, $file->getPathname().' is a school seal anybody can open.');
            }
        }
    }

    /**
     * Which of a school's seal markers the body carries, or null.
     */
    private function sealIn(string $body, string $school): ?string
    {
        $bytes = (string) file_get_contents($this->sealFile($school));
        $encoded = base64_encode($bytes);

        $markers = [
            'file name' => $this->sealName($school),
            'image bytes' => substr($bytes, intdiv(strlen($bytes), 2), 64),
            'base64 image' => substr($encoded, intdiv(strlen($encoded), 8) * 4, 96),
        ];

        foreach ($markers as $label => $marker) {
            if ($marker !== '' && str_contains($body, $marker)) {
                return $label;
            }
        }

        return null;
    }

    /**
     * Worked out here from the school's name, not asked of SchoolLetterhead,
     * so a lookup that hands every school the same seal cannot also fool the
     * test that is checking it.
     */
    private function sealName(string $school): string
    {
        return Str::slug($this->schools[$school]->name).'.png';
    }

    private function sealFile(string $school): string
    {
        return resource_path(SchoolLetterhead::SCHOOL_LOGO_DIR.'/'.$this->sealName($school));
    }

    private function bodyOf(Response $response): string
    {
        if ($response instanceof StreamedResponse) {
            ob_start();
            $response->sendContent();
            $content = (string) ob_get_clean();
        } elseif ($response instanceof BinaryFileResponse) {
            $content = (string) file_get_contents($response->getFile()->getPathname());
        } else {
            $content = (string) $response->getContent();
        }

        // A workbook is a zip: its pictures are stored as the files themselves.
        if (str_starts_with($content, "PK\x03\x04")) {
            $path = tempnam(sys_get_temp_dir(), 'xlsx');
            file_put_contents($path, $content);
            $zip = new \ZipArchive;
            $text = '';

            if ($zip->open($path) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $text .= (string) $zip->getFromIndex($i);
                }
                $zip->close();
            }

            @unlink($path);

            return $text;
        }

        return $content;
    }

    /** @return array<string, mixed> */
    private function sessionFor(string $role, string $school): array
    {
        $institution = $this->schools[$school];

        return [
            'active_role' => $role,
            'active_name' => 'Test '.$role,
            'active_username' => 'test.'.$role,
            'active_institution_id' => $institution->id,
            'active_school_name' => $institution->name,
        ];
    }
}
