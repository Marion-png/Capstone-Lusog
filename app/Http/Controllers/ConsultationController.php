<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConsultationController extends Controller
{
    public function index(): View
    {
        $consultations = Consultation::query()
            ->latest('consulted_at')
            ->latest('id')
            ->paginate(10);

        $topConditionStats = Consultation::query()
            ->selectRaw('LOWER(condition) as condition_name, COUNT(*) as total')
            ->whereMonth('consulted_at', now()->month)
            ->whereYear('consulted_at', now()->year)
            ->groupBy('condition_name')
            ->orderByDesc('total')
            ->limit(7)
            ->get();

        $weekStart = now()->startOfWeek();
        $dailyTrend = collect(range(0, 6))->map(function (int $offset) use ($weekStart): array {
            $day = $weekStart->copy()->addDays($offset);

            return [
                'label' => $day->format('D'),
                'count' => Consultation::query()
                    ->whereDate('consulted_at', $day->toDateString())
                    ->count(),
            ];
        });

        return view('dashboard.consultation-log', [
            'consultations' => $consultations,
            'stats' => [
                'total' => Consultation::count(),
                'month' => Consultation::whereMonth('consulted_at', now()->month)
                    ->whereYear('consulted_at', now()->year)
                    ->count(),
                'week' => Consultation::whereBetween('consulted_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
                'today' => Consultation::whereDate('consulted_at', now()->toDateString())->count(),
                'referrals' => Consultation::where('status', 'referred')->count(),
            ],
            'topConditionStats' => $topConditionStats,
            'dailyTrend' => $dailyTrend,
        ]);
    }

    public function create(): View
    {
        return view('dashboard.consultation-create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'consulted_at' => ['required', 'date'],
            'student_name' => ['required', 'string', 'max:255'],
            'grade_section' => ['required', 'string', 'max:255'],
            'condition' => ['required', 'string', 'max:255'],
            'treatment_given' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', 'in:treated,referred'],
        ]);

        Consultation::create($validated);

        return redirect()
            ->route('dashboard.consultation-log')
            ->with('success', 'Consultation saved successfully.');
    }
}
