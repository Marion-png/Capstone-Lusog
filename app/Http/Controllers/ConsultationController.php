<?php

namespace App\Http\Controllers;

use App\Models\Condition;
use App\Models\Consultation;
use App\Models\Medicine;
use App\Models\MedicineDispense;
use App\Models\StudentHealthRecord;
use App\Support\SchemaCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ConsultationController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->requireClinicRole($request)) {
            return $redirect;
        }

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

    public function create(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->requireClinicRole($request)) {
            return $redirect;
        }

        // Opened from a learner's profile: ?lrn=... pre-fills who this
        // consultation is for. The LRN is looked up rather than the name
        // being passed through the URL — names are encrypted and URLs are
        // logged and shared, and a looked-up record cannot be spoofed by
        // editing the query string.
        $learner = null;
        $lrn = trim((string) $request->query('lrn', ''));

        if ($lrn !== '' && SchemaCache::hasTable('student_health_records')) {
            $learner = StudentHealthRecord::forActiveInstitution()
                ->where('student_id', $lrn)
                ->latest('id')
                ->first();
        }

        return view('dashboard.consultation-create', [
            'prefillName' => $learner?->student_name,
            'prefillSection' => $learner?->section,
            'prefillLrn' => $learner ? $lrn : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if ($redirect = $this->requireClinicRole($request)) {
            return $redirect;
        }

        // Named bag: the form is now also a dialog, and a dialog whose save
        // failed has to re-open itself to show why. Its own bag keeps that
        // from re-opening whatever other dialog shares the page.
        $validated = $request->validateWithBag('consultation', [
            'consulted_at' => ['required', 'date'],
            'student_name' => ['required', 'string', 'max:255'],
            'grade_section' => ['required', 'string', 'max:255'],
            'condition_id' => ['nullable', 'integer', 'exists:conditions,id'],
            'condition' => ['nullable', 'string', 'max:255'],
            'treatment_given' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', 'in:treated,referred'],
            // Optional: a medicine handed over during the visit. Recording it
            // here draws the stock down in the same transaction, so what the
            // clinic gave out and what the inventory says cannot drift apart.
            'medicine_id' => ['nullable', 'integer', 'exists:medicines,id'],
            'medicine_quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        // Ensure at least one condition source is provided
        $conditionId = $validated['condition_id'] ?? null;
        $conditionText = $validated['condition'] ?? null;

        if (! $conditionId && ! $conditionText) {
            return back()
                ->withErrors(['condition_id' => 'Please select or enter a condition.'], 'consultation')
                ->withInput();
        }

        // If condition_id is provided, fetch the condition name
        $conditionName = $conditionText;
        if ($conditionId) {
            $condition = Condition::find($conditionId);
            if ($condition) {
                // "Others" is the catalogue's catch-all. Storing that word
                // would throw away what the nurse actually recorded, and the
                // top-conditions report groups on this text — so an unusual
                // case would read as "others" forever. Keep the detail.
                $isCatchAll = strcasecmp($condition->name, 'Others') === 0;

                if ($isCatchAll && ($conditionText === null || trim($conditionText) === '')) {
                    return back()
                        ->withErrors(['condition' => 'Please describe the condition.'], 'consultation')
                        ->withInput();
                }

                $conditionName = $isCatchAll ? trim((string) $conditionText) : $condition->name;
            }
        }

        $institutionId = $request->session()->get('active_institution_id');

        // Dispensing is the school nurse's, and this is now the only path to it:
        // clinic staff log consultations but are deliberately not admitted to
        // the dispensing path, and this must not become a way around that.
        $mayDispense = $request->session()->get('active_role') === 'school_nurse';
        $medicineId = $mayDispense ? ($validated['medicine_id'] ?? null) : null;
        $quantity = max(1, (int) ($validated['medicine_quantity'] ?? 1));

        $dispensed = null;

        // One transaction: a consultation that saved without its dispense would
        // leave the stock overstating what the clinic holds, and a dispense
        // without its consultation would be a draw nobody can account for.
        // Either both land or neither does.
        DB::transaction(function () use ($validated, $conditionName, $conditionId, $institutionId, $medicineId, $quantity, $request, &$dispensed) {
            Consultation::create([
                'institution_id' => $institutionId,
                'consulted_at' => $validated['consulted_at'],
                'student_name' => $validated['student_name'],
                'grade_section' => $validated['grade_section'],
                'condition' => $conditionName,
                'condition_id' => $conditionId,
                // Nullable in the rules above, so it is absent from $validated
                // when left blank — reading it directly raised a 500.
                'treatment_given' => $validated['treatment_given'] ?? null,
                'status' => $validated['status'],
            ]);

            if (! $medicineId) {
                return;
            }

            // Lock the row so two nurses dispensing at once cannot both read
            // the same stock level and drive it negative.
            $medicine = Medicine::query()
                ->when($institutionId, fn ($q) => $q->where('institution_id', $institutionId))
                ->lockForUpdate()
                ->find($medicineId);

            if ($medicine === null) {
                throw ValidationException::withMessages([
                    'medicine_id' => 'That medicine is not in this school inventory.',
                ])->errorBag('consultation');
            }

            if ($medicine->stock_quantity < $quantity) {
                throw ValidationException::withMessages([
                    'medicine_quantity' => "Only {$medicine->stock_quantity} {$medicine->unit} of {$medicine->name} left in stock.",
                ])->errorBag('consultation');
            }

            MedicineDispense::create([
                'institution_id' => $institutionId,
                'medicine_id' => $medicine->id,
                'student_lrn' => null,
                'student_name' => $validated['student_name'],
                'reason' => $conditionName,
                'quantity' => $quantity,
                'dispensed_by_name' => (string) $request->session()->get('active_name', 'School Nurse'),
                'dispensed_by_role' => (string) $request->session()->get('active_role', 'school_nurse'),
                'dispensed_at' => now(),
            ]);

            $medicine->decrement('stock_quantity', $quantity);

            $dispensed = $quantity.' '.$medicine->unit.' of '.$medicine->name;
        });

        return redirect()
            ->route('dashboard.consultation-log')
            ->with('success', $dispensed === null
                ? 'Consultation saved successfully.'
                : "Consultation saved. {$dispensed} deducted from inventory.");
    }

    /**
     * Redirects a non-nurse/clinic-staff session to its own dashboard
     * instead of letting it view or write consultation data.
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
