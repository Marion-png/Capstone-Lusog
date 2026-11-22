<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Consultation;
use App\Models\FeedingAttendance;
use App\Models\Institution;
use App\Models\Medicine;
use App\Models\StudentHealthRecord;
use App\Support\SchoolClock;
use App\Support\SchoolLetterhead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Opens every page and export a role can reach, at each of the two
 * registration schools, and requires that none of them shows the other
 * school's learners, medicines, announcements or seal, and that an adviser sees
 * only their own section even beside another section of the same grade.
 * Every name and LRN below is invented.
 */
class SchoolSeparationCrawlTest extends TestCase
{
    use RefreshDatabase;

    private const DAVAO_CITY = 'Davao City National High School';

    /** @var array<string, array<string, string>> school => marker => text that must stay there */
    private array $markers = [];

    private Institution $staAna;

    private Institution $davaoCity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staAna = Institution::create(['name' => Institution::REGISTRATION_SCHOOL, 'status' => 'active']);
        $this->davaoCity = Institution::create(['name' => self::DAVAO_CITY, 'status' => 'active']);

        $this->seedSchool($this->staAna, 'Salvador', '900000000101', 'Grade 7', 'MAAYOS', 'Villacorta', '900000000102', 'MABAIT', 'Staana');
        $this->seedSchool($this->davaoCity, 'Montecillo', '900000000201', 'Grade 7', 'SAMPAGUITA', 'Buenaventura', '900000000202', 'ROSAL', 'Dcnhs');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function rolesAtSchools(): array
    {
        $cases = [];

        foreach (['school_nurse', 'clinic_staff', 'clinic_teacher', 'class_adviser', 'school_head', 'feeding_coor'] as $role) {
            $cases["{$role} at Davao City"] = [$role, 'davao'];
            $cases["{$role} at Sta. Ana"] = [$role, 'staana'];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('rolesAtSchools')]
    public function no_page_shows_the_other_schools_data_or_another_sections_learners(string $role, string $which): void
    {
        [$own, $other] = $which === 'davao' ? [$this->davaoCity, $this->staAna] : [$this->staAna, $this->davaoCity];

        $forbidden = array_values($this->markers[$other->name]);

        if ($role === 'class_adviser') {
            // The second learner sits in another section of the same grade.
            $forbidden[] = $this->markers[$own->name]['neighbour'];
            $forbidden[] = $this->markers[$own->name]['neighbour_lrn'];
        }

        $failures = [];
        $served = 0;

        foreach ($this->pagesFor($other) as $uri) {
            $response = $this->withSession($this->sessionFor($role, $own))->get('/'.ltrim($uri, '/'));
            $status = $response->getStatusCode();

            if ($status >= 500) {
                $failures[] = "{$uri}: HTTP {$status}";
                $this->flushSession();

                continue;
            }

            if ($status === 200) {
                $served++;
                $body = $this->bodyOf($response->baseResponse);

                foreach ($forbidden as $text) {
                    if (str_contains($body, $text)) {
                        $failures[] = "{$uri}: shows \"{$text}\"";
                    }
                }

                // The other school's seal, by name, as a workbook's picture or
                // as a PDF's inline image.
                foreach ($this->sealMarkers($other) as $how => $marker) {
                    if (str_contains($body, $marker)) {
                        $failures[] = "{$uri}: carries the other school's seal ({$how})";
                    }
                }
            }

            $this->flushSession();
        }

        $this->assertGreaterThan(0, $served, "{$role} was served no page at all.");
        $this->assertSame([], $failures, implode("\n", $failures));
    }

    #[Test]
    #[DataProvider('rolesAtSchools')]
    public function each_role_still_sees_its_own_schools_learner(string $role, string $which): void
    {
        $own = $which === 'davao' ? $this->davaoCity : $this->staAna;

        $home = [
            'school_nurse' => '/dashboard/student-health-records',
            'clinic_staff' => '/dashboard/consultation-log',
            'clinic_teacher' => '/dashboard/student-health-records',
            'class_adviser' => '/dashboard/class-adviser',
            'school_head' => '/dashboard/school-head/masterlist',
            'feeding_coor' => '/dashboard/feedingcor-dashboard',
        ][$role];

        $this->withSession($this->sessionFor($role, $own))
            ->get($home)
            ->assertOk()
            ->assertSee($this->markers[$own->name]['learner']);
    }

    /**
     * Every GET route, with route parameters filled from the other school's
     * rows: a page reached by another school's LRN or record id must refuse.
     *
     * @return list<string>
     */
    private function pagesFor(Institution $other): array
    {
        $otherRecord = StudentHealthRecord::query()->where('institution_id', $other->id)->orderBy('id')->first();
        $skip = ['storage/{path}', 'consent/{token}', 'up', '/', 'login', 'admin-login', 'account-request', 'terms', 'api/institutions'];

        $uris = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true) || in_array($route->uri(), $skip, true)) {
                continue;
            }

