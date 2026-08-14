<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Announcements carry a priority and an audience.
 *
 * Priority (Normal / Important / Urgent) changes how a notice reads on every
 * dashboard. Audience decides which roles see it at all — a deworming
 * reminder concerns class advisers, a stock warning concerns the clinic.
 *
 * Two rules worth pinning, because both are easy to get backwards:
 *  - no audience ticked means EVERYONE, not no one;
 *  - the author always sees their own notice, even when addressed elsewhere.
 */
class AnnouncementPriorityAudienceTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function sessionFor(string $role): array
    {
        return [
            'active_role' => $role,
            'active_name' => $role === 'school_nurse' ? 'Nurse Cruz' : 'Maria Santos',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
            'assigned_grade_level' => 'Grade 10',
            'assigned_section' => 'Dalton',
        ];
    }

    private function dashboardFor(string $role): string
    {
        $route = $role === 'class_adviser' ? 'dashboard.class-adviser' : 'dashboard.school-nurse';

        return $this->withSession($this->sessionFor($role))->get(route($route))->assertOk()->getContent();
    }

    #[Test]
    public function the_dialog_offers_a_priority_dropdown_and_an_audience_picker(): void
    {
        $html = $this->dashboardFor('school_nurse');

        $this->assertStringContainsString('name="priority"', $html);
        foreach (Announcement::PRIORITIES as $label) {
            $this->assertStringContainsString('>'.$label.'</option>', $html);
        }

        // Audience is a dropdown: "Everyone" plus one option per role.
        $this->assertStringContainsString('<select id="annAudience" name="audience[]">', $html);
        $this->assertStringContainsString('>Everyone</option>', $html);
        foreach (Announcement::AUDIENCES as $label) {
            $this->assertStringContainsString('>'.$label.' only</option>', $html);
        }
    }

    /** The dropdown's "Everyone" option posts an empty value. */
    #[Test]
    public function the_everyone_option_is_not_treated_as_an_unknown_role(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('announcements.store'), [
                'title' => 'To all staff',
                'body' => 'General reminder.',
                'audience' => [''],
            ])->assertRedirect();

        $announcement = Announcement::firstOrFail();
        $this->assertSame([], $announcement->audience);
        $this->assertSame('Everyone', $announcement->audienceLabel());

        $this->assertStringContainsString('To all staff', $this->dashboardFor('class_adviser'));
    }

    #[Test]
    public function an_urgent_announcement_is_stored_and_shown_as_urgent(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('announcements.store'), [
                'title' => 'Clinic closed at noon',
                'body' => 'No walk-ins after 12.',
                'priority' => Announcement::PRIORITY_URGENT,
            ])->assertRedirect();

        $announcement = Announcement::firstOrFail();
        $this->assertSame(Announcement::PRIORITY_URGENT, $announcement->priority);
        $this->assertTrue($announcement->isFlagged());

        // Match the rendered element, not the class name — the inline
        // stylesheet on the page defines .ann-pill-urgent too.
        $html = $this->dashboardFor('school_nurse');
        $this->assertStringContainsString('<span class="ann-pill ann-pill-urgent">Urgent</span>', $html);
    }

    /** A normal notice gets no chip — only the two that matter are coloured. */
    #[Test]
    public function a_normal_announcement_carries_no_priority_chip(): void
    {
        Announcement::create([
            'institution_id' => $this->school->id,
            'title' => 'Routine notice',
            'body' => 'Nothing pressing.',
            'priority' => Announcement::PRIORITY_NORMAL,
            'audience' => [],
            'posted_by_name' => 'Nurse Cruz',
            'posted_by_role' => 'school_nurse',
        ]);

        $html = $this->dashboardFor('school_nurse');

        $this->assertStringContainsString('Routine notice', $html);
        // No chip element is rendered at all (the stylesheet still defines
        // the classes, so match the element, not the class name).
        $this->assertStringNotContainsString('<span class="ann-pill', $html);
    }

    #[Test]
    public function an_announcement_reaches_only_the_roles_it_was_addressed_to(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('announcements.store'), [
                'title' => 'Advisers only notice',
                'body' => 'Please submit health cards.',
                'audience' => ['class_adviser'],
            ])->assertRedirect();

        $this->assertSame(['class_adviser'], Announcement::firstOrFail()->audience);

        // The addressed role sees it…
        $this->assertStringContainsString('Advisers only notice', $this->dashboardFor('class_adviser'));

        // …and a role that was not addressed does not.
        $this->assertStringNotContainsString(
            'Advisers only notice',
            $this->withSession($this->sessionFor('clinic_staff'))
                ->get(route('dashboard.clinic-staff'))->assertOk()->getContent()
        );
    }

    /** Unticked means everyone, not no one. */
    #[Test]
    public function an_announcement_with_no_audience_reaches_every_role(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('announcements.store'), [
                'title' => 'Everyone notice',
                'body' => 'General reminder.',
            ])->assertRedirect();

        $this->assertSame([], Announcement::firstOrFail()->audience);

        $this->assertStringContainsString('Everyone notice', $this->dashboardFor('class_adviser'));
        $this->assertStringContainsString('Everyone notice', $this->dashboardFor('school_nurse'));
    }

    /** The nurse must still see a notice they addressed to someone else. */
    #[Test]
    public function the_author_always_sees_their_own_announcement(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('announcements.store'), [
                'title' => 'For advisers',
                'body' => 'Submit by Friday.',
                'audience' => ['class_adviser'],
            ])->assertRedirect();

        $this->assertStringContainsString('For advisers', $this->dashboardFor('school_nurse'));
    }

    /** Announcements written before audiences existed still reach everyone. */
    #[Test]
    public function a_legacy_announcement_with_a_null_audience_reaches_every_role(): void
    {
        Announcement::create([
            'institution_id' => $this->school->id,
            'title' => 'Legacy notice',
            'body' => 'Written before audiences existed.',
            'audience' => null,
            'posted_by_name' => 'Nurse Cruz',
            'posted_by_role' => 'school_nurse',
        ]);

        $this->assertStringContainsString('Legacy notice', $this->dashboardFor('class_adviser'));
    }

    /**
     * The audience filter must not compare the json column with `=`.
     *
     * This suite runs on SQLite, where `audience = '[]'` is accepted without
     * complaint. PostgreSQL — what the app actually runs on — defines no
     * equality operator for the `json` type and returns
     * SQLSTATE[42883] instead, taking every dashboard down with it. Since the
     * database under test cannot catch that, assert on the compiled SQL.
     */
    #[Test]
    public function the_audience_filter_avoids_equality_on_the_json_column(): void
    {
        $sql = Announcement::query()->visibleToRole('school_nurse')->toSql();

        $this->assertStringNotContainsString(
            '"audience" =',
            $sql,
            'PostgreSQL has no = operator for json; match the empty audience by length instead.'
        );
        $this->assertStringNotContainsString('`audience` =', $sql);

        // The empty-audience case is still covered, by array length.
        $this->assertMatchesRegularExpression('/json_?b?_array_length/i', $sql);
    }

    #[Test]
    public function an_unknown_priority_or_audience_is_rejected(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('announcements.store'), [
                'title' => 'Bad', 'body' => 'Bad', 'priority' => 'catastrophic',
            ])->assertSessionHasErrors('priority', null, 'announcement');

        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('announcements.store'), [
                'title' => 'Bad', 'body' => 'Bad', 'audience' => ['president'],
            ])->assertSessionHasErrors('audience.0', null, 'announcement');

        $this->assertSame(0, Announcement::count());
    }

    #[Test]
    public function the_poster_can_see_who_an_announcement_went_to(): void
    {
        $announcement = Announcement::create([
            'institution_id' => $this->school->id,
            'title' => 'Targeted',
            'body' => 'Body.',
            'audience' => ['class_adviser', 'clinic_staff'],
            'posted_by_name' => 'Nurse Cruz',
            'posted_by_role' => 'school_nurse',
        ]);

        $this->assertSame('Class Advisers, Clinic Staff', $announcement->audienceLabel());
        $this->assertStringContainsString('To: Class Advisers, Clinic Staff', $this->dashboardFor('school_nurse'));
    }
}
