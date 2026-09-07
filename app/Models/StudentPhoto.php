<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A learner's profile photograph — one per learner, per school.
 *
 * Keyed by LRN + institution rather than by health record, so it survives
 * grade promotion: the learner is the same child in Grade 8 as in Grade 7,
 * and their picture should not have to be re-taken each August.
 */
class StudentPhoto extends Model
{
    use Auditable;

    /** What a browser renders inline, and what a phone camera produces. */
    public const ALLOWED_EXTENSIONS = 'jpg,jpeg,png,webp';

    /** 4 MB in kilobytes — a headshot, not a photo album. */
    public const MAX_KILOBYTES = 4096;

    protected $fillable = [
        'institution_id',
        'student_lrn',
        'file_path',
        'file_original_name',
        'file_size',
        'uploaded_by_name',
        'uploaded_by_role',
    ];

    protected $casts = [
        'file_size' => 'integer',
        // A photo file is very often named after the child.
        'file_original_name' => EncryptedString::class,
        'uploaded_by_name' => EncryptedString::class,
    ];

    /** @param  Builder<self>  $query */
    public function scopeForLearner(Builder $query, string $lrn, mixed $institutionId): Builder
    {
        return $query
            ->where('student_lrn', $lrn)
            ->where('institution_id', $institutionId);
    }
}
