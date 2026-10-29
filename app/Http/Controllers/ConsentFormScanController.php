<?php

namespace App\Http\Controllers;

use App\Models\HealthConsentForm;
use App\Support\AdviserClassScope;
use App\Support\AuditTrail;
use App\Support\ConsentFormScanner;
use App\Support\ConsentPaperCopy;
use App\Support\LearnerPlacement;
use App\Support\StudentRosterSync;
use App\Support\UploadReadFailure;
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

        if (! $scanner->isAvailable()) {
            return response()->json([
                'message' => 'Consent scanning is not set up on this server. Fill the form in by hand.',
            ], 503);
        }

        $validated = $request->validate([
            // HEIC is not listed: the `image` rule refuses it whatever `mimes`
            // says, and a phone hands over a JPEG when the page does not ask
            // for HEIC.
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_KILOBYTES],
            // The learner the form is being recorded for, so the name on the
            // sheet can be checked against them. Optional: without it the
            // draft is returned unchecked.
            'lrn' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            $draft = $scanner->scan($validated['photo']);
        } catch (UploadReadFailure $e) {
            // Written for the adviser — busy, unreachable, a photo the reader
            // would not process — so it is shown as it is. The cause underneath
            // still goes to the log.
            report($e);

            return response()->json(['message' => $e->getMessage()], 503);
        } catch (Throwable $e) {
            // One message for every other failure, and never the exception's
            // own. A provider error carries rate-limit details, account ids and
            // endpoint URLs; none of that belongs on a teacher's screen, and
            // none of it tells them anything they can act on. The detail goes
            // to the log, where somebody who can act on it will look.
            report($e);

            return response()->json([
                'message' => 'The form could not be read. Try a clearer photo, or fill the form in by hand.',
            ], 503);
        }

        $learner = $this->learner($request, trim((string) ($validated['lrn'] ?? '')));

        if ($learner !== null) {
            $draft = ConsentFormScanner::checkLearner($draft, $learner['first_name'], $learner['last_name'], $learner['name']);
        }

        // The photograph carried a named child's health decisions past a
        // third-party model, so the read is recorded even though nothing was
        // written. The draft itself is not logged — it is not a record yet.
        AuditTrail::record(
            'scanned',
            'HealthConsentForm',
            null,
            'Scanned a photographed consent form to pre-fill the consent form'
                .($draft['unreadable'] ? ' (unreadable)' : '')
                .(($draft['learner_mismatch'] ?? false) ? ' (the name on the sheet is another learner\'s)' : ''),
        );

        $copy = ConsentPaperCopy::fromDraft($draft, $learner['grade'] ?? null);

        return response()->json([
            'draft' => $draft,
            // The sheet drawn back as the parent marked it, in the letter's
            // own words, by the partial every read-back of a form uses.
            'copy_html' => $copy !== null ? view('consent-forms._paper-copy', ['copy' => $copy])->render() : null,
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

    /**
     * The learner the adviser is recording for, if they are in this adviser's
     * own class — read from the roster the rest of the module reads, so a
     * learner of another class (or school) is never compared against, and the
     * answer never says anything about them.
     *
     * @return array{first_name: string, last_name: string, name: string, grade: int|null}|null
     */
    private function learner(Request $request, string $lrn): ?array
    {
        if ($lrn === '') {
            return null;
        }

        StudentRosterSync::syncToSession($request);

        $row = AdviserClassScope::rosterRows($request)
            ->first(fn (array $row): bool => (string) ($row['lrn'] ?? '') === $lrn);

        if ($row === null) {
            return null;
        }

        $first = trim((string) ($row['first_name'] ?? ''));
        $last = trim((string) ($row['last_name'] ?? ''));

        return [
            'first_name' => $first,
            'last_name' => $last,
            'name' => trim($first.' '.$last),
            'grade' => LearnerPlacement::gradeNumber((string) ($row['grade_level'] ?? '')),
        ];
    }
}
