<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use App\Support\SchoolCalendar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stretch of days a school holds no class: a public holiday read from the
 * Calendarific API, or a class suspension or school break entered by the
 * System Admin. `App\Support\SchoolCalendar` is the one reading of these rows.
 */
class SchoolCalendarDay extends Model
{
    use Auditable;

    protected $fillable = [
        'institution_id',
        'starts_on',
        'ends_on',
        'name',
        'kind',
        'source',
        'external_id',
        'created_by_name',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            // Who entered a suspension is a staff name, encrypted like every
            // other one; the dates and the reason stay plain.
            'created_by_name' => EncryptedString::class,
        ];
    }

    protected static function booted(): void
    {
        // The closed dates are read once per request; a write in that request
        // has moved them.
        static::saved(static fn () => SchoolCalendar::forget());
        static::deleted(static fn () => SchoolCalendar::forget());
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }
}
