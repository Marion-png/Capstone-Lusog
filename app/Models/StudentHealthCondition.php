<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentHealthCondition extends Model
{
    protected $fillable = [
        'student_health_record_id',
        'condition_name',
    ];

    public function studentHealthRecord(): BelongsTo
    {
        return $this->belongsTo(StudentHealthRecord::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(MedicalCertificate::class);
    }

    public function isVerified(): bool
    {
        return $this->certificates()->exists();
    }
}
