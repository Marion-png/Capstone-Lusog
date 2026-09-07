<?php

namespace App\Http\Controllers;

use App\Models\Medicine;
use App\Support\MedicineCatalogue;
use App\Support\MedicineUsage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MedicineInventoryController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->requireClinicRole($request)) {
            return $redirect;
        }

        return view('dashboard.medicine-create');
    }

    public function index(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->requireClinicRole($request)) {
            return $redirect;
        }

        $institutionId = $request->session()->get('active_institution_id');
        $q = Medicine::query();
        if ($institutionId) {
            $q->where('institution_id', $institutionId);
        }
        $medicines = $q->orderBy('name')->get();
        $lowStockCount = $medicines
            ->filter(fn (Medicine $medicine) => $medicine->stock_quantity < $medicine->minimum_threshold)
            ->count();

        $forecastMedicine = $this->resolveForecastMedicine($medicines);
        $forecastUnit = $forecastMedicine?->unit ?? 'doses';
        $forecastStock = (int) ($forecastMedicine?->stock_quantity ?? 0);
        $minimumThreshold = (int) ($forecastMedicine?->minimum_threshold ?? 20);

        // Real consumption, read from the dispensing log. This used to be a
        // generator that invented six months of "seasonal" figures from a
        // hash of the medicine's name — a reorder quantity computed from
        // numbers nobody dispensed is worse than none, because it looks
        // like evidence. See App\Support\MedicineUsage.
        $monthlyUsage = $forecastMedicine
            ? MedicineUsage::monthlyFor((int) $forecastMedicine->id, $institutionId)
            : [];

        $usageSummary = $forecastMedicine
            ? MedicineUsage::summaryFor((int) $forecastMedicine->id, $institutionId)
            : ['this_month' => 0, 'average' => 0.0, 'total' => 0, 'months_of_history' => 0];

        // No dispensing on record means no rate, and no rate means no
        // recommendation. A target invented from an empty log would be the
        // same mistake in a different shape.
        $hasHistory = $usageSummary['months_of_history'] > 0;

        $recent = collect($monthlyUsage)->slice(-3)->pluck('used');
        $recentAverage = $recent->isEmpty() ? 0.0 : (float) $recent->avg();

        // A 20% safety buffer on the recent rate, floored at the clinic's
        // own reorder line so a quiet month cannot recommend running dry.
        $recommendedForNextMonth = $hasHistory
            ? max($minimumThreshold, (int) ceil($recentAverage * 1.2))
            : 0;
        $recommendedOrder = max(0, $recommendedForNextMonth - $forecastStock);

        $peak = collect($monthlyUsage)->sortByDesc('used')->first();
        $maxUsage = (int) ($peak['used'] ?? 0);

        return view('dashboard.medicine-inventory', [
            'medicines' => $medicines,
            'stats' => [
                'total' => $medicines->count(),
                'low' => $lowStockCount,
                'good' => $medicines->count() - $lowStockCount,
            ],
            // Usage per medicine for the Current Inventory table — one query
            // for the page, keyed by medicine id.
            'usage' => $medicines->mapWithKeys(fn (Medicine $m): array => [
                $m->id => MedicineUsage::summaryFor((int) $m->id, $institutionId),
            ]),
            'usage_months' => MedicineUsage::MONTHS,
            'prediction' => [
                'medicine_name' => $forecastMedicine?->name ?? 'Paracetamol',
                'has_history' => $hasHistory,
                'peak_month' => $peak['label'] ?? null,
                'average' => $usageSummary['average'],
                'this_month' => $usageSummary['this_month'],
                'months_of_cover' => $forecastMedicine
                    ? MedicineUsage::monthsOfCover($forecastMedicine, $institutionId)
                    : null,
                'unit' => $forecastUnit,
                'current_stock' => $forecastStock,
                'next_month' => now()->addMonth()->format('F'),
                'recommended_doses' => $recommendedForNextMonth,
                'recommended_order' => $recommendedOrder,
                'monthly_usage' => $monthlyUsage,
                'max_usage' => $maxUsage,
            ],
        ]);
    }

    private function resolveForecastMedicine($medicines): ?Medicine
    {
        if ($medicines->isEmpty()) {
            return null;
        }

        return $medicines
            ->sortBy(function (Medicine $medicine): float {
                $minimum = max(1, (int) $medicine->minimum_threshold);

                return (float) $medicine->stock_quantity / $minimum;
            })
            ->first();
    }

    public function store(Request $request): RedirectResponse
    {
        if ($redirect = $this->requireClinicRole($request)) {
            return $redirect;
        }

        // The name comes from the DepEd catalogue, or is typed as an
        // explicit off-list entry with a reason — see App\Support\MedicineCatalogue.
        $resolved = MedicineCatalogue::resolve(
            $request->input('catalogue_name'),
            $request->input('custom_name'),
        );

        $request->merge(['name' => $resolved['name']]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:medicines,name'],
            // A reason is required only when the medicine is off the list.
            // Refusing an off-list entry outright would not stop the practice,
            // it would only stop the stock record being true.
            'off_catalogue_reason' => [
                $resolved['off_catalogue'] ? 'required' : 'nullable',
                'string', 'max:255',
            ],
            'stock_quantity' => ['required', 'integer', 'min:0'],
            'minimum_threshold' => ['required', 'integer', 'min:0'],
            'unit' => ['required', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        Medicine::create([
            ...$validated,
            'off_catalogue' => $resolved['off_catalogue'],
            'institution_id' => $request->session()->get('active_institution_id'),
        ]);

        return redirect()
            ->route('dashboard.medicine-inventory')
            ->with('success', 'Medicine added to inventory.');
    }

    /**
     * Redirects a non-nurse/clinic-staff session to its own dashboard
     * instead of letting it view or write medicine inventory data.
     */
    private function requireClinicRole(Request $request): ?RedirectResponse
    {
        $role = (string) $request->session()->get('active_role', '');
        if (in_array($role, ['school_nurse', 'clinic_staff', 'system_admin'], true)) {
            return null;
        }

        $redirectByRole = [
            'class_adviser' => 'dashboard.class-adviser',
            'school_head' => 'dashboard.school-head',
            'feeding_coor' => 'dashboard.feedingcor-dashboard',
            'nutricor' => 'dashboard.nutricor-dashboard',
            'system_admin' => 'dashboard.system-admin',
        ];

        return redirect()->route($redirectByRole[$role] ?? 'login');
    }
}
