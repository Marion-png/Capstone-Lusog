<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A school applying to join the system.
 *
 * Approval creates the Institution and provisions its private database, so this
 * lives in the central database — it exists before the tenant does.
 */
class InstitutionRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'name', 'address', 'division', 'contact_person',
        'contact_email', 'contact_number', 'status',
        'decline_reason', 'institution_id', 'reviewed_at',
    ];

    protected $casts = ['reviewed_at' => 'datetime'];

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class);
    }
}
