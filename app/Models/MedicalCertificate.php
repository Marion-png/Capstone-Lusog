<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalCertificate extends Model
{
    use Auditable;

    protected $fillable = [
        'student_health_condition_id',
        'file_path',
        'file_original_name',
        'doctor_clinic',
        'diagnosis_date',
        'uploaded_by_name',
    ];

    protected $casts = [
        'diagnosis_date' => 'date',
        'doctor_clinic' => EncryptedString::class,
        'file_original_name' => EncryptedString::class,
    ];

    public function condition(): BelongsTo
    {
        return $this->belongsTo(StudentHealthCondition::class, 'student_health_condition_id');
    }
}
