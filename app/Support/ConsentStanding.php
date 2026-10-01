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

    /**
     * A guardian's signed form is on file; nobody has keyed in its answer.
     *
     * Not a standing `for()` can return — see `badge()`. It is what a screen
     * prints, never what decides whether a service may be given.
     */
    public const RETURNED = 'returned';

    /**
     * The short label printed beside a learner, for every value `badge()` can
     * return. Typed once, so the profile's tab badge and the My Students
     * column cannot word one answer two ways.
     *
     * @var array<string, string>
     */
    public const LABELS = [
        self::APPROVED => 'Approved',
        self::PARTIAL => 'Partial',
        self::DECLINED => 'Declined',
        self::RETURNED => 'Returned',
        self::PENDING => 'Pending',
    ];

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
     * What to print beside a learner: the standing, plus one value of its own.
     *
     * A guardian's signed form on file whose answer nobody has keyed in
     * authorises nothing, so `for()` keeps it PENDING and every gate reads it
     * that way. But telling the adviser who has just filed that form that the
     * consent is still outstanding is simply wrong — the guardian has filled it
     * in and the school holds it. So the two questions are answered separately
     * and both from here, rather than each screen deciding for itself.
     */
    public static function badge(?HealthConsentForm $form, ?ParentalConsentForm $upload = null): string
    {
        $standing = self::for($form, $upload);

        return $standing === self::PENDING && self::guardianReturnedPaperForm($upload)
            ? self::RETURNED
            : $standing;
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
