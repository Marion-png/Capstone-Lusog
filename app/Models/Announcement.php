<?php

namespace App\Models;

use App\Models\Concerns\TenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    use TenantConnection;

    /**
     * Roles allowed to post announcements. Currently only the School Nurse —
     * kept as a list (not a single hardcoded check) so opening this up to
     * other roles later is a one-line change.
     */
    public const POSTER_ROLES = ['school_nurse'];

    protected $fillable = [
        'institution_id',
        'title',
        'body',
        'posted_by_name',
        'posted_by_role',
    ];

    /**
     * Restrict to the viewer's school. Announcements are never shared across
     * institutions; sessions without a school (e.g. system admin) see none.
     */
    public function scopeForActiveInstitution(Builder $query): Builder
    {
        $institutionId = session('active_institution_id');

        return $institutionId ? $query->where('institution_id', $institutionId) : $query->whereRaw('1 = 0');
    }

    public static function canPost(?string $role): bool
    {
        return in_array($role, self::POSTER_ROLES, true);
    }
}
