<?php

namespace App\Support;

use App\Models\ClinicNote;
use App\Models\Condition;
use App\Models\Consultation;
use App\Models\HealthConsentForm;
use App\Models\Medicine;
use App\Models\MedicineDispense;
use App\Models\StudentHealthRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The clinic, consent and inventory half of what the School Head reads.
 *
 * SchoolHeadOverview answers the feeding programme and the nutritional
 * picture; this class answers the other three things the role is accountable
 * for — what the clinic did, whether the school holds the consent it is
 * required to hold, and whether the clinic can still dispense. Both are read
 * once per request and handed to every panel that needs them, for the same
 * reason: the Dashboard's consultation count and the Health tab's must be one
 * count of one set of records, not two that can drift.
 *
 * The head never writes any of it. Consultations belong to the nurse and the
 * clinic staff, consent to the class adviser and the parent, inventory to the
 * clinic — so everything here is derived at read time and nothing is stored.
 *
 * Three rules hold throughout, the same ones the feeding side keeps:
 *
 * 1. **Never aggregate an encrypted column in SQL.** A consultation's learner,
 *    grade and complaint are encrypted at rest, so the rows come back whole and
 *    every grouping happens in PHP. Only `consulted_at`, `status`,
 *    `condition_id` and `institution_id` are ever named in a WHERE.
 * 2. **A rate with nothing behind it is undefined, not 0%.** Consent
 *    completion over a roster of nobody, or a disposition share over no
 *    consultation, is NULL and prints as an em dash.
 * 3. **The period is the school year**, and figures are counted inside it. A
 *    head reading last year's oversight must not see this month's clinic
 *    traffic folded into it.
 */
final class SchoolHeadHealthOverview
{
    /** Consultation dispositions, as the clinic log itself records them. */
    public const DISPOSITIONS = ['treated' => 'Treated', 'referred' => 'Referred'];

    /** Stock states, worst first. Derived from the clinic's own reorder threshold, never a constant here. */
    public const STOCK_STATES = ['out', 'low', 'monitor', 'good'];

    /** How many rows a "worst first" list hands the dashboard before it becomes a tab's job. */
    public const PANEL_ROWS = 6;

    /** @var Collection<int, Consultation> */
    public readonly Collection $consultations;

    /** @var Collection<int, Medicine> */
    public readonly Collection $medicines;

    /** @var array<string, mixed>|null */
    private ?array $clinic = null;

    /** @var array<string, mixed>|null */
    private ?array $consent = null;

    /** @var array<string, mixed>|null */
    private ?array $inventory = null;

    /**
     * @param  Collection<int, StudentHealthRecord>  $records  the roster the head has scoped to
     * @param  Collection<int, Consultation>  $consultations
     * @param  Collection<int, Medicine>  $medicines
     * @param  array<string, array{status: string, choice: string}>  $consentByLrn
     * @param  array<int, string>  $conditionCategories
     */
    private function __construct(
        public readonly ?int $institutionId,
        public readonly string $schoolYear,
        public readonly CarbonImmutable $periodStart,
        public readonly CarbonImmutable $periodEnd,
        public readonly Collection $records,
        Collection $consultations,
        Collection $medicines,
        private array $consentByLrn,
        private array $conditionCategories,
        public readonly int $clinicNotes,
        public readonly int $dispensedThisMonth,
    ) {
        $this->consultations = $consultations;
        $this->medicines = $medicines;
    }

