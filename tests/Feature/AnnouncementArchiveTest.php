<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The board holds Announcement::BOARD_LIMIT notices, and the nurse has two
 * ways to clear it.
 *
 * Archiving is a stamp beside the announcement, never a deletion of it: the
 * notice leaves every dashboard but stays retrievable as what the school
 * actually told its staff. Deleting still means gone. Both are the nurse's
 * alone, and both are scoped to their own school.
 */
class AnnouncementArchiveTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::create(['name' => 'Test School', 'status' => 'active']);
    }

    private function sessionFor(string $role): array
    {
        return [
            'active_role' => $role,
            'active_name' => ucfirst($role).' User',
            'active_username' => strtolower($role).'.user',
            'active_institution_id' => $this->institution->id,
        ];
    }

    private function makeAnnouncement(array $overrides = []): Announcement
    {
        return Announcement::create(array_merge([
            'institution_id' => $this->institution->id,
            'title' => 'Deworming Day',
            'body' => 'Bring signed consent forms this Friday.',
            'posted_by_name' => 'Nurse Reyes',
            'posted_by_role' => 'school_nurse',
        ], $overrides));
    }

    // ── The capacity ────────────────────────────────────────────────────────

    #[Test]
    public function the_board_shows_at_most_the_board_limit(): void
    {
        for ($i = 1; $i <= Announcement::BOARD_LIMIT + 3; $i++) {
            $this->makeAnnouncement(['title' => "Notice number {$i}"]);
        }

        $response = $this->withSession($this->sessionFor('school_nurse'))
            ->get('/dashboard/school-nurse')
            ->assertOk();

        // The newest BOARD_LIMIT are on the board; everything older is not.
        $total = Announcement::BOARD_LIMIT + 3;
        for ($i = $total; $i > $total - Announcement::BOARD_LIMIT; $i--) {
            $response->assertSee("Notice number {$i}");
        }
        for ($i = $total - Announcement::BOARD_LIMIT; $i >= 1; $i--) {
            $response->assertDontSee("Notice number {$i}");
        }
    }

    /**
     * An announcement that scrolled off silently is one the nurse thinks is
     * still up, so the board says how many it is holding back.
     */
    #[Test]
    public function the_board_says_when_it_is_holding_notices_back(): void
    {
        for ($i = 1; $i <= Announcement::BOARD_LIMIT + 2; $i++) {
            $this->makeAnnouncement(['title' => "Notice number {$i}"]);
        }

        $this->withSession($this->sessionFor('school_nurse'))
            ->get('/dashboard/school-nurse')
            ->assertOk()
            ->assertSee('Showing the '.Announcement::BOARD_LIMIT.' most recent of '.(Announcement::BOARD_LIMIT + 2));
    }

    #[Test]
    public function a_board_within_its_capacity_says_nothing_about_older_notices(): void
    {
        $this->makeAnnouncement(['title' => 'The only notice']);

        $this->withSession($this->sessionFor('school_nurse'))
            ->get('/dashboard/school-nurse')
            ->assertOk()
            ->assertDontSee('most recent of');
    }

    // ── Archiving ───────────────────────────────────────────────────────────

    #[Test]
    public function the_nurse_can_archive_an_announcement_and_it_survives(): void
    {
        $announcement = $this->makeAnnouncement(['title' => 'Last term notice']);

        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('announcements.archive', $announcement))
            ->assertRedirect();

        $announcement->refresh();

        $this->assertNotNull($announcement->archived_at, 'Archiving must stamp the row.');
        $this->assertSame('School_nurse User', $announcement->archived_by_name);
        $this->assertSame(1, Announcement::count(), 'Archiving must never delete the announcement.');
    }

    /**
     * Archived means off the board for everybody, the nurse who archived it
     * included — the board is what is current, not what is unread.
     */
    #[Test]
    public function an_archived_announcement_leaves_every_dashboard(): void
    {
        $announcement = $this->makeAnnouncement(['title' => 'Filed away notice']);

        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('announcements.archive', $announcement));

        $dashboards = [
            'class_adviser' => '/dashboard/class-adviser',
            'school_nurse' => '/dashboard/school-nurse',
            'clinic_staff' => '/dashboard/clinic-staff',
            'school_head' => '/dashboard/school-head',
            'feeding_coor' => '/dashboard/feedingcor-dashboard',
            'nutricor' => '/dashboard/nutricor-dashboard',
        ];

        foreach ($dashboards as $role => $url) {
            $session = $this->sessionFor($role);
            if ($role === 'class_adviser') {
                $session['assigned_grade_level'] = 'Grade 7/SPED';
                $session['assigned_section'] = 'SPED-A';
            }

            $response = $this->withSession($session)->get($url)->assertOk();

            // The nurse still reaches it through the archive dialog, so for
            // that one role the title is on the page — just not on the board.
            if ($role === 'school_nurse') {
                $response->assertSee('Archived announcements');
            } else {
                $response->assertDontSee('Filed away notice');
            }
        }
    }

    #[Test]
    public function archiving_twice_does_not_restamp_the_announcement(): void
    {
        $announcement = $this->makeAnnouncement();

        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('announcements.archive', $announcement));

        $firstStamp = $announcement->fresh()->archived_at;

        $this->travel(2)->minutes();

        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('announcements.archive', $announcement))
            ->assertRedirect();

        $this->assertEquals(
            $firstStamp->timestamp,
            $announcement->fresh()->archived_at->timestamp,
            'Re-archiving must be a no-op, not a second stamp.'
        );
    }

    #[Test]
    public function the_nurse_can_restore_an_archived_announcement(): void
    {
        $announcement = $this->makeAnnouncement(['title' => 'Back on the board']);

        $session = $this->sessionFor('school_nurse');

        $this->withSession($session)->post(route('announcements.archive', $announcement));
        $this->withSession($session)->post(route('announcements.restore', $announcement))->assertRedirect();

        $announcement->refresh();
        $this->assertNull($announcement->archived_at);
        $this->assertNull($announcement->archived_by_name);

        $this->withSession($session)
            ->get('/dashboard/school-nurse')
            ->assertOk()
            ->assertSee('Back on the board');
    }

    /**
     * An archived notice is still deletable — archiving is the softer answer,
     * not a lock.
     */
    #[Test]
    public function an_archived_announcement_can_still_be_deleted(): void
    {
        $announcement = $this->makeAnnouncement();
        $session = $this->sessionFor('school_nurse');

        $this->withSession($session)->post(route('announcements.archive', $announcement));
        $this->withSession($session)->post(route('announcements.destroy', $announcement))->assertRedirect();

        $this->assertSame(0, Announcement::count());
    }

    // ── Who may do it ───────────────────────────────────────────────────────

    #[Test]
    public function other_roles_cannot_archive_or_restore(): void
    {
        $announcement = $this->makeAnnouncement();

        foreach (['class_adviser', 'clinic_staff', 'school_head', 'feeding_coor', 'nutricor', 'system_admin'] as $role) {
            $this->withSession($this->sessionFor($role))
                ->post(route('announcements.archive', $announcement))
                ->assertStatus(403);

            $this->withSession($this->sessionFor($role))
                ->post(route('announcements.restore', $announcement))
                ->assertStatus(403);
        }

        $this->assertNull($announcement->fresh()->archived_at);
    }

    #[Test]
    public function a_nurse_cannot_archive_another_schools_announcement(): void
    {
        $otherSchool = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $announcement = $this->makeAnnouncement([
            'institution_id' => $otherSchool->id,
            'title' => 'Not yours',
        ]);

        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('announcements.archive', $announcement))
            ->assertStatus(404);

        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('announcements.restore', $announcement))
            ->assertStatus(404);

        $this->assertNull($announcement->fresh()->archived_at);
    }

    /**
     * The archive is the poster's own working list. Another school's archived
     * notices must never appear in it, scope being the one thing a shared
     * board cannot get wrong.
     */
    #[Test]
    public function the_archive_dialog_shows_only_this_schools_notices(): void
    {
        $otherSchool = Institution::create(['name' => 'Other School', 'status' => 'active']);

        $mine = $this->makeAnnouncement(['title' => 'My archived notice']);
        $theirs = $this->makeAnnouncement([
            'institution_id' => $otherSchool->id,
            'title' => 'Their archived notice',
        ]);

        $mine->forceFill(['archived_at' => now(), 'archived_by_name' => 'Nurse Reyes'])->save();
        $theirs->forceFill(['archived_at' => now(), 'archived_by_name' => 'Other Nurse'])->save();

        $this->withSession($this->sessionFor('school_nurse'))
            ->get('/dashboard/school-nurse')
            ->assertOk()
            ->assertSee('My archived notice')
            ->assertDontSee('Their archived notice');
    }

    #[Test]
    public function only_the_nurse_sees_the_archive_button(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->get('/dashboard/school-nurse')
            ->assertOk()
            ->assertSee('Archived announcements');

        $this->withSession($this->sessionFor('school_head'))
            ->get('/dashboard/school-head')
            ->assertOk()
            ->assertDontSee('Archived announcements');
    }
}
