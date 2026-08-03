<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalCertificate extends Model
{
    use Auditable;

    protected $fillable = [
        'student_lrn',
        'institution_id',
        'student_health_condition_id',
        'file_path',
        'file_original_name',
        'file_size',
        'doctor_clinic',
        'diagnosis_date',
        'uploaded_by_name',
        'uploaded_by_role',
    ];

    protected $casts = [
        'diagnosis_date' => 'date',
        'doctor_clinic' => EncryptedString::class,
        'file_original_name' => EncryptedString::class,
        'file_size' => 'integer',
    ];

    public function condition(): BelongsTo
    {
        return $this->belongsTo(StudentHealthCondition::class, 'student_health_condition_id');
    }

    /**
     * Documents are keyed directly by student (LRN + institution) so they
     * carry forward across grade promotion, and so the student profile can
     * list them without going through the condition table — a document
     * uploaded from the profile has no condition attached at all.
     */
    public function scopeForStudent(Builder $query, string $lrn, ?int $institutionId = null): Builder
    {
        return $query->where('student_lrn', $lrn)
            ->when($institutionId, fn (Builder $q) => $q->where('institution_id', $institutionId));
    }
}
