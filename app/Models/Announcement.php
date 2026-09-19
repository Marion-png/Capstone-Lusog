<?php

namespace App\Models;

use App\Support\SchemaCache;
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

    /**
     * How many announcements the dashboard board shows at once.
     *
     * The single declaration of the board's capacity — the partial, the
     * "showing N of M" line and the tests all read it, so the number cannot
     * be raised in one place and left behind in another. Anything older than
     * this stays on the record and is reached through the archive.
     */
    public const BOARD_LIMIT = 4;

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

    /**
     * Roles an announcement can be addressed to.
     *
     * The Nutrition Coordinator was taken off the list: that role still reads
     * the board (an announcement to everyone reaches it), but it is no longer
     * offered as an audience of its own.
     */
    public const AUDIENCES = [
        'class_adviser' => 'Class Advisers',
        'clinic_staff' => 'Clinic Staff',
        'school_head' => 'School Head',
        'feeding_coor' => 'Feeding Coordinator',
        'school_nurse' => 'School Nurse',
    ];

    /**
     * Labels for audiences no longer offered, so an announcement posted to
     * one before it was retired still reads as the role rather than its key.
     */
    private const RETIRED_AUDIENCES = [
        'nutricor' => 'Nutrition Coordinator',
    ];

    protected $fillable = [
        'institution_id',
        'title',
        'body',
        'priority',
        'audience',
        'posted_by_name',
        'posted_by_role',
        'archived_at',
        'archived_by_name',
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
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Announcements still on the board.
     *
     * Guarded on the column rather than assuming it: the boards render on
     * every role's dashboard, and a machine that has pulled this code but not
     * yet run the migration must show its announcements rather than 500.
     */
    public function scopeActive(Builder $query): Builder
    {
        return self::supportsArchiving() ? $query->whereNull('archived_at') : $query;
    }

    /** Announcements the nurse has archived — off the board, still on record. */
    public function scopeArchived(Builder $query): Builder
    {
        return self::supportsArchiving() ? $query->whereNotNull('archived_at') : $query->whereRaw('1 = 0');
    }

    public static function supportsArchiving(): bool
    {
        return SchemaCache::hasColumn('announcements', 'archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
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
            fn (string $role) => self::AUDIENCES[$role] ?? self::RETIRED_AUDIENCES[$role] ?? $role,
            $audience
        ));
    }
}
