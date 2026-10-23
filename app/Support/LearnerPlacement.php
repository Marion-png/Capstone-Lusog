<?php

namespace App\Support;

use App\Models\StudentHealthRecord;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Where a learner stands between sections and school years — the one reading.
 *
 * The SIGLA School Year Transition spec (14 Oct 2026) moves a learner's record
 * from one section to the next by LRN, without re-encoding it. In this schema
 * a `student_health_records` row is one learner in one section for one school
 * year, which is the spec's placement; this class reads a learner's standing
 * off those rows and nothing else:
 *
 *  - **active** — the latest row is open (no `left_at`, no `archived_at`).
 *    Active in an *earlier* year's section means that section was never
 *    closed (`UNCLOSED`), and the learner cannot be placed anywhere until it is.
 *  - **waiting** — the latest row was closed at year end as Promoted or
 *    Retained; `wait_grade` is the grade they are waiting to be placed in.
 *  - **archived** — the latest row carries `archived_at`: the learner left
 *    (transferred out, dropped out, completed Grade 10) and is kept until
 *    `delete_after`.
 *
 * The LRN is the only link (rule 1): every lookup here is keyed on the plain
 * `student_id` + `institution_id`, never on a name, and reads only plain
 * columns, so deciding where a learner stands never decrypts anybody.
 *
 * Writes live in SchoolYearTransition; this class only reads.
 */
final class LearnerPlacement
{
    public const ACTIVE = 'active';

    public const WAITING = 'waiting';

    public const ARCHIVED = 'archived';

    /** Active in an earlier school year's section that nobody closed. */
    public const UNCLOSED = 'unclosed';

    /** Left the section with no status this reading can place. */
    public const LEFT = 'left';

    public const PROMOTED = 'promoted';

    public const RETAINED = 'retained';

    public const TRANSFERRED_OUT = 'transferred_out';

    public const DROPPED_OUT = 'dropped_out';

    public const COMPLETED_GRADE_10 = 'completed_grade_10';

    /** @var array<string, string> */
    public const END_STATUS_LABELS = [
        self::PROMOTED => 'Promoted',
        self::RETAINED => 'Retained',
        self::TRANSFERRED_OUT => 'Transferred out',
        self::DROPPED_OUT => 'Dropped out',
        self::COMPLETED_GRADE_10 => 'Completed Grade 10',
    ];

    /** The statuses that leave a learner waiting for a section. */
    public const WAITING_STATUSES = [self::PROMOTED, self::RETAINED];

    /** The statuses that archive a learner. */
    public const ARCHIVING_STATUSES = [self::TRANSFERRED_OUT, self::DROPPED_OUT, self::COMPLETED_GRADE_10];

    /** The reasons a learner leaves mid-year (Mark as left school). */
    public const LEFT_SCHOOL_REASONS = [self::TRANSFERRED_OUT, self::DROPPED_OUT];

    /**
     * The last grade SIGLA covers. A Grade 10 learner is not promoted: Senior
     * High is outside the application's scope, so a completer is archived.
     */
    public const FINAL_GRADE = 10;

    public const CARRIED_OVER = 'carried_over';

    public const NEW_ENROLLEE = 'new_enrollee';

    public const RESTORED = 'restored';

    /** @var array<string, string> */
    public const JOINED_LABELS = [
        self::CARRIED_OVER => 'Carried over',
        self::NEW_ENROLLEE => 'New enrollee',
        self::RESTORED => 'Restored',
    ];

    public const MATCHED = 'matched';

    public const NO_MATCH = 'no_match';

    public const FOUND_IN_ARCHIVED = 'found_in_archived';

    public const NEEDS_CHECKING = 'needs_checking';

    public const ALREADY_ADDED = 'already_added';

    public const REJECTED = 'rejected';

    /** @var array<string, string> */
    public const OUTCOME_LABELS = [
        self::MATCHED => 'Matched',
        self::NO_MATCH => 'No match',
        self::FOUND_IN_ARCHIVED => 'Found in Archived',
        self::NEEDS_CHECKING => 'Needs checking',
        self::ALREADY_ADDED => 'Already added',
        self::REJECTED => 'Rejected',
    ];

    /** The outcomes that put a learner into the section on confirm. */
    public const PLACING_OUTCOMES = [self::MATCHED, self::NO_MATCH, self::FOUND_IN_ARCHIVED];

