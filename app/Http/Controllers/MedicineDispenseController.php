<?php

namespace App\Http\Controllers;

use App\Models\Medicine;
use App\Models\MedicineDispense;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Dispensing Log — the School Nurse's own module.
 *
 * Unlike the rest of the clinic section, this one is restricted to
 * `school_nurse`: issuing medicine is the nurse's responsibility, so Clinic
 * Staff can see stock levels but cannot draw stock down. That is the whole
 * point of the guard below — see requireNurse().
 */
class MedicineDispenseController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->requireNurse($request)) {
            return $redirect;
        }

        $institutionId = $request->session()->get('active_institution_id');

        $dispenses = MedicineDispense::with('medicine')
            ->forInstitution($institutionId)
            ->orderByDesc('dispensed_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $medicines = Medicine::query()
            ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
            ->orderBy('name')
            ->get();

        // Totals are summed in PHP over the decrypted collection only where
        // they have to be; quantity is a plain column, so this one is safe
        // to read straight off the rows.
        $today = $dispenses->filter(fn (MedicineDispense $d) => $d->dispensed_at?->isToday() ?? false);
        $thisMonth = $dispenses->filter(fn (MedicineDispense $d) => $d->dispensed_at?->isSameMonth(now()) ?? false);

        return view('dashboard.dispensing-log', [
            'dispenses' => $dispenses,
            'medicines' => $medicines,
            'stats' => [
                'today' => $today->count(),
                'today_units' => (int) $today->sum('quantity'),
                'month' => $thisMonth->count(),
                'month_units' => (int) $thisMonth->sum('quantity'),
                'learners' => $dispenses->pluck('student_lrn')->filter()->unique()->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if ($redirect = $this->requireNurse($request)) {
            return $redirect;
        }

        $validated = $request->validate([
            'medicine_id' => ['required', 'integer', 'exists:medicines,id'],
            'student_name' => ['required', 'string', 'max:255'],
            'student_lrn' => ['nullable', 'string', 'max:32'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $institutionId = $request->session()->get('active_institution_id');

        try {
            DB::transaction(function () use ($validated, $institutionId, $request) {
                // Lock the row so two nurses dispensing at once cannot both
                // read the same stock level and drive it negative.
                $medicine = Medicine::query()
                    ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
                    ->lockForUpdate()
                    ->findOrFail($validated['medicine_id']);

                if ($medicine->stock_quantity < $validated['quantity']) {
                    throw ValidationException::withMessages([
                        'quantity' => "Only {$medicine->stock_quantity} {$medicine->unit} of {$medicine->name} left in stock.",
                    ]);
                }

                MedicineDispense::create([
                    'institution_id' => $institutionId,
                    'medicine_id' => $medicine->id,
                    'student_lrn' => $validated['student_lrn'] ?? null,
                    'student_name' => $validated['student_name'],
                    'reason' => $validated['reason'] ?? null,
                    'quantity' => $validated['quantity'],
                    'dispensed_by_name' => (string) $request->session()->get('active_name', 'School Nurse'),
                    'dispensed_by_role' => (string) $request->session()->get('active_role', 'school_nurse'),
                    'dispensed_at' => now(),
                ]);

                $medicine->decrement('stock_quantity', $validated['quantity']);
            });
        } catch (ValidationException $e) {
            // Re-thrown so the stock message lands on the form field rather
            // than becoming a 500.
            throw $e;
        }

        return redirect()
            ->route('dashboard.dispensing-log')
            ->with('success', 'Dispensing recorded and stock updated.');
    }

    /**
     * School Nurse only.
     *
     * Deliberately narrower than requireClinicRole() elsewhere in the clinic
     * section: clinic_staff is NOT admitted here. system_admin is, as it is
     * everywhere, so the account holder can verify the module.
     */
    private function requireNurse(Request $request): ?RedirectResponse
    {
        $role = (string) $request->session()->get('active_role', '');

        if (in_array($role, ['school_nurse', 'system_admin'], true)) {
            return null;
        }

        $redirectByRole = [
            'clinic_staff' => 'dashboard.clinic-staff',
            'class_adviser' => 'dashboard.class-adviser',
            'school_head' => 'dashboard.school-head',
            'feeding_coor' => 'dashboard.feedingcor-dashboard',
            'nutricor' => 'dashboard.nutricor-dashboard',
        ];

        return redirect()
            ->route($redirectByRole[$role] ?? 'login')
            ->with('error', 'Only the School Nurse can record medicine dispensing.');
    }
}
