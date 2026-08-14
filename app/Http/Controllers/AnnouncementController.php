<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    /**
     * Post a new announcement. Restricted to Announcement::POSTER_ROLES
     * (School Nurse, for now) and scoped to the poster's own school.
     */
    public function store(Request $request): RedirectResponse
    {
        $role = (string) $request->session()->get('active_role', '');

        abort_unless(Announcement::canPost($role), 403, 'Only the School Nurse may post announcements.');

        // Named bag: the announcement and event dialogs sit on the same
        // dashboard and both have a `title` field. Without separate bags an
        // error in one would re-open both.
        // The audience dropdown's "Everyone" option posts an empty value.
        // Drop it before validation so it reads as "no audience" rather than
        // as an unknown role.
        $request->merge([
            'audience' => array_values(array_filter(
                (array) $request->input('audience', []),
                fn ($value) => is_string($value) && $value !== ''
            )),
        ]);

        $validated = $request->validateWithBag('announcement', [
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:2000'],
            // Optional: an announcement with no priority stated is a normal
            // one, which is what every announcement was before this existed.
            'priority' => ['nullable', 'in:'.implode(',', array_keys(Announcement::PRIORITIES))],
            'audience' => ['nullable', 'array'],
            'audience.*' => ['string', 'in:'.implode(',', array_keys(Announcement::AUDIENCES))],
        ]);

        // No audience ticked means everyone — stored as an empty list rather
        // than every role, so "all staff" keeps meaning all staff even if a
        // role is added to the system later.
        $audience = array_values(array_unique($validated['audience'] ?? []));

        Announcement::create([
            'institution_id' => $request->session()->get('active_institution_id'),
            'title' => $validated['title'],
            'body' => $validated['body'],
            'priority' => $validated['priority'] ?? Announcement::PRIORITY_NORMAL,
            'audience' => $audience,
            'posted_by_name' => (string) $request->session()->get('active_name', 'School Nurse'),
            'posted_by_role' => $role,
        ]);

        return back()->with('announcement_success', 'Announcement posted.');
    }

    /**
     * Remove an announcement. Restricted to poster roles and the
     * announcement's own school — a nurse can never delete another
     * school's announcement even if they somehow knew its ID.
     */
    public function destroy(Request $request, Announcement $announcement): RedirectResponse
    {
        $role = (string) $request->session()->get('active_role', '');

        abort_unless(Announcement::canPost($role), 403, 'Only the School Nurse may remove announcements.');

        $institutionId = $request->session()->get('active_institution_id');
        abort_if(
            $institutionId && (int) $announcement->institution_id !== (int) $institutionId,
            404
        );

        $announcement->delete();

        return back()->with('announcement_success', 'Announcement removed.');
    }
}
