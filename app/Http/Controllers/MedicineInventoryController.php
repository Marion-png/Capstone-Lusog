<?php

namespace App\Http\Controllers;

use App\Models\Medicine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MedicineInventoryController extends Controller
{
    public function index(): View
    {
        $medicines = Medicine::query()->orderBy('name')->get();
        $lowStockCount = $medicines
            ->filter(fn (Medicine $medicine) => $medicine->stock_quantity < $medicine->minimum_threshold)
            ->count();

        $paracetamol = $medicines->first(fn (Medicine $medicine) => strcasecmp($medicine->name, 'Paracetamol') === 0);
        $paracetamolUnit = $paracetamol?->unit ?? 'doses';
        $paracetamolStock = $paracetamol?->stock_quantity ?? 0;
        $minimumThreshold = $paracetamol?->minimum_threshold ?? 20;

        // Prototype dataset until dispensing transactions are persisted.
        $monthlyUsage = [
            ['month' => 'Aug', 'used' => 205],
            ['month' => 'Sep', 'used' => 218],
            ['month' => 'Oct', 'used' => 224],
            ['month' => 'Nov', 'used' => 237],
            ['month' => 'Dec', 'used' => 256],
            ['month' => 'Jan', 'used' => 372],
        ];

        $januaryUsage = collect($monthlyUsage)->firstWhere('month', 'Jan')['used'];
        $recommendedForNextMonth = max(
            $minimumThreshold,
            (int) ceil($januaryUsage * 1.2)
        );
        $recommendedOrder = max(0, $recommendedForNextMonth - $paracetamolStock);
        $maxUsage = max(array_column($monthlyUsage, 'used'));

        return view('dashboard.medicine-inventory', [
            'medicines' => $medicines,
            'stats' => [
                'total' => $medicines->count(),
                'low' => $lowStockCount,
                'good' => $medicines->count() - $lowStockCount,
            ],
            'prediction' => [
                'medicine_name' => $paracetamol?->name ?? 'Paracetamol',
                'unit' => $paracetamolUnit,
                'current_stock' => $paracetamolStock,
                'next_month' => 'February',
                'recommended_doses' => $recommendedForNextMonth,
                'recommended_order' => $recommendedOrder,
                'monthly_usage' => $monthlyUsage,
                'max_usage' => $maxUsage,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:medicines,name'],
            'stock_quantity' => ['required', 'integer', 'min:0'],
            'minimum_threshold' => ['required', 'integer', 'min:0'],
            'unit' => ['required', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        Medicine::create($validated);

        return redirect()
            ->route('dashboard.medicine-inventory')
            ->with('success', 'Medicine added to inventory.');
    }
}
