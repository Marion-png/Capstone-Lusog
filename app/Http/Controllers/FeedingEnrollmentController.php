<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use App\Support\AuditTrail;
use App\Support\SchemaCache;
use Illuminate\Http\JsonResponse;
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

        // nutritional_status is encrypted at rest, so qualification is decided
        // in PHP after fetch, never in a WHERE clause.
        $qualified = $records->filter(fn (StudentHealthRecord $r): bool => $this->qualifies((string) $r->nutritional_status));
        $waiting = $qualified->filter(fn (StudentHealthRecord $r): bool => $r->feeding_enrolled_at === null);

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
                    'status' => $status,
                    'status_short' => $status === 'Severely Wasted' ? 'SW' : 'W',
                    'badge' => $status === 'Severely Wasted' ? 'badge-critical' : 'badge-risk',
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

        // Ids arrive off the wire, so the roster is re-read and re-scoped here:
        // only this school's qualified, not-yet-enrolled learners may be taken.
        $eligible = $this->schoolRecords($institutionId)
            ->whereIn('id', $validated['record_ids'])
            ->filter(fn (StudentHealthRecord $r): bool => $this->qualifies((string) $r->nutritional_status))
            ->filter(fn (StudentHealthRecord $r): bool => $r->feeding_enrolled_at === null)
            ->values();

        if ($eligible->isEmpty()) {
            return response()->json([
                'message' => 'No learner on that list is still waiting to be enrolled.',
                'enrolled_now' => 0,
            ], 422);
        }

        $coordinator = (string) $request->session()->get('active_name', 'Feeding Coordinator');
        $now = now();

        DB::transaction(function () use ($eligible, $coordinator, $now): void {
            foreach ($eligible as $record) {
                // Through the model, so the Auditable trait records each
                // enrolment against the learner it changed.
                $record->update([
                    'feeding_enrolled_at' => $now,
                    'feeding_enrolled_by' => $coordinator,
                ]);
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
     * This school's current-year records. Scoped by institution like every
     * other read of learner data.
     *
     * @return Collection<int, StudentHealthRecord>
     */
    private function schoolRecords(?int $institutionId): Collection
    {
        if (! SchemaCache::hasTable('student_health_records')) {
            return collect();
        }

        return StudentHealthRecord::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->forCurrentSchoolYear()
            ->get();
    }

    /** Wasted, severely wasted or the app's "underweight" — the feeding criteria. */
    private function qualifies(string $status): bool
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($status)) ?? '');

        return in_array($normalized, ['wasted', 'severely wasted', 'severly wasted', 'underweight'], true);
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
