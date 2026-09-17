<?php

namespace App\Http\Controllers;

use App\Models\Medicine;
use App\Models\MedicineReceipt;
use App\Support\MedicineCatalogue;
use App\Support\MedicineUsage;
use App\Support\SchemaCache;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        // Expiry, judged per item off the earliest date on hand. An item with
        // no stock is not counted — an empty shelf cannot expire — and an item
        // with no date on file is unknown, not fine, so it is neither.
        $stocked = $medicines->filter(fn (Medicine $medicine) => $medicine->stock_quantity > 0);
        $expiredCount = $stocked->filter(fn (Medicine $m) => $m->expiryStatus() === Medicine::EXPIRY_EXPIRED)->count();
        $expiringCount = $stocked->filter(fn (Medicine $m) => $m->expiryStatus() === Medicine::EXPIRY_SOON)->count();

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
                'expired' => $expiredCount,
                'expiring' => $expiringCount,
            ],
            'expiry_warning_days' => Medicine::EXPIRY_WARNING_DAYS,
            'supports_expiry' => Medicine::supportsExpiry(),
            'supports_receipts' => SchemaCache::hasTable('medicine_receipts'),
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
            // The earliest expiry of the stock being entered. Optional: an
            // item created with no stock has nothing to expire.
            'expiry_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        if (! Medicine::supportsExpiry()) {
            unset($validated['expiry_date']);
        }

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
     * A delivery: stock comes in.
     *
     * The logbook's other column. Until this existed a level could only be
     * typed at creation and drawn down by consultations, so the first box to
     * arrive put the record out of step with the shelf. The receipt row and
     * the increment are written in one transaction, holding the medicine row
     * locked exactly as a dispense does, so a delivery and an issue landing
     * together cannot both read the same level.
     *
     * The item's expiry becomes the earliest date on hand: a fresh delivery
     * onto an empty shelf sets it, a delivery onto existing stock keeps the
     * sooner of the two — the older box is used first and is still the one
     * the item is judged by.
     */
    public function receive(Request $request, Medicine $medicine): RedirectResponse
    {
        if ($redirect = $this->requireClinicRole($request)) {
            return $redirect;
        }

        $institutionId = $request->session()->get('active_institution_id');

        // Re-scoped to the school: an id off the wire decides nothing.
        if ($institutionId && (int) $medicine->institution_id !== (int) $institutionId) {
            abort(404);
        }

        if (! SchemaCache::hasTable('medicine_receipts') || ! Medicine::supportsExpiry()) {
            return redirect()
                ->route('dashboard.medicine-inventory')
                ->with('error', 'Receiving stock is not available until the database has been updated.');
        }

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            // Already-expired stock has no business on the shelf; refusing it
            // here is what keeps the count a count of usable medicine.
            'expiry_date' => ['nullable', 'date', 'after:today'],
            'received_at' => ['nullable', 'date', 'before_or_equal:today'],
            'source' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($request, $medicine, $validated, $institutionId): void {
            $locked = Medicine::query()->lockForUpdate()->findOrFail($medicine->id);

            $newExpiry = ! empty($validated['expiry_date'])
                ? Carbon::parse($validated['expiry_date'])->startOfDay()
                : null;

            $current = $locked->expiry_date?->copy()->startOfDay();

            // Nothing on the shelf, or a date already past: the delivery's
            // date is the shelf's date. Otherwise the earlier of the two.
            $shelfEmpty = (int) $locked->stock_quantity === 0
                || ($current !== null && $current->lte(now()->startOfDay()));

            $expiry = match (true) {
                $newExpiry === null => $shelfEmpty ? null : $current,
                $shelfEmpty, $current === null => $newExpiry,
                default => $newExpiry->lt($current) ? $newExpiry : $current,
            };

            MedicineReceipt::create([
                'institution_id' => $institutionId,
                'medicine_id' => $locked->id,
                'quantity' => (int) $validated['quantity'],
                'expiry_date' => $newExpiry?->toDateString(),
                'received_at' => ! empty($validated['received_at'])
                    ? Carbon::parse($validated['received_at'])->toDateString()
                    : now()->toDateString(),
                'source' => $validated['source'] ?? null,
                'notes' => $validated['notes'] ?? null,
                // Attribution is the app's, not the form's.
                'received_by_name' => (string) $request->session()->get('active_name', ''),
                'received_by_role' => (string) $request->session()->get('active_role', ''),
            ]);

            $locked->forceFill([
                'stock_quantity' => (int) $locked->stock_quantity + (int) $validated['quantity'],
                'expiry_date' => $expiry?->toDateString(),
            ])->save();
        });

        return redirect()
            ->route('dashboard.medicine-inventory')
            ->with('success', 'Received '.(int) $validated['quantity'].' '.$medicine->unit.' of '.$medicine->name.'.');
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
