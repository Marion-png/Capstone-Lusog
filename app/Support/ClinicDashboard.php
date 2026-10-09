<?php

namespace App\Support;

use App\Models\Consultation;
use App\Models\Medicine;
use App\Models\StudentHealthRecord;
use Illuminate\Support\Collection;

/**
 * The clinic dashboard's figures, read once.
 *
 * The School Nurse and the Clinic Teacher open the same dashboard — four
 * cards, this month's top conditions, the medicine stock monitor and the
 * recent consultations — because they are both reading the same clinic. Two
 * routes computing it separately is how two desks end up reporting different
 * numbers for one school on the same afternoon, so both read this.
 *
 * It is a reading and nothing else: every figure is derived at read time from
 * the live tables, nothing here is stored, and no card is backed by a counter
 * somebody has to keep up to date.
 */
class ClinicDashboard
{
    /** How many rows each panel shows. */
    private const RECENT_CONSULTATIONS = 8;

    private const TOP_CONDITIONS = 4;

    private const LOW_STOCK_ITEMS = 4;

    /**
     * Everything the dashboard view needs, keyed as the view names it.
     *
     * Scoped by institution, and a null institution reads nothing per-school
     * rather than falling through to every school's children — the same lock
     * `InstitutionScope` puts on the session.
     *
     * @return array<string, mixed>
     */
    public static function read(?int $institutionId): array
    {
        $recent = self::recentConsultations($institutionId);

        return [
            'totalRecords' => self::totalRecords($institutionId),
            'atRiskCount' => self::atRiskCount($institutionId),
            'consultationsToday' => self::consultationsToday($institutionId),
            'recentConsultations' => $recent,
            'consultationFilters' => self::consultationFilters($institutionId, $recent),
            'topConditions' => self::topConditions($institutionId),
            'lowStockCount' => self::lowStockCount($institutionId),
            'lowStockMedicines' => self::lowStockMedicines($institutionId),
        ];
    }

    private static function totalRecords(?int $institutionId): int
    {
        if (! SchemaCache::hasTable('student_health_records')) {
            return 0;
        }

        return StudentHealthRecord::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->forCurrentSchoolYear()
            ->count();
    }

    private static function atRiskCount(?int $institutionId): int
    {
        if (! SchemaCache::hasTable('student_health_records')) {
            return 0;
        }

        // `is_at_risk` is deliberately a plain column, so this one is a count
        // in SQL rather than a decryption of the whole school.
        return StudentHealthRecord::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->forCurrentSchoolYear()
            ->where('is_at_risk', true)
            ->count();
    }

    private static function consultationsToday(?int $institutionId): int
    {
        if (! SchemaCache::hasTable('consultations')) {
            return 0;
        }

        return Consultation::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->whereDate('consulted_at', now()->toDateString())
            ->count();
    }

    private static function recentConsultations(?int $institutionId)
    {
        if (! SchemaCache::hasTable('consultations')) {
            return collect();
        }

        return Consultation::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->latest('consulted_at')->latest('id')
            ->limit(self::RECENT_CONSULTATIONS)
            ->get();
    }

    /**
     * What the consultations table filters on: each visit's grade, section and
     * gender, and the options the three controls offer.
     *
     * A visit carries only a name and a typed "Grade 10 - Rizal" label — no
     * LRN and no gender — so the grade and section are read off the label the
     * row prints, and the gender off the learner the name matches on the
     * roster. Every one of those is encrypted at rest, so all of it runs here
     * in PHP. The roster is the memoized `rosterFor()` read the topbar search
     * already makes, so this costs no second trip.
     *
     * A visit whose name matches no learner, or more than one that its grade
     * and section cannot tell apart, has no gender. It is left out when a
     * gender is chosen rather than guessed into one.
     *
     * @param  Collection<int, Consultation>  $consultations
     * @return array{rows: array<int, array{grade: string, section: string, sex: string}>, grades: list<string>, sections: array<string, list<string>>}
     */
    private static function consultationFilters(?int $institutionId, Collection $consultations): array
    {
        $roster = $institutionId && SchemaCache::hasTable('student_health_records')
            ? StudentHealthRecord::rosterFor($institutionId)
            : collect();

        $learners = $roster
            ->map(function (StudentHealthRecord $record): array {
                [$grade, $section] = FeedingBeneficiarySummary::splitSection((string) $record->section);

                return [
                    'tokens' => self::nameTokens((string) $record->student_name),
                    'grade' => $grade === 'Unassigned' ? '' : $grade,
                    'section' => $section === 'Unassigned' ? '' : $section,
                    'sex' => FeedingBeneficiarySummary::sexOf($record),
                ];
            })
            ->filter(fn (array $learner): bool => $learner['tokens'] !== [])
            ->values()
            ->all();

        $rows = [];
        foreach ($consultations as $consultation) {
            [$grade, $section] = self::gradeAndSection((string) $consultation->grade_section);
            $learner = self::learnerFor(self::nameTokens((string) $consultation->student_name), $grade, $section, $learners);

            // A label with no grade in it borrows the matched learner's class,
            // so a visit typed "Rizal, Grade 9" can still be found.
            if ($grade === '' && $learner !== null) {
                $grade = $learner['grade'];
                $section = $section !== '' ? $section : $learner['section'];
            }

            $rows[$consultation->id] = [
                'grade' => $grade,
                'section' => $section,
                'sex' => $learner['sex'] ?? '',
            ];
        }

        // The school's own grades and sections, plus any a visit was typed
        // under, so no row is unreachable from the controls. Sections keep
        // the roster's spelling where both name one.
        $sections = [];
        foreach (array_merge($learners, array_values($rows)) as $entry) {
            if ($entry['grade'] === '') {
                continue;
            }
            $sections[$entry['grade']] ??= [];
            if ($entry['section'] !== '') {
                $sections[$entry['grade']][mb_strtolower($entry['section'])] ??= $entry['section'];
            }
        }

        uksort($sections, 'strnatcasecmp');
        $sections = array_map(function (array $names): array {
            $names = array_values($names);
            natcasesort($names);

            return array_values($names);
        }, $sections);

        return [
            'rows' => $rows,
            'grades' => array_keys($sections),
            'sections' => $sections,
        ];
    }

