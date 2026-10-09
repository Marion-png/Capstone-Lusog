<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Institution;
use App\Models\Medicine;
use App\Models\StudentHealthRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The nurse's dashboard renders its headline metrics, consultations table and
 * summary panels from real records. "Top Consultation Cases" in particular
 * must tally decrypted values in PHP — `condition` is encrypted, so a SQL
 * GROUP BY would bucket ciphertext and show one row per consultation.
 */
class SchoolNurseDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function nurseSession(): array
    {
        return [
            'active_role' => 'school_nurse',
            'active_name' => 'Ana Reyes',
            'active_username' => 'ana.reyes',
            'active_institution_id' => $this->institution->id,
            'active_school_name' => 'Sta. Ana NHS',
        ];
    }

    /** @param  array<string, mixed>  $attributes */
    private function consultation(array $attributes = []): Consultation
    {
        return Consultation::create(array_merge([
            'institution_id' => $this->institution->id,
            'consulted_at' => now(),
            'student_name' => 'Dela Cruz, Juan',
            'grade_section' => 'Grade 10 - Dalton',
            'condition' => 'Fever',
            'treatment_given' => 'Rest and fluids',
            'status' => 'treated',
        ], $attributes));
    }

    #[Test]
    public function top_consultation_cases_groups_decrypted_conditions_not_ciphertext(): void
    {
        $this->consultation(['condition' => 'Fever']);
        $this->consultation(['condition' => 'Fever', 'student_name' => 'Reyes, Maria']);
        $this->consultation(['condition' => 'fever', 'student_name' => 'Tan, Sofia']);
        $this->consultation(['condition' => 'Cough', 'student_name' => 'Gomez, Jose']);

        $response = $this->withSession($this->nurseSession())
            ->get('/dashboard/school-nurse')
            ->assertOk();

        $top = $response->viewData('topConditions');

        // Three "Fever" rows collapse into one bucket, case-insensitively.
        $this->assertSame(
            [['name' => 'fever', 'total' => 3], ['name' => 'cough', 'total' => 1]],
            $top->all()
        );

        $response->assertSee('Fever')->assertSee('Cough');
        // Ciphertext starts with the Laravel payload marker once base64-decoded;
        // the raw column value must never reach the page.
        $response->assertDontSee(Consultation::first()->getRawOriginal('condition'));
    }

    #[Test]
    public function the_headline_metrics_come_from_real_records(): void
    {
        StudentHealthRecord::create([
            'institution_id' => $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_id' => 'LRN001',
            'student_name' => 'Dela Cruz, Juan',
            'section' => 'Grade 10 / Dalton',
            'weight' => 40, 'bmi_value' => 17.7, 'nutritional_status' => 'Normal',
            'is_at_risk' => true,
        ]);

        $this->consultation();
        $this->consultation(['consulted_at' => now()->subMonths(2), 'student_name' => 'Old, Case']);

        Medicine::create([
            'institution_id' => $this->institution->id,
            'name' => 'Paracetamol',
            'unit' => 'tablets',
            'stock_quantity' => 3,
            'minimum_threshold' => 20,
        ]);

        $response = $this->withSession($this->nurseSession())
            ->get('/dashboard/school-nurse')
            ->assertOk();

        $this->assertSame(1, $response->viewData('totalRecords'));
        $this->assertSame(1, $response->viewData('consultationsToday'));
        $this->assertSame(1, $response->viewData('atRiskCount'));
        $this->assertSame(1, $response->viewData('lowStockCount'));

        $response->assertSee('Total Records')
            ->assertSee('At-Risk Learners')
            ->assertSee('Low Stock Medicines')
            ->assertSee('Paracetamol');
    }

    #[Test]
    public function the_dashboard_greets_the_nurse_and_shows_the_school_and_year(): void
    {
        $response = $this->withSession($this->nurseSession())
            ->get('/dashboard/school-nurse')
            ->assertOk();

        $response->assertSee('Ana Reyes')
            ->assertSee('School Nurse')
            ->assertSee('Sta. Ana NHS')
            ->assertSee(StudentHealthRecord::currentSchoolYear())
            // The "Clinic Open" pill was removed: it was a static label, never
            // read from anything, so it could only ever say "Open".
            ->assertDontSee('Clinic Open');
    }

    #[Test]
    public function the_consultations_table_carries_the_filter_hooks(): void
    {
        $this->consultation(['grade_section' => 'Grade 9 - Rizal']);
        $this->consultation(['grade_section' => 'Grade 12 - STEM A', 'student_name' => 'Tan, Sofia']);
        $this->consultation(['grade_section' => 'Faculty', 'student_name' => 'Santos, Mr.']);

        $html = $this->withSession($this->nurseSession())
            ->get('/dashboard/school-nurse')
            ->assertOk()
            ->getContent();

        // Grade and section are read off the decrypted label, in PHP; a
        // label with no grade is a staff visit.
        $this->assertStringContainsString('data-grade="grade 9"', $html);
        $this->assertStringContainsString('data-section="rizal"', $html);
        $this->assertStringContainsString('data-grade="grade 12"', $html);
        $this->assertStringContainsString('data-section="stem a"', $html);
        $this->assertStringContainsString('data-grade="personnel"', $html);
        $this->assertStringContainsString('id="consultSearch"', $html);

        // Grade levels replace the old Junior / Senior High buckets, and a
        // grade a visit was typed under is selectable even with no roster.
        $this->assertStringContainsString('id="consultGradeFilter"', $html);
        $this->assertStringContainsString('<option value="grade 9">Grade 9</option>', $html);
        $this->assertStringContainsString('<option value="personnel">Personnel</option>', $html);
        $this->assertStringNotContainsString('consultLevelFilter', $html);
        $this->assertStringNotContainsString('Junior High', $html);
        $this->assertStringContainsString('id="consultSectionFilter"', $html);
        $this->assertStringContainsString('<option value="rizal">Rizal</option>', $html);
        $this->assertStringContainsString('id="consultSexFilter"', $html);
        $this->assertStringContainsString('<option value="male">Male</option>', $html);
        $this->assertStringContainsString('<option value="female">Female</option>', $html);

        // Each row prints the day it was logged.
        $this->assertStringContainsString(now()->format('M j, Y'), $html);

        // The date filter is a calendar, and each row carries the calendar
        // date it is matched against — stamped server-side.
        $this->assertStringContainsString('type="date" class="input" id="consultDateFilter"', $html);
        $this->assertStringNotContainsString('<option value="today">', $html);
        $this->assertStringContainsString('data-date="'.now()->toDateString().'"', $html);
    }

    #[Test]
    public function a_visit_takes_its_gender_and_class_from_the_learner_its_name_matches(): void
    {
        $learner = fn (string $lrn, string $name, string $section, string $gender) => StudentHealthRecord::create([
            'institution_id' => $this->institution->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_id' => $lrn,
            'student_name' => $name,
            'section' => $section,
            'student_details' => ['gender' => $gender],
        ]);

        $learner('LRN1', 'Reyes, Maria S.', 'Grade 8 / Sampaguita', 'F');
        $learner('LRN2', 'Gomez, Jose', 'Grade 7 / Curie', 'male');
        // Two learners share a name; only the visit's grade tells them apart.
        $learner('LRN3', 'Tan, Leo', 'Grade 9 / Rizal', 'Male');
        $learner('LRN4', 'Tan, Leo', 'Grade 10 / Dalton', 'Female');

        // Typed in another order and without the middle initial.
        $this->consultation(['student_name' => 'Maria Reyes', 'grade_section' => 'Grade 8 - Sampaguita']);
        // A label with no grade borrows the matched learner's class.
        $this->consultation(['student_name' => 'Gomez, Jose', 'grade_section' => 'Curie']);
        $this->consultation(['student_name' => 'Tan, Leo', 'grade_section' => 'Grade 10 - Dalton']);
        // Nobody on the roster: no gender is guessed.
        $this->consultation(['student_name' => 'Santos, Mr.', 'grade_section' => 'Faculty']);

        $html = $this->withSession($this->nurseSession())
            ->get('/dashboard/school-nurse')
            ->assertOk()
            ->getContent();

        $rows = [];
        preg_match_all('/<tr class="js-consult-row"(.*?)>/s', $html, $match);
        foreach ($match[1] as $attributes) {
            preg_match('/data-grade="([^"]*)"\s+data-section="([^"]*)"\s+data-sex="([^"]*)"/', $attributes, $hook);
            preg_match('/data-search="([^"]*)"/', $attributes, $search);
            $rows[$search[1]] = array_slice($hook, 1);
        }

        $this->assertSame(['grade 8', 'sampaguita', 'female'], $rows['maria reyes grade 8 - sampaguita fever rest and fluids']);
        $this->assertSame(['grade 7', 'curie', 'male'], $rows['gomez, jose curie fever rest and fluids']);
        $this->assertSame(['grade 10', 'dalton', 'female'], $rows['tan, leo grade 10 - dalton fever rest and fluids']);
        $this->assertSame(['personnel', '', ''], $rows['santos, mr. faculty fever rest and fluids']);

        // The section control knows which grade runs which section.
        preg_match("/data-sections='([^']*)'/", $html, $sections);
        $this->assertSame([
            'grade 7' => ['Curie'],
            'grade 8' => ['Sampaguita'],
            'grade 9' => ['Rizal'],
            'grade 10' => ['Dalton'],
        ], json_decode($sections[1], true));
    }

    #[Test]
    public function the_role_is_labelled_school_nurse_across_the_app(): void
    {
        $this->withSession($this->nurseSession())
            ->get('/dashboard/school-nurse')
            ->assertOk()
            ->assertDontSee('Clinical Teacher');

        $this->get('/account-request')
            ->assertOk()
            ->assertSee('School Nurse')
            ->assertDontSee('Clinical Teacher');
    }

    #[Test]
    public function every_nurse_page_uses_the_shared_sidebar_with_the_right_item_active(): void
    {
        // Moved onto the LUSOG design system: partials/nurse-lusog-sidebar.
        $lusogPages = [
            '/dashboard/school-nurse' => 'dashboard.school-nurse',
            '/nurse' => 'nurse.index',
            '/dashboard/student-health-records' => 'dashboard.student-health-records',
            '/dashboard/consultation-log' => 'dashboard.consultation-log',
            '/dashboard/medicine-inventory' => 'dashboard.medicine-inventory',
            '/dashboard/data-visualization' => 'dashboard.data-visualization',
        ];

        // Still on the older .nsb-* rail. Move each entry up to $lusogPages
        // as it is converted; the list is expected to reach zero.
        $legacyPages = [];

        // Reachable and fully working, but deliberately absent from the rail
        // (see the note in partials/nurse-lusog-sidebar). These still render
        // the shared sidebar — they simply have no entry to highlight.
        $unlistedPages = [
            '/dashboard/school-nurse/deworming',
        ];

        foreach ($lusogPages as $url => $activeRoute) {
            $html = $this->withSession($this->nurseSession())->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('class="sidebar"', $html, "{$url} must render the shared nurse rail.");
            $this->assertStringContainsString(
                'href="'.route($activeRoute).'" class="sb-link active"',
                $html,
                "{$url} must highlight its own nav item."
            );
        }

        foreach ($legacyPages as $url => $activeRoute) {
            $html = $this->withSession($this->nurseSession())->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('class="nsb"', $html, "{$url} must render the shared nurse sidebar.");
            $this->assertStringContainsString(
                'href="'.route($activeRoute).'" class="nsb-item active"',
                $html,
                "{$url} must highlight its own nav item."
            );
        }

        foreach ($unlistedPages as $url) {
            $html = $this->withSession($this->nurseSession())->get($url)->assertOk()->getContent();

            $this->assertMatchesRegularExpression(
                '/class="(nsb|sidebar)"/',
                $html,
                "{$url} must still render the shared nurse sidebar."
            );
            // The page's own heading names the programme, so match the rail
            // link shape rather than the words.
            foreach (['sb-link', 'nsb-item'] as $railClass) {
                $this->assertStringNotContainsString(
                    'href="'.route('dashboard.school-nurse.deworming').'" class="'.$railClass,
                    $html,
                    "{$url} is hidden from the rail, so no {$railClass} may link to it."
                );
            }
        }

        // Whichever rail a page is on, it comes from a shared partial: no
        // page may inline a second <aside> of its own.
        foreach (array_merge(array_keys($lusogPages + $legacyPages), $unlistedPages) as $url) {
            $html = $this->withSession($this->nurseSession())->get($url)->assertOk()->getContent();

            $this->assertSame(
                1,
                substr_count($html, '<aside'),
                "{$url} must render exactly one sidebar."
            );
        }
    }

    #[Test]
    public function the_side_menu_renders_the_full_nav_and_the_user_card(): void
    {
        $nurse = $this->withSession($this->nurseSession())
            ->get('/dashboard/school-nurse')
            ->assertOk();

        foreach (['sb-logo', 'sb-section-label', 'sb-link', 'sb-user', 'sb-avatar'] as $hook) {
            $nurse->assertSee($hook, false);
        }

        // The LUSOG rail ends at Reports — the retired "System" group held
        // only a dead Settings link, and signing out moved to the user card.
        foreach (['Main', 'Health Programs', 'Inventory', 'Reports'] as $section) {
            $nurse->assertSee($section);
        }

        $nurse->assertSee('Ana Reyes')
            ->assertSee('Sta. Ana NHS')
            // One entry, not two: the Review Queue and the old Health
            // Assessments tab were the same job, so the rail names it once.
            ->assertSee('Health Assessment')
            ->assertDontSee('Review Queue')
            ->assertSee('Medicine Inventory')
            // The avatar carries one initial per name part, as everywhere
            // else in the LUSOG system — "Ana Reyes" reads AR.
            ->assertSee('<div class="sb-avatar">AR</div>', false);
    }

    #[Test]
    public function signing_out_from_the_side_menu_still_posts_a_csrf_protected_form(): void
    {
        $html = $this->withSession($this->nurseSession())
            ->get('/dashboard/school-nurse')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('action="'.route('logout').'"', $html);
        $this->assertMatchesRegularExpression(
            '/action="'.preg_quote(route('logout'), '/').'".*?_token/s',
            $html,
            'The logout form must carry a CSRF token.'
        );

        $this->withSession($this->nurseSession())
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertNull(session('active_role'));
    }

    #[Test]
    public function the_pending_health_card_badge_counts_unexamined_learners(): void
    {
        $session = array_merge($this->nurseSession(), [
            'school_health_card_records' => [
                ['lrn' => 'LRN001', 'examination' => []],
                ['lrn' => 'LRN002', 'examination' => []],
                ['lrn' => 'LRN003', 'examination' => ['deworming' => 'V']],
            ],
        ]);

        $html = $this->withSession($session)->get('/dashboard/school-nurse')->assertOk()->getContent();

        // Two learners still awaiting examination.
        $this->assertStringContainsString('<span class="sb-count alert">2</span>', $html);
    }

    #[Test]
    public function another_schools_consultations_never_appear(): void
    {
        $other = Institution::create(['name' => 'Other School', 'status' => 'active']);

        Consultation::create([
            'institution_id' => $other->id,
            'consulted_at' => now(),
            'student_name' => 'Outsider, Nina',
            'grade_section' => 'Grade 10 - Dalton',
            'condition' => 'Migraine',
            'treatment_given' => 'Rest',
            'status' => 'treated',
        ]);

        $response = $this->withSession($this->nurseSession())
            ->get('/dashboard/school-nurse')
            ->assertOk();

        $this->assertSame(0, $response->viewData('consultationsToday'));
        $response->assertDontSee('Outsider, Nina')->assertDontSee('Migraine');
    }

    /**
     * The Health Assessment queue's action follows the Status beside it.
     *
     * A row reading "Completed" used to offer "Fill Medical Record", telling
     * the nurse to do work that is already on file. Both states open the one
     * existing form, so this is the label and the affordance changing, never a
     * second form or a second way to write an examination.
     */
    #[Test]
    public function a_completed_row_offers_an_edit_and_a_pending_row_a_fill(): void
    {
        $session = $this->nurseSession();
        $session['school_health_card_records'] = [
            [
                'last_name' => 'Gomez', 'first_name' => 'Jose',
                'lrn' => '100000000001', 'grade_level' => 'Grade 10', 'section' => 'Dalton',
                // Nothing on file: this learner is Pending.
                'examination' => [],
            ],
            [
                'last_name' => 'Reyes', 'first_name' => 'Ana',
                'lrn' => '100000000002', 'grade_level' => 'Grade 10', 'section' => 'Dalton',
                // The nurse has examined this one: Completed.
                'examination' => ['date_of_examination' => now()->toDateString()],
            ],
        ];

        $html = $this->withSession($session)->get('/nurse')->assertOk()->getContent();

        // One of each, and each keyed to its own row's form.
        $this->assertSame(1, substr_count($html, 'Fill Medical Record'));
        $this->assertSame(1, substr_count($html, 'aria-label="Edit Medical Record"'));
        $this->assertStringContainsString('Edit Medical Record', $html);

        $pendingAt = strpos($html, route('nurse.examine', 0));
        $completedAt = strpos($html, route('nurse.examine', 1));
        $this->assertNotFalse($pendingAt);
        $this->assertNotFalse($completedAt);
        $this->assertTrue(
            $pendingAt < strpos($html, 'Fill Medical Record') && strpos($html, 'Fill Medical Record') < $completedAt,
            'The Fill button belongs to the row with nothing on file.'
        );

        // The pencil carries its own accessible name — the label is beside it,
        // but the glyph is what a nurse aims at.
        $this->assertStringContainsString('aria-label="Edit Medical Record"', $html);
        $this->assertStringContainsString('title="Edit Medical Record"', $html);

        // Both states read the same Status the row prints, so the badge and the
        // button cannot disagree about one learner.
        $this->assertStringContainsString('>Completed</span>', $html);
        $this->assertStringContainsString('>Pending</span>', $html);
    }

    /**
     * One form, whichever button opened it — and its save updates the row it
     * was opened on rather than filing a second examination.
     */
    #[Test]
    public function editing_a_completed_record_reopens_the_one_form_and_updates_in_place(): void
    {
        $record = StudentHealthRecord::create([
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'institution_id' => $this->institution->id,
            'student_id' => '100000000002',
            'student_name' => 'Reyes, Ana',
            'school_name' => 'Sta. Ana NHS',
            'section' => 'Grade 10 / Dalton',
            'weight' => 42.0,
            'bmi_value' => 17.1,
            'nutritional_status' => 'Wasted',
            'examination' => ['date_of_examination' => '2026-09-01', 'others' => 'First pass'],
        ]);

        $session = $this->nurseSession();
        $session['school_health_card_records'] = [
            [
                'last_name' => 'Reyes', 'first_name' => 'Ana',
                'lrn' => '100000000002', 'grade_level' => 'Grade 10', 'section' => 'Dalton',
                'height_cm' => 150, 'weight_kg' => 42,
                'examination' => ['date_of_examination' => '2026-09-01', 'others' => 'First pass'],
            ],
        ];

        // The form opens prefilled with what is already on file.
        $this->withSession($session)
            ->get(route('nurse.examine', 0))
            ->assertOk()
            ->assertSee('value="2026-09-01"', false);

        $before = StudentHealthRecord::count();

        $this->withSession($session)->post(route('nurse.examine.save', 0), [
            'date_of_examination' => '2026-09-20',
            'others' => 'Corrected on review',
        ])->assertRedirect();

        // The same record, updated — not a second one.
        $this->assertSame($before, StudentHealthRecord::count());
        $this->assertSame('2026-09-20', $record->fresh()->examination['date_of_examination']);
        $this->assertSame('Corrected on review', $record->fresh()->examination['others']);
    }
}
