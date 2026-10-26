<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One lot of a medicine on the clinic shelf — the stock an item was created
 * with, or one delivery — with its own expiry date and how much of it is left.
 *
 * The lots of an item add up to its `stock_quantity`, and App\Support\
 * MedicineBatches is the only thing that writes them: a receipt adds one, a
 * dispense draws from the one that expires first, and expired stock is taken
 * off the shelf from here. Who took it off is a staff name and is encrypted;
 * everything the shelf is counted and ordered on stays plain.
 */
class MedicineBatch extends Model
{
    use Auditable;

    protected $fillable = [
        'medicine_id',
        'institution_id',
        'medicine_receipt_id',
        'expiry_date',
        'received_at',
        'quantity_received',
        'quantity_remaining',
        'quantity_disposed',
        'source',
        'disposed_at',
        'disposed_by_name',
        'disposed_by_role',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'received_at' => 'date',
            'quantity_received' => 'integer',
            'quantity_remaining' => 'integer',
            'quantity_disposed' => 'integer',
            'disposed_at' => 'datetime',
            'disposed_by_name' => EncryptedString::class,
        ];
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(MedicineReceipt::class, 'medicine_receipt_id');
    }

    /** Lots with something still on the shelf. */
    public function scopeOnShelf(Builder $query): Builder
    {
        return $query->where('quantity_remaining', '>', 0);
    }
}