    /** @var list<string> */
    public const COLUMNS = [
        'left_at',
        'end_status',
        'wait_grade',
        'delete_after',
        'joined_at',
        'joined_as',
        'archived_at',
        'archived_by',
        'archive_reason',
    ];

    /** The plain columns a standing is read from. */
    private const STATE_COLUMNS = ['left_at', 'end_status', 'wait_grade', 'delete_after', 'archived_at', 'joined_as'];

    private const MEMO = 'learner_placement:';

    /**
     * Whether the database carries the handover at all. Until the migration
     * has run, every learner reads as active exactly as before, and the pages
     * that close a year or archive a learner say so instead of failing.
     */
    public static function supported(): bool
    {
        if (! SchemaCache::hasTable('student_health_records') || ! SchemaCache::hasTable('section_closures')) {
            return false;
        }

        foreach (self::COLUMNS as $column) {
            if (! SchemaCache::hasColumn('student_health_records', $column)) {
                return false;
            }
        }

        return true;
    }

    // ── One row ─────────────────────────────────────────────────────────

    /** The placement is still open: the learner has not left the section. */
    public static function isOpen(object $row): bool
    {
        return self::value($row, 'left_at') === null && self::value($row, 'archived_at') === null;
    }

    public static function isArchived(object $row): bool
    {
        return self::value($row, 'archived_at') !== null;
    }

    public static function isWaiting(object $row): bool
    {
        return ! self::isArchived($row)
            && self::value($row, 'left_at') !== null
            && in_array((string) self::value($row, 'end_status'), self::WAITING_STATUSES, true);
    }

    /**
     * A learner's standing from their latest row.
     */
    public static function stateOf(?object $latest, ?string $currentYear = null): ?string
    {
        if ($latest === null) {
            return null;
        }

        $currentYear ??= StudentHealthRecord::currentSchoolYear();

        if (self::isArchived($latest)) {
            return self::ARCHIVED;
        }

        if (self::isWaiting($latest)) {
            return self::WAITING;
        }

        if (self::isOpen($latest)) {
            return strcmp((string) self::value($latest, 'school_year'), $currentYear) >= 0
                ? self::ACTIVE
                : self::UNCLOSED;
        }

        return self::LEFT;
    }

    /**
     * The latest of one learner's rows: the highest school year, then the
     * highest id. "2026-2027" sorts after "2025-2026" as a string because the
     * opening year leads and is always four digits.
     *
     * @param  iterable<object>  $rows
     */
    public static function latest(iterable $rows): ?object
    {
        $latest = null;

        foreach ($rows as $row) {
            if ($latest === null) {
                $latest = $row;

                continue;
            }

            $byYear = strcmp((string) self::value($row, 'school_year'), (string) self::value($latest, 'school_year'));

            if ($byYear > 0 || ($byYear === 0 && (int) self::value($row, 'id') > (int) self::value($latest, 'id'))) {
                $latest = $row;
            }
        }

        return $latest;
    }

    /** [grade, section] from a row's "Grade 7 / MATIYAGA" string. */
    public static function sectionOf(object $row): array
    {
        $parts = explode(' / ', (string) self::value($row, 'section'), 2);

        return [trim($parts[0] ?? ''), trim($parts[1] ?? '')];
    }

    public static function sectionLabel(string $grade, string $section): string
    {
        return trim(trim($grade).' / '.trim($section), ' /');
    }

    /** Whether a row is in the given section, compared as every section is: without regard to case. */
    public static function inSection(object $row, string $grade, string $section): bool
    {
        if (trim($grade) === '' || trim($section) === '') {
            return false;
        }

        [$rowGrade, $rowSection] = self::sectionOf($row);

        return strcasecmp($rowGrade, trim($grade)) === 0 && strcasecmp($rowSection, trim($section)) === 0;
    }

    public static function endStatusLabel(?string $status): string
    {
        return self::END_STATUS_LABELS[(string) $status] ?? '';
    }

    public static function joinedLabel(?string $joinedAs): string
    {
        return self::JOINED_LABELS[(string) $joinedAs] ?? '';
    }

    // ── Grades ──────────────────────────────────────────────────────────

