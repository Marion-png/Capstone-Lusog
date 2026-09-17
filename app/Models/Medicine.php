<?php

namespace App\Models;

use App\Support\SchemaCache;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
    ];

    protected $casts = [
        'off_catalogue' => 'boolean',
        'expiry_date' => 'date',
    ];

    /** Whether the expiry column is on this database yet. */
    public static function supportsExpiry(): bool
    {
        return SchemaCache::hasColumn('medicines', 'expiry_date');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(MedicineReceipt::class);
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

        $today = ($today ?? now())->copy()->startOfDay();
        $expiry = $this->expiry_date->copy()->startOfDay();

        if ($expiry->lte($today)) {
            return self::EXPIRY_EXPIRED;
        }

        if ($expiry->lte($today->copy()->addDays(self::EXPIRY_WARNING_DAYS))) {
            return self::EXPIRY_SOON;
        }

        return self::EXPIRY_OK;
    }

    /** Whole days until expiry; negative once past, NULL with no date on file. */
    public function daysToExpiry(?Carbon $today = null): ?int
    {
        if (! self::supportsExpiry() || ! $this->expiry_date) {
            return null;
        }

        return (int) ($today ?? now())->copy()->startOfDay()->diffInDays($this->expiry_date->copy()->startOfDay(), false);
    }
}
