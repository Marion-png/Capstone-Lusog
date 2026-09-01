<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use App\Support\AuditTrail;
use App\Support\FeedingBeneficiarySummary;
use App\Support\SchemaCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who the feeding programme feeds.
 *
 * Qualifying and being enrolled are two different facts. The class adviser
 * measures a learner and their status decides whether they *qualify*; the
 * Feeding Coordinator then decides whether to *enrol* them. This controller
 * serves the waiting list and records that decision.
 *
 * A learner never enrols themselves out of the list by accident: enrolling is
 * additive and idempotent — re-enrolling an already-enrolled learner is a no-op
 * rather than a second stamp, so a double-clicked button cannot rewrite when
 * someone joined the programme.
 *
 * Leaving is the same decision in reverse, and it lives here for that reason.
 * A beneficiary who has stopped coming — transferred, moved away, withdrawn —
 * is taken off the active list once the class adviser has confirmed they are
 * not returning, and the vacated slot goes to somebody on the waiting list.
 * Two things that must stay true of it:
 *
 * - **Attendance never removes anybody.** Being below the school's threshold is
 *   a reason to follow a learner up, never a reason to stop feeding them, so no
 *   rule in this application calls `remove()`. A human does, with a reason, and
 *   the entry is audited.
 * - **Removal is a stamp beside the enrolment, never a deletion of it.** The
 *   learner was fed; the record has to keep saying so. `feeding_removed_at`
 *   sits next to `feeding_enrolled_at`, and "on the feeding line today" is
 *   FeedingBeneficiarySummary::isEnrolled() reading the pair.
 */
