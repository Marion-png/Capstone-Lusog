<?php

namespace App\Http\Controllers;

use App\Models\Condition;
use App\Models\Consultation;
use App\Models\ConsultationPhoto;
use App\Models\Medicine;
use App\Models\MedicineDispense;
use App\Models\StudentHealthRecord;
use App\Support\EncryptedFileStorage;
use App\Support\LearnerSearchIndex;
use App\Support\SchemaCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

        // How many photographs each visit on this page carries — one grouped
        // query on plain columns, so the row can say "No photos attached" or
        // offer to view them without opening the dialog to find out.
        $photoCounts = collect();
        if (SchemaCache::hasTable('consultation_photos') && $consultations->isNotEmpty()) {
            $photoCounts = ConsultationPhoto::query()
                ->whereIn('consultation_id', $consultations->pluck('id')->all())
                ->selectRaw('consultation_id, COUNT(*) AS total')
                ->groupBy('consultation_id')
                ->pluck('total', 'consultation_id');
        }

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
            'photoCounts' => $photoCounts,
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

        // Back, Cancel and the save all return to the page this was opened
        // from, when that page is inside the app; the log is the fallback.
        $previous = (string) url()->previous();
        $base = rtrim(url('/'), '/');
        $backTo = str_starts_with($previous, $base.'/')
            && $previous !== url()->current()
            && ! str_starts_with($previous, route('consultations.create'))
            ? $previous
            : route('dashboard.consultation-log');

        return view('dashboard.consultation-create', [
            'prefillName' => $learner?->student_name,
            'prefillSection' => $learner?->section,
            'prefillLrn' => $learner ? $lrn : null,
            'backTo' => $backTo,
            // The roll, for the learner picker: choosing a name fills the
            // grade and section from the record instead of typing them.
            'learnerIndex' => LearnerSearchIndex::fromSession($request),
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
            // A note on the visit — what the profile's Clinic Notes tab used
            // to take separately. It rides the consultation it is about.
            'notes' => ['nullable', 'string', 'max:2000'],
            'notes_shared_with_adviser' => ['nullable', 'boolean'],
            'status' => ['required', 'in:treated,referred'],
            // Optional: a medicine handed over during the visit. Recording it
            // here draws the stock down in the same transaction, so what the
            // clinic gave out and what the inventory says cannot drift apart.
            'medicine_id' => ['nullable', 'integer', 'exists:medicines,id'],
            'medicine_quantity' => ['nullable', 'integer', 'min:1'],
            // Optional: photographs of the injury, taken while the visit is
            // being recorded — the same kind of file, the same limits and the
            // same storage the Photos dialog on the log uses (ConsultationPhoto).
            'photos' => ['nullable', 'array', 'max:'.ConsultationPhoto::MAX_PER_UPLOAD],
            'photos.*' => [
                'file',
                'image',
                'mimes:'.ConsultationPhoto::ALLOWED_EXTENSIONS,
                'max:'.ConsultationPhoto::MAX_KILOBYTES,
            ],
            'photo_caption' => ['nullable', 'string', 'max:500'],
            'photo_shared_with_adviser' => ['nullable', 'boolean'],
        ]);

        $photos = array_values(array_filter((array) $request->file('photos', [])));

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
        $photoCount = 0;

        DB::transaction(function () use ($validated, $conditionName, $conditionId, $institutionId, $medicineId, $quantity, $photos, $request, &$dispensed, &$photoCount) {
            // The note travels only where its column has been migrated, so an
            // older database still records the visit. Whether the clinic
            // shared it with the class adviser rides with it — a note nobody
            // wrote is never 'shared', so the flag follows the text.
            $noteText = trim((string) ($validated['notes'] ?? '')) ?: null;
            $note = SchemaCache::hasColumn('consultations', 'notes')
                ? ['notes' => $noteText]
                : [];

            if (SchemaCache::hasColumn('consultations', 'notes_shared_with_adviser')) {
                $note['notes_shared_with_adviser'] = $noteText !== null
                    && (bool) ($validated['notes_shared_with_adviser'] ?? false);
            }

            $consultation = Consultation::create($note + [
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

            // Photographs attached at the point of recording. Written through
            // the model and EncryptedFileStorage exactly as the Photos dialog
            // writes them, so a photo taken now and one added later are the
            // same record. Not shared with the adviser unless the nurse says
            // so — the adviser sees no other consultation detail, and a photo
            // of a child's injury must not become the back door.
            if ($photos !== [] && SchemaCache::hasTable('consultation_photos')) {
                foreach ($photos as $file) {
                    ConsultationPhoto::create([
                        'consultation_id' => $consultation->id,
                        'institution_id' => $institutionId,
                        'file_path' => EncryptedFileStorage::store($file, 'consultation-photos'),
                        'file_original_name' => $file->getClientOriginalName(),
                        'file_size' => $file->getSize(),
                        'caption' => $validated['photo_caption'] ?? null,
                        'shared_with_adviser' => (bool) ($validated['photo_shared_with_adviser'] ?? false),
                        'uploaded_by_name' => (string) $request->session()->get('active_name', ''),
                        'uploaded_by_role' => (string) $request->session()->get('active_role', ''),
                    ]);
                    $photoCount++;
                }
            }

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

        $message = $dispensed === null
            ? 'Consultation saved successfully.'
            : "Consultation saved. {$dispensed} deducted from inventory.";
        if ($photoCount > 0) {
            $message .= ' '.$photoCount.' '.Str::plural('photo', $photoCount).' attached.';
        }

        return redirect()
            ->to($this->returnTo($request))
            ->with('success', $message);
    }

    /**
     * Where a saved consultation comes back to: the page the dialog was opened
     * on — a learner's profile, the log, the standalone form's referrer — not
     * always the Consultation Log. Only a URL inside this app is honoured; a
     * value off the wire pointing anywhere else falls back to the log, so the
     * field cannot be used to send a nurse off-site after a save.
     */
    private function returnTo(Request $request): string
    {
        $candidate = trim((string) $request->input('return_to', ''));
        $base = rtrim(url('/'), '/');

        if ($candidate !== '' && str_starts_with($candidate, $base.'/') && ! str_contains($candidate, "\n")) {
            return $candidate;
        }

        return route('dashboard.consultation-log');
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
