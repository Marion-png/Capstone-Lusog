<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventTest extends TestCase
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
    public function school_nurse_can_schedule_an_event(): void
    {
        $response = $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('events.store'), [
                'title' => 'Deworming Day',
                'description' => 'Bring signed consent forms.',
                'event_date' => now()->addDays(3)->toDateString(),
                'category' => 'program',
            ]);

        $response->assertRedirect();

        $event = Event::firstOrFail();
        $this->assertSame('Deworming Day', $event->title);
        $this->assertSame($this->institution->id, $event->institution_id);
        $this->assertSame('school_nurse', $event->created_by_role);
    }

    #[Test]
    public function other_roles_cannot_schedule_an_event(): void
    {
        foreach (['class_adviser', 'clinic_staff', 'school_head', 'feeding_coor', 'nutricor', 'system_admin'] as $role) {
            $response = $this->withSession($this->sessionFor($role))
                ->post(route('events.store'), [
                    'title' => 'Should not be allowed',
                    'event_date' => now()->addDay()->toDateString(),
                    'category' => 'program',
                ]);

            $response->assertStatus(403);
        }

        $this->assertSame(0, Event::count());
    }

    #[Test]
    public function event_requires_title_date_and_category(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('events.store'), [])
            ->assertSessionHasErrors(['title', 'event_date', 'category']);

        $this->assertSame(0, Event::count());
    }

    #[Test]
    public function class_adviser_dashboard_shows_upcoming_events(): void
    {
        Event::create([
            'institution_id' => $this->institution->id,
            'title' => 'Vitamin A Supplementation',
            'event_date' => now()->addWeek()->toDateString(),
            'category' => 'program',
            'created_by_name' => 'Nurse Reyes',
            'created_by_role' => 'school_nurse',
        ]);

        $session = $this->sessionFor('class_adviser');
        $session['assigned_grade_level'] = 'Grade 7/SPED';
        $session['assigned_section'] = 'SPED-A';

        $this->withSession($session)
            ->get('/dashboard/class-adviser')
            ->assertStatus(200)
            ->assertSee('Vitamin A Supplementation');
    }

    #[Test]
    public function past_events_do_not_appear_as_upcoming(): void
    {
        Event::create([
            'institution_id' => $this->institution->id,
            'title' => 'Old Event Already Happened',
            'event_date' => now()->subWeek()->toDateString(),
            'category' => 'program',
            'created_by_name' => 'Nurse Reyes',
            'created_by_role' => 'school_nurse',
        ]);

        $this->withSession($this->sessionFor('school_nurse'))
            ->get('/dashboard/school-nurse')
            ->assertDontSee('Old Event Already Happened');
    }

    #[Test]
    public function only_school_nurse_sees_the_add_event_control(): void
    {
        $this->withSession($this->sessionFor('school_nurse'))
            ->get('/dashboard/school-nurse')
            ->assertSee('Add Event');

        $this->withSession($this->sessionFor('school_head'))
            ->get('/dashboard/school-head')
            ->assertDontSee('Add Event');
    }

    #[Test]
    public function events_are_scoped_to_the_creators_school(): void
    {
        $otherSchool = Institution::create(['name' => 'Other School', 'status' => 'active']);

        Event::create([
            'institution_id' => $otherSchool->id,
            'title' => 'Other School Only',
            'event_date' => now()->addDay()->toDateString(),
            'category' => 'program',
            'created_by_name' => 'Other Nurse',
            'created_by_role' => 'school_nurse',
        ]);

        $this->withSession($this->sessionFor('school_nurse'))
            ->get('/dashboard/school-nurse')
            ->assertDontSee('Other School Only');
    }

    #[Test]
    public function school_nurse_can_delete_an_event_from_their_own_school(): void
    {
        $event = Event::create([
            'institution_id' => $this->institution->id,
            'title' => 'To be removed',
            'event_date' => now()->addDay()->toDateString(),
            'category' => 'deadline',
            'created_by_name' => 'Nurse Reyes',
            'created_by_role' => 'school_nurse',
        ]);

        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('events.destroy', $event))
            ->assertRedirect();

        $this->assertSame(0, Event::count());
    }

    #[Test]
    public function school_nurse_cannot_delete_another_schools_event(): void
    {
        $otherSchool = Institution::create(['name' => 'Other School', 'status' => 'active']);
        $event = Event::create([
            'institution_id' => $otherSchool->id,
            'title' => 'Not yours',
            'event_date' => now()->addDay()->toDateString(),
            'category' => 'deadline',
            'created_by_name' => 'Other Nurse',
            'created_by_role' => 'school_nurse',
        ]);

        $this->withSession($this->sessionFor('school_nurse'))
            ->post(route('events.destroy', $event))
            ->assertStatus(404);

        $this->assertSame(1, Event::count());
    }
}
