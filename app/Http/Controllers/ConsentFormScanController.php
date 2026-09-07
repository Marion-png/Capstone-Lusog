<?php

namespace App\Http\Controllers;

use App\Models\HealthConsentForm;
use App\Support\AuditTrail;
use App\Support\ConsentFormScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Reads a photographed, signed Sulat-Pahibalo and hands the class adviser a
 * filled-in draft of the on-screen consent form.
 *
 * **This endpoint records nothing.** It returns a proposal; the adviser reads
 * it against the paper in their hand and saves the form themselves through the
 * existing consent-form write path. A parent's consent authorises medical
 * procedures on a child, and a photograph read by a model is evidence for a
 * person to check, never the authorisation itself.
 *
 * That is why there is no `store` here and no flag that skips the review. The
 * scan is a typing aid.
 */
class ConsentFormScanController extends Controller
{
    /** The adviser collects these forms, so the scan is theirs. */
    private const SCAN_ROLES = ['class_adviser'];

    /** A phone photo of one sheet of paper. */
    private const MAX_KILOBYTES = 8192;

    public function scan(Request $request, ConsentFormScanner $scanner): JsonResponse
    {
        if (! in_array((string) $request->session()->get('active_role'), self::SCAN_ROLES, true)) {
            return response()->json(['message' => 'Only the class adviser may scan a consent form.'], 403);
        }

        if (! ConsentFormScanner::isConfigured()) {
            return response()->json([
                'message' => 'Consent scanning is not set up on this server. Fill the form in by hand.',
            ], 503);
        }

        $validated = $request->validate([
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,heic', 'max:'.self::MAX_KILOBYTES],
        ]);

        try {
            $draft = $scanner->scan($validated['photo']);
        } catch (Throwable $e) {
            // One message for every failure, and never the exception's own.
            // A provider error carries rate-limit details, account ids and
            // endpoint URLs; none of that belongs on a teacher's screen, and
            // none of it tells them anything they can act on. The detail goes
            // to the log, where somebody who can act on it will look.
            report($e);

            return response()->json([
                'message' => 'The form could not be read. Try a clearer photo, or fill the form in by hand.',
            ], 503);
        }

        // The photograph carried a named child's health decisions past a
        // third-party model, so the read is recorded even though nothing was
        // written. The draft itself is not logged — it is not a record yet.
        AuditTrail::record(
            'scanned',
            'HealthConsentForm',
            null,
            'Scanned a photographed consent form to pre-fill the consent form'
                .($draft['unreadable'] ? ' (unreadable)' : ''),
        );

        return response()->json([
            'draft' => $draft,
            'labels' => ConsentFormScanner::serviceKeys(),
            'choices' => [
                HealthConsentForm::CONSENT_ALL => 'Agreed to all services',
                HealthConsentForm::CONSENT_SPECIFIC => 'Agreed to some services',
                HealthConsentForm::CONSENT_DENY => 'Refused',
            ],
            // Said plainly on the response, so a caller cannot mistake this for
            // a saved consent.
            'saved' => false,
            'review_required' => true,
        ]);
    }
}
