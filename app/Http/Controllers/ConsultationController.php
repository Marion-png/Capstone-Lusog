<?php

namespace App\Http\Controllers;

use App\Models\Condition;
use App\Models\Consultation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConsultationController extends Controller
{
    public function index(Request $request): View
    {
        $institutionId = $request->session()->get('active_institution_id');

        $baseQuery = function () use ($institutionId) {
            $q = Consultation::query();
            if ($institutionId) {
                $q->where('institution_id', $institutionId);
            }

            return $q;
        };

        $consultations = $baseQuery()
            ->latest('consulted_at')
            ->latest('id')
            ->paginate(10);

        // condition is encrypted at rest, so grouping happens in PHP after decryption.
        $topConditionStats = $baseQuery()
            ->whereMonth('consulted_at', now()->month)
            ->whereYear('consulted_at', now()->year)
            ->pluck('condition')
            ->map(fn ($condition) => strtolower((string) $condition))
            ->filter()
            ->countBy()
            ->sortDesc()
            ->take(7)
            ->map(fn ($total, $conditionName) => (object) ['condition_name' => $conditionName, 'total' => $total])
            ->values();

        $weekStart = now()->startOfWeek();
        $dailyTrend = collect(range(0, 6))->map(function (int $offset) use ($weekStart, $baseQuery): array {
            $day = $weekStart->copy()->addDays($offset);

            return [
                'label' => $day->format('D'),
                'count' => $baseQuery()
                    ->whereDate('consulted_at', $day->toDateString())
                    ->count(),
            ];
        });

        return view('dashboard.consultation-log', [
            'consultations' => $consultations,
            'stats' => [
                'total' => $baseQuery()->count(),
                'month' => $baseQuery()->whereMonth('consulted_at', now()->month)->whereYear('consulted_at', now()->year)->count(),
                'week' => $baseQuery()->whereBetween('consulted_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
                'today' => $baseQuery()->whereDate('consulted_at', now()->toDateString())->count(),
                'referrals' => $baseQuery()->where('status', 'referred')->count(),
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
            'condition_id' => ['nullable', 'integer', 'exists:conditions,id'],
            'condition' => ['nullable', 'string', 'max:255'],
            'treatment_given' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', 'in:treated,referred'],
        ]);

        // Ensure at least one condition source is provided
        $conditionId = $validated['condition_id'] ?? null;
        $conditionText = $validated['condition'] ?? null;

        if (! $conditionId && ! $conditionText) {
            return back()
                ->withErrors(['condition' => 'Please select or enter a condition.'])
                ->withInput();
        }

        // If condition_id is provided, fetch the condition name
        $conditionName = $conditionText;
        if ($conditionId) {
            $condition = Condition::find($conditionId);
            if ($condition) {
                $conditionName = $condition->name;
            }
        }

        Consultation::create([
            'institution_id' => $request->session()->get('active_institution_id'),
            'consulted_at' => $validated['consulted_at'],
            'student_name' => $validated['student_name'],
            'grade_section' => $validated['grade_section'],
            'condition' => $conditionName,
            'condition_id' => $conditionId,
            'treatment_given' => $validated['treatment_given'],
            'status' => $validated['status'],
        ]);

        return redirect()
            ->route('dashboard.consultation-log')
            ->with('success', 'Consultation saved successfully.');
    }
}
