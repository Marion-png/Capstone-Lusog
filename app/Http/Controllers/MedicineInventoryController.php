<?php

namespace App\Http\Controllers;

use App\Models\Medicine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MedicineInventoryController extends Controller
{
    public function create(): View
    {
        return view('dashboard.medicine-create');
    }

    public function index(): View
    {
        $medicines = Medicine::query()->orderBy('name')->get();
        $lowStockCount = $medicines
            ->filter(fn (Medicine $medicine) => $medicine->stock_quantity < $medicine->minimum_threshold)
            ->count();

        $forecastMedicine = $this->resolveForecastMedicine($medicines);
        $forecastUnit = $forecastMedicine?->unit ?? 'doses';
        $forecastStock = (int) ($forecastMedicine?->stock_quantity ?? 0);
        $minimumThreshold = (int) ($forecastMedicine?->minimum_threshold ?? 20);

        // Functional dummy forecast until dispensing transactions are persisted.
        $monthlyUsage = $this->buildDummyMonthlyUsage(
            (string) ($forecastMedicine?->name ?? 'Paracetamol'),
            $minimumThreshold
        );

        $lastThreeAverage = (float) collect($monthlyUsage)
            ->slice(-3)
            ->avg(fn (array $item): int => (int) ($item['used'] ?? 0));
        $januaryUsage = (int) (collect($monthlyUsage)->firstWhere('month', 'Jan')['used'] ?? 0);

        $recommendedForNextMonth = max(
            $minimumThreshold,
            (int) ceil(max($lastThreeAverage * 1.15, $januaryUsage * 1.1))
        );
        $recommendedOrder = max(0, $recommendedForNextMonth - $forecastStock);
        $maxUsage = max(array_column($monthlyUsage, 'used'));

        return view('dashboard.medicine-inventory', [
            'medicines' => $medicines,
            'stats' => [
                'total' => $medicines->count(),
                'low' => $lowStockCount,
                'good' => $medicines->count() - $lowStockCount,
            ],
            'prediction' => [
                'medicine_name' => $forecastMedicine?->name ?? 'Paracetamol',
                'unit' => $forecastUnit,
                'current_stock' => $forecastStock,
                'next_month' => 'February',
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

    private function buildDummyMonthlyUsage(string $medicineName, int $minimumThreshold): array
    {
        $months = ['Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'Jan'];
        $seasonalFactors = [0.84, 0.90, 0.95, 1.00, 1.08, 1.30];
        $seed = abs((int) crc32(strtolower(trim($medicineName))));
        $baseline = max(18, (int) round(max(1, $minimumThreshold) * 1.4));

        $usage = [];
        foreach ($months as $index => $month) {
            $jitter = (($seed >> ($index * 3)) & 7) - 3;
            $value = (int) round(($baseline * $seasonalFactors[$index]) + ($jitter * 2));

            $usage[] = [
                'month' => $month,
                'used' => max(8, $value),
            ];
        }

        return $usage;
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
