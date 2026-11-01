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
    public const DEFAULT_REGION = 'Region XI';

    public const DEFAULT_DIVISION = 'DAVAO CITY';

    public const DEFAULT_SCHOOL_NAME = 'STA. ANA NATIONAL HIGH SCHOOL';

    public const DEFAULT_SCHOOL_ADDRESS = 'DAMASO SUAZO ST., BRGY. 28-C, DAVAO CITY';

    /**
     * There is deliberately no PRINCIPAL_NAME here any more.
     *
     * It held one school's principal, and the document printed it on every
     * school's letter — so a parent at another school was handed a consent
     * form signed by a head they have never met. The name is read per school
     * from `accounts` through `App\Support\SchoolSignatories::notedBy()`, the
     * same lookup every other DepEd form in this app uses, and a head the app
     * does not know prints a blank line to sign.
     */

    // Consent choices
    public const CONSENT_ALL = 'all';

    public const CONSENT_SPECIFIC = 'specific';

    public const CONSENT_DENY = 'deny';

    /** The parent's three consent boxes, worded exactly as the paper prints them. */
    public const CONSENT_TEXT = [
        self::CONSENT_ALL => 'Oo, ako mutugot nga mahatagan o mahimong benepisyaryo akong anak sa mga serbisyong panglawas nga ihatag sa iskwelahan base sa rekomendasyon sa DOH',
        self::CONSENT_SPECIFIC => 'Oo, ako mutugot nga mahatagan o mahimong benepisyaryo akong anak sa mga serbisyong panglawas, gawas lamang niini (palihug isulat):',
        self::CONSENT_DENY => 'Dili ko mutugot nga mahatagan o mahimong benepisyaryo akong anak sa mga serbisyong panglawas, tungod niining mosunod nga rason (palihug isulat):',
    ];

    /** The allergy and illness boxes, worded exactly as the paper prints them. */
    public const ALLERGY_TEXT = [
        'allergy' => 'Kon adunay Allergy ang bata, palihug butang ug tsek (✓) sa kahon kon unsang klase:',
        'allergy_food' => 'Pagkaon/Food (isulat unsang klaseng pagkaon)',
        'allergy_medicine' => 'Tambal/Medicines (isulat unsang klaseng tambal)',
        'prev_immunization' => 'Nahatag nga Bakuna/Previous Immunization (isulat unsang klaseng bakuna)',
        'other_illness' => 'Kasamtangang Sakit or Gibati / Other Illnesses',
    ];

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

    /**
     * A short name for each service, for lists and filters where the printed
     * label — written for a parent reading the letter — will not fit. Keyed
     * exactly as SERVICES is; a sub-item's name reads under its parent's
     * ("Deworming — worms"), so it is written without it.
     *
     * @var array<string, string>
     */
    public const SERVICE_NAMES = [
        'checkup' => 'Check-up',
        'dental' => 'Dental check-up',
        'deworming' => 'Deworming',
        'deworming_worms' => 'worms',
        'deworming_schistosomiasis' => 'schistosomiasis',
        'deworming_filariasis' => 'filariasis',
        'tas' => 'TAS (filariasis survey)',
        'iron' => 'Iron supplementation',
        'vitamin_a' => 'Vitamin A',
        'immunization' => 'Immunization',
        'immunization_grade7' => 'Grade 7 MR, Td',
    ];

    /**
     * The services a nurse plans a round of — one per thing given to a child,
     * so a heading that only groups sub-items ("Pag Purga") is not one of
     * them. A letter that ticked a heading covers its sub-items
     * (coversService()).
     *
     * @var array<string, string>
     */
    public const PLANNED_SERVICES = [
        'checkup' => 'Check-up (Nurse or Doctor)',
        'dental' => 'Dental check-up',
        'deworming_worms' => 'Deworming – Worms (Bitok)',
        'deworming_schistosomiasis' => 'Deworming – Schistosomiasis',
        'deworming_filariasis' => 'Deworming – Filariasis',
        'tas' => 'TAS – Lymphatic Filariasis survey',
        'iron' => 'Iron supplementation',
        'vitamin_a' => 'Vitamin A supplementation',
        'immunization_grade7' => 'Immunization – MR, Td (Grade 7 only)',
    ];

    protected $fillable = [
        'token', 'school_year', 'institution_id',
        'division', 'school_name', 'school_address',
        'student_lrn', 'student_name', 'student_address', 'grade_level', 'section', 'parent_guardian_name',
        'services', 'status',
        'consent_choice', 'consent_exceptions', 'refusal_reason',
        'allergy_food', 'allergy_medicine', 'prev_immunization', 'other_illness',
        'signature', 'paper_form_path', 'sent_at', 'signed_at', 'reviewed_at',
        'created_by_name', 'adviser_unread', 'audit',
    ];

    /**
     * Never serialized: the token is the parent's link (whoever holds it can
     * answer the form), the signature is a drawn image of a parent's hand, and
     * the photograph's location on disk is the server's business.
     */
    protected $hidden = ['token', 'signature', 'paper_form_path'];

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

    /** The service a sub-item sits under, or null for a top-level one. */
    public static function parentService(string $key): ?string
    {
        foreach (self::SERVICES as $parent => $service) {
            if (array_key_exists($key, $service['children'])) {
                return $parent;
            }
        }

        return null;
    }

    /** "Deworming", or "Deworming — worms" for a sub-item. */
    public static function serviceName(string $key): string
    {
        $name = self::SERVICE_NAMES[$key] ?? (self::serviceLabels()[$key] ?? $key);
        $parent = self::parentService($key);

        return $parent !== null ? self::SERVICE_NAMES[$parent].' — '.$name : $name;
    }

    /**
     * Whether a list of service keys covers one service.
     *
     * The printed form ticks a heading ("Pag Purga") and the sub-items under
     * it separately, and either says the letter is about that service: a
     * heading covers its sub-items, and a sub-item says its heading is on the
     * letter.
     *
     * @param  list<string>  $keys
     */
    public static function coversService(array $keys, string $service): bool
    {
        if (in_array($service, $keys, true)) {
            return true;
        }

        $parent = self::parentService($service);

        if ($parent !== null) {
            return in_array($parent, $keys, true);
        }

        return array_intersect(array_keys(self::SERVICES[$service]['children'] ?? []), $keys) !== [];
    }

    /**
     * The services in a list, named in the letter's own order, with any
     * sub-items under their heading: "Deworming (worms, filariasis)".
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function serviceNames(array $keys): array
    {
        $names = [];

        foreach (self::SERVICES as $parent => $service) {
            $children = array_values(array_filter(
                array_keys($service['children']),
                fn (string $child): bool => in_array($child, $keys, true),
            ));

            if (! in_array($parent, $keys, true) && $children === []) {
                continue;
            }

            $names[] = self::SERVICE_NAMES[$parent].($children !== []
                ? ' ('.implode(', ', array_map(fn (string $child): string => self::SERVICE_NAMES[$child], $children)).')'
                : '');
        }

        return $names;
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
