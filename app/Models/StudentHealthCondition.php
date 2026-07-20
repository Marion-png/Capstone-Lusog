<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentHealthCondition extends Model
{
    use Auditable;

    protected $fillable = [
        'student_lrn',
        'institution_id',
        'condition_name',
    ];

    /**
     * The diagnosis itself is sensitive health information — encrypted at
     * rest. Deduplication by name must compare in PHP, not in SQL.
     */
    protected $casts = [
        'condition_name' => EncryptedString::class,
    ];

    /**
     * Conditions are keyed directly by student (LRN + institution), not by
     * one school year's student_health_records row, so a condition carries
     * forward automatically across grade promotion instead of resetting.
     */
    public function scopeForStudent(Builder $query, string $lrn, ?int $institutionId = null): Builder
    {
        return $query->where('student_lrn', $lrn)
            ->when($institutionId, fn (Builder $q) => $q->where('institution_id', $institutionId));
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
