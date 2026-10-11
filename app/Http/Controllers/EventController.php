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
        $validated = $request->validateWithBag('event', $this->rules());

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
     * Change a posted event — its title, date, category or description.
     * Same guard as destroy(): a creator role, and only the event's own
     * school. The school and the author it was posted under are not
     * editable; correcting a date does not make it somebody else's event.
     */
    public function update(Request $request, Event $event): RedirectResponse
    {
        $role = (string) $request->session()->get('active_role', '');

        abort_unless(Event::canCreate($role), 403, 'Only the School Nurse may edit events.');
        $this->abortUnlessOwnSchool($request, $event);

        // Its own bag, so a failed edit re-opens the edit dialog with its
        // messages rather than the Add dialog beside it.
        $validated = $request->validateWithBag('eventEdit', $this->rules());

        $event->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'event_date' => $validated['event_date'],
            'category' => $validated['category'],
        ]);

        return back()->with('event_success', 'Event updated.');
    }

    /**
     * Remove an event. Restricted to creator roles and the event's own
     * school, same guard pattern as AnnouncementController::destroy().
     */
    public function destroy(Request $request, Event $event): RedirectResponse
    {
        $role = (string) $request->session()->get('active_role', '');

        abort_unless(Event::canCreate($role), 403, 'Only the School Nurse may remove events.');
        $this->abortUnlessOwnSchool($request, $event);

        $event->delete();

        return back()->with('event_success', 'Event removed.');
    }

    /** What an event must carry, whether it is being posted or corrected. */
    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'event_date' => ['required', 'date'],
            'category' => ['required', 'in:'.implode(',', array_keys(Event::CATEGORIES))],
        ];
    }

    private function abortUnlessOwnSchool(Request $request, Event $event): void
    {
        $institutionId = $request->session()->get('active_institution_id');
        abort_if(
            $institutionId && (int) $event->institution_id !== (int) $institutionId,
            404
        );
    }
}
