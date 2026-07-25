<?php

namespace App\Models;

use App\Casts\EncryptedArray;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * The small manual-admin part of the SBFP report that cannot be computed from
 * data — narrative text, milk consignee contacts, and signatory blocks. One
 * row per institution + school year. Report data itself is never stored here;
 * it is recomputed from student/attendance records on every view.
 *
 * institution_id / school_year stay plain (scoping); the free-text and contact
 * payloads are encrypted at rest because they carry names and contact details.
 */
class FeedingReportDetail extends Model
{
    use Auditable;

    protected $fillable = [
        'institution_id',
        'school_year',
        'narrative',
        'consignees',
        'signatories',
    ];

    protected $casts = [
        'narrative' => EncryptedArray::class,
        'consignees' => EncryptedArray::class,
        'signatories' => EncryptedArray::class,
    ];
}