    /** The number in a grade label: "Grade 7" → 7, "Grade 7/SPED" → 7. */
    public static function gradeNumber(string $grade): ?int
    {
        return preg_match('/(\d{1,2})/', $grade, $m) === 1 ? (int) $m[1] : null;
    }

    /** The grade a promoted learner waits for, spelt the way their own grade is. */
    public static function nextGrade(string $grade): ?string
    {
        $number = self::gradeNumber($grade);

        if ($number === null || $number >= self::FINAL_GRADE) {
            return null;
        }

        return (string) preg_replace('/\d{1,2}/', (string) ($number + 1), $grade, 1);
    }

    public static function isFinalGrade(string $grade): bool
    {
        return self::gradeNumber($grade) === self::FINAL_GRADE;
    }

    /**
     * The end-of-year statuses offered for a learner in this grade.
     *
     * Grades 7 to 9: Promoted, Retained, Transferred out, Dropped out.
     * Grade 10: Completed Grade 10, Retained, Transferred out, Dropped out.
     *
     * @return list<string>
     */
    public static function endStatusOptions(string $grade): array
    {
        if (self::isFinalGrade($grade)) {
            return [self::COMPLETED_GRADE_10, self::RETAINED, self::TRANSFERRED_OUT, self::DROPPED_OUT];
        }

        if (self::nextGrade($grade) === null) {
            return [self::RETAINED, self::TRANSFERRED_OUT, self::DROPPED_OUT];
        }

        return [self::PROMOTED, self::RETAINED, self::TRANSFERRED_OUT, self::DROPPED_OUT];
    }

    /** What the status dropdown starts on: Promoted (Completed Grade 10 in Grade 10). */
    public static function defaultEndStatus(string $grade): string
    {
        return self::endStatusOptions($grade)[0];
    }

    /** The date an archived learner's record is permanently deleted. */
    public static function deleteAfter(?CarbonImmutable $archivedOn = null): CarbonImmutable
    {
        return StudentRetention::deletionDateFrom($archivedOn ?? CarbonImmutable::now());
    }

    /**
     * What a status will do to the record, as a sentence — shown beside every
     * learner before the year is closed.
     */
    public static function outcomeSentence(string $status, string $grade, ?CarbonImmutable $today = null): string
    {
        if (in_array($status, self::ARCHIVING_STATUSES, true)) {
            return 'Archived; deleted on '.self::deleteAfter($today)->format('j F Y');
        }

        if ($status === self::PROMOTED) {
            return 'Waiting for '.(self::nextGrade($grade) ?? $grade);
        }

        if ($status === self::RETAINED) {
            return 'Waiting for '.$grade;
        }

        return '';
    }

    // ── Reading a school's rows ─────────────────────────────────────────

    /**
     * The plain columns of every row these learners have at one school, by
     * LRN. One query whatever the number of LRNs.
     *
     * @param  list<string>  $lrns
     * @return array<string, list<object>>
     */
    public static function rowsFor(?int $institutionId, array $lrns): array
    {
        $lrns = array_values(array_unique(array_filter(array_map(fn ($l) => trim((string) $l), $lrns), fn ($l) => $l !== '')));

        if (! $institutionId || $lrns === [] || ! SchemaCache::hasTable('student_health_records')) {
            return [];
        }

        $byLrn = [];

        foreach (array_chunk($lrns, 500) as $chunk) {
            $rows = DB::table('student_health_records')
                ->where('institution_id', $institutionId)
                ->whereIn('student_id', $chunk)
                ->get(self::selectColumns());

            foreach ($rows as $row) {
                $byLrn[(string) $row->student_id][] = $row;
            }
        }

        return $byLrn;
    }

    /** Every row one learner has at one school. */
    public static function rowsForLearner(?int $institutionId, string $lrn): array
    {
        return self::rowsFor($institutionId, [$lrn])[trim($lrn)] ?? [];
    }

    /**
     * The latest row of every learner at one school, by LRN — read once per
     * request. Used where a whole list has to be judged at once (an earlier
     * year's consents, the archived list, the deletion job).
     *
     * @return array<string, object>
     */
    public static function latestRows(?int $institutionId): array
    {
        if (! $institutionId || ! SchemaCache::hasTable('student_health_records')) {
            return [];
        }

        return RequestMemo::remember(self::MEMO.'latest:'.$institutionId, function () use ($institutionId): array {
            $rows = DB::table('student_health_records')
                ->where('institution_id', $institutionId)
                ->get(self::selectColumns());

            $grouped = [];
            foreach ($rows as $row) {
                $grouped[(string) $row->student_id][] = $row;
            }

            $latest = [];
            foreach ($grouped as $lrn => $learnerRows) {
                if ($lrn !== '') {
                    $latest[$lrn] = self::latest($learnerRows);
                }
            }

            return $latest;
        });
    }

