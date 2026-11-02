<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One medicine allergy recorded for a learner, as it was typed, with the
 * RxNorm concept it matched (App\Support\MedicineAllergies keeps these in step
 * with Sheet 1, the Sulat-Pahibalo and the health assessment).
 *
 * Keyed by LRN + school like the other learner tables, so it follows the
 * learner across school years and goes with them in a purge. The text is a
 * fact about a child's health and is encrypted; the status and source are
 * plain so the retry job can find the unverified ones.
 */
class LearnerMedicineAllergy extends Model
{
    use Auditable;

    public const SOURCE_SHEET1 = 'sheet1';

    public const SOURCE_CONSENT = 'consent_form';

    public const SOURCE_ASSESSMENT = 'assessment';

    protected $fillable = [
        'institution_id',
        'student_lrn',
        'entered_text',
        'rx_concept_id',
        'match_status',
        'source',
        'created_by_name',
    ];

    protected function casts(): array
    {
        return [
            'entered_text' => EncryptedString::class,
            'created_by_name' => EncryptedString::class,
        ];
    }

    public function concept(): BelongsTo
    {
        return $this->belongsTo(RxConcept::class, 'rx_concept_id');
    }
}
