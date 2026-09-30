<?php

namespace App\Support;

use App\Models\HealthConsentForm;
use App\Models\ParentalConsentForm;

/**
 * Where one learner's consent stands, whichever way it arrived.
 *
 * A consent reaches this school two ways — the digital Sulat-Pahibalo the
 * class adviser sends to the parent (`health_consent_forms`), and the signed
 * paper form the guardian fills in and the adviser scans and uploads
 * (`parental_consent_forms`) — and every screen asking "may this child be
 * given the service?" has to read both. This is the one place that decides,
 * so the adviser's Consent Forms page, their student profile and the counts
 * on their dashboard cannot report three different answers about one child.
 *
 * Two rules it keeps:
 *
 * - **A status is only reported once there is a document behind it.** A
 *   parent-signed online form carries its own e-signature, so it stands on its
 *   own. An uploaded record does not: the signed scan is its evidence, and the
 *   file is nullable on upload, so a record can exist carrying an answer with
 *   no scan attached. Reporting that as Approved would claim a consent the
 *   school cannot produce.
 * - **A letter nobody has answered is pending, not refused.** Drafts and
 *   sent-but-unsigned forms are still waiting on the guardian.
 *
 * `consent_choice` and `consent_type` are both encrypted at rest, so every
 * comparison here is on decrypted values in PHP — never in a WHERE.
 */
final class ConsentStanding
{
    public const APPROVED = 'approved';

    public const PARTIAL = 'partial';

    public const DECLINED = 'declined';

    public const PENDING = 'pending';

    /** The standing one learner's consent is in. */
    public static function for(?HealthConsentForm $form, ?ParentalConsentForm $upload = null): string
    {
        $answered = [HealthConsentForm::STATUS_SIGNED, HealthConsentForm::STATUS_REVIEWED];

        if ($form !== null && in_array($form->status, $answered, true)) {
            return match ($form->consent_choice) {
                HealthConsentForm::CONSENT_DENY => self::DECLINED,
                HealthConsentForm::CONSENT_SPECIFIC => self::PARTIAL,
                default => self::APPROVED,
            };
        }

        if ($upload !== null && $upload->file_path !== null) {
            return match ($upload->consent_type) {
                'refused' => self::DECLINED,
                'partial' => self::PARTIAL,
                'full' => self::APPROVED,
                // The upload dialog no longer asks what the parent answered, so
                // a scan usually carries none. The form is in and the school
                // holds it; what it says has not been recorded, and an answer
                // nobody has read authorises nothing.
                default => self::PENDING,
            };
        }

        return self::PENDING;
    }

    /**
     * Whether the guardian has returned a filled-in paper form for this
     * learner — the scan is the evidence, so a record with no file is not one.
     */
    public static function guardianReturnedPaperForm(?ParentalConsentForm $upload): bool
    {
        return $upload !== null && $upload->file_path !== null;
    }

    /** How the adviser recorded the parent's answer on a scanned form. */
    public static function uploadAnswerLabel(?ParentalConsentForm $upload): ?string
    {
        return match ($upload?->consent_type) {
            'full' => 'Consented to all health services',
            'partial' => 'Consented with exceptions',
            'refused' => 'Consent declined',
            default => null,
        };
    }
}
