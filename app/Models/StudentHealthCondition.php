<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentHealthCondition extends Model
{
    use Auditable;

    protected $fillable = [
        'student_health_record_id',
        'condition_name',
    ];

    /**
     * The diagnosis itself is sensitive health information — encrypted at
     * rest. Deduplication by name must compare in PHP, not in SQL.
     */
    protected $casts = [
        'condition_name' => EncryptedString::class,
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
