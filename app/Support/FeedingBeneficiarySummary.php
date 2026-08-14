<?php

namespace App\Support;

use App\Models\FeedingAttendance;
use App\Models\StudentHealthRecord;
use Illuminate\Support\Collection;

/**
 * The programme's headline numbers, computed once for every screen that shows
 * them: how many learners the coordinator feeds, how they were measured, how
 * many the attendance rule has flagged, and what share is turning up.
 *
 * It exists because those five figures appear on two tabs. Counting them twice
 * would let the Beneficiaries tab and the Dashboard disagree about the size of
 * the programme, which is exactly the sort of drift the feeding invariants are
 * written to prevent — so this class is the only place that decides:
 *
 * - who is a beneficiary (qualified by the adviser's measurement AND enrolled
 *   by the coordinator — see FeedingProgramController::isEnrolledBeneficiary),
 * - which status a learner is reported under (the app's non-standard
 *   "Underweight" folds into Wasted, exactly as the DepEd BMI sheets do),
 * - and how attendance is counted (NULL is an unconfirmed mark and votes
 *   neither way, per FeedingAtRiskRule).
 *
 * Everything here is derived at read time. Nothing is stored, cached, or
 * hand-entered, so a mark recorded at the feeding line moves these figures on
 * the very next read.
 */
final class FeedingBeneficiarySummary
{
    /**
     * The adviser's measurement qualifies a learner for the programme.
     * Qualifying is not enrolment — see isBeneficiary().
     */
    public static function qualifies(?string $status): bool
    {
        $normalized = strtolower((string) $status);

        return str_contains($normalized, 'wast')
            || str_contains($normalized, 'severe')
            || str_contains($normalized, 'underweight');
    }

    /**
     * A beneficiary: qualified by the measurement AND enrolled by the
     * coordinator. A qualified learner nobody has enrolled is on the waiting
     * list, not on the feeding line, and is counted by none of these figures.
     */
    public static function isBeneficiary(StudentHealthRecord $record): bool
    {
        return $record->feeding_enrolled_at !== null
            && self::qualifies((string) $record->nutritional_status);
    }

    /**
     * One learner's status as the cards report it.
     *
     * Baseline is what the programme enrolled them on; a learner with no
     * baseline on file is counted under their current status. Every learner
     * lands on exactly one of the four labels, so the breakdown always sums to
     * the beneficiary total rather than quietly losing a row.
     */
    public static function statusOf(StudentHealthRecord $record): string
    {
        return match (self::normalize((string) ($record->baseline_nutritional_status ?: $record->nutritional_status))) {
            'Severely Wasted' => 'Severely Wasted',
            'Wasted', 'Underweight' => 'Wasted',
            'Overweight', 'Obese' => FeedingNutritionProgress::ABOVE_NORMAL,
            default => 'Normal',
        };
    }

    /** The many spellings an adviser may have typed, reduced to one label. */
    public static function normalize(string $status): string
    {
        $normalized = strtolower(trim($status));

        return match (true) {
            $normalized === '' => '',
            str_contains($normalized, 'severe') => 'Severely Wasted',
            str_contains($normalized, 'wast') => 'Wasted',
            str_contains($normalized, 'underweight') => 'Underweight',
            str_contains($normalized, 'over') => 'Overweight',
            str_contains($normalized, 'normal') => 'Normal',
            default => $status,
        };
    }

    /**
     * Every beneficiary's session marks in date order, NULL where a scanned
     * mark is still unconfirmed.
     *
     * Fetched, never aggregated in SQL: a `SUM(CASE WHEN is_present ...)` folds
     * NULL into "absent", which is the one thing attendance must never do.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @return Collection<int, list<bool|null>>
     */
    public static function marksByRecord(Collection $beneficiaries): Collection
    {
        if ($beneficiaries->isEmpty() || ! SchemaCache::hasTable('feeding_attendances')) {
            return collect();
        }

        $hasReviewColumn = SchemaCache::hasColumn('feeding_attendances', 'needs_review');

        return FeedingAttendance::query()
            ->whereIn('student_health_record_id', $beneficiaries->pluck('id'))
            ->whereDate('session_date', '<=', now()->toDateString())
            ->orderBy('session_date')
            ->get(array_merge(
                ['student_health_record_id', 'session_date', 'is_present'],
                $hasReviewColumn ? ['needs_review'] : []
            ))
            ->groupBy('student_health_record_id')
            // Before the review migration every mark is confirmed by definition.
            ->map(fn ($rows) => $rows->map(fn ($row) => ($row->needs_review ?? false) ? null : $row->is_present)->all());
    }

    /**
     * The five headline figures for a roll of beneficiaries already chosen.
     *
     * At-risk is computed live from the school's current threshold rather than
     * read off the stored is_at_risk column, so changing the threshold shows at
     * once instead of waiting for the next import to recompute the flags.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @return array{beneficiaries: int, severely_wasted: int, wasted: int, at_risk: int, attendance_rate: ?int, attendance_sessions: int, at_risk_rule: string, at_risk_threshold: float}
     */
    public static function tally(Collection $beneficiaries, FeedingAtRiskRule $rule): array
    {
        $marksByRecord = self::marksByRecord($beneficiaries);

        $confirmedSessions = 0;
        $presentSessions = 0;
        $atRisk = 0;
        $severelyWasted = 0;
        $wasted = 0;

        foreach ($beneficiaries as $record) {
            $status = self::statusOf($record);
            if ($status === 'Severely Wasted') {
                $severelyWasted++;
            } elseif ($status === 'Wasted') {
                $wasted++;
            }

            // A learner no session has covered yet contributes nothing and is
            // flagged by nothing — the rule already reads no marks as no
            // evidence, so there is no special case to write here.
            $marks = $marksByRecord->get($record->id, []);
            $confirmedSessions += count(array_filter($marks, static fn ($mark) => $mark !== null));
            $presentSessions += $rule->presentCount($marks);

            if ($rule->isAtRisk($marks)) {
                $atRisk++;
            }
        }

        return [
            'beneficiaries' => $beneficiaries->count(),
            'severely_wasted' => $severelyWasted,
            'wasted' => $wasted,
            'at_risk' => $atRisk,
            // Null, not zero: no confirmed session is not a 0% turnout.
            'attendance_rate' => $confirmedSessions > 0
                ? (int) round(($presentSessions / $confirmedSessions) * 100)
                : null,
            'attendance_sessions' => $confirmedSessions,
            'at_risk_rule' => $rule->describe(),
            'at_risk_threshold' => $rule->thresholdPercent(),
        ];
    }

