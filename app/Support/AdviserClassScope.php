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
 * **Access follows the learner** (School Year Transition spec, rule 4). A
 * learner belongs to the class their *latest* placement is in, and only while
 * that placement is open: once they are promoted, retained, archived or
 * placed in another section, the adviser they left loses access, checked
 * against the database on every request (LearnerPlacement). An adviser keeps a
 * read-only view of the learners archived from their own section, from the
 * Archived tab.
 *
 * `AdviserClassScopeTest` guards all of it.
 */
final class AdviserClassScope
{
    /** The learner is in this adviser's section now: read and edit. */
    public const ACCESS_CURRENT = 'current';

    /** Archived from this adviser's section: read only, from the Archived tab. */
    public const ACCESS_ARCHIVED = 'archived';

    /** Was in this adviser's section and has left it: no access. */
    public const ACCESS_PREVIOUS = 'previous';

    /** Never in this adviser's section. */
    public const ACCESS_NONE = 'none';

    /** What a previous adviser is told, and nothing more — not the record, not the new section. */
    public const NO_LONGER_MESSAGE = 'You no longer have access to this record.';

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
     * how AdviserController::store writes it. The placement must also still be
     * open and be the learner's latest: a learner who has left the section,
     * or been placed in another since, is no longer this adviser's.
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
            && strcasecmp(trim($parts[1] ?? ''), $section) === 0
            && LearnerPlacement::isOpen($record)
            && LearnerPlacement::isLatestRow($record);
    }

    /**
     * What this session may do with one learner's record, read from the
     * learner's own rows on this request.
     *
     * Any role but a class adviser is not limited to a class and gets
     * ACCESS_CURRENT, exactly as before; their school is scoped by the caller.
     */
    public static function learnerAccess(Request $request, string $lrn): string
    {
        $assigned = self::assignedClass($request);

        if ($assigned === null) {
            return self::ACCESS_CURRENT;
        }

        [$grade, $section] = $assigned;
        $institutionId = $request->session()->get('active_institution_id');

        if ($grade === '' || $section === '' || ! $institutionId || trim($lrn) === '') {
            return self::ACCESS_NONE;
        }

        $rows = LearnerPlacement::rowsForLearner((int) $institutionId, $lrn);
        $latest = LearnerPlacement::latest($rows);

        if ($latest !== null && LearnerPlacement::inSection($latest, $grade, $section)) {
            if (LearnerPlacement::isOpen($latest)) {
                return self::ACCESS_CURRENT;
            }

            if (LearnerPlacement::isArchived($latest)) {
                return self::ACCESS_ARCHIVED;
            }
        }

        foreach ($rows as $row) {
            if (LearnerPlacement::inSection($row, $grade, $section)) {
                return self::ACCESS_PREVIOUS;
            }
        }

        return self::ACCESS_NONE;
    }

    /** Whether this session may read a learner's record at all (any year of it). */
    public static function mayReadLearner(Request $request, string $lrn): bool
    {
        return in_array(self::learnerAccess($request, $lrn), [self::ACCESS_CURRENT, self::ACCESS_ARCHIVED], true);
    }

    /**
     * The session roster narrowed to what this session may read, de-duplicated
     * by LRN.
     *
     * A learner whose placement this school year has closed is dropped even if
     * a stale session still carries them: who is in a section is read from the
     * database on every request, not from when the session was filled.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function rosterRows(Request $request): Collection
    {
        $left = self::leftThisYear($request);

        return collect($request->session()->get('school_health_card_records', []))
            ->filter(fn ($row): bool => is_array($row)
                && self::coversRow($request, $row)
                && ! isset($left[(string) ($row['lrn'] ?? '')]))
            ->unique(fn ($row) => (string) ($row['lrn'] ?? ''))
            ->values();
    }

    /**
     * LRNs whose row this school year has left its section, keyed for lookup.
     * Read through the memoized roster, so it costs nothing a page that has
     * synced its roster has not already paid.
     *
     * @return array<string, true>
     */
    private static function leftThisYear(Request $request): array
    {
        $institutionId = $request->session()->get('active_institution_id');

        if (! $institutionId || ! LearnerPlacement::supported()) {
            return [];
        }

        $left = [];
        foreach (StudentHealthRecord::rosterFor((int) $institutionId) as $record) {
            if (! LearnerPlacement::isOpen($record)) {
                $left[(string) $record->student_id] = true;
            }
        }

        return $left;
    }

    /**
     * The school's learners for one school year (the current one by default),
     * narrowed to what this session may read.
     *
     * An earlier year is narrowed by the same rule as the current one — the
     * record's own grade and section against this session's assignment — so a
     * year selector shows the class as it stood that year and never widens the
     * scope to another adviser's learners.
     *
     * Reads through StudentHealthRecord::rosterFor(), so it shares the one
     * memoized fetch the rest of the request uses rather than adding a query.
     *
     * @return Collection<int, StudentHealthRecord>
     */
    public static function records(Request $request, ?string $schoolYear = null): Collection
    {
        return StudentHealthRecord::rosterFor(
            $request->session()->get('active_institution_id'),
            $schoolYear
        )->filter(fn (StudentHealthRecord $record): bool => self::coversRecord($request, $record))->values();
    }
}
