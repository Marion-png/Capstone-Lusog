<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of one dispense came out of one lot.
 *
 * A dispense takes the lot that expires first and moves on to the next only
 * when that one runs out, so most dispenses have one of these and a few have
 * two. The expiry date is copied from the lot so the record keeps saying
 * which date was handed over. Nothing here is personal information — who the
 * medicine went to is on the dispense, encrypted.
 */
class MedicineDispenseBatch extends Model
{
    protected $fillable = [
        'medicine_dispense_id',
        'medicine_batch_id',
        'quantity',
        'expiry_date',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'expiry_date' => 'date',
        ];
    }

    public function dispense(): BelongsTo
    {
        return $this->belongsTo(MedicineDispense::class, 'medicine_dispense_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MedicineBatch::class, 'medicine_batch_id');
    }
}
