<?php

namespace App\Models;

use App\Support\SchemaCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * One section a school runs, in one grade level.
 *
 * The catalogue is what registration offers a class adviser instead of a free
 * text box, and what the server checks their answer against — an adviser is
 * scoped to `assigned_grade_level` + `assigned_section`, so a typo there is a
 * class they can never see. School structure is not personal information, so
 * every column is plain and safe to filter on in SQL.
 *
 * A school with no rows here has no catalogue, and registration falls back to
 * the generic grade list and a typed section: the app serves schools that have
 * not published their sections yet, and refusing them a registration would be
 * worse than accepting a typed answer.
 */
class InstitutionSection extends Model
{
    protected $fillable = ['institution_id', 'grade_level', 'name'];

    /** Whether this school has published its sections at all. */
    public static function hasCatalog(?int $institutionId): bool
    {
        if (! $institutionId || ! SchemaCache::hasTable('institution_sections')) {
            return false;
        }

        return static::query()->where('institution_id', $institutionId)->exists();
    }

    /**
     * This school's sections, grouped by grade level and ordered the way the
     * school's own sheet lists them (grade ascending, section alphabetical).
     *
     * @return array<string, list<string>>
     */
    public static function catalogFor(?int $institutionId): array
    {
        if (! $institutionId || ! SchemaCache::hasTable('institution_sections')) {
            return [];
        }

        return static::query()
            ->where('institution_id', $institutionId)
            ->orderBy('name')
            ->get(['grade_level', 'name'])
            ->groupBy('grade_level')
            ->sortBy(fn (Collection $rows, string $grade) => self::gradeOrder($grade))
            ->map(fn (Collection $rows) => $rows->pluck('name')->values()->all())
            ->all();
    }

    /**
     * The catalogue's own spelling of a grade + section pair, or null when the
     * school does not run it. Matching is case- and space-insensitive because
     * the answer arrives off the wire; what comes back is what the school
     * published, so every adviser is scoped by the same string.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function canonical(?int $institutionId, string $gradeLevel, string $section): ?array
    {
        foreach (self::catalogFor($institutionId) as $grade => $names) {
            if (strcasecmp(trim($grade), trim($gradeLevel)) !== 0) {
                continue;
            }

            foreach ($names as $name) {
                if (strcasecmp(trim($name), trim($section)) === 0) {
                    return [$grade, $name];
                }
            }
        }

        return null;
    }

    /** Whether this exact grade + section pair is one the school runs. */
    public static function covers(?int $institutionId, string $gradeLevel, string $section): bool
    {
        return self::canonical($institutionId, $gradeLevel, $section) !== null;
    }

    /** "Grade 10" sorts after "Grade 9", which it would not do alphabetically. */
    private static function gradeOrder(string $gradeLevel): int
    {
        return preg_match('/(\d{1,2})/', $gradeLevel, $m) ? (int) $m[1] : 99;
    }
}
