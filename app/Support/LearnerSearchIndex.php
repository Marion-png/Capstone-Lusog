<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The school's learners as a search index for the browser — LRN, display
 * name and "Grade – Section" — one row per learner, sorted by name.
 *
 * Student names are encrypted at rest, so no SQL LIKE can find one; the
 * roster is synced from the database (the invariant: any reader of
 * `school_health_card_records` calls StudentRosterSync first), embedded on
 * the page and filtered in the browser. The topbar learner search and the
 * New Consultation learner picker both read this, so a name is spelled the
 * same way wherever it is searched for.
 */
class LearnerSearchIndex
{
    /**
     * @return list<array{lrn: string, name: string, section: string}>
     */
    public static function fromSession(Request $request): array
    {
        StudentRosterSync::syncToSession($request);

        return collect($request->session()->get('school_health_card_records', []))
            ->filter(fn ($row) => is_array($row))
            ->map(function (array $row) {
                $middle = trim((string) ($row['middle_name'] ?? ''));
                $name = trim(
                    trim((string) ($row['last_name'] ?? '')).', '.
                    trim((string) ($row['first_name'] ?? '')).
                    ($middle !== '' ? ' '.strtoupper(mb_substr($middle, 0, 1)).'.' : '')
                );

                return [
                    'lrn' => (string) ($row['lrn'] ?? ''),
                    'name' => trim($name, ' ,'),
                    'section' => trim(trim((string) ($row['grade_level'] ?? '')).' - '.trim((string) ($row['section'] ?? '')), ' -'),
                ];
            })
            ->filter(fn (array $row) => $row['lrn'] !== '' && $row['name'] !== '')
            ->unique('lrn')
            ->sortBy(fn (array $row) => mb_strtolower($row['name']), SORT_NATURAL)
            ->values()
            ->all();
    }
}
