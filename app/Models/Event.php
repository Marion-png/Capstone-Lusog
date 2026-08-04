<?php

namespace App\Models;

use App\Models\Concerns\TenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use TenantConnection;

    /**
     * Roles allowed to create events. Currently only the School Nurse —
     * kept as a list (not a single hardcoded check) for the same reason
     * as Announcement::POSTER_ROLES.
     */
    public const CREATOR_ROLES = ['school_nurse'];

    public const CATEGORIES = [
        'deadline' => 'Deadline',
        'program' => 'Program',
    ];

    protected $fillable = [
        'institution_id',
        'title',
        'description',
        'event_date',
        'category',
        'created_by_name',
        'created_by_role',
    ];

    protected $casts = [
        'event_date' => 'date',
    ];

    public function scopeForActiveInstitution(Builder $query): Builder
    {
        $institutionId = session('active_institution_id');

        return $institutionId ? $query->where('institution_id', $institutionId) : $query->whereRaw('1 = 0');
    }

    /** Today and later, soonest first. */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereDate('event_date', '>=', now()->toDateString())->orderBy('event_date');
    }

    public static function canCreate(?string $role): bool
    {
        return in_array($role, self::CREATOR_ROLES, true);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst($this->category);
    }
}
