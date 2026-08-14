<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    /**
     * Roles allowed to post announcements. Currently only the School Nurse —
     * kept as a list (not a single hardcoded check) so opening this up to
     * other roles later is a one-line change.
     */
    public const POSTER_ROLES = ['school_nurse'];

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_IMPORTANT = 'important';

    public const PRIORITY_URGENT = 'urgent';

    /**
     * Priority ladder, quietest first. Urgent is the system's critical
     * coral, Important its monitoring amber; a normal notice carries no
     * colour at all, so the two that matter stand out.
     */
    public const PRIORITIES = [
        self::PRIORITY_NORMAL => 'Normal',
        self::PRIORITY_IMPORTANT => 'Important',
        self::PRIORITY_URGENT => 'Urgent',
    ];

    /** Roles an announcement can be addressed to. */
    public const AUDIENCES = [
        'class_adviser' => 'Class Advisers',
        'clinic_staff' => 'Clinic Staff',
        'school_head' => 'School Head',
        'feeding_coor' => 'Feeding Coordinator',
        'nutricor' => 'Nutrition Coordinator',
        'school_nurse' => 'School Nurse',
    ];

    protected $fillable = [
        'institution_id',
        'title',
        'body',
        'priority',
        'audience',
        'posted_by_name',
        'posted_by_role',
    ];

    /**
     * `audience` is a plain JSON list of role keys, deliberately not
     * encrypted: it carries no personal information and has to be filtered
     * in SQL on every dashboard read.
     */
    protected function casts(): array
    {
        return [
            'audience' => 'array',
        ];
    }

    /**
     * Restrict to the viewer's school. Announcements are never shared across
     * institutions; sessions without a school (e.g. system admin) see none.
     */
    public function scopeForActiveInstitution(Builder $query): Builder
    {
        $institutionId = session('active_institution_id');

        return $institutionId ? $query->where('institution_id', $institutionId) : $query->whereRaw('1 = 0');
    }

    /**
     * Restrict to what this role is meant to see.
     *
     * An empty or missing audience means everyone — that is what every
     * announcement written before audiences existed is, and what the poster
     * gets by leaving the picker untouched. The author's own role always
     * matches, so a nurse can still see the notice they just wrote even if
     * they addressed it to advisers alone.
     */
    public function scopeVisibleToRole(Builder $query, ?string $role): Builder
    {
        if ($role === null || $role === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($role) {
            // The empty-audience case is matched by length, not by comparing
            // the column to '[]'. PostgreSQL defines no equality operator for
            // the `json` type, so `audience = '[]'` is a hard error there —
            // and SQLite, which the test suite runs on, accepts it happily.
            $q->whereNull('audience')
                ->orWhereJsonLength('audience', 0)
                ->orWhereJsonContains('audience', $role)
                ->orWhere('posted_by_role', $role);
        });
    }

    public static function canPost(?string $role): bool
    {
        return in_array($role, self::POSTER_ROLES, true);
    }

    public function priorityLabel(): string
    {
        return self::PRIORITIES[$this->priority] ?? self::PRIORITIES[self::PRIORITY_NORMAL];
    }

    public function isFlagged(): bool
    {
        return in_array($this->priority, [self::PRIORITY_IMPORTANT, self::PRIORITY_URGENT], true);
    }

    /**
     * Human summary of who this went to, for the poster's own reference.
     */
    public function audienceLabel(): string
    {
        $audience = array_filter((array) ($this->audience ?? []));

        if ($audience === []) {
            return 'Everyone';
        }

        return implode(', ', array_map(
            fn (string $role) => self::AUDIENCES[$role] ?? $role,
            $audience
        ));
    }
}
