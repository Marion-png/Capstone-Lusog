<?php

namespace Tests\Feature;

use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Post Announcement" and "Add Event" open a dialog over a blurred page.
 *
 * Both boards used to expand a form inline inside their own card. They now
 * open a .bmodal dialog whose backdrop blurs the dashboard behind it.
 *
 * The two failure modes worth pinning:
 *  - the shared dialog assets being emitted twice on a dashboard that
 *    carries both boards (six of the seven do), and
 *  - a validation failure leaving the messages stranded behind a closed
 *    panel, or re-opening the wrong dialog because both forms have a
 *    `title` field.
 */
class DashboardBoardModalTest extends TestCase
{
    use RefreshDatabase;

    private function nurseSession(): array
    {
        $school = Institution::firstOrCreate(['name' => 'Sta. Ana NHS'], ['status' => 'active']);

        return [
            'active_role' => 'school_nurse',
            'active_name' => 'Nurse Cruz',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $school->id,
        ];
    }

    private function dashboard(): string
    {
        return $this->withSession($this->nurseSession())
            ->get(route('dashboard.school-nurse'))
            ->assertOk()
            ->getContent();
    }

    #[Test]
    public function both_buttons_open_a_dialog_rather_than_an_inline_form(): void
    {
        $html = $this->dashboard();

        $this->assertStringContainsString('data-bmodal-open="annPostModal"', $html);
        $this->assertStringContainsString('data-bmodal-open="evtAddModal"', $html);

        $this->assertStringContainsString('id="annPostModal"', $html);
        $this->assertStringContainsString('id="evtAddModal"', $html);

        // The old inline panels are gone.
        $this->assertStringNotContainsString('id="annPostForm"', $html);
        $this->assertStringNotContainsString('id="evtAddForm"', $html);
    }

    #[Test]
    public function the_backdrop_blurs_the_page_behind_it(): void
    {
        $html = $this->dashboard();

        $this->assertStringContainsString('backdrop-filter: blur(6px)', $html);
        // Safari needs the prefix, so it must be there too.
        $this->assertStringContainsString('-webkit-backdrop-filter: blur(6px)', $html);
    }

    /** Both boards are on this dashboard; the shared assets must load once. */
    #[Test]
    public function the_shared_dialog_assets_are_emitted_only_once(): void
    {
        $html = $this->dashboard();

        $this->assertSame(1, substr_count($html, 'body.bmodal-open'), 'Dialog CSS was emitted more than once.');
        $this->assertSame(1, substr_count($html, 'data-bmodal-autoopen]'), 'Dialog JS was emitted more than once.');
    }

    #[Test]
    public function the_dialog_is_dismissable_and_labelled_for_assistive_tech(): void
    {
        $html = $this->dashboard();

        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('aria-labelledby="annPostModalTitle"', $html);
        $this->assertStringContainsString('data-bmodal-close', $html);
    }

    /**
     * Whether a specific dialog carries the auto-open marker.
     *
     * Matched on that dialog's own opening tag — the string also appears in
     * the shared script's selector, so a plain search finds the JS first.
     */
    private function dialogAutoOpens(string $html, string $id): bool
    {
        preg_match('/<div class="bmodal" id="'.preg_quote($id, '/').'"[^>]*>/', $html, $match);

        $this->assertNotEmpty($match, "The {$id} dialog is not on the page at all.");

        return str_contains($match[0], 'data-bmodal-autoopen');
    }

    /** Neither dialog is open on a normal page load. */
    #[Test]
    public function no_dialog_opens_by_itself(): void
    {
        $html = $this->dashboard();

        $this->assertFalse($this->dialogAutoOpens($html, 'annPostModal'));
        $this->assertFalse($this->dialogAutoOpens($html, 'evtAddModal'));
    }

    #[Test]
    public function a_failed_announcement_reopens_only_the_announcement_dialog(): void
    {
        $html = $this->withSession($this->nurseSession())
            ->from(route('dashboard.school-nurse'))
            ->followingRedirects()
            ->post(route('announcements.store'), ['title' => '', 'body' => ''])
            ->assertOk()
            ->getContent();

        $this->assertTrue(
            $this->dialogAutoOpens($html, 'annPostModal'),
            'The announcement dialog must re-open so its errors are visible.'
        );
        $this->assertFalse(
            $this->dialogAutoOpens($html, 'evtAddModal'),
            'The event dialog re-opened for an announcement error.'
        );

        // And the message itself is rendered.
        $this->assertStringContainsString('bmodal-error', $html);
    }

    #[Test]
    public function a_failed_event_reopens_only_the_event_dialog(): void
    {
        $html = $this->withSession($this->nurseSession())
            ->from(route('dashboard.school-nurse'))
            ->followingRedirects()
            ->post(route('events.store'), ['title' => '', 'event_date' => '', 'category' => ''])
            ->assertOk()
            ->getContent();

        $this->assertTrue(
            $this->dialogAutoOpens($html, 'evtAddModal'),
            'The event dialog must re-open so its errors are visible.'
        );
        $this->assertFalse(
            $this->dialogAutoOpens($html, 'annPostModal'),
            'The announcement dialog re-opened for an event error.'
        );
    }

    /** A role that may not post sees neither trigger nor dialog. */
    #[Test]
    public function a_role_that_cannot_post_gets_no_dialog(): void
    {
        $school = Institution::firstOrCreate(['name' => 'Sta. Ana NHS'], ['status' => 'active']);

        $html = $this->withSession([
            'active_role' => 'class_adviser',
            'active_name' => 'Maria Santos',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $school->id,
            'assigned_grade_level' => 'Grade 10',
            'assigned_section' => 'Dalton',
        ])->get(route('dashboard.class-adviser'))->assertOk()->getContent();

        $this->assertStringNotContainsString('id="annPostModal"', $html);
        $this->assertStringNotContainsString('data-bmodal-open', $html);
    }
}
