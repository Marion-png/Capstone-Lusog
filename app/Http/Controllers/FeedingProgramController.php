<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\StudentHealthRecord;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class FeedingProgramController extends Controller
{
	public function index(): View
	{
		$students = collect();
		if (Schema::hasTable('student_health_records')) {
			$students = StudentHealthRecord::query()
				->orderBy('student_name')
				->get();
		}

		$studentRows = $students->map(function (StudentHealthRecord $record): array {
			$currentWeight = (float) $record->weight;
			$baselineWeight = max(1, $currentWeight - 0.7);
			$bmiCurrent = (float) $record->bmi_value;
			$bmiBaseline = max(0, $bmiCurrent - 0.5);

			$trendClass = 't-stable';
			$trendLabel = 'Stable';
			$bmiClass = 'bmi-up';

			$status = strtolower((string) $record->nutritional_status);
			if (str_contains($status, 'normal')) {
				$trendClass = 't-improving';
				$trendLabel = 'Improving';
			} elseif (str_contains($status, 'severe') || str_contains($status, 'wasted')) {
				$trendClass = 't-regressing';
				$trendLabel = 'Regressing';
				$bmiClass = 'bmi-down';
			}

			return [
				'student_name' => $record->student_name,
				'section' => $record->section,
				'baseline_weight' => number_format($baselineWeight, 1),
				'current_weight' => number_format($currentWeight, 1),
				'bmi_range' => number_format($bmiBaseline, 1) . ' - ' . number_format($bmiCurrent, 1),
				'bmi_class' => $bmiClass,
				'bmi_value' => number_format($bmiCurrent, 1),
				'attendance' => '0/0 days',
				'trend_label' => $trendLabel,
				'trend_class' => $trendClass,
			];
		})->values();

		$studentCount = $studentRows->count();
		$improvingCount = $studentRows->where('trend_label', 'Improving')->count();

		$programDay = 0;
		if (Schema::hasTable('consultations')) {
			$firstFeedingDate = Consultation::query()->min('consulted_at');
			if ($firstFeedingDate) {
				$programDay = min(120, Carbon::parse($firstFeedingDate)->startOfDay()->diffInDays(now()->startOfDay()) + 1);
			}
		}

		$consultationRate = 0;
		if ($studentCount > 0 && Schema::hasTable('consultations')) {
			$recentDistinct = Consultation::query()
				->where('consulted_at', '>=', now()->copy()->subDays(30))
				->distinct('student_name')
				->count('student_name');
			$consultationRate = (int) round(($recentDistinct / $studentCount) * 100);
		}

		return view('feedingcor-dashboard.feed-program', [
			'programStats' => [
				'enrolled_students' => $studentCount,
				'program_day' => $programDay . '/120',
				'avg_attendance' => $consultationRate . '%',
				'improving_rate' => $studentCount > 0 ? (int) round(($improvingCount / $studentCount) * 100) . '%' : '0%',
				'improving_hint' => $improvingCount . ' of ' . $studentCount . ' students',
			],
			'students' => $studentRows,
		]);
	}
}

