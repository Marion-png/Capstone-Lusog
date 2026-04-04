<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\StudentHealthRecord;
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
                ->get()
                ->map(function (StudentHealthRecord $record): array {
                    $currentWeight = (float) $record->weight;
                    $baselineWeight = max(1, $currentWeight - 0.7);
                    $bmiCurrent = (float) $record->bmi_value;
                    $bmiBaseline = max(0, $bmiCurrent - 0.5);

                    $trendClass = 't-stable';
                    $trendLabel = 'stable';
                    $bmiClass = 'bmi-up';

                    $status = strtolower((string) $record->nutritional_status);
                    if (str_contains($status, 'normal')) {
                        $trendClass = 't-improving';
                        $trendLabel = 'improving';
                    } elseif (str_contains($status, 'severely') || str_contains($status, 'wasted')) {
                        $trendClass = 't-regressing';
                        $trendLabel = 'regressing';
                        $bmiClass = 'bmi-down';
                    }

                    return [
                        'student_name' => $record->student_name,
                        'section' => $record->section,
                        'baseline_weight' => number_format($baselineWeight, 1),
                        'current_weight' => number_format($currentWeight, 1),
                        'bmi_range' => number_format($bmiBaseline, 1) . ' - ' . number_format($bmiCurrent, 1),
                        'bmi_class' => $bmiClass,
                        'attendance' => '0/67 days',
                        'trend_label' => $trendLabel,
                        'trend_class' => $trendClass,
                    ];
                });
        }

        $consultationCount = Schema::hasTable('consultations')
            ? Consultation::query()->count()
            : 0;

        $studentCount = $students->count();
        $improvingCount = $students->where('trend_label', 'improving')->count();
        $improvingRate = $studentCount > 0
            ? round(($improvingCount / $studentCount) * 100)
            : 0;
        $attendanceRate = $studentCount > 0
            ? min(100, round(($consultationCount / $studentCount) * 100))
            : 0;

        return view('feedingcor-dashboard.feed-program', [
            'programStats' => [
                'enrolled_students' => $studentCount,
                'program_day' => '67/120',
                'avg_attendance' => $attendanceRate . '%',
                'improving_rate' => $improvingRate . '%',
                'improving_hint' => $improvingCount . ' of ' . $studentCount . ' students',
            ],
            'students' => $students,
        ]);
    }
}