    /**
     * One reading of the school's clinic, consent and inventory records for one
     * school year.
     *
     * The roster is passed in rather than re-queried, so the consent figures
     * describe exactly the learners the head has filtered to — a grade filter
     * on the dashboard moves the completion rate with it.
     *
     * `$scopedRoster` says whether that roster is a *narrowed* one. When it is,
     * the clinic figures are narrowed to the same learners, so one filter moves
     * every panel rather than half of them. A consultation is matched to a
     * learner by name, which is the only link the clinic log carries — and a
     * visit that matches nobody on a narrowed roll is left out, because it is
     * not a visit by anyone in the scope on screen. Unscoped, every one of the
     * school's visits counts, so a mistyped name can never drop a visit from
     * the school-wide total.
     *
     * **The institution scope is required, not optional.** Without one this
     * reads nothing at all rather than falling through to every school's
     * clinic, consent and inventory — see SchoolHeadOverview::for().
     *
     * @param  Collection<int, StudentHealthRecord>  $records
     */
    public static function for(?int $institutionId, ?string $schoolYear, Collection $records, bool $scopedRoster = false): self
    {
        $schoolYear = trim((string) $schoolYear) !== ''
            ? trim((string) $schoolYear)
            : StudentHealthRecord::currentSchoolYear();

        [$start, $end] = self::periodFor($schoolYear);

        $consultations = collect();
        if ($institutionId && SchemaCache::hasTable('consultations')) {
            $consultations = Consultation::query()
                ->where('institution_id', $institutionId)
                ->whereBetween('consulted_at', [$start, $end])
                ->orderByDesc('consulted_at')
                ->get();

            if ($scopedRoster) {
                // Both sides are encrypted at rest, so the match runs here on
                // decrypted values — never as a SQL join or WHERE.
                $roll = $records
                    ->map(fn (StudentHealthRecord $record): string => self::nameKey((string) $record->student_name))
                    ->filter()
                    ->flip();

                $consultations = $consultations
                    ->filter(fn (Consultation $consultation): bool => $roll->has(self::nameKey((string) $consultation->student_name)))
                    ->values();
            }
        }

        $medicines = collect();
        if ($institutionId && SchemaCache::hasTable('medicines')) {
            $medicines = Medicine::query()
                ->where('institution_id', $institutionId)
                ->orderBy('name')
                ->get();
        }

        return new self(
            $institutionId,
            $schoolYear,
            $start,
            $end,
            $records,
            $consultations,
            $medicines,
            self::readConsent($institutionId, $schoolYear),
            self::readConditionCategories($consultations),
            self::countClinicNotes($institutionId, $schoolYear),
            self::countDispensed($institutionId),
        );
    }

    /**
     * The school year as a date range: June of the opening year through May of
     * the closing one, which is the window ParentalConsentForm::currentSchoolYear
     * rolls the label over on.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function periodFor(string $schoolYear): array
    {
        $opening = (int) substr(trim($schoolYear), 0, 4);

        if ($opening < 2000) {
            $opening = (int) now()->format('n') >= 6 ? (int) now()->format('Y') : (int) now()->format('Y') - 1;
        }

        return [
            CarbonImmutable::create($opening, 6, 1)->startOfDay(),
            CarbonImmutable::create($opening + 1, 5, 31)->endOfDay(),
        ];
    }

    /**
     * A learner's name reduced to something two records can be compared on:
     * lowercased, with runs of whitespace collapsed. It is a display name, not
     * an identifier, so this is a best-effort match and never a key anything is
     * written against.
     */
    private static function nameKey(string $name): string
    {
        return trim(strtolower(preg_replace('/\s+/', ' ', $name) ?? $name));
    }

    /** Whether today falls inside the year being read — decides whether "this month" means anything. */
    public function isCurrentPeriod(): bool
    {
        return now()->betweenIncluded($this->periodStart, $this->periodEnd);
    }

    /**
     * What the clinic did: how much, of what, to whom, and how it ended.
     *
     * Every grouping runs in PHP — the learner, the grade and the complaint are
     * all encrypted at rest.
     *
     * @return array<string, mixed>
     */
    public function clinic(): array
    {
        if ($this->clinic !== null) {
            return $this->clinic;
        }

        $total = $this->consultations->count();
        $monthStart = now()->startOfMonth();
        $weekStart = now()->startOfWeek();

        $thisMonth = $this->consultations
            ->filter(fn (Consultation $c): bool => $c->consulted_at !== null && $c->consulted_at->greaterThanOrEqualTo($monthStart))
            ->count();

        $thisWeek = $this->consultations
            ->filter(fn (Consultation $c): bool => $c->consulted_at !== null && $c->consulted_at->greaterThanOrEqualTo($weekStart))
            ->count();

        $today = $this->consultations
            ->filter(fn (Consultation $c): bool => $c->consulted_at !== null && $c->consulted_at->isToday())
            ->count();

        $referred = $this->consultations->where('status', 'referred')->count();
        $referredMonth = $this->consultations
            ->filter(fn (Consultation $c): bool => $c->status === 'referred'
                && $c->consulted_at !== null
                && $c->consulted_at->greaterThanOrEqualTo($monthStart))
            ->count();

        // Distinct learners seen. The name is encrypted, so "distinct" is a
        // PHP operation over decrypted values — never a SQL DISTINCT.
        $learners = $this->consultations
            ->map(fn (Consultation $c): string => strtolower(trim((string) $c->student_name)))
            ->filter()
            ->unique()
            ->count();

        $dispositions = [];
        foreach (self::DISPOSITIONS as $key => $label) {
            $count = $this->consultations->where('status', $key)->count();
            $dispositions[] = [
                'key' => $key,
                'label' => $label,
                'count' => $count,
                'share' => $total > 0 ? round(($count / $total) * 100, 1) : null,
            ];
        }

        return $this->clinic = [
            'total' => $total,
            'this_month' => $thisMonth,
            'this_week' => $thisWeek,
            'today' => $today,
            'month_label' => now()->format('F'),
            'is_current_period' => $this->isCurrentPeriod(),
            'referred' => $referred,
            'referred_this_month' => $referredMonth,
            'treated' => $this->consultations->where('status', 'treated')->count(),
            'learners' => $learners,
            'notes' => $this->clinicNotes,
            'dispositions' => $dispositions,
            'categories' => $this->byCategory(),
            'complaints' => $this->byComplaint(),
            'grades' => $this->byGrade(),
            'trend' => $this->trend(),
            'recent' => $this->recent(),
        ];
    }

