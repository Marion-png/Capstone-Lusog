<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\FeedingAttendance;
use App\Models\StudentHealthRecord;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class FeedingProgramController extends Controller
{
	private const PROGRAM_DURATION_DAYS = 120;
	private const AT_RISK_THRESHOLD_PERCENT = 75;

	public function index(Request $request): View
	{
		$activeRole = (string) $request->session()->get('active_role', '');
		$currentRouteName = (string) optional($request->route())->getName();
		$isNurseFeedingRoute = $currentRouteName === 'dashboard.school-nurse.feeding-program';
		$isReadOnly = $isNurseFeedingRoute || $activeRole === 'school_nurse';

		$hasSchoolColumn = Schema::hasTable('student_health_records')
			&& Schema::hasColumn('student_health_records', 'school_name');
		$selectedSchool = trim((string) $request->query('school', 'all'));
		if ($selectedSchool === '') {
			$selectedSchool = 'all';
		}

		$schoolOptions = collect();
		if ($hasSchoolColumn) {
			$schoolOptions = StudentHealthRecord::query()
				->select('school_name')
				->whereNotNull('school_name')
				->where('school_name', '!=', '')
				->distinct()
				->orderBy('school_name')
				->pluck('school_name')
				->values();

			if ($selectedSchool !== 'all' && !$schoolOptions->contains($selectedSchool)) {
				$selectedSchool = 'all';
			}
		}

		$students = collect();
		if (Schema::hasTable('student_health_records')) {
			$studentsQuery = StudentHealthRecord::query();
			if ($hasSchoolColumn && $selectedSchool !== 'all') {
				$studentsQuery->where('school_name', $selectedSchool);
			}

			$students = $studentsQuery
				->orderBy('student_name')
				->get()
				->filter(fn (StudentHealthRecord $record): bool => $this->isAttendanceEligible($record->nutritional_status))
				->values();
		}

		$programDay = $this->resolveProgramDay();
		$atRiskThresholdCount = $programDay > 0
			? (int) ceil($programDay * (self::AT_RISK_THRESHOLD_PERCENT / 100))
			: 0;

		$studentRows = $students->map(function (StudentHealthRecord $record) use ($programDay): array {
			$currentWeight = (float) $record->weight;
			$baselineWeight = $record->baseline_weight_kg !== null
				? (float) $record->baseline_weight_kg
				: max(1, $currentWeight - 0.7);
			$bmiCurrent = (float) $record->bmi_value;
			$bmiBaseline = $record->baseline_bmi_value !== null
				? (float) $record->baseline_bmi_value
				: max(0, $bmiCurrent - 0.5);
			$resolvedStatus = $this->normalizeNutritionalStatus($record->nutritional_status, $bmiCurrent);

			$trendClass = 't-stable';
			$trendLabel = 'Stable';
			$bmiClass = 'bmi-up';

			$status = strtolower((string) $resolvedStatus);
			$isAttendanceEligible = $this->isAttendanceEligible($resolvedStatus);
			if (str_contains($status, 'normal')) {
				$trendClass = 't-improving';
				$trendLabel = 'Improving';
			} elseif (str_contains($status, 'severe') || str_contains($status, 'wasted') || str_contains($status, 'underweight')) {
				$trendClass = 't-regressing';
				$trendLabel = 'Regressing';
				$bmiClass = 'bmi-down';
			}

			$attendanceCount = (int) ($record->attendance_sessions_count ?? 0);
			$expectedAttendance = max(1, $programDay);
			$attendancePercent = $programDay > 0
				? (int) round(($attendanceCount / $expectedAttendance) * 100)
				: 0;

			return [
				'id' => $record->id,
				'student_name' => $record->student_name,
				'section' => $record->section,
				'baseline_weight' => number_format($baselineWeight, 1),
				'current_weight' => number_format($currentWeight, 1),
				'bmi_range' => number_format($bmiBaseline, 1) . ' - ' . number_format($bmiCurrent, 1),
				'bmi_class' => $bmiClass,
				'bmi_value' => number_format($bmiCurrent, 1),
				'attendance' => $attendanceCount . '/' . self::PROGRAM_DURATION_DAYS . ' days',
				'attendance_count' => $attendanceCount,
				'attendance_percent' => $attendancePercent,
				'nutritional_status' => $resolvedStatus,
				'is_attendance_eligible' => $isAttendanceEligible,
				'is_at_risk' => (bool) $record->is_at_risk,
				'trend_label' => $trendLabel,
				'trend_class' => $trendClass,
			];
		})->values();

		$studentCount = $studentRows->count();
		$improvingCount = $studentRows->where('trend_label', 'Improving')->count();
		$totalPresentAttendance = (int) $studentRows->sum('attendance_count');
		$maxPossibleAttendance = max(1, $studentCount * max(1, $programDay));
		$attendanceRate = $programDay > 0
			? (int) round(($totalPresentAttendance / $maxPossibleAttendance) * 100)
			: 0;

		$atRiskStudents = $studentRows
			->filter(fn (array $student): bool => (bool) ($student['is_at_risk'] ?? false))
			->values();

		return view('feedingcor-dashboard.feed-program', [
			'isReadOnly' => $isReadOnly,
			'programStats' => [
				'enrolled_students' => $studentCount,
				'program_day' => $programDay . '/' . self::PROGRAM_DURATION_DAYS,
				'avg_attendance' => $attendanceRate . '%',
				'improving_rate' => $studentCount > 0 ? (int) round(($improvingCount / $studentCount) * 100) . '%' : '0%',
				'improving_hint' => $improvingCount . ' of ' . $studentCount . ' students',
				'at_risk_count' => $atRiskStudents->count(),
				'at_risk_threshold' => self::AT_RISK_THRESHOLD_PERCENT,
				'at_risk_threshold_count' => $atRiskThresholdCount,
			],
			'students' => $studentRows,
			'atRiskStudents' => $atRiskStudents,
			'schoolOptions' => $schoolOptions,
			'selectedSchool' => $selectedSchool,
			'hasSchoolColumn' => $hasSchoolColumn,
		]);
	}

	public function storeAttendance(Request $request): RedirectResponse
	{
		$activeRole = strtolower(trim((string) $request->session()->get('active_role', '')));
		$allowedCoordinatorRoles = ['feeding_coor', 'feedingcoor', 'feeding_coordinator', 'feeding coordinator'];
		if (!in_array($activeRole, $allowedCoordinatorRoles, true)) {
			$redirectRoute = $activeRole === 'school_nurse'
				? 'dashboard.school-nurse.feeding-program'
				: 'login';

			return redirect()
				->route($redirectRoute)
				->with('error', 'You have view-only access to Feeding Program attendance.');
		}

		$request->validate([
			'session_date' => ['required', 'date', 'before_or_equal:today'],
			'present_student_ids' => ['nullable', 'array'],
			'present_student_ids.*' => ['integer', 'exists:student_health_records,id'],
			'school' => ['nullable', 'string', 'max:255'],
		]);

		$sessionDate = Carbon::parse((string) $request->input('session_date'))->toDateString();
		$presentIds = collect($request->input('present_student_ids', []))
			->map(fn ($value) => (int) $value)
			->unique()
			->values();

		if (!Schema::hasTable('student_health_records') || !Schema::hasTable('feeding_attendances')) {
			return back()->with('error', 'Attendance tracking tables are not ready. Run migrations first.');
		}

		$hasSchoolColumn = Schema::hasColumn('student_health_records', 'school_name');
		$selectedSchool = trim((string) $request->input('school', 'all'));
		if ($selectedSchool === '') {
			$selectedSchool = 'all';
		}

		$studentsQuery = StudentHealthRecord::query();
		if ($hasSchoolColumn && $selectedSchool !== 'all') {
			$studentsQuery->where('school_name', $selectedSchool);
		}

		$students = $studentsQuery->get(['id', 'nutritional_status', 'bmi_value']);
		$students = $students
			->filter(fn (StudentHealthRecord $student): bool => $this->isAttendanceEligible($this->normalizeNutritionalStatus($student->nutritional_status, $student->bmi_value)))
			->values();

		if ($students->isEmpty()) {
			return back()->with('error', 'No eligible beneficiaries (Wasted/Severely Wasted/Underweight) available to record attendance.');
		}

		$allowedStudentIds = $students->pluck('id')->all();
		$presentIds = $presentIds
			->filter(fn (int $id): bool => in_array($id, $allowedStudentIds, true))
			->values();

		DB::transaction(function () use ($students, $presentIds, $sessionDate): void {
			$presentLookup = $presentIds->flip();
			$now = now();
			$rows = [];

			foreach ($students as $student) {
				$rows[] = [
					'student_health_record_id' => $student->id,
					'session_date' => $sessionDate,
					'is_present' => $presentLookup->has($student->id),
					'created_at' => $now,
					'updated_at' => $now,
				];
			}

			FeedingAttendance::query()->upsert(
				$rows,
				['student_health_record_id', 'session_date'],
				['is_present', 'updated_at']
			);

			$this->refreshAttendanceRiskFlags();
		});

		$schoolSuffix = $hasSchoolColumn && $selectedSchool !== 'all'
			? ' for ' . $selectedSchool
			: '';

		return redirect()
			->route('dashboard.feedingcor-program', ['school' => $selectedSchool])
			->with('success', 'Attendance for ' . Carbon::parse($sessionDate)->format('M d, Y') . $schoolSuffix . ' was recorded successfully.');
	}

	private function isAttendanceEligible(?string $nutritionalStatus): bool
	{
		$status = strtolower((string) $nutritionalStatus);
		$status = preg_replace('/\s+/', ' ', trim($status)) ?? '';

		return $status === 'wasted'
			|| $status === 'severely wasted'
			|| $status === 'severly wasted'
			|| $status === 'underweight';
	}

	private function normalizeNutritionalStatus(?string $nutritionalStatus, ?float $bmi): string
	{
		$status = trim((string) $nutritionalStatus);
		$normalized = strtolower($status);

		if (str_contains($normalized, 'severe')) {
			return 'Severely Wasted';
		}
		if (str_contains($normalized, 'wast')) {
			return 'Wasted';
		}
		if (str_contains($normalized, 'underweight')) {
			return 'Underweight';
		}
		if (str_contains($normalized, 'over')) {
			return 'Overweight';
		}

		if ($bmi !== null) {
			$bmiValue = (float) $bmi;
			if ($bmiValue < 16.0) {
				return 'Severely Wasted';
			}
			if ($bmiValue < 17.0) {
				return 'Wasted';
			}
			if ($bmiValue < 18.5) {
				return 'Underweight';
			}
			if ($bmiValue >= 25.0) {
				return 'Overweight';
			}
		}

		return $status !== '' ? $status : 'Normal';
	}

	private function resolveProgramDay(): int
	{
		$programDay = 0;
		$todayDate = now()->toDateString();

		if (Schema::hasTable('feeding_attendances')) {
			$firstAttendanceDate = FeedingAttendance::query()
				->whereDate('session_date', '<=', $todayDate)
				->min('session_date');
			if ($firstAttendanceDate) {
				$programDay = min(self::PROGRAM_DURATION_DAYS, Carbon::parse($firstAttendanceDate)->startOfDay()->diffInDays(now()->startOfDay()) + 1);
			}
		}

		if ($programDay === 0 && Schema::hasTable('consultations')) {
			$firstFeedingDate = Consultation::query()->min('consulted_at');
			if ($firstFeedingDate) {
				$programDay = min(self::PROGRAM_DURATION_DAYS, Carbon::parse($firstFeedingDate)->startOfDay()->diffInDays(now()->startOfDay()) + 1);
			}
		}

		return $programDay;
	}

	private function refreshAttendanceRiskFlags(): void
	{
		$programDay = $this->resolveProgramDay();
		$thresholdCount = $programDay > 0
			? (int) ceil($programDay * (self::AT_RISK_THRESHOLD_PERCENT / 100))
			: 0;
		$todayDate = now()->toDateString();

		$presentCounts = FeedingAttendance::query()
			->selectRaw('student_health_record_id, SUM(CASE WHEN is_present = 1 THEN 1 ELSE 0 END) as present_count')
			->whereDate('session_date', '<=', $todayDate)
			->groupBy('student_health_record_id')
			->pluck('present_count', 'student_health_record_id');

		StudentHealthRecord::query()->each(function (StudentHealthRecord $record) use ($presentCounts, $thresholdCount, $programDay): void {
			$normalizedStatus = $this->normalizeNutritionalStatus($record->nutritional_status, $record->bmi_value);
			if (!$this->isAttendanceEligible($normalizedStatus)) {
				$record->update([
					'is_at_risk' => false,
				]);

				return;
			}

			$attendanceCount = (int) ($presentCounts[$record->id] ?? 0);
			$isAtRisk = $programDay > 0 && $attendanceCount < $thresholdCount;

			$record->update([
				'attendance_sessions_count' => $attendanceCount,
				'is_at_risk' => $isAtRisk,
			]);
		});
	}
}