    /**
     * "Grade 10 - Rizal" → ['Grade 10', 'Rizal']. The label is typed, so this
     * accepts the spellings a nurse actually uses ("G10-Rizal", "10 / Rizal",
     * "Gr. 8 Sampaguita") and reads a label that does not open with a grade —
     * "Faculty", "Personnel" — as having none.
     *
     * @return array{0: string, 1: string}
     */
    private static function gradeAndSection(string $label): array
    {
        if (! preg_match('/^\s*(?:grade|gr\.?|g)?\s*(1[0-2]|[1-9])(?![\d\p{L}])\s*[-–—\/|,:.]?\s*(.*)$/iu', $label, $match)) {
            return ['', ''];
        }

        return ['Grade '.$match[1], trim($match[2], " \t-–—/|,:.")];
    }

    /**
     * The words of a name, lowercased, without initials — so "Gomez, Jose C."
     * and "Jose Gomez" compare equal however the visit was typed.
     *
     * @return list<string>
     */
    private static function nameTokens(string $name): array
    {
        preg_match_all("/\p{L}[\p{L}'’-]*/u", mb_strtolower($name), $match);

        return array_values(array_unique(array_filter(
            $match[0],
            fn (string $token): bool => mb_strlen($token) > 1
        )));
    }

    /**
     * The one learner a visit's name belongs to, or null.
     *
     * A learner matches when every word of their name is in the visit's. A
     * grade on the visit must agree with the learner's; several matches are
     * then narrowed to the exact name, then to the section, and a tie that
     * survives both is no match at all.
     *
     * @param  list<string>  $visitTokens
     * @param  list<array{tokens: list<string>, grade: string, section: string, sex: string}>  $learners
     * @return array{tokens: list<string>, grade: string, section: string, sex: string}|null
     */
    private static function learnerFor(array $visitTokens, string $grade, string $section, array $learners): ?array
    {
        if ($visitTokens === []) {
            return null;
        }

        $candidates = array_values(array_filter(
            $learners,
            fn (array $learner): bool => array_diff($learner['tokens'], $visitTokens) === []
                && ($grade === '' || $learner['grade'] === '' || strcasecmp($learner['grade'], $grade) === 0)
        ));

        if (count($candidates) > 1) {
            $exact = array_values(array_filter(
                $candidates,
                fn (array $learner): bool => count($learner['tokens']) === count($visitTokens)
            ));
            $candidates = $exact !== [] ? $exact : $candidates;
        }

        if (count($candidates) > 1 && $section !== '') {
            $candidates = array_values(array_filter(
                $candidates,
                fn (array $learner): bool => strcasecmp($learner['section'], $section) === 0
            ));
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    private static function topConditions(?int $institutionId)
    {
        if (! SchemaCache::hasTable('consultations')) {
            return collect();
        }

        // `condition` is encrypted at rest, so this month's rows are fetched
        // and tallied in PHP — a SQL GROUP BY would group ciphertext, giving
        // one "condition" per row.
        return Consultation::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->whereMonth('consulted_at', now()->month)
            ->whereYear('consulted_at', now()->year)
            ->get()
            ->groupBy(fn (Consultation $c) => strtolower(trim((string) $c->condition)))
            ->reject(fn ($group, $name) => $name === '')
            ->map(fn ($group, $name) => ['name' => $name, 'total' => $group->count()])
            ->sortByDesc('total')
            ->values()
            ->take(self::TOP_CONDITIONS);
    }

    private static function lowStockCount(?int $institutionId): int
    {
        if (! SchemaCache::hasTable('medicines')) {
            return 0;
        }

        return self::lowStockQuery($institutionId)->count();
    }

    private static function lowStockMedicines(?int $institutionId)
    {
        if (! SchemaCache::hasTable('medicines')) {
            return collect();
        }

        return self::lowStockQuery($institutionId)
            ->orderBy('stock_quantity')
            ->limit(self::LOW_STOCK_ITEMS)
            ->get();
    }

    /**
     * At or below the item's **own** reorder line, never a constant: out of
     * stock is what that medicine's `minimum_threshold` says it is.
     */
    private static function lowStockQuery(?int $institutionId)
    {
        return Medicine::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->whereColumn('stock_quantity', '<=', 'minimum_threshold');
    }
}