    /**
     * Whether the school holds the health-services consent it is required to
     * hold, for the learners on the scoped roster.
     *
     * A form counts only once a parent has actually answered it and the answer
     * was not a refusal: a draft nobody sent, and a form sent but unanswered,
     * are both missing consent, because neither authorises anything.
     *
     * @return array<string, mixed>
     */
    public function consent(): array
    {
        if ($this->consent !== null) {
            return $this->consent;
        }

        $sections = [];
        $missing = [];
        $granted = [];
        $serviceCounts = array_fill_keys(array_keys(HealthConsentForm::serviceLabels()), 0);
        $valid = 0;
        $declined = 0;
        $awaiting = 0;
        $none = 0;

        foreach ($this->records as $record) {
            [$grade, $section] = FeedingBeneficiarySummary::splitSection((string) $record->section);
            $key = $grade.' / '.$section;

            if (! isset($sections[$key])) {
                $sections[$key] = [
                    'grade' => $grade,
                    'section' => $section,
                    'required' => 0,
                    'valid' => 0,
                    'missing' => 0,
                    'rate' => null,
                ];
            }

            $sections[$key]['required']++;

            $state = $this->consentState((string) $record->student_id);

            if ($state === 'valid') {
                $valid++;
                $sections[$key]['valid']++;

                // The learners a health service may actually be given to: a
                // parent answered, and did not refuse. Which services the letter
                // covered is what the head filters on — "show me who I may
                // deworm" is the question this list answers.
                $form = $this->consentByLrn[trim((string) $record->student_id)] ?? null;
                $services = is_array($form['services'] ?? null) ? $form['services'] : [];

                foreach ($services as $service) {
                    if (array_key_exists($service, $serviceCounts)) {
                        $serviceCounts[$service]++;
                    }
                }

                $granted[] = [
                    'id' => $record->id,
                    // The form itself, so the head can open the signed letter.
                    'form_id' => (int) ($form['id'] ?? 0),
                    'name' => (string) $record->student_name,
                    'lrn' => (string) $record->student_id,
                    'grade' => $grade,
                    'section' => $section,
                    'services' => array_values($services),
                ];

                continue;
            }

            $sections[$key]['missing']++;

            match ($state) {
                'declined' => $declined++,
                'awaiting' => $awaiting++,
                default => $none++,
            };

            $missing[] = [
                'id' => $record->id,
                'name' => (string) $record->student_name,
                'lrn' => (string) $record->student_id,
                'grade' => $grade,
                'section' => $section,
                'state' => $state,
                'state_label' => self::consentLabel($state),
            ];
        }

        foreach ($sections as $key => $row) {
            $sections[$key]['rate'] = $row['required'] > 0
                ? round(($row['valid'] / $row['required']) * 100, 1)
                : null;
        }

        // Worst completion first: the section a head has to chase is the row
        // at the top, not the row alphabetically nearest A.
        $sections = collect($sections)
            ->sortBy([
                fn (array $row): float => $row['rate'] ?? 0.0,
                fn (array $row): string => $row['grade'].$row['section'],
            ])
            ->values()
            ->all();

        $required = $this->records->count();

        return $this->consent = [
            'required' => $required,
            'valid' => $valid,
            'missing' => $required - $valid,
            'declined' => $declined,
            'awaiting' => $awaiting,
            'none' => $none,
            // Null, never 0%: a roster of nobody has no completion rate.
            'rate' => $required > 0 ? round(($valid / $required) * 100, 1) : null,
            'sections' => $sections,
            'missing_rows' => $missing,
            // Who the school holds valid consent for, and for what. Standing and
            // service only — never the allergies, the write-in exceptions or the
            // parent's signature, which belong to the adviser and the nurse who
            // act on them.
            'granted_rows' => $granted,
            'service_counts' => $serviceCounts,
        ];
    }

