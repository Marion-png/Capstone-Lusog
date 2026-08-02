<?php

namespace App\Http\Controllers;

use App\Models\ClinicNote;
use App\Models\Consultation;
use App\Models\StudentHealthRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Clinic Notes and the per-learner Consultation log behind the School Nurse's
 * student profile. Both are scoped to the viewer's school and to a learner
 * that school actually holds a record for.
 */
class ClinicNoteController extends Controller
{
    /** Clinic notes on file for one learner, newest first. */
    public function index(Request $request): JsonResponse
    {
        if (! ClinicNote::canManage((string) $request->session()->get('active_role', ''))) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $record = $this->resolveLearner($request);
        if ($record === null) {
            return response()->json(['notes' => []]);
        }

        $notes = Schema::hasTable('clinic_notes')
            ? ClinicNote::forActiveInstitution()
                ->where('student_lrn', (string) $record->student_id)
                ->latest('created_at')
                ->latest('id')
                ->limit(50)
                ->get()
            : collect();

        return response()->json([
            'notes' => $notes->map(fn (ClinicNote $note) => [
                'id' => $note->id,
                'note' => (string) $note->note,
                'author' => (string) $note->author_name,
                'recorded_at' => $note->created_at?->format('M j, Y g:i A'),
                'follow_up_date' => $note->follow_up_date?->format('M j, Y'),
                'school_year' => $note->school_year,
            ])->values(),
        ]);
    }

    /** Record a new clinic note against a learner. */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        if (! ClinicNote::canManage((string) $request->session()->get('active_role', ''))) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $validated = $request->validate([
            'lrn' => ['required', 'string', 'max:50'],
            'note' => ['required', 'string', 'max:5000'],
            'follow_up_date' => ['nullable', 'date'],
            'author_name' => ['nullable', 'string', 'max:255'],
        ]);

        $record = $this->resolveLearner($request, $validated['lrn']);
        if ($record === null) {
            return response()->json(['message' => 'No health record on file for this learner.'], 404);
        }

        $note = ClinicNote::create([
            'institution_id' => $request->session()->get('active_institution_id'),
            'student_lrn' => (string) $record->student_id,
            'school_year' => ClinicNote::currentSchoolYear(),
            'note' => trim($validated['note']),
            // A nullable rule omits the key entirely when it is absent, so the
            // session name is the fallback for both "missing" and "blank".
            'author_name' => trim((string) (($validated['author_name'] ?? '') ?: $request->session()->get('active_name', 'School Nurse'))),
            'follow_up_date' => $validated['follow_up_date'] ?? null,
        ]);

        return response()->json([
            'note' => [
                'id' => $note->id,
                'note' => (string) $note->note,
                'author' => (string) $note->author_name,
                'recorded_at' => $note->created_at?->format('M j, Y g:i A'),
                'follow_up_date' => $note->follow_up_date?->format('M j, Y'),
                'school_year' => $note->school_year,
            ],
        ], 201);
    }

    /**
     * Consultations logged for one learner.
     *
     * Consultations carry the learner's name rather than an LRN, and that name
     * is encrypted, so the match runs in PHP against the decrypted values
     * after scoping the query to this school.
     */
    public function consultations(Request $request): JsonResponse
    {
        if (! ClinicNote::canManage((string) $request->session()->get('active_role', ''))) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $record = $this->resolveLearner($request);
        if ($record === null || ! Schema::hasTable('consultations')) {
            return response()->json(['consultations' => []]);
        }

        $needle = $this->normalizeName((string) $record->student_name);
        if ($needle === '') {
            return response()->json(['consultations' => []]);
        }

        $consultations = Consultation::query()
            ->when(
                $request->session()->get('active_institution_id'),
                fn ($q, $id) => $q->where('institution_id', $id)
            )
            ->latest('consulted_at')
            ->latest('id')
            ->get()
            ->filter(fn (Consultation $c) => $this->normalizeName((string) $c->student_name) === $needle)
            ->take(20);

        return response()->json([
            'consultations' => $consultations->map(fn (Consultation $c) => [
                'id' => $c->id,
                'date' => $c->consulted_at?->format('M j, Y'),
                'grade_section' => (string) $c->grade_section,
                'condition' => (string) $c->condition,
                'treatment' => (string) $c->treatment_given,
                'status' => (string) $c->status,
            ])->values(),
        ]);
    }

    /**
     * The learner this request is about, but only if the viewer's school holds
     * a current-year record for them.
     */
    private function resolveLearner(Request $request, ?string $lrn = null): ?StudentHealthRecord
    {
        $lrn = trim($lrn ?? (string) $request->query('lrn', ''));

        if ($lrn === '' || ! Schema::hasTable('student_health_records')) {
            return null;
        }

        return StudentHealthRecord::currentForStudent(
            $lrn,
            $request->session()->get('active_institution_id')
        );
    }

    /** "Dela Cruz, Juan A." and "dela cruz,  juan a" compare equal. */
    private function normalizeName(string $name): string
    {
        $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower(trim($name))) ?? '';

        return trim(preg_replace('/\s+/', ' ', $name) ?? '');
    }
}
