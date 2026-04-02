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

        return view('dashboard.consultation-log', [
            'consultations' => $consultations,
            'stats' => [
                'total' => Consultation::count(),
                'month' => Consultation::whereMonth('consulted_at', now()->month)
                    ->whereYear('consulted_at', now()->year)
                    ->count(),
                'week' => Consultation::whereBetween('consulted_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
                'referrals' => Consultation::where('status', 'referred')->count(),
            ],
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