    /**
     * What the clinic can still dispense.
     *
     * The reorder line is the clinic's own `minimum_threshold` on each
     * medicine, so a school that keeps ten of one thing and two hundred of
     * another is judged against what it decided, not against one number
     * written here.
     *
     * @return array<string, mixed>
     */
    public function inventory(): array
    {
        if ($this->inventory !== null) {
            return $this->inventory;
        }

        $rows = $this->medicines
            ->map(function (Medicine $medicine): array {
                $stock = (int) $medicine->stock_quantity;
                $threshold = max(0, (int) $medicine->minimum_threshold);
                $state = self::stockState($stock, $threshold);

                return [
                    'id' => $medicine->id,
                    'name' => (string) $medicine->name,
                    'stock' => $stock,
                    'unit' => (string) ($medicine->unit ?: 'pcs'),
                    'threshold' => $threshold,
                    'state' => $state,
                    'label' => self::stockLabel($state),
                    'badge' => self::stockBadge($state),
                    // Share of the reorder line, capped: the bar answers "how
                    // far below the line", so a well-stocked item fills it.
                    'level' => $threshold > 0 ? min(100, round(($stock / $threshold) * 100, 1)) : ($stock > 0 ? 100.0 : 0.0),
                ];
            })
            ->values();

        $counts = array_fill_keys(self::STOCK_STATES, 0);
        foreach ($rows as $row) {
            $counts[$row['state']]++;
        }

        // Worst first, then emptiest, then by name.
        $order = array_flip(self::STOCK_STATES);
        $attention = $rows
            ->filter(fn (array $row): bool => $row['state'] !== 'good')
            ->sortBy([
                fn (array $row): int => $order[$row['state']],
                fn (array $row): float => $row['level'],
                fn (array $row): string => strtolower($row['name']),
            ])
            ->values();

        return $this->inventory = [
            'tracked' => $rows->count(),
            'out' => $counts['out'],
            'low' => $counts['low'],
            'monitor' => $counts['monitor'],
            'good' => $counts['good'],
            'needs_attention' => $attention->count(),
            'units' => (int) $rows->sum('stock'),
            'dispensed_this_month' => $this->dispensedThisMonth,
            'rows' => $rows->sortBy([
                fn (array $row): int => $order[$row['state']],
                fn (array $row): string => strtolower($row['name']),
            ])->values(),
            'attention_rows' => $attention,
        ];
    }

    /** Where a stock level sits against the clinic's own reorder line. */
    public static function stockState(int $stock, int $threshold): string
    {
        return match (true) {
            $stock <= 0 => 'out',
            $threshold > 0 && $stock < $threshold => 'low',
            $threshold > 0 && $stock < (int) ceil($threshold * 1.5) => 'monitor',
            default => 'good',
        };
    }

    public static function stockLabel(string $state): string
    {
        return match ($state) {
            'out' => 'Out of stock',
            'low' => 'Low',
            'monitor' => 'Monitor',
            default => 'Good',
        };
    }

    public static function stockBadge(string $state): string
    {
        return match ($state) {
            'out' => 'badge-critical',
            'low' => 'badge-risk',
            'monitor' => 'badge-monitor',
            default => 'badge-normal',
        };
    }

    public static function consentLabel(string $state): string
    {
        return match ($state) {
            'valid' => 'Valid consent',
            'declined' => 'Consent refused',
            'awaiting' => 'Awaiting parent',
            default => 'No form on file',
        };
    }

    /**
     * The tallest bar rounded up to a clean multiple of four, so a count never
     * lands between two gridlines.
     */
    public static function axisMax(int $peak): int
    {
        if ($peak <= 0) {
            return 0;
        }

        for ($magnitude = 1; $magnitude <= 1_000_000; $magnitude *= 10) {
            foreach ([1, 2, 5] as $unit) {
                $step = $unit * $magnitude;
                if ($step * 4 >= $peak) {
                    return $step * 4;
                }
            }
        }

        return $peak;
    }

