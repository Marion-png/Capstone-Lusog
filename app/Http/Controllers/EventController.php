<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    /**
     * Create an event. Restricted to Event::CREATOR_ROLES (School Nurse, for
     * now) and scoped to the creator's own school.
     */
    public function store(Request $request): RedirectResponse
    {
        $role = (string) $request->session()->get('active_role', '');

        abort_unless(Event::canCreate($role), 403, 'Only the School Nurse may schedule events.');

        // Named bag — see the note in AnnouncementController::store().
        $validated = $request->validateWithBag('event', [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'event_date' => ['required', 'date'],
            'category' => ['required', 'in:'.implode(',', array_keys(Event::CATEGORIES))],
        ]);

        Event::create([
            'institution_id' => $request->session()->get('active_institution_id'),
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'event_date' => $validated['event_date'],
            'category' => $validated['category'],
            'created_by_name' => (string) $request->session()->get('active_name', 'School Nurse'),
            'created_by_role' => $role,
        ]);

        return back()->with('event_success', 'Event scheduled.');
    }

    /**
     * Remove an event. Restricted to creator roles and the event's own
     * school, same guard pattern as AnnouncementController::destroy().
     */
    public function destroy(Request $request, Event $event): RedirectResponse
    {
        $role = (string) $request->session()->get('active_role', '');

        abort_unless(Event::canCreate($role), 403, 'Only the School Nurse may remove events.');

        $institutionId = $request->session()->get('active_institution_id');
        abort_if(
            $institutionId && (int) $event->institution_id !== (int) $institutionId,
            404
        );

        $event->delete();

        return back()->with('event_success', 'Event removed.');
    }
}
