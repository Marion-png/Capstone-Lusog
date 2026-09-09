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
     * Archive an announcement: off the board, still on the record.
     *
     * The nurse's answer to "this has already happened". Deleting is the
     * other answer and still means gone — see destroy(). Archiving stamps
     * the row rather than removing it, so last term's deworming notice stays
     * retrievable as what the school actually told its staff.
     */
    public function archive(Request $request, Announcement $announcement): RedirectResponse
    {
        $this->authorizeManaging($request, $announcement, 'archive');

        // Already archived is a no-op, not a second stamp — a double-submit
        // or a stale tab must not rewrite who archived it and when.
        if (! $announcement->isArchived()) {
            $announcement->forceFill([
                'archived_at' => now(),
                'archived_by_name' => (string) $request->session()->get('active_name', 'School Nurse'),
            ])->save();
        }

        return back()->with('announcement_success', 'Announcement archived.');
    }

    /**
     * Put an archived announcement back on the board.
     */
    public function restore(Request $request, Announcement $announcement): RedirectResponse
    {
        $this->authorizeManaging($request, $announcement, 'restore');

        $announcement->forceFill([
            'archived_at' => null,
            'archived_by_name' => null,
        ])->save();

        return back()->with('announcement_success', 'Announcement restored to the board.');
    }

    /**
     * Remove an announcement. Restricted to poster roles and the
     * announcement's own school — a nurse can never delete another
     * school's announcement even if they somehow knew its ID.
     */
    public function destroy(Request $request, Announcement $announcement): RedirectResponse
    {
        $this->authorizeManaging($request, $announcement, 'remove');

        $announcement->delete();

        return back()->with('announcement_success', 'Announcement removed.');
    }

    /**
     * The one guard on every write to an existing announcement: a poster role,
     * and the announcement's own school. Kept in one place so archive, restore
     * and delete cannot drift apart — a third copy of a scope check is a third
     * chance to forget the school and hand a nurse another school's notices.
     */
    private function authorizeManaging(Request $request, Announcement $announcement, string $verb): void
    {
        $role = (string) $request->session()->get('active_role', '');

        abort_unless(Announcement::canPost($role), 403, "Only the School Nurse may {$verb} announcements.");

        $institutionId = $request->session()->get('active_institution_id');
        abort_if(
            $institutionId && (int) $announcement->institution_id !== (int) $institutionId,
            404
        );
    }
}
