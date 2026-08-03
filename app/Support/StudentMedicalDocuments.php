<?php

namespace App\Support;

use App\Models\MedicalCertificate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the Medical Documents list shown on a learner's profile — the one
 * shape used by both the server-rendered page payload and the JSON endpoint
 * the upload flow refreshes from, so a document reads the same either way.
 *
 * Rows are read by (student_lrn, institution_id): both are plaintext lookup
 * columns, while the file name and doctor/clinic are encrypted at rest and
 * are only decrypted here, in PHP.
 */
class StudentMedicalDocuments
{
    /**
     * The desks that file a learner's medical documents. All three see the
     * same list — a document belongs to the learner, not to whoever uploaded
     * it — so each row records the role it came from.
     */
    public const UPLOAD_ROLES = ['class_adviser', 'school_nurse', 'clinic_staff'];

    /**
     * @param  iterable<int, string>  $lrns
     * @return Collection<string, Collection<int, array<string, mixed>>> keyed by LRN
     */
    public static function forLearners(iterable $lrns, ?int $institutionId): Collection
    {
        $lrns = collect($lrns)->filter()->map(fn ($lrn) => (string) $lrn)->unique()->values();

        if ($lrns->isEmpty() || ! Schema::hasTable('medical_certificates')) {
            return collect();
        }

        return MedicalCertificate::query()
            ->whereIn('student_lrn', $lrns)
            ->when($institutionId, fn ($q, $id) => $q->where('institution_id', $id))
            ->with('condition')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('student_lrn')
            ->map(fn (Collection $documents) => $documents->map(
                fn (MedicalCertificate $document) => self::present($document)
            )->values());
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public static function forLearner(string $lrn, ?int $institutionId): Collection
    {
        return self::forLearners([$lrn], $institutionId)->get($lrn, collect());
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(MedicalCertificate $document): array
    {
        return [
            'id' => $document->id,
            'file_name' => $document->file_original_name,
            'file_size' => $document->file_size,
            'uploaded_at' => $document->created_at?->toDateString(),
            'uploaded_by' => $document->uploaded_by_name,
            'uploaded_by_role' => self::roleLabel($document->uploaded_by_role),
            'condition_name' => $document->condition?->condition_name,
            'doctor_clinic' => $document->doctor_clinic,
            'diagnosis_date' => $document->diagnosis_date?->toDateString(),
            'view_url' => route('student-documents.view', $document->id),
            'download_url' => route('student-documents.download', $document->id),
            'delete_url' => route('student-documents.destroy', $document->id),
        ];
    }

    public static function roleLabel(?string $role): ?string
    {
        return match ($role) {
            'class_adviser' => 'Class Adviser',
            'school_nurse' => 'School Nurse',
            'clinic_staff' => 'Clinic Staff',
            default => null,
        };
    }
}