    /**
     * The same five figures for one school, read straight from the database.
     *
     * Grade and section are applied in PHP because `section` holds
     * "Grade 7 / Sampaguita" as one encrypted-adjacent lookup string; passing
     * either empty counts the whole school.
     *
     * @param  array{school_year?: string, grade?: string, section?: string}  $filters
     * @return array{beneficiaries: int, severely_wasted: int, wasted: int, at_risk: int, pending: int, attendance_rate: ?int, attendance_sessions: int, at_risk_rule: string, at_risk_threshold: float}
     */
    public static function forInstitution(?int $institutionId, array $filters = []): array
    {
        $records = collect();

        if (SchemaCache::hasTable('student_health_records')) {
            $records = StudentHealthRecord::query()
                ->when($institutionId, fn ($query) => $query->where('institution_id', $institutionId))
                ->forCurrentSchoolYear($filters['school_year'] ?? null)
                ->get();
        }

        $records = self::scopeToSection($records, $filters);

        $qualified = $records
            ->filter(fn (StudentHealthRecord $record): bool => self::qualifies((string) $record->nutritional_status))
            ->values();

        $beneficiaries = $qualified
            ->filter(fn (StudentHealthRecord $record): bool => $record->feeding_enrolled_at !== null)
            ->values();

        return self::tally($beneficiaries, FeedingAtRiskRule::forInstitution($institutionId))
            // Qualified by the measurement, not yet enrolled by the coordinator:
            // the waiting list, and not a beneficiary until someone acts on it.
            + ['pending' => $qualified->count() - $beneficiaries->count()];
    }

    /**
     * Narrows a roll to one grade and/or section. Both are read off the plain
     * "Grade 7 / Sampaguita" string in PHP; passing neither keeps everyone.
     *
     * @param  Collection<int, StudentHealthRecord>  $records
     * @param  array{grade?: string, section?: string}  $filters
     * @return Collection<int, StudentHealthRecord>
     */
    public static function scopeToSection(Collection $records, array $filters): Collection
    {
        $grade = trim((string) ($filters['grade'] ?? ''));
        $section = trim((string) ($filters['section'] ?? ''));

        if ($grade === '' && $section === '') {
            return $records->values();
        }

        return $records
            ->filter(function (StudentHealthRecord $record) use ($grade, $section): bool {
                [$recordGrade, $recordSection] = self::splitSection((string) $record->section);

                return ($grade === '' || $recordGrade === $grade)
                    && ($section === '' || $recordSection === $section);
            })
            ->values();
    }

    /**
     * Where each beneficiary stands with the school's attendance rule: the
     * cumulative rate, and whether that rate has flagged them.
     *
     * Both come from one reading of one set of marks, so the figure a table
     * prints beside a learner is exactly the figure that decided their flag —
     * a row can never show 92% next to an at-risk badge.
     *
     * The rate is NULL, not 0, where no session has been confirmed: no
     * evidence is not a turnout of nothing.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @return array<int, array{rate: ?int, at_risk: bool}>
     */
    public static function attendanceStandings(Collection $beneficiaries, FeedingAtRiskRule $rule): array
    {
        $marksByRecord = self::marksByRecord($beneficiaries);
        $standings = [];

        foreach ($beneficiaries as $record) {
            $marks = $marksByRecord->get($record->id, []);
            $rate = $rule->attendanceRate($marks);

            $standings[$record->id] = [
                'rate' => $rate === null ? null : (int) round($rate),
                'at_risk' => $rule->isAtRisk($marks),
            ];
        }

        return $standings;
    }

    /**
     * The record ids the school's attendance threshold has flagged.
     *
     * Returned as a lookup rather than a count so a table can mark the very
     * learners the At Risk figure counted — the tab and the rows it filters
     * must never be decided by two different readings of the same rule.
     *
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @return array<int, true>
     */
    public static function atRiskIds(Collection $beneficiaries, FeedingAtRiskRule $rule): array
    {
        return array_map(
            static fn (array $standing): bool => true,
            array_filter(
                self::attendanceStandings($beneficiaries, $rule),
                static fn (array $standing): bool => $standing['at_risk']
            )
        );
    }

    /**
     * Splits the plain "Grade X / Section" string into [grade, section].
     * Rows with no section land under "Unassigned" so they stay selectable.
     *
     * @return array{0: string, 1: string}
     */
    public static function splitSection(string $section): array
    {
        $parts = explode(' / ', $section, 2);
        $grade = trim($parts[0]);
        $sectionName = trim($parts[1] ?? '');

        return [$grade !== '' ? $grade : 'Unassigned', $sectionName !== '' ? $sectionName : 'Unassigned'];
    }
}
