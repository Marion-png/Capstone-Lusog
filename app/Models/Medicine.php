<?php

namespace App\Models;

use App\Support\RxNormService;
use App\Support\SchemaCache;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Medicine extends Model
{
    use HasFactory;

    /**
     * How far ahead an expiry is flagged. Thirty days is one reorder cycle:
     * long enough to use the stock up or ask for a replacement, short enough
     * that the flag is not on every item all year.
     */
    public const EXPIRY_WARNING_DAYS = 30;

    public const EXPIRY_EXPIRED = 'expired';

    public const EXPIRY_SOON = 'expiring';

    public const EXPIRY_OK = 'ok';

    protected $fillable = [
        'institution_id',
        'name',
        'off_catalogue',
        'off_catalogue_reason',
        'stock_quantity',
        'minimum_threshold',
        'unit',
        'expiry_date',
        'notes',
        'generic_name',
        'rx_concept_id',
        'rx_status',
    ];

    protected $casts = [
        'off_catalogue' => 'boolean',
        'expiry_date' => 'date',
    ];

    /** The RxNorm concept the generic name matched (App\Support\RxNormService). */
    public function rxConcept(): BelongsTo
    {
        return $this->belongsTo(RxConcept::class, 'rx_concept_id');
    }

    /** Was this medicine matched in RxNorm, so the allergy check can read it? */
    public function isRxMatched(): bool
    {
        return $this->rx_status === RxNormService::MATCHED && $this->rxConcept !== null;
    }

    /**
     * Keep a lookup's answer (RxNormService::lookup). Candidates the nurse has
     * not chosen from yet are not a match.
     *
     * @param  array{status: string, concept: ?RxConcept}  $result
     */
    public function applyRxNorm(string $genericName, array $result): void
    {
        $this->generic_name = $genericName;
        $this->rx_status = $result['status'] === RxNormService::CANDIDATES ? RxNormService::NOT_FOUND : $result['status'];
        $this->rx_concept_id = $result['concept']?->id;
        $this->save();
    }

    /** Whether the expiry column is on this database yet. */
    public static function supportsExpiry(): bool
    {
        return SchemaCache::hasColumn('medicines', 'expiry_date');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(MedicineReceipt::class);
    }

    /** The lots on the shelf and every lot before them (App\Support\MedicineBatches). */
    public function batches(): HasMany
    {
        return $this->hasMany(MedicineBatch::class);
    }

    /**
     * Where the stock on hand stands against its expiry date.
     *
     * NULL when no date is on file — "unknown" is a different answer from
     * "fine", and the page prints an em dash for it rather than a green
     * badge. Judged against the calendar date, never the clock: a box
     * expiring today is expired today.
     */
    public function expiryStatus(?Carbon $today = null): ?string
    {
        if (! self::supportsExpiry() || ! $this->expiry_date) {
            return null;
        }

        return self::statusOfDate($this->expiry_date, $today);
    }

    /** Whole days until expiry; negative once past, NULL with no date on file. */
    public function daysToExpiry(?Carbon $today = null): ?int
    {
        if (! self::supportsExpiry() || ! $this->expiry_date) {
            return null;
        }

        return self::daysUntil($this->expiry_date, $today);
    }

    /**
     * The same reading for any date — an item's, or one lot's on the shelf —
     * so a lot and the item it belongs to are judged by one rule.
     */
    public static function statusOfDate(?CarbonInterface $expiry, ?CarbonInterface $today = null): ?string
    {
        if ($expiry === null) {
            return null;
        }

        $today = ($today ?? now())->copy()->startOfDay();
        $expiry = $expiry->copy()->startOfDay();

        if ($expiry->lte($today)) {
            return self::EXPIRY_EXPIRED;
        }

        if ($expiry->lte($today->copy()->addDays(self::EXPIRY_WARNING_DAYS))) {
            return self::EXPIRY_SOON;
        }

        return self::EXPIRY_OK;
    }

    /** Whole days from today to this date; negative once past. */
    public static function daysUntil(CarbonInterface $expiry, ?CarbonInterface $today = null): int
    {
        return (int) ($today ?? now())->copy()->startOfDay()->diffInDays($expiry->copy()->startOfDay(), false);
    }
}
