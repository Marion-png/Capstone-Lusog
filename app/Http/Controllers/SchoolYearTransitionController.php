<?php

namespace App\Http\Controllers;

use App\Models\StudentHealthRecord;
use App\Support\AdviserClassScope;
use App\Support\LearnerPlacement;
use App\Support\SchoolYearTransition;
use App\Support\StudentRosterSync;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * The adviser's end of the school-year handover, and the clinic's view of the
 * learners between sections.
 *
 *  - **Close school year** — the outgoing adviser marks one end-of-year status
 *    per learner and submits; SchoolYearTransition writes the whole section in
 *    one transaction and the section becomes read-only. Until a learner is
 *    placed in a new section, the adviser may correct the status they chose.
 *  - **Mark as left school** — a learner who transfers out or stops attending
 *    mid-year is archived on the spot.
 *  - **Archived** — the adviser's own: only the learners who left this
 *    adviser's section, each with the date their record will be deleted, a
 *    read-only view of the record, and Restore.
 *  - **Archived Students** for the School Nurse and the Clinic Teacher — every
 *    learner the school holds who is archived or waiting for a section, read
 *    only.
 *
 * The adviser never chooses the next section or the next adviser; the
 * incoming adviser's upload does that (AdviserController::confirmImport).
 */
class SchoolYearTransitionController extends Controller
{
    /** The roles that read every archived and waiting learner. */
    public const CLINIC_ROLES = ['school_nurse', 'clinic_teacher'];

    public function closeYearForm(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->requireAdviser($request)) {
            return $redirect;
        }

        [$grade, $section, $institutionId] = $this->assignment($request);

        // The rail and the topbar search read the session roster.
        StudentRosterSync::syncToSession($request);

        if (! LearnerPlacement::supported()) {
            return view('adviser-dashboard.close-year', [
                'supported' => false,
                'schoolYear' => StudentHealthRecord::currentSchoolYear(),
                'grade' => $grade,
                'section' => $section,
                'open' => collect(),
                'closure' => null,
                'closed' => collect(),
            ]);
        }

        $schoolYear = $this->displayYear($institutionId, $grade, $section);
        $open = SchoolYearTransition::openLearners($institutionId, $schoolYear, $grade, $section);
        $closure = SchoolYearTransition::closureFor($institutionId, $schoolYear, $grade, $section);