            $uri = strtr($route->uri(), [
                '{lrn}' => $otherRecord->student_id,
                '{record}' => (string) $otherRecord->id,
                '{index}' => '0',
            ]);

            if (str_contains($uri, '{')) {
                continue;
            }

            $uris[] = $uri;
        }

        return array_values(array_unique($uris));
    }

    /** @return array<string, string> how the seal would appear => the text that gives it away */
    private function sealMarkers(Institution $school): array
    {
        // From the school's name, not from SchoolLetterhead, so a lookup that
        // hands every school one seal cannot also fool this check.
        $relative = SchoolLetterhead::SCHOOL_LOGO_DIR.'/'.Str::slug($school->name).'.png';
        $bytes = (string) @file_get_contents(resource_path($relative));
        $this->assertNotSame('', $bytes, "{$school->name} has no seal file at {$relative}.");
        $encoded = base64_encode($bytes);

        return [
            'file name' => basename($relative),
            'image bytes' => substr($bytes, intdiv(strlen($bytes), 2), 64),
            'base64 image' => substr($encoded, intdiv(strlen($encoded), 8) * 4, 96),
        ];
    }

    private function bodyOf($response): string
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

        // A workbook is a zip of XML: read the text out of it.
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
    private function sessionFor(string $role, Institution $school): array
    {
        $grade = $role === 'class_adviser' ? 'Grade 7' : null;
        $section = $role === 'class_adviser' ? $this->markers[$school->name]['section'] : null;

        return [
            'active_role' => $role,
            'active_name' => 'Test '.$role,
            'active_username' => 'test.'.$role,
            'active_institution_id' => $school->id,
            'active_school_name' => $school->name,
            'assigned_grade_level' => $grade,
            'assigned_section' => $section,
        ];
    }

    private function seedSchool(
        Institution $school,
        string $surname,
        string $lrn,
        string $grade,
        string $section,
        string $neighbourSurname,
        string $neighbourLrn,
        string $neighbourSection,
        string $tag,
    ): void {
        $learner = $this->learner($school, "{$surname}, Ignacio P.", $lrn, "{$grade} / {$section}");
        $neighbour = $this->learner($school, "{$neighbourSurname}, Remedios S.", $neighbourLrn, "{$grade} / {$neighbourSection}");

        foreach ([$learner, $neighbour] as $record) {
            FeedingAttendance::create([
                'student_health_record_id' => $record->id,
                'session_date' => SchoolClock::today(),
                'is_present' => true,
            ]);
        }

        Consultation::create([
            'institution_id' => $school->id,
            'consulted_at' => now(),
            'student_name' => "{$surname}, Ignacio P.",
            'grade_section' => "{$grade} - {$section}",
            'condition' => 'Fever',
            'status' => 'treated',
        ]);

        Medicine::create([
            'institution_id' => $school->id,
            'name' => "{$tag} Paracetamol",
            'stock_quantity' => 40,
            'minimum_threshold' => 10,
            'unit' => 'tablets',
        ]);

        Announcement::create([
            'institution_id' => $school->id,
            'title' => "{$tag} deworming day",
            'body' => 'Bring a signed slip.',
            'priority' => Announcement::PRIORITY_NORMAL,
            'audience' => 'all',
            'posted_by_name' => 'Test Nurse',
            'posted_by_role' => 'school_nurse',
        ]);

        $this->markers[$school->name] = [
            'learner' => $surname,
            'lrn' => $lrn,
            'neighbour' => $neighbourSurname,
            'neighbour_lrn' => $neighbourLrn,
            'medicine' => "{$tag} Paracetamol",
            'announcement' => "{$tag} deworming day",
            'section' => $section,
        ];
    }

    private function learner(Institution $school, string $name, string $lrn, string $section): StudentHealthRecord
    {
        return StudentHealthRecord::create([
            'institution_id' => $school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'school_name' => $school->name,
            'student_name' => $name,
            'student_id' => $lrn,
            'section' => $section,
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
    }
}
