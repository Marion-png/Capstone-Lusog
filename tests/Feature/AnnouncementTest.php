<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AnnouncementTest extends TestCase
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

    #[Test]
    public function school_nurse_can_post_an_announcement(): void
    {
        $response = $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('announcements.store'), [
                'title' => 'Deworming Day',
                'body' => 'Bring signed consent forms this Friday.',
            ]);

        $response->assertRedirect();

        $announcement = Announcement::firstOrFail();
        $this->assertSame('Deworming Day', $announcement->title);
        $this->assertSame($this->institution->id, $announcement->institution_id);
        $this->assertSame('school_nurse', $announcement->posted_by_role);
    }

    #[Test]
    public function other_roles_cannot_post_an_announcement(): void
    {
        foreach (['class_adviser', 'clinic_staff', 'school_head', 'feeding_coor', 'nutricor', 'system_admin'] as $role) {
            $response = $this->withSession($this->sessionFor($role))
                ->post(route('announcements.store'), [
                    'title' => 'Should not be allowed',
                    'body' => 'Body text.',
                ]);

            $response->assertStatus(403);
        }

        $this->assertSame(0, Announcement::count());
    }

    #[Test]
    public function announcement_requires_title_and_body(): void
    {
        // Errors land in the 'announcement' bag so the announcement dialog
        // re-opens without also re-opening the event one beside it.
        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('announcements.store'), [])
            ->assertSessionHasErrors(['title', 'body'], null, 'announcement');

        $this->assertSame(0, Announcement::count());
    }

    #[Test]
    public function every_role_dashboard_shows_the_announcement(): void
    {
        Announcement::create([
            'institution_id' => $this->institution->id,
            'title' => 'Vitamin A Supplementation',
            'body' => 'All Grade 1 learners this week.',
            'posted_by_name' => 'Nurse Reyes',
            'posted_by_role' => 'school_nurse',
        ]);

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

            $this->withSession($session)
                ->get($url)
                ->assertStatus(200)
                ->assertSee('Vitamin A Supplementation');
        }
    }

    #[Test]
    public function only_school_nurse_sees_the_post_button(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->get('/dashboard/school-nurse')
            ->assertSee('Post Announcement');

        $this->withSession($this->sessionFor('school_head'))
            ->get('/dashboard/school-head')
            ->assertDontSee('Post Announcement');
    }

    #[Test]
    public function announcements_are_scoped_to_the_posters_school(): void
    {
        $otherSchool = Institution::create(['name' => 'Other School', 'status' => 'active']);

        Announcement::create([
            'institution_id' => $otherSchool->id,
            'title' => 'Other School Only',
            'body' => 'Should not be visible elsewhere.',
            'posted_by_name' => 'Other Nurse',
            'posted_by_role' => 'school_nurse',
        ]);

        $this->withSession($this->sessionFor('school_nurse'))
            ->get('/dashboard/school-nurse')
            ->assertDontSee('Other School Only');
    }

    #[Test]
    public function school_nurse_can_delete_an_announcement_from_their_own_school(): void
    {
        $announcement = Announcement::create([
            'institution_id' => $this->institution->id,
            'title' => 'To be removed',
            'body' => 'Body.',
            'posted_by_name' => 'Nurse Reyes',
            'posted_by_role' => 'school_nurse',
        ]);

        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('announcements.destroy', $announcement))
            ->assertRedirect();

        $this->assertSame(0, Announcement::count());
    }

    #[Test]
    public function school_nurse_cannot_delete_another_schools_announcement(): void
    {
        $otherSchool = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $announcement = Announcement::create([
            'institution_id' => $otherSchool->id,
            'title' => 'Not yours',
            'body' => 'Body.',
            'posted_by_name' => 'Other Nurse',
            'posted_by_role' => 'school_nurse',
        ]);

        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('announcements.destroy', $announcement))
            ->assertStatus(404);

        $this->assertSame(1, Announcement::count());
    }
}
