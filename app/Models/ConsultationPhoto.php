<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photograph taken at the clinic during a consultation.
 *
 * The clinic sees every photo on a consultation. The class adviser sees only
 * the ones the nurse deliberately shared — consultation detail otherwise
 * stops at the clinic (App\Support\ConsultationVisibility), and a photograph
 * of an injury is more revealing than the text it sits beside, not less.
 */
class ConsultationPhoto extends Model
{
    use Auditable;

    /** What a browser will render inline, and what the clinic actually takes. */
    public const ALLOWED_EXTENSIONS = 'jpg,jpeg,png,webp,heic';

    /** 8 MB in kilobytes — a phone photo, not a video. */
    public const MAX_KILOBYTES = 8192;

    protected $fillable = [
        'consultation_id',
        'institution_id',
        'file_path',
        'file_original_name',
        'file_size',
        'caption',
        'shared_with_adviser',
        'uploaded_by_name',
        'uploaded_by_role',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'shared_with_adviser' => 'boolean',
        'file_original_name' => EncryptedString::class,
        'caption' => EncryptedString::class,
        'uploaded_by_name' => EncryptedString::class,
    ];

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeSharedWithAdviser(Builder $query): Builder
    {
        return $query->where('shared_with_adviser', true);
    }
}