    /**
     * Gridline values, low to high.
     *
     * @return list<int>
     */
    public static function ticks(int $axisMax): array
    {
        if ($axisMax <= 0) {
            return [0];
        }

        $step = $axisMax / 4;

        return array_map(static fn (int $i): int => (int) round($step * $i), [0, 1, 2, 3, 4]);
    }

    /**
     * Where one learner's health-services consent stands.
     *
     * Four states, and only the first authorises anything.
     */
    private function consentState(string $lrn): string
    {
        $form = $this->consentByLrn[trim($lrn)] ?? null;

        if ($form === null) {
            return 'none';
        }

        if (! in_array($form['status'], [HealthConsentForm::STATUS_SIGNED, HealthConsentForm::STATUS_REVIEWED], true)) {
            return $form['status'] === HealthConsentForm::STATUS_SENT ? 'awaiting' : 'none';
        }

        return $form['choice'] === HealthConsentForm::CONSENT_DENY ? 'declined' : 'valid';
    }

    /**
     * Consultations by the catalogue category of the condition behind them.
     *
     * A visit logged against a free-text complaint with no catalogue entry is
     * counted as Uncategorised rather than dropped — a head reading "143 this
     * month" must be able to add the breakdown back up to it.
     *
     * @return list<array<string, mixed>>
     */
    private function byCategory(): array
    {
        $total = $this->consultations->count();
        $counts = [];

        foreach ($this->consultations as $consultation) {
            $category = $this->conditionCategories[(int) $consultation->condition_id] ?? '';
            $label = trim($category) !== '' ? trim($category) : 'Uncategorised';
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        return $this->rank($counts, $total);
    }

    /**
     * The complaints themselves, which are encrypted and so are counted here
     * rather than grouped in SQL.
     *
     * @return list<array<string, mixed>>
     */
    private function byComplaint(): array
    {
        $total = $this->consultations->count();
        $counts = [];

        foreach ($this->consultations as $consultation) {
            $label = trim((string) $consultation->condition);
            $label = $label !== '' ? $label : 'Not recorded';
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        return $this->rank($counts, $total);
    }

    /**
     * Consultations by grade, read off the grade-and-section the clinic typed.
     *
     * @return list<array<string, mixed>>
     */
    private function byGrade(): array
    {
        $total = $this->consultations->count();
        $counts = [];

        foreach ($this->consultations as $consultation) {
            $raw = trim((string) $consultation->grade_section);
            $label = preg_match('/(\d{1,2})/', $raw, $matches) ? 'Grade '.(int) $matches[1] : 'Unassigned';
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        uksort($counts, static function (string $a, string $b): int {
            $rank = static fn (string $key): int => preg_match('/(\d{1,2})/', $key, $m) ? (int) $m[1] : 99;

            return $rank($a) <=> $rank($b);
        });

        $peak = $counts === [] ? 0 : max($counts);

        return array_values(array_map(static fn (string $label): array => [
            'label' => $label,
            'count' => $counts[$label],
            'share' => $total > 0 ? round(($counts[$label] / $total) * 100, 1) : null,
            'pct' => $peak > 0 ? round(($counts[$label] / $peak) * 100, 2) : 0.0,
        ], array_keys($counts)));
    }

    /**
     * Consultations month by month across the school year, oldest column
     * first — time reads left to right.
     *
     * @return array<string, mixed>
     */
    private function trend(): array
    {
        $counts = [];

        foreach ($this->consultations as $consultation) {
            if ($consultation->consulted_at === null) {
                continue;
            }

            $key = $consultation->consulted_at->format('Y-m');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        ksort($counts);

        $peak = $counts === [] ? 0 : max($counts);
        $axisMax = self::axisMax($peak);

        $columns = [];
        foreach ($counts as $month => $count) {
            $columns[] = [
                'month' => $month,
                'label' => CarbonImmutable::parse($month.'-01')->format('M'),
                'full_label' => CarbonImmutable::parse($month.'-01')->format('F Y'),
                'count' => $count,
                'pct' => $axisMax > 0 ? round(($count / $axisMax) * 100, 2) : 0.0,
            ];
        }

        return [
            'columns' => $columns,
            'axis_max' => $axisMax,
            'ticks' => array_reverse(self::ticks($axisMax)),
            'peak' => $peak,
        ];
    }

    /**
     * The latest visits, as the oversight panel lists them: when, which grade,
     * what for and how it ended. Aggregated reading — the clinical narrative
     * stays on the nurse's screen.
     *
     * @return list<array<string, string>>
     */
    private function recent(): array
    {
        return $this->consultations
            ->take(self::PANEL_ROWS)
            ->map(fn (Consultation $consultation): array => [
                'date' => $consultation->consulted_at?->format('M j') ?? '—',
                'time' => $consultation->consulted_at?->format('g:i A') ?? '',
                'grade' => trim((string) $consultation->grade_section) !== ''
                    ? trim((string) $consultation->grade_section)
                    : 'Unassigned',
                'complaint' => trim((string) $consultation->condition) !== ''
                    ? trim((string) $consultation->condition)
                    : 'Not recorded',
                'status' => self::DISPOSITIONS[$consultation->status] ?? ucfirst((string) $consultation->status),
                'badge' => $consultation->status === 'referred' ? 'badge-monitor' : 'badge-normal',
            ])
            ->values()
            ->all();
    }

    /**
     * A tally as a ranked, direct-labelled list: biggest first, each carrying
     * its count, its share and its length against the biggest.
     *
     * @param  array<string, int>  $counts
     * @return list<array<string, mixed>>
     */
    private function rank(array $counts, int $total): array
    {
        arsort($counts);
        $peak = $counts === [] ? 0 : max($counts);

        return array_values(array_map(static fn (string $label): array => [
            'label' => $label,
            'count' => $counts[$label],
            'share' => $total > 0 ? round(($counts[$label] / $total) * 100, 1) : null,
            'pct' => $peak > 0 ? round(($counts[$label] / $peak) * 100, 2) : 0.0,
        ], array_keys($counts)));
    }

    /**
     * Every health-services consent form on file for this school year, keyed by
     * the learner's LRN.
     *
     * `status` is plain and so is filtered in SQL; `consent_choice` is
     * encrypted and is read after fetch, never in a WHERE.
     *
     * @return array<string, array{status: string, choice: string}>
     */
    private static function readConsent(?int $institutionId, string $schoolYear): array
    {
        if (! $institutionId || ! SchemaCache::hasTable('health_consent_forms')) {
            return [];
        }

        $forms = HealthConsentForm::query()
            ->where('institution_id', $institutionId)
            ->where('school_year', $schoolYear)
            ->get(['id', 'student_lrn', 'status', 'consent_choice', 'services']);

        $byLrn = [];

        foreach ($forms as $form) {
            $byLrn[trim((string) $form->student_lrn)] = [
                'id' => (int) $form->id,
                'status' => (string) $form->status,
                'choice' => strtolower(trim((string) $form->consent_choice)),
                // Which health services the letter actually asked about. The
                // column is encrypted, so this is read after fetch and every
                // filter on it runs in PHP.
                'services' => is_array($form->services) ? $form->services : [],
            ];
        }

        return $byLrn;
    }

    /**
     * The catalogue category behind each consultation's condition.
     *
     * @param  Collection<int, Consultation>  $consultations
     * @return array<int, string>
     */
    private static function readConditionCategories(Collection $consultations): array
    {
        $ids = $consultations->pluck('condition_id')->filter()->unique()->values();

        if ($ids->isEmpty() || ! SchemaCache::hasTable('conditions')) {
            return [];
        }

        return Condition::query()
            ->whereIn('id', $ids)
            ->pluck('category', 'id')
            ->map(fn ($category): string => (string) $category)
            ->all();
    }

    private static function countClinicNotes(?int $institutionId, string $schoolYear): int
    {
        if (! $institutionId || ! SchemaCache::hasTable('clinic_notes')) {
            return 0;
        }

        return ClinicNote::query()
            ->where('institution_id', $institutionId)
            ->where('school_year', $schoolYear)
            ->count();
    }

    private static function countDispensed(?int $institutionId): int
    {
        if (! $institutionId || ! SchemaCache::hasTable('medicine_dispenses')) {
            return 0;
        }

        return (int) MedicineDispense::query()
            ->where('institution_id', $institutionId)
            ->where('dispensed_at', '>=', now()->startOfMonth())
            ->sum('quantity');
    }
}