class FeedingEnrollmentController extends Controller
{
    /**
     * Learners who qualify but have not been enrolled yet — the modal's list —
     * together with the counts its footer reads.
     */
    public function candidates(Request $request): JsonResponse
    {
        if (! $this->isCoordinator($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $institutionId = $request->session()->get('active_institution_id');
        $records = $this->schoolRecords($institutionId);

        // nutritional_status is encrypted at rest and the grade lives inside
        // the section string, so eligibility is decided in PHP after fetch,
        // never in a WHERE clause. FeedingBeneficiarySummary::isEligible is the
        // one test — status AND a grade the programme covers — so this dialog
        // can never offer a learner the reports would go on to drop.
        $qualified = $records->filter(fn (StudentHealthRecord $r): bool => FeedingBeneficiarySummary::isEligible($r));
        // The waiting list — the coordinator's "buffer". A learner taken off
        // the active list is back on it, which is exactly where the substitute
        // for a vacated slot comes from.
        $waiting = $qualified->filter(fn (StudentHealthRecord $r): bool => ! FeedingBeneficiarySummary::isEnrolled($r));

        // student_name is encrypted, so sorting happens in PHP too.
        $rows = $waiting
            ->map(function (StudentHealthRecord $record): array {
                [$grade, $section] = $this->splitSection((string) $record->section);
                $status = $this->normalizeStatus((string) $record->nutritional_status);

                return [
                    'id' => $record->id,
                    'name' => (string) $record->student_name,
                    'grade' => $grade,
                    'section' => $section,
                    // Read through the one normalizer, so the dialog's Sex
                    // filter agrees with every other coordinator screen.
                    'sex' => FeedingBeneficiarySummary::sexOf($record),
                    'status' => $status,
                    'status_short' => $status === 'Severely Wasted' ? 'SW' : 'W',
                    'badge' => $status === 'Severely Wasted' ? 'badge-critical' : 'badge-risk',
                    // A learner who has been on the programme before is on this
                    // list for a different reason from one who never has, and a
                    // coordinator filling a slot needs to see which.
                    'removed_on' => FeedingBeneficiarySummary::removedAt($record)?->format('j M Y'),
                ];
            })
            ->sortBy(fn (array $row) => strtolower($row['name']))
            ->values()
            ->all();

        // The weigh-in the list is drawn from: the most recent baseline reading
        // on file, so the modal can say which measurement qualified these
        // learners rather than implying it is live.
        $weighIn = $qualified
            ->pluck('baseline_recorded_at')
            ->filter()
            ->sort()
            ->last();

        return response()->json([
            'rows' => $rows,
            'enrolled' => $qualified->count() - $waiting->count(),
            'waiting' => $waiting->count(),
            'weigh_in' => $weighIn?->format('j F Y'),
            'grades' => collect($rows)->pluck('grade')->filter()->unique()->sort(SORT_NATURAL)->values()->all(),
            'sections' => collect($rows)->pluck('section')->filter()->unique()->sort(SORT_NATURAL)->values()->all(),
        ]);
    }

    /**
     * Enrols one or more learners. Everything lands in a single transaction, so
     * a bulk enrolment either takes effect completely or not at all.
     */
    public function store(Request $request): JsonResponse
    {
        if (! $this->isCoordinator($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'record_ids' => ['required', 'array', 'min:1'],
            'record_ids.*' => ['integer'],
        ]);

        $institutionId = $request->session()->get('active_institution_id');

        if (! $institutionId) {
            return response()->json([
                'message' => 'Your account is not attached to a school, so no learner can be enrolled.',
                'enrolled_now' => 0,
            ], 422);
        }

        // Ids arrive off the wire, so the roster is re-read and re-scoped here:
        // only this school's eligible, not-yet-enrolled learners may be taken.
        $eligible = $this->schoolRecords($institutionId)
            ->whereIn('id', $validated['record_ids'])
            ->filter(fn (StudentHealthRecord $r): bool => FeedingBeneficiarySummary::isEligible($r))
            ->filter(fn (StudentHealthRecord $r): bool => ! FeedingBeneficiarySummary::isEnrolled($r))
            ->values();

        if ($eligible->isEmpty()) {
            return response()->json([
                'message' => 'No learner on that list is still waiting to be enrolled.',
                'enrolled_now' => 0,
            ], 422);
        }

        $coordinator = (string) $request->session()->get('active_name', 'Feeding Coordinator');
        $now = now();

        $hasRemovalColumns = SchemaCache::hasColumn('student_health_records', 'feeding_removed_at');

        DB::transaction(function () use ($eligible, $coordinator, $now, $hasRemovalColumns): void {
            foreach ($eligible as $record) {
                $attributes = [
                    'feeding_enrolled_at' => $now,
                    'feeding_enrolled_by' => $coordinator,
                ];

                if ($hasRemovalColumns) {
                    // Re-enrolling is how a slot is refilled and how a removal
                    // recorded in error is undone, so it clears the removal
                    // rather than leaving a learner both enrolled and removed.
                    $attributes['feeding_removed_at'] = null;
                    $attributes['feeding_removed_by'] = null;
                    $attributes['feeding_removal_reason'] = null;
                }

                // Through the model, so the Auditable trait records each
                // enrolment against the learner it changed.
                $record->update($attributes);
            }
        });

        AuditTrail::record(
            'updated',
            'StudentHealthRecord',
            null,
            $eligible->count().' learner(s) enrolled into the feeding programme by '.$coordinator
        );

        return response()->json(['enrolled_now' => $eligible->count()]);
    }

    /**
     * Takes one learner off the active list, with the reason a human gave.
     *
     * The second half of the school's own rule: a week of unexcused absence
     * flags a beneficiary, and if they still do not return and the class
     * adviser confirms they are not going to — transferred, moved, withdrawn —
     * the place is released so a learner on the waiting list can take it.
     *
     * Nothing about that is automatic. The at-risk rule can say a removal is
     * worth *reviewing* (FeedingAtRiskRule::needsRemovalReview) and nothing
     * more: this endpoint is the only way a learner leaves the programme, it
     * requires a reason, and it records who decided.
     */
    public function remove(Request $request, int $record): RedirectResponse
    {
        if (! $this->isCoordinator($request)) {
            return redirect()->route('login')->with('error', 'Only the Feeding Coordinator can remove a beneficiary.');
        }

        $validated = $request->validate([
            // Required, and not from a fixed list: "transferred to Davao City
            // NHS" is the answer that will be needed a year from now, and no
            // dropdown this application writes would have contained it.
            'reason' => ['required', 'string', 'max:255'],
        ]);

        if (! SchemaCache::hasColumn('student_health_records', 'feeding_removed_at')) {
            return back()->with('error', 'Beneficiary removal is not ready on this database. Run migrations first.');
        }

        $institutionId = $request->session()->get('active_institution_id');

        $learner = $this->schoolRecords($institutionId)->firstWhere('id', $record);

        if (! $learner) {
            return redirect()
                ->route('dashboard.feedingcor-health-records')
                ->with('error', 'That learner is not on this school\'s roster.');
        }

        if (! FeedingBeneficiarySummary::isEnrolled($learner)) {
            return back()->with('error', 'That learner is not currently on the active list.');
        }

        $coordinator = (string) $request->session()->get('active_name', 'Feeding Coordinator');
        $reason = trim($validated['reason']);

        DB::transaction(function () use ($learner, $coordinator, $reason): void {
            // Through the model: the casts are what keep the staff name and the
            // reason encrypted at rest, and the Auditable trait records the
            // change against the learner it was made to.
            $learner->update([
                'feeding_removed_at' => now(),
                'feeding_removed_by' => $coordinator,
                'feeding_removal_reason' => $reason,
            ]);
        });

        AuditTrail::record(
            'updated',
            'StudentHealthRecord',
            $learner->id,
            'Beneficiary removed from the active feeding list by '.$coordinator.': '.$reason
        );

        return back()->with(
            'success',
            'Removed from the active feeding list. The slot is now open on the waiting list.'
        );
    }

    /**
     * This school's current-year records — the learners its advisers measured.
     *
     * The institution is **required**, not merely applied when present. A
     * coordinator whose session carries no school gets an empty list rather
     * than every school's children: this dialog names learners, and "no scope"
     * must fail closed. It is also what makes the adviser-to-coordinator
     * handover exact — an adviser's entry appears here when, and only when,
     * they work at the same institution, because the adviser stamps
     * `institution_id` from their own session on the way in.
     *
     * @return Collection<int, StudentHealthRecord>
     */
    private function schoolRecords(?int $institutionId): Collection
    {
        if (! $institutionId || ! SchemaCache::hasTable('student_health_records')) {
            return collect();
        }

        return StudentHealthRecord::query()
            ->where('institution_id', $institutionId)
            ->forCurrentSchoolYear()
            ->get();
    }

    private function normalizeStatus(string $status): string
    {
        $normalized = strtolower($status);

        if (str_contains($normalized, 'severe')) {
            return 'Severely Wasted';
        }

        return 'Wasted';
    }

    /** "Grade 7 / Sampaguita" => ["Grade 7", "Sampaguita"]. */
    private function splitSection(string $section): array
    {
        $parts = explode(' / ', $section, 2);

        return [trim($parts[0]), trim($parts[1] ?? '')];
    }

    private function isCoordinator(Request $request): bool
    {
        return $request->session()->get('active_role') === 'feeding_coor';
    }
}
