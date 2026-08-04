<?php

namespace App\Http\Controllers;

use App\Models\MedicalCertificate;
use App\Models\StudentHealthRecord;
use App\Support\EncryptedFileStorage;
use App\Support\StudentMedicalDocuments;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Medical documents on a learner's student profile: upload, list, preview,
 * download, delete. The class adviser, school nurse, and clinic staff share
 * one list — a document belongs to the learner, not to the desk that filed it,
 * so each role sees what the others uploaded. Every action is restricted to a
 * learner who has a record in the viewer's own school, the same gate that
 * makes the profile itself reachable.
 *
 * Files are written through EncryptedFileStorage, so nothing readable lands on
 * disk; the served Content-Type is derived from the stored file name, whose
 * extension is validated on the way in.
 */
class StudentMedicalDocumentController extends Controller
{
    /** Mirrors the formats named on the upload panel. */
    private const ALLOWED_EXTENSIONS = 'pdf,jpg,jpeg,png,doc,docx,xls,xlsx';

    /** 10 MB, in kilobytes — the limit named on the upload panel. */
    private const MAX_KILOBYTES = 10240;

    public function index(Request $request, string $lrn): JsonResponse
    {
        $this->authorizeLearner($request, $lrn);

        return response()->json($this->listPayload($request, $lrn));
    }

    /**
     * A cheap change signal for an open Medical Documents panel: row count +
     * the latest timestamp for this learner's documents. It carries no
     * personal information, so the panel can poll it often and only re-fetch
     * (and be audited) when a document is actually added or removed — which is
     * how the adviser sees what the nurse just filed, and the other way round.
     */
    public function pulse(Request $request, string $lrn): JsonResponse
    {
        if (! $this->mayManageDocuments($request)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $institutionId = $this->institutionId($request);

        // A learner this school does not hold gets the same stamp as one with
        // no documents, so polling a foreign LRN reveals nothing.
        if (StudentHealthRecord::currentForStudent($lrn, $institutionId) === null) {
            return response()->json(['stamp' => self::documentStamp(null)]);
        }

        return response()->json(['stamp' => self::documentStamp($lrn, $institutionId)]);
    }

    /**
     * The list plus the stamp that matches it, so a client that just uploaded
     * or deleted knows the current signal without a second request.
     *
     * @return array<string, mixed>
     */
    private function listPayload(Request $request, string $lrn): array
    {
        $institutionId = $this->institutionId($request);

        return [
            'documents' => StudentMedicalDocuments::forLearner($lrn, $institutionId),
            'stamp' => self::documentStamp($lrn, $institutionId),
        ];
    }

    /**
     * student_lrn, institution_id, and updated_at are plaintext columns —
     * nothing encrypted is touched by this aggregate.
     */
    private static function documentStamp(?string $lrn, ?int $institutionId = null): string
    {
        if ($lrn === null || ! Tenancy::schema()->hasTable('medical_certificates')) {
            return md5('-');
        }

        // Certificates live in the institution's own database, so this has to
        // run on the tenant connection — DB::table() would hit the central one.
        $row = Tenancy::table('medical_certificates')
            ->where('student_lrn', $lrn)
            ->when($institutionId, fn ($q, $id) => $q->where('institution_id', $id))
            ->selectRaw('COUNT(*) as row_count, MAX(updated_at) as last_touched')
            ->first();

        return md5(((int) ($row->row_count ?? 0)).'@'.((string) ($row->last_touched ?? '')));
    }

    public function store(Request $request, string $lrn): JsonResponse
    {
        $record = $this->authorizeLearner($request, $lrn);

        $request->validate([
            'document' => [
                'required',
                'file',
                'extensions:'.self::ALLOWED_EXTENSIONS,
                'max:'.self::MAX_KILOBYTES,
            ],
        ], [
            'document.extensions' => 'Only PDF, JPG, PNG, DOC, and XLS files may be uploaded.',
            'document.max' => 'The document may not be larger than 10MB.',
        ]);

        $file = $request->file('document');

        MedicalCertificate::create([
            'student_lrn' => $lrn,
            'institution_id' => $this->institutionId($request),
            // No condition attached: this is a document filed against the
            // learner, not a certificate verifying one diagnosed condition.
            'student_health_condition_id' => null,
            'file_path' => EncryptedFileStorage::store($file, 'medical-documents/'.$record->id),
            'file_original_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'uploaded_by_name' => (string) $request->session()->get('active_name', 'School Staff'),
            'uploaded_by_role' => (string) $request->session()->get('active_role'),
        ]);

        return response()->json($this->listPayload($request, $lrn), 201);
    }

    public function view(Request $request, int $id): Response
    {
        $document = $this->authorizeDocument($request, $id);

        return EncryptedFileStorage::response($document->file_path, $document->file_original_name, 'inline')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function download(Request $request, int $id): Response
    {
        $document = $this->authorizeDocument($request, $id);

        return EncryptedFileStorage::response($document->file_path, $document->file_original_name)
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $document = $this->authorizeDocument($request, $id, requireFile: false);

        $lrn = (string) $document->student_lrn;
        $path = $document->file_path;

        // The row goes first so the deletion is audited even if the file was
        // already gone from disk; the ciphertext is then removed behind it.
        $document->delete();
        Storage::disk('local')->delete($path);

        return response()->json($this->listPayload($request, $lrn));
    }

    /**
     * The learner must have a current record in the viewer's own school.
     */
    private function authorizeLearner(Request $request, string $lrn): StudentHealthRecord
    {
        abort_unless(
            $this->mayManageDocuments($request),
            403,
            'Only a Class Adviser, School Nurse, or Clinic Staff may manage a learner\'s medical documents.'
        );

        $record = StudentHealthRecord::currentForStudent($lrn, $this->institutionId($request));

        abort_if($record === null, 404, 'Student not found in your school.');

        return $record;
    }

    /**
     * A document is reachable only from its own school. institution_id is a
     * plaintext column on the document itself, backfilled for rows uploaded
     * against a condition, so no join is needed to check it.
     */
    private function authorizeDocument(Request $request, int $id, bool $requireFile = true): MedicalCertificate
    {
        abort_unless(
            $this->mayManageDocuments($request),
            403,
            'Only a Class Adviser, School Nurse, or Clinic Staff may open a learner\'s medical documents.'
        );

        $institutionId = $this->institutionId($request);
        $document = MedicalCertificate::find($id);

        abort_if($document === null, 404, 'Document not found.');
        abort_unless(
            $institutionId !== null && (int) $document->institution_id === $institutionId,
            404,
            'Document not found.'
        );

        abort_if(
            $requireFile && ! Storage::disk('local')->exists($document->file_path),
            404,
            'Document file not found on disk.'
        );

        return $document;
    }

    private function mayManageDocuments(Request $request): bool
    {
        return in_array(
            (string) $request->session()->get('active_role', ''),
            StudentMedicalDocuments::UPLOAD_ROLES,
            true
        );
    }

    private function institutionId(Request $request): ?int
    {
        $id = $request->session()->get('active_institution_id');

        return $id !== null ? (int) $id : null;
    }
}
