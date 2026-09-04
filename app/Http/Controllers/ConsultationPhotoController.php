<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\ConsultationPhoto;
use App\Models\StudentHealthRecord;
use App\Support\ConsultationVisibility;
use App\Support\EncryptedFileStorage;
use App\Support\SchemaCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Photographs attached to a consultation — a cut, a rash, a swelling.
 *
 * The clinic takes them so an injury can be seen rather than described. The
 * class adviser sees the ones the nurse chose to share, and only those:
 * consultation detail otherwise stops at the clinic (ConsultationVisibility),
 * and a photograph of a child's injury is more revealing than the text it
 * sits beside, not less. So sharing is a decision the nurse makes per photo,
 * and the default is not shared.
 *
 * Files go through EncryptedFileStorage, so nothing readable lands on disk.
 * The served Content-Type is derived from the stored name, whose extension
 * is validated on the way in.
 */
class ConsultationPhotoController extends Controller
{
    /** The desks that run the clinic, and therefore take the photographs. */
    private const CLINIC_ROLES = ['school_nurse', 'clinic_staff'];

    public function index(Request $request, Consultation $consultation): JsonResponse
    {
        if (! $this->sameSchool($request, $consultation)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $role = (string) $request->session()->get('active_role');

        if (! $this->mayRead($request, $consultation, $role)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json(['photos' => $this->listFor($consultation, $role)]);
    }

    public function store(Request $request, Consultation $consultation): JsonResponse
    {
        if (! $this->isClinic($request) || ! $this->sameSchool($request, $consultation)) {
            return response()->json(['message' => 'Only the clinic may attach a photo.'], 403);
        }

        if (! SchemaCache::hasTable('consultation_photos')) {
            return response()->json(['message' => 'Consultation photos are not available.'], 503);
        }

        $validated = $request->validate([
            'photo' => [
                'required',
                'file',
                'image',
                'mimes:'.ConsultationPhoto::ALLOWED_EXTENSIONS,
                'max:'.ConsultationPhoto::MAX_KILOBYTES,
            ],
            'caption' => ['nullable', 'string', 'max:500'],
            'shared_with_adviser' => ['nullable', 'boolean'],
        ]);

        $file = $validated['photo'];

        // Through the model, never a raw insert: the casts are what keep the
        // caption, the file name and the staff name encrypted.
        $photo = ConsultationPhoto::create([
            'consultation_id' => $consultation->id,
            'institution_id' => $consultation->institution_id,
            'file_path' => EncryptedFileStorage::store($file, 'consultation-photos'),
            'file_original_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'caption' => $validated['caption'] ?? null,
            // Not shared unless the nurse says so. The adviser sees no other
            // consultation detail, so a photo must not become the back door.
            'shared_with_adviser' => (bool) ($validated['shared_with_adviser'] ?? false),
            'uploaded_by_name' => (string) $request->session()->get('active_name', ''),
            'uploaded_by_role' => (string) $request->session()->get('active_role', ''),
        ]);

        return response()->json([
            'photo' => $this->present($photo, (string) $request->session()->get('active_role')),
            'photos' => $this->listFor($consultation, (string) $request->session()->get('active_role')),
        ], 201);
    }

    /**
     * Change whether the learner's class adviser may see this photo.
     *
     * Its own endpoint because it is its own decision: a nurse who realises a
     * teacher needs to know about an injury should not have to re-upload, and
     * one who shared by mistake must be able to take it back.
     */
    public function share(Request $request, ConsultationPhoto $photo): JsonResponse
    {
        if (! $this->isClinic($request) || ! $this->photoInSchool($request, $photo)) {
            return response()->json(['message' => 'Only the clinic may share a photo.'], 403);
        }

        $validated = $request->validate([
            'shared_with_adviser' => ['required', 'boolean'],
        ]);

        $photo->update(['shared_with_adviser' => (bool) $validated['shared_with_adviser']]);

        return response()->json([
            'photos' => $this->listFor($photo->consultation, (string) $request->session()->get('active_role')),
        ]);
    }

    /** The image itself. */
    public function view(Request $request, ConsultationPhoto $photo): Response
    {
        if (! $this->photoInSchool($request, $photo)) {
            abort(403);
        }

        $role = (string) $request->session()->get('active_role');

        // The adviser may open a shared photo and nothing else. Checked here,
        // not only in the list: a photo id off the wire decides nothing.
        if (! $this->isClinic($request)) {
            if (! $this->adviserHoldsLearner($request, $photo->consultation) || ! $photo->shared_with_adviser) {
                abort(403);
            }
        }

        return EncryptedFileStorage::response(
            $photo->file_path,
            (string) $photo->file_original_name,
            'inline',
        );
    }

    public function destroy(Request $request, ConsultationPhoto $photo): JsonResponse
    {
        if (! $this->isClinic($request) || ! $this->photoInSchool($request, $photo)) {
            return response()->json(['message' => 'Only the clinic may remove a photo.'], 403);
        }

        $consultation = $photo->consultation;

        // The row is deleted through the model, so the Auditable trait keeps a
        // record that the photo existed and who removed it.
        EncryptedFileStorage::delete($photo->file_path);
        $photo->delete();

        return response()->json([
            'photos' => $this->listFor($consultation, (string) $request->session()->get('active_role')),
        ]);
    }

    // ── Access ───────────────────────────────────────────────────────

    private function isClinic(Request $request): bool
    {
        return in_array((string) $request->session()->get('active_role'), self::CLINIC_ROLES, true);
    }

    private function sameSchool(Request $request, Consultation $consultation): bool
    {
        $institutionId = $request->session()->get('active_institution_id');

        return $institutionId && (string) $consultation->institution_id === (string) $institutionId;
    }

    private function photoInSchool(Request $request, ConsultationPhoto $photo): bool
    {
        $institutionId = $request->session()->get('active_institution_id');

        return $institutionId && (string) $photo->institution_id === (string) $institutionId;
    }

    private function mayRead(Request $request, Consultation $consultation, string $role): bool
    {
        if ($this->isClinic($request)) {
            return true;
        }

        return $role === 'class_adviser' && $this->adviserHoldsLearner($request, $consultation);
    }

    /**
     * The consultation must belong to a learner in this adviser's own class.
     *
     * The clinic log records a free-text name rather than an LRN, so this is
     * the same best-effort match the student profile uses — the adviser only
     * ever reaches it from that learner's own page.
     */
    private function adviserHoldsLearner(Request $request, ?Consultation $consultation): bool
    {
        if ($consultation === null || ! $this->sameSchool($request, $consultation)) {
            return false;
        }

        $grade = trim((string) $request->session()->get('assigned_grade_level', ''));
        $section = trim((string) $request->session()->get('assigned_section', ''));

        if ($grade === '' || $section === '') {
            return false;
        }

        $name = $this->nameKey((string) $consultation->student_name);

        if ($name === '') {
            return false;
        }

        return StudentHealthRecord::query()
            ->where('institution_id', $request->session()->get('active_institution_id'))
            ->where('school_year', StudentHealthRecord::currentSchoolYear())
            ->get()
            ->contains(function (StudentHealthRecord $record) use ($name, $grade, $section): bool {
                // Section is plain, the learner's name is not — so the section
                // narrows in SQL terms and the name is compared here.
                return strcasecmp(trim((string) $record->section), trim($grade.' / '.$section)) === 0
                    && $this->nameKey((string) $record->student_name) === $name;
            });
    }

    private function nameKey(string $name): string
    {
        return preg_replace('/[^a-z]/', '', strtolower($name)) ?? '';
    }

    // ── Presentation ─────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function listFor(?Consultation $consultation, string $role): array
    {
        if ($consultation === null || ! SchemaCache::hasTable('consultation_photos')) {
            return [];
        }

        return ConsultationPhoto::query()
            ->where('consultation_id', $consultation->id)
            ->when(
                ! ConsultationVisibility::maySeeDetails($role),
                fn ($q) => $q->sharedWithAdviser(),
            )
            ->orderByDesc('id')
            ->get()
            ->map(fn (ConsultationPhoto $photo) => $this->present($photo, $role))
            ->all();
    }

    /** @return array<string, mixed> */
    private function present(ConsultationPhoto $photo, string $role): array
    {
        $row = [
            'id' => $photo->id,
            'url' => route('consultation-photos.view', $photo),
            'caption' => (string) $photo->caption,
            'taken_label' => $photo->created_at?->format('M j, Y \a\t g:i A'),
        ];

        if (! ConsultationVisibility::maySeeDetails($role)) {
            return $row;
        }

        return $row + [
            'file_name' => (string) $photo->file_original_name,
            'file_size' => $photo->file_size,
            'shared_with_adviser' => (bool) $photo->shared_with_adviser,
            'uploaded_by' => (string) $photo->uploaded_by_name,
        ];
    }
}
