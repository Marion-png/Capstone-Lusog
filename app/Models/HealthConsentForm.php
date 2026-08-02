<?php

namespace App\Models;

use App\Casts\EncryptedArray;
use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HealthConsentForm extends Model
{
    use Auditable;
    use HasFactory;

    // Workflow statuses
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent_to_parent';

    public const STATUS_SIGNED = 'signed_by_parent';

    public const STATUS_REVIEWED = 'reviewed_by_adviser';

    // Official header defaults (Sta. Ana National High School Sulat-Pahibalo)
    public const DEFAULT_DIVISION = 'DAVAO CITY';

    public const DEFAULT_SCHOOL_NAME = 'STA. ANA NATIONAL HIGH SCHOOL';

    public const DEFAULT_SCHOOL_ADDRESS = 'DAMASO SUAZO ST., BRGY. 28-C, DAVAO CITY';

    public const PRINCIPAL_NAME = 'WELITO I. ROSAL';

    // Consent choices
    public const CONSENT_ALL = 'all';

    public const CONSENT_SPECIFIC = 'specific';

    public const CONSENT_DENY = 'deny';

    /**
     * Health services exactly as they appear on the paper Sulat-Pahibalo.
     * Keys are stored in the `services` JSON column; `children` are indented
     * sub-items on the printed form.
     */
    public const SERVICES = [
        'checkup' => [
            'label' => 'Check Up / Eksaminasyon (sa Nurse o Doktor) ug Paghatag ug tambal kon gikinahanglan',
            'children' => [],
        ],
        'dental' => [
            'label' => 'Check Up sa Dentist ug Ibot sa Ngipon / Paghatag ug tambal kon gikinahanglan',
            'children' => [],
        ],
        'deworming' => [
            'label' => 'Pag Purga / Pagpainom ug Tambal sa:',
            'children' => [
                'deworming_worms' => 'Pag Bitok / Worms',
                'deworming_schistosomiasis' => 'Schistosomiasis (sa mga lugar nga common / endemic ang sakit)',
                'deworming_filariasis' => 'Filariasis (sa mga lugar nga common / endemic ang sakit)',
            ],
        ],
        'tas' => [
            'label' => 'Transmission Assessment Survey (TAS) on Lymphatic Filariasis / Pagsuta sa kagaw sa Sakit nga Filariasis',
            'children' => [],
        ],
        'iron' => [
            'label' => 'Iron Supplementation / Pagpainom ug Tambal para sa Kulang og Pula nga Dugo',
            'children' => [],
        ],
        'vitamin_a' => [
            'label' => 'Vitamin A Supplementation / Pagpainom ug Bitamina A',
            'children' => [],
        ],
        'immunization' => [
            'label' => 'Immunization / Bakuna:',
            'children' => [
                'immunization_grade7' => 'Grade 7 Male (Lalake) ug Female (Babae) - MR, Td',
            ],
        ],
    ];

    protected $fillable = [
        'token', 'school_year', 'institution_id',
        'division', 'school_name', 'school_address',
        'student_lrn', 'student_name', 'student_address', 'grade_level', 'section', 'parent_guardian_name',
        'services', 'status',
        'consent_choice', 'consent_exceptions', 'refusal_reason',
        'allergy_food', 'allergy_medicine', 'prev_immunization', 'other_illness',
        'signature', 'sent_at', 'signed_at', 'reviewed_at',
        'created_by_name', 'adviser_unread', 'audit',
    ];

    /**
     * Student identity, consent answers, health details, and the signature
     * image are encrypted at rest. token / student_lrn / school_year /
     * status / institution_id / adviser_unread stay plain — queries filter
     * on them.
     */
    protected $casts = [
        'student_name' => EncryptedString::class,
        'student_address' => EncryptedString::class,
        'parent_guardian_name' => EncryptedString::class,
        'consent_choice' => EncryptedString::class,
        'consent_exceptions' => EncryptedString::class,
        'refusal_reason' => EncryptedString::class,
        'allergy_food' => EncryptedString::class,
        'allergy_medicine' => EncryptedString::class,
        'prev_immunization' => EncryptedString::class,
        'other_illness' => EncryptedString::class,
        'signature' => EncryptedString::class,
        'services' => EncryptedArray::class,
        'audit' => EncryptedArray::class,
        'adviser_unread' => 'boolean',
        'sent_at' => 'datetime',
        'signed_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    /** Every valid service key, including children. */
    public static function serviceKeys(): array
    {
        $keys = [];
        foreach (self::SERVICES as $key => $service) {
            $keys[] = $key;
            $keys = array_merge($keys, array_keys($service['children']));
        }

        return $keys;
    }

    /** Flat key => label map for displaying stored selections. */
    public static function serviceLabels(): array
    {
        $labels = [];
        foreach (self::SERVICES as $key => $service) {
            $labels[$key] = $service['label'];
            foreach ($service['children'] as $childKey => $childLabel) {
                $labels[$childKey] = $childLabel;
            }
        }

        return $labels;
    }

    public function hasService(string $key): bool
    {
        return in_array($key, $this->services ?? [], true);
    }

    /** Display label + badge color per status, used across all role views. */
    public static function statusBadges(): array
    {
        return [
            self::STATUS_DRAFT => ['label' => 'Draft', 'bg' => '#f1f5f9', 'fg' => '#475569'],
            self::STATUS_SENT => ['label' => 'Awaiting Parent Response', 'bg' => '#fef3c7', 'fg' => '#92400e'],
            self::STATUS_SIGNED => ['label' => 'Signed by Parent', 'bg' => '#dbeafe', 'fg' => '#1e40af'],
            self::STATUS_REVIEWED => ['label' => 'Reviewed — Available to School Nurse', 'bg' => '#dcfce7', 'fg' => '#166534'],
        ];
    }

    public function statusBadge(): array
    {
        return self::statusBadges()[$this->status] ?? self::statusBadges()[self::STATUS_DRAFT];
    }

    public function isLockedForAdviser(): bool
    {
        return $this->status !== self::STATUS_DRAFT;
    }

    public function isLockedForParent(): bool
    {
        return $this->status !== self::STATUS_SENT;
    }

    /** Append an entry to the audit trail. */
    public function addAudit(string $action, string $actorRole, string $actorName): void
    {
        $audit = $this->audit ?? [];
        $audit[] = [
            'at' => now()->toDateTimeString(),
            'action' => $action,
            'actor_role' => $actorRole,
            'actor_name' => $actorName,
        ];
        $this->audit = $audit;
    }

    public static function currentSchoolYear(): string
    {
        return ParentalConsentForm::currentSchoolYear();
    }
}
