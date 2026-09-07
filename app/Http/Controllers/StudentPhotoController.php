<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use App\Models\StudentPhoto;
use App\Support\EncryptedFileStorage;
use App\Support\SchemaCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A learner's profile photograph.
 *
 * The class adviser takes and replaces it — they enrol the learner and know
 * which face belongs to which name. The school nurse and clinic staff see it,
 * because putting a face to a name is the point of it when a child arrives at
 * the clinic and cannot explain who they are.
 *
 * Deliberately narrower than that: no other role reads one here. A photograph
 * of a child's face is the most identifying field in the record, and the roles
 * that do not handle the learner in person have no use for it.
 *
 * One photo per learner. Uploading again replaces the old file rather than
 * accumulating them, so there is never a stale picture to serve by mistake.
 */
class StudentPhotoController extends Controller
{
    /** The adviser enrols the learner, so the photograph is theirs to set. */
    private const WRITE_ROLES = ['class_adviser'];

    /** The desks that meet the learner in person. */
    private const READ_ROLES = ['class_adviser', 'school_nurse', 'clinic_staff'];

    public function show(Request $request, string $lrn): Response
    {
        if (! $this->mayRead($request, $lrn)) {
            abort(403);
        }

        $photo = $this->photoFor($request, $lrn);

        if ($photo === null) {
            abort(404);
        }

        return EncryptedFileStorage::response(
            $photo->file_path,
            (string) $photo->file_original_name,
            'inline',
        );
    }

    /** Whether this learner has one, for a page deciding what to render. */
    public function status(Request $request, string $lrn): JsonResponse
    {
        if (! $this->mayRead($request, $lrn)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $photo = $this->photoFor($request, $lrn);

        return response()->json([
            'has_photo' => $photo !== null,
            'url' => $photo !== null ? route('student-photo.show', $lrn) : null,
            'uploaded_by' => (string) ($photo?->uploaded_by_name ?? ''),
            'uploaded_at' => $photo?->updated_at?->format('M j, Y'),
            'may_manage' => $this->mayWrite($request, $lrn),
        ]);
    }

    public function store(Request $request, string $lrn): JsonResponse
    {
        if (! $this->mayWrite($request, $lrn)) {
            return response()->json(['message' => 'Only the class adviser may set a learner\'s photo.'], 403);
        }

        if (! SchemaCache::hasTable('student_photos')) {
            return response()->json(['message' => 'Student photos are not available.'], 503);
        }

        $validated = $request->validate([
            'photo' => [
                'required',
                'file',
                'image',
                'mimes:'.StudentPhoto::ALLOWED_EXTENSIONS,
                'max:'.StudentPhoto::MAX_KILOBYTES,
            ],
        ]);

        $institutionId = $request->session()->get('active_institution_id');
        $existing = StudentPhoto::query()->forLearner($lrn, $institutionId)->first();

        $file = $validated['photo'];
        $path = EncryptedFileStorage::store($file, 'student-photos');

        // Written through the model, never a raw upsert: the casts are what
        // keep the filename and the staff name encrypted, and the Auditable
        // trait is what records who changed a child's photograph.
        if ($existing !== null) {
            $old = $existing->file_path;

            $existing->update([
                'file_path' => $path,
                'file_original_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'uploaded_by_name' => (string) $request->session()->get('active_name', ''),
                'uploaded_by_role' => (string) $request->session()->get('active_role', ''),
            ]);

            // Only after the replacement is safely stored.
            EncryptedFileStorage::delete($old);
        } else {
            StudentPhoto::create([
                'institution_id' => $institutionId,
                'student_lrn' => $lrn,
                'file_path' => $path,
                'file_original_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'uploaded_by_name' => (string) $request->session()->get('active_name', ''),
                'uploaded_by_role' => (string) $request->session()->get('active_role', ''),
            ]);
        }

        return response()->json([
            'has_photo' => true,
            // Cache-busted: the URL does not change when the file behind it does.
            'url' => route('student-photo.show', $lrn).'?v='.now()->timestamp,
        ], 201);
    }

    public function destroy(Request $request, string $lrn): JsonResponse
    {
        if (! $this->mayWrite($request, $lrn)) {
            return response()->json(['message' => 'Only the class adviser may remove a learner\'s photo.'], 403);
        }

        $photo = $this->photoFor($request, $lrn);

        if ($photo === null) {
            return response()->json(['has_photo' => false]);
        }

        EncryptedFileStorage::delete($photo->file_path);
        $photo->delete();

        return response()->json(['has_photo' => false, 'url' => null]);
    }

    // ── Access ───────────────────────────────────────────────────────

    private function photoFor(Request $request, string $lrn): ?StudentPhoto
    {
        if (! SchemaCache::hasTable('student_photos')) {
            return null;
        }

        return StudentPhoto::query()
            ->forLearner($lrn, $request->session()->get('active_institution_id'))
            ->first();
    }

    /** The learner must be on this school's roll. */
    private function mayRead(Request $request, string $lrn): bool
    {
        if (! in_array((string) $request->session()->get('active_role'), self::READ_ROLES, true)) {
            return false;
        }

        $institutionId = $request->session()->get('active_institution_id');

        return $institutionId && StudentHealthRecord::currentForStudent($lrn, $institutionId) !== null;
    }

    /**
     * Writing needs the adviser's own class, not just their school — the same
     * double scope every other adviser surface uses.
     */
    private function mayWrite(Request $request, string $lrn): bool
    {
        if (! in_array((string) $request->session()->get('active_role'), self::WRITE_ROLES, true)) {
            return false;
        }

        $institutionId = $request->session()->get('active_institution_id');

        if (! $institutionId) {
            return false;
        }

        $record = StudentHealthRecord::currentForStudent($lrn, $institutionId);

        if ($record === null) {
            return false;
        }

        $grade = trim((string) $request->session()->get('assigned_grade_level', ''));
        $section = trim((string) $request->session()->get('assigned_section', ''));

        if ($grade === '' || $section === '') {
            return true;
        }

        return strcasecmp(trim((string) $record->section), trim($grade.' / '.$section)) === 0;
    }
}
