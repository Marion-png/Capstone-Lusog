<?php

namespace App\Models;

use App\Casts\EncryptedString;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedingAttendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_health_record_id',
        'session_date',
        'is_present',
        'attendance_import_id',
        'source',
        'needs_review',
        'reviewed_by_name',
        'reviewed_at',
    ];

    /**
     * `is_present` is deliberately nullable, not a boolean with a default.
     * NULL means a scanned mark nobody has confirmed yet — neither present nor
     * absent. Anything that counts attendance must skip those rows rather than
     * treat them as absences (see FeedingAtRiskRule).
     */
    protected $casts = [
        'session_date' => 'date',
        'is_present' => 'boolean',
        'needs_review' => 'boolean',
        'reviewed_at' => 'datetime',
        'reviewed_by_name' => EncryptedString::class,
    ];

    public const SOURCE_SPREADSHEET = 'spreadsheet';

    public const SOURCE_PHOTO_SCAN = 'photo_scan';

    public const SOURCE_MANUAL_REVIEW = 'manual_review';

    public function studentHealthRecord(): BelongsTo
    {
        return $this->belongsTo(StudentHealthRecord::class);
    }

    public function attendanceImport(): BelongsTo
    {
        return $this->belongsTo(AttendanceImport::class);
    }

    /** Marks awaiting a human decision, oldest first. */
    public function scopeAwaitingReview($query)
    {
        return $query->where('needs_review', true);
    }
}
