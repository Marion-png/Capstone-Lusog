<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalCertificate extends Model
{
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
    ];

    public function condition(): BelongsTo
    {
        return $this->belongsTo(StudentHealthCondition::class, 'student_health_condition_id');
    }
}
