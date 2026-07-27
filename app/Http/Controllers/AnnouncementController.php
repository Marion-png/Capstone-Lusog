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

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        Announcement::create([
            'institution_id' => $request->session()->get('active_institution_id'),
            'title' => $validated['title'],
            'body' => $validated['body'],
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
