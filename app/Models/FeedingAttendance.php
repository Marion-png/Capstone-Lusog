<?php

namespace App\Models;

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
    ];

    protected $casts = [
        'session_date' => 'date',
        'is_present' => 'boolean',
    ];

    public function studentHealthRecord(): BelongsTo
    {
        return $this->belongsTo(StudentHealthRecord::class);
    }
}