        return view('adviser-dashboard.close-year', [
            'supported' => true,
            'schoolYear' => $schoolYear,
            'grade' => $grade,
            'section' => $section,
            'open' => $this->sortByName($open)->map(fn (StudentHealthRecord $record): array => $this->openRow($record, $grade))->values(),
            'closure' => $closure,
            'closed' => $closure === null
                ? collect()
                : $this->closedRows($institutionId, $schoolYear, $grade, $section),
        ]);
    }

    public function closeYear(Request $request): RedirectResponse
    {
        if ($redirect = $this->requireAdviser($request)) {
            return $redirect;
        }

        [$grade, $section, $institutionId] = $this->assignment($request);

        $validated = $request->validate([
            'school_year' => ['required', 'string', 'max:20'],
            'statuses' => ['required', 'array'],
            'statuses.*' => ['required', 'string', 'in:'.implode(',', array_keys(LearnerPlacement::END_STATUS_LABELS))],
        ], [
            'statuses.required' => 'Choose an end-of-year status for every learner.',
        ]);

        $schoolYear = SchoolYearTransition::closableYear($institutionId, $grade, $section);

        if ($validated['school_year'] !== $schoolYear) {
            return redirect()->route('dashboard.class-adviser.close-year')
                ->with('error', 'The section changed while the page was open. Check every status and submit again.');
        }

        try {
            $result = SchoolYearTransition::closeYear(
                $institutionId,
                $schoolYear,
                $grade,
                $section,
                array_map('strval', $validated['statuses']),
                (string) $request->session()->get('active_name', 'Class Adviser'),
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->route('dashboard.class-adviser.close-year')->with('error', $e->getMessage());
        }

        return redirect()->route('dashboard.class-adviser.close-year')->with(
            'success',
            'S.Y. '.$schoolYear.' closed for '.LearnerPlacement::sectionLabel($grade, $section).': '
                .$result['waiting'].' waiting for a section, '.$result['archived'].' archived.'
        );
    }

    /** Correct one learner's end-of-year status, until they are placed in a new section. */
    public function correctStatus(Request $request, StudentHealthRecord $record): RedirectResponse
    {
        if ($redirect = $this->requireAdviser($request)) {
            return $redirect;
        }

        [$grade, $section, $institutionId] = $this->assignment($request);

        if ((int) $record->institution_id !== $institutionId || ! LearnerPlacement::inSection($record, $grade, $section)) {
            abort(404);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', array_keys(LearnerPlacement::END_STATUS_LABELS))],
        ]);

        if (! SchoolYearTransition::canCorrect($record)) {
            return redirect()->route('dashboard.class-adviser.close-year')
                ->with('error', 'LRN '.$record->student_id.' has been placed in a new section; the school corrects the status now.');
        }

        try {
            SchoolYearTransition::correctStatus($record, $validated['status'], (string) $request->session()->get('active_name', 'Class Adviser'));
        } catch (InvalidArgumentException $e) {
            return redirect()->route('dashboard.class-adviser.close-year')->with('error', $e->getMessage());
        }

        return redirect()->route('dashboard.class-adviser.close-year')
            ->with('success', 'Status for LRN '.$record->student_id.' changed to '.LearnerPlacement::endStatusLabel($validated['status']).'.');
    }

    /** Mark as left school: a learner who transfers out or stops attending mid-year. */
    public function markLeft(Request $request, string $lrn): RedirectResponse
    {
        if ($redirect = $this->requireAdviser($request)) {
            return $redirect;
        }

        [, , $institutionId] = $this->assignment($request);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'in:'.implode(',', LearnerPlacement::LEFT_SCHOOL_REASONS)],
        ], [
            'reason.required' => 'Choose why the learner left the school.',
            'reason.in' => 'Choose why the learner left the school.',
        ]);

        $record = StudentHealthRecord::currentForStudent($lrn, $institutionId);

        if ($record === null || ! AdviserClassScope::coversRecord($request, $record)) {
            return redirect()->route('dashboard.class-adviser', ['tab' => 'saved'])
                ->with('error', 'Student not found in your assigned class.');
        }

        try {
            SchoolYearTransition::markLeft($record, $validated['reason'], (string) $request->session()->get('active_name', 'Class Adviser'));
        } catch (InvalidArgumentException $e) {
            return redirect()->route('dashboard.class-adviser.student-profile', $lrn)->with('error', $e->getMessage());
        }

        return redirect()->route('dashboard.class-adviser.archived')
            ->with('success', 'LRN '.$lrn.' marked as left school and archived.');
    }

    /** The adviser's Archived tab: the learners who left this adviser's own section. */
    public function archived(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->requireAdviser($request)) {
            return $redirect;
        }

        [$grade, $section, $institutionId] = $this->assignment($request);

        StudentRosterSync::syncToSession($request);

        $latest = LearnerPlacement::supported()
            ? LearnerPlacement::latestInState($institutionId, LearnerPlacement::ARCHIVED)
                ->filter(fn (object $row): bool => LearnerPlacement::inSection($row, $grade, $section))
            : collect();

        return view('adviser-dashboard.archived', [
            'supported' => LearnerPlacement::supported(),
            'grade' => $grade,
            'section' => $section,
            'rows' => $this->learnerRows($latest),
        ]);
    }

    /** Every archived and waiting learner at the school, read only — the nurse and the Clinic Teacher. */
    public function clinicArchived(Request $request): View|RedirectResponse
    {
        $role = (string) $request->session()->get('active_role', '');

        if (! in_array($role, self::CLINIC_ROLES, true)) {
            return redirect()->route('login')->with('error', 'You do not have access to that page.');
        }

        // The rail's pending count reads the session roster.
        StudentRosterSync::syncToSession($request);

        $institutionId = $request->session()->get('active_institution_id');
        $id = $institutionId ? (int) $institutionId : null;
        $supported = LearnerPlacement::supported();

        return view('dashboard.archived-students', [
            'supported' => $supported,
            'archived' => $supported ? $this->learnerRows(LearnerPlacement::latestInState($id, LearnerPlacement::ARCHIVED)) : collect(),
            'waiting' => $supported ? $this->learnerRows(LearnerPlacement::latestInState($id, LearnerPlacement::WAITING)) : collect(),
        ]);
    }

    // ── Reading ──────────────────────────────────────────────────────

    /**
     * Which of the section's school years the page shows: last year's while it
     * still has learners nobody closed, this year's while it has learners or
     * has been closed, otherwise last year's closed section — where a status
     * can still be corrected after the new year has begun.
     */
    private function displayYear(int $institutionId, string $grade, string $section): string
    {
        $current = StudentHealthRecord::currentSchoolYear();
        $previous = SchoolYearTransition::previousSchoolYear($current);

        if (SchoolYearTransition::openLearners($institutionId, $previous, $grade, $section)->isNotEmpty()) {
            return $previous;
        }

        if (SchoolYearTransition::openLearners($institutionId, $current, $grade, $section)->isNotEmpty()
            || SchoolYearTransition::closureFor($institutionId, $current, $grade, $section) !== null) {
            return $current;
        }

        return SchoolYearTransition::closureFor($institutionId, $previous, $grade, $section) !== null ? $previous : $current;
    }

    /** One learner still in the section, with what each status would do. */
    private function openRow(StudentHealthRecord $record, string $grade): array
    {
        [$rowGrade] = LearnerPlacement::sectionOf($record);
        $rowGrade = $rowGrade !== '' ? $rowGrade : $grade;
        $today = CarbonImmutable::now();

        $options = [];
        foreach (LearnerPlacement::endStatusOptions($rowGrade) as $status) {
            $options[$status] = [
                'label' => LearnerPlacement::endStatusLabel($status),
                'outcome' => LearnerPlacement::outcomeSentence($status, $rowGrade, $today),
            ];
        }

        return [
            'lrn' => (string) $record->student_id,
            'name' => LearnerPlacement::displayName(LearnerPlacement::nameOnFile($record)),
            'default' => LearnerPlacement::defaultEndStatus($rowGrade),
            'options' => $options,
        ];
    }

    /**
     * The learners who left a closed section that year, read only — their
     * name and the status they were given, and nothing about where they are
     * now beyond whether they have been placed.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function closedRows(int $institutionId, string $schoolYear, string $grade, string $section): Collection
    {
        $latest = LearnerPlacement::latestRows($institutionId);

        $rows = SchoolYearTransition::sectionRows($institutionId, $schoolYear, $grade, $section)
            ->reject(fn (StudentHealthRecord $record): bool => LearnerPlacement::isOpen($record));

        return $this->sortByName($rows)->map(function (StudentHealthRecord $record) use ($latest, $grade): array {
            $now = $latest[(string) $record->student_id] ?? null;
            $placed = $now !== null && (int) $now->id !== (int) $record->id;
            $status = (string) $record->end_status;
            [$rowGrade] = LearnerPlacement::sectionOf($record);
            $rowGrade = $rowGrade !== '' ? $rowGrade : $grade;

            $standing = match (true) {
                $placed => 'Placed in a new section',
                LearnerPlacement::isArchived($record) => 'Archived; deleted on '.($record->delete_after?->format('j F Y') ?? '—'),
                LearnerPlacement::isWaiting($record) => 'Waiting for '.($record->wait_grade ?: $rowGrade),
                default => '—',
            };

            return [
                'id' => (int) $record->id,
                'lrn' => (string) $record->student_id,
                'name' => LearnerPlacement::displayName(LearnerPlacement::nameOnFile($record)),
                'status' => $status,
                'status_label' => LearnerPlacement::endStatusLabel($status) ?: '—',
                'standing' => $standing,
                'can_correct' => ! $placed && $status !== '' && isset(LearnerPlacement::END_STATUS_LABELS[$status]),
                'options' => collect(LearnerPlacement::endStatusOptions($rowGrade))
                    ->mapWithKeys(fn (string $option): array => [$option => LearnerPlacement::endStatusLabel($option)])
                    ->all(),
            ];
        })->values();
    }

    /**
     * Archived or waiting learners for a list, from their latest rows: one
     * read of those rows for the names, which are encrypted.
     *
     * @param  Collection<string, object>  $latest
     * @return Collection<int, array<string, mixed>>
     */
    private function learnerRows(Collection $latest): Collection
    {
        if ($latest->isEmpty()) {
            return collect();
        }

        $records = StudentHealthRecord::query()
            ->whereIn('id', $latest->map(fn (object $row): int => (int) $row->id)->values()->all())
            ->get();

        return $this->sortByName($records)->map(function (StudentHealthRecord $record): array {
            [$grade, $section] = LearnerPlacement::sectionOf($record);
            $status = (string) $record->end_status;

            return [
                'lrn' => (string) $record->student_id,
                'name' => LearnerPlacement::displayName(LearnerPlacement::nameOnFile($record)),
                'section' => LearnerPlacement::sectionLabel($grade, $section),
                'school_year' => (string) $record->school_year,
                'reason' => LearnerPlacement::endStatusLabel($status) ?: (string) $record->archive_reason,
                'wait_grade' => (string) $record->wait_grade,
                'archived_at' => $record->archived_at,
                'left_at' => $record->left_at,
                'delete_after' => $record->delete_after,
                'view_url' => route('student-mlat.print', [
                    'lrn' => (string) $record->student_id,
                    'sheet' => 1,
                    'view' => 1,
                    'school_year' => (string) $record->school_year,
                ]),
            ];
        })->values();
    }

    /** @param  Collection<int, StudentHealthRecord>  $records */
    private function sortByName(Collection $records): Collection
    {
        // The names are encrypted, so the order is set here, never in SQL.
        return $records->sortBy(
            fn (StudentHealthRecord $record): string => mb_strtolower(LearnerPlacement::displayName(LearnerPlacement::nameOnFile($record))),
            SORT_NATURAL
        )->values();
    }

    // ── Guards ───────────────────────────────────────────────────────

    private function requireAdviser(Request $request): ?RedirectResponse
    {
        if ((string) $request->session()->get('active_role', '') !== 'class_adviser') {
            return redirect()->route('dashboard.class-adviser');
        }

        [$grade, $section, $institutionId] = $this->assignment($request);

        if ($grade === '' || $section === '' || $institutionId === 0) {
            return redirect()->route('dashboard.class-adviser', ['tab' => 'saved'])
                ->with('error', 'Your account has no assigned class.');
        }

        return null;
    }

    /** @return array{0: string, 1: string, 2: int} */
    private function assignment(Request $request): array
    {
        return [
            trim((string) $request->session()->get('assigned_grade_level', '')),
            trim((string) $request->session()->get('assigned_section', '')),
            (int) $request->session()->get('active_institution_id', 0),
        ];
    }
}
