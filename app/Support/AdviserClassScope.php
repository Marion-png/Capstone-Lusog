<?php

namespace App\Support;

use App\Models\StudentHealthRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The second half of a class adviser's scope: the one class inside their school.
 *
 * An adviser is scoped twice — to their school, and within it to one
 * `"Grade 7 / MATIYAGA"` pair. The school half is enforced by the queries
 * themselves (`institution_id`) and by InstitutionScope. The class half is not,
 * and cannot be, because the session roster
 * (`school_health_card_records`) is deliberately the **whole school**:
 * StudentRosterSync rebuilds it from every learner the institution holds so the
 * roster survives a session expiry. Every adviser surface is therefore
 * responsible for narrowing that roster itself, and a surface that forgets
 * shows one teacher another teacher's learners while every query still looks
 * correct.
 *
 * That responsibility was spread across four near-identical copies of the same
 * closure, and the surfaces that mattered most had no copy at all. Two rules
 * live here now, in one place:
 *
 * **The narrowing is decided by role, not by whether an assignment happens to
 * be filled in.** The consent-form and health-assessment pickers are shared
 * with the school nurse and clinic staff, who have no class and are entitled to
 * the whole school. The old test — "if grade or section is empty, return
 * everything" — served those roles by accident, and served an adviser whose
 * assignment was missing the same way. Asking the role separates the two.
 *
 * **For an adviser, a missing assignment yields no learners, never all of
 * them.** An adviser account cannot be approved without a grade and a section
 * (`required_if:role,class_adviser` on the account routes), so a session
 * carrying neither is a broken account rather than an adviser of every class.
 * Scope fails closed here for the reason it does in SchoolHeadOverview and
 * FeedingEnrollmentController: the failure mode of a missing scope has to be
 * seeing nothing, because the alternative is a silent disclosure that nothing
 * reports.
 *
 * `AdviserClassScopeTest` guards all of it.
 */
final class AdviserClassScope
{
    /**
     * The class this session is limited to, as [grade, section].
     *
     * Null means "not limited to a class" — a nurse or clinic session, which
     * legitimately reads the whole school.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function assignedClass(Request $request): ?array
    {
        if ((string) $request->session()->get('active_role', '') !== 'class_adviser') {
            return null;
        }

        return [
            trim((string) $request->session()->get('assigned_grade_level', '')),
            trim((string) $request->session()->get('assigned_section', '')),
        ];
    }

    /** Whether this session reads one class rather than the whole school. */
    public static function limitsToOneClass(Request $request): bool
    {
        return self::assignedClass($request) !== null;
    }

    /**
     * Whether a roster row belongs to the class this session may read.
     *
     * A section is a choice from the school's published catalogue, but it is
     * stored and compared as text, so the match ignores case and stray spacing
     * the way every other comparison of it does.
     *
     * @param  array<string, mixed>  $row
     */
    public static function coversRow(Request $request, array $row): bool
    {
        $assigned = self::assignedClass($request);

        if ($assigned === null) {
            return true;
        }

        [$grade, $section] = $assigned;

        if ($grade === '' || $section === '') {
            return false;
        }

        return strcasecmp(trim((string) ($row['grade_level'] ?? '')), $grade) === 0
            && strcasecmp(trim((string) ($row['section'] ?? '')), $section) === 0;
    }

    /**
     * Whether a database record belongs to the class this session may read.
     *
     * `section` holds the pair as one `"Grade 7 / MATIYAGA"` string, which is
     * how AdviserController::store writes it.
     */
    public static function coversRecord(Request $request, StudentHealthRecord $record): bool
    {
        $assigned = self::assignedClass($request);

        if ($assigned === null) {
            return true;
        }

        [$grade, $section] = $assigned;

        if ($grade === '' || $section === '') {
            return false;
        }

        $parts = explode(' / ', (string) $record->section, 2);

        return strcasecmp(trim($parts[0] ?? ''), $grade) === 0
            && strcasecmp(trim($parts[1] ?? ''), $section) === 0;
    }

    /**
     * The session roster narrowed to what this session may read, de-duplicated
     * by LRN.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function rosterRows(Request $request): Collection
    {
        return collect($request->session()->get('school_health_card_records', []))
            ->filter(fn ($row): bool => is_array($row) && self::coversRow($request, $row))
            ->unique(fn ($row) => (string) ($row['lrn'] ?? ''))
            ->values();
    }

    /**
     * The school's learners for the current year, narrowed to what this session
     * may read.
     *
     * Reads through StudentHealthRecord::rosterFor(), so it shares the one
     * memoized fetch the rest of the request uses rather than adding a query.
     *
     * @return Collection<int, StudentHealthRecord>
     */
    public static function records(Request $request): Collection
    {
        return StudentHealthRecord::currentYearForInstitution(
            $request->session()->get('active_institution_id')
        )->filter(fn (StudentHealthRecord $record): bool => self::coversRecord($request, $record))->values();
    }
}