    /**
     * Whether a row is its learner's latest. A row in the current school year
     * always is (nothing is filed under a year that has not begun), so only an
     * earlier year's row costs a read — the memoized one above.
     */
    public static function isLatestRow(StudentHealthRecord $record): bool
    {
        if (strcmp((string) $record->school_year, StudentHealthRecord::currentSchoolYear()) >= 0) {
            return true;
        }

        $latest = self::latestRows($record->institution_id ? (int) $record->institution_id : null)[(string) $record->student_id] ?? null;

        return $latest === null || (int) $latest->id === (int) $record->id;
    }

    /** Drop every memo this class and the roster read keep, after a write. */
    public static function forget(): void
    {
        RequestMemo::forgetPrefix(self::MEMO);
        RequestMemo::forgetPrefix('student_health_records:');
    }

    /** @return list<string> */
    private static function selectColumns(): array
    {
        $columns = ['id', 'student_id', 'institution_id', 'school_year', 'section'];

        foreach (self::STATE_COLUMNS as $column) {
            if (SchemaCache::hasColumn('student_health_records', $column)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    // ── A master-list row ───────────────────────────────────────────────

    /** An LRN is twelve digits. Anything else is refused, never guessed at. */
    public static function isValidLrn(string $lrn): bool
    {
        return preg_match('/^\d{12}$/', trim($lrn)) === 1;
    }

    /**
     * What happens when an LRN is added to a section — the spec's table.
     *
     * | LRN in the list                                  | Outcome           |
     * |--------------------------------------------------|-------------------|
     * | waiting, `wait_grade` = this section's grade     | Matched           |
     * | nobody has this LRN                              | No match          |
     * | archived                                         | Found in Archived |
     * | waiting for a different grade                    | Needs checking    |
     * | active in a section not yet closed               | Needs checking    |
     * | already in this section                          | Already added     |
     *
     * Nothing here writes. The adviser sees the outcome first, and the write
     * re-reads the rows and asks again, so a learner whose standing moved in
     * between is never placed on the strength of an old answer.
     *
     * @param  list<object>  $rows  every row this LRN has at the school
     * @return array{outcome: string, label: string, message: string, latest: ?object, in_place: bool}
     */
    public static function classify(array $rows, string $grade, string $section, ?string $currentYear = null): array
    {
        $currentYear ??= StudentHealthRecord::currentSchoolYear();
        $latest = self::latest($rows);

        if ($latest === null) {
            return self::answer(self::NO_MATCH, 'Creates a new learner record.', null);
        }

        $year = (string) self::value($latest, 'school_year');
        [$fromGrade, $fromSection] = self::sectionOf($latest);
        $from = self::sectionLabel($fromGrade, $fromSection);

        switch (self::stateOf($latest, $currentYear)) {
            case self::ACTIVE:
                if (self::inSection($latest, $grade, $section)) {
                    return self::answer(self::ALREADY_ADDED, 'Already in this section. Nothing changes.', $latest);
                }

                // Another adviser's section is not named: the learner is not
                // this adviser's, and neither is where they are.
                return self::answer(
                    self::NEEDS_CHECKING,
                    'Already enrolled in another class this school year. A move between sections is not yet supported; ask the school.',
                    $latest
                );

            case self::ARCHIVED:
                return self::answer(
                    self::FOUND_IN_ARCHIVED,
                    'Restores the archived record to this section; its deletion is cancelled.',
                    $latest,
                    strcmp($year, $currentYear) >= 0
                );

            case self::WAITING:
                $waitGrade = trim((string) self::value($latest, 'wait_grade'));

                if (strcmp($year, $currentYear) >= 0) {
                    return self::answer(
                        self::NEEDS_CHECKING,
                        'Waiting for '.($waitGrade !== '' ? $waitGrade : 'a section').' from S.Y. '.$year.'. The learner can be added once the next school year begins.',
                        $latest
                    );
                }

                if ($waitGrade !== '' && strcasecmp($waitGrade, trim($grade)) === 0) {
                    return self::answer(
                        self::MATCHED,
                        'Carries the record over from '.$from.' (S.Y. '.$year.').',
                        $latest
                    );
                }

                return self::answer(
                    self::NEEDS_CHECKING,
                    'The previous adviser marked this learner for '.($waitGrade !== '' ? $waitGrade : 'another grade').'.',
                    $latest
                );

            case self::UNCLOSED:
                return self::answer(
                    self::NEEDS_CHECKING,
                    'Still active in a section of S.Y. '.$year.': the previous adviser has not closed the school year.',
                    $latest
                );

            default:
                return self::answer(
                    self::NEEDS_CHECKING,
                    'The learner left their section in S.Y. '.$year.' without a status the school can place.',
                    $latest
                );
        }
    }

    /** @return array{outcome: string, label: string, message: string, latest: ?object, in_place: bool} */
    private static function answer(string $outcome, string $message, ?object $latest, bool $inPlace = false): array
    {
        return [
            'outcome' => $outcome,
            'label' => self::OUTCOME_LABELS[$outcome],
            'message' => $message,
            'latest' => $latest,
            'in_place' => $inPlace,
        ];
    }

    // ── Names ───────────────────────────────────────────────────────────

    /**
     * Whether two spellings name the same learner: last and first name,
     * without regard to case, punctuation or spacing, and the middle initial
     * only where both carry one. Used to decide whether the preview shows both
     * names — never to decide who a learner is; that is the LRN's job.
     */
    public static function sameName(array $a, array $b): bool
    {
        $norm = fn ($value): string => (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim((string) $value)));

        if ($norm($a['last_name'] ?? '') !== $norm($b['last_name'] ?? '')
            || $norm($a['first_name'] ?? '') !== $norm($b['first_name'] ?? '')) {
            return false;
        }

        $middleA = substr($norm($a['middle_name'] ?? ''), 0, 1);
        $middleB = substr($norm($b['middle_name'] ?? ''), 0, 1);

        return $middleA === '' || $middleB === '' || $middleA === $middleB;
    }

    /** "Dela Cruz, Juan S." — the roster's own spelling of a name. */
    public static function displayName(array $name): string
    {
        $middle = trim((string) ($name['middle_name'] ?? ''));
        $initial = $middle !== '' ? ' '.mb_strtoupper(mb_substr($middle, 0, 1)).'.' : '';

        return trim(trim((string) ($name['last_name'] ?? '')).', '.trim((string) ($name['first_name'] ?? '')).$initial, ', ');
    }

    /**
     * The name on file for a record, as last / first / middle.
     *
     * @return array{last_name: string, first_name: string, middle_name: string}
     */
    public static function nameOnFile(StudentHealthRecord $record): array
    {
        $details = is_array($record->student_details) ? $record->student_details : [];
        $last = trim((string) ($details['last_name'] ?? ''));
        $first = trim((string) ($details['first_name'] ?? ''));
        $middle = trim((string) ($details['middle_name'] ?? ''));

        if ($last === '' && $first === '') {
            [$last, $first, $middle] = StudentRosterSync::splitStudentName((string) $record->student_name);
        }

        return ['last_name' => $last, 'first_name' => $first, 'middle_name' => $middle];
    }

    // ── Reading a value off either kind of row ──────────────────────────

    /**
     * A column off an Eloquent model or a query-builder row, null when the
     * row does not carry it (a database that has not run the migration).
     */
    public static function value(object $row, string $column): mixed
    {
        $value = $row->{$column} ?? null;

        return $value === '' ? null : $value;
    }

    /** A date column as a date, whichever kind of row it came from. */
    public static function dateOf(object $row, string $column): ?CarbonImmutable
    {
        $value = self::value($row, $column);

        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The latest rows of one school's learners in one standing.
     *
     * @return Collection<string, object>
     */
    public static function latestInState(?int $institutionId, string $state): Collection
    {
        $currentYear = StudentHealthRecord::currentSchoolYear();

        return collect(self::latestRows($institutionId))
            ->filter(fn (object $row): bool => self::stateOf($row, $currentYear) === $state);
    }
}
