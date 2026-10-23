<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * One section closed for one school year — the spec's `sections.closed_at`.
 *
 * Written once, when the outgoing class adviser submits the end-of-year
 * statuses (App\Support\SchoolYearTransition::closeYear). A closed section is
 * read-only: nobody can be added to it for that school year again.
 *
 * Keyed by the same plain `grade_level` + `section` strings an adviser's
 * account carries, compared without regard to case the way every other
 * comparison of a section is. The adviser's name is personal data and is
 * encrypted.
 */
class SectionClosure extends Model
{
    use Auditable;

    protected $fillable = [
        'institution_id',
        'school_year',
        'grade_level',
        'section',
        'closed_at',
        'closed_by',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
        'closed_by' => EncryptedString::class,
    ];
}
