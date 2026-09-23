<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Reads a photographed or scanned CLASS MASTERLIST with Gemini vision.
 *
 * The spreadsheet import already takes the masterlist as a file. This takes it
 * as a picture — the form the adviser actually has on their desk, photographed
 * or scanned, rather than a workbook somebody has to build first. What comes
 * back is shaped exactly like StudentImportSheet's output, so the controller
 * runs it through the very same validation and the very same enrolLearner()
 * write: there is one enrolment path, and a camera is not a second one.
 *
 * Four rules shape the design.
 *
 * 1. **The model reports; the server decides whether this is the form.** It is
 *    asked what it read — the heading lines, the name caption, the column
 *    headings, the band rows — and ClassMasterlistTemplate::mismatches() rules
 *    on it. A model asked "is this a class masterlist?" will say yes to an SBFP
 *    beneficiary list, a seating chart or last year's grade sheet, and every
 *    one of those would enrol a class's worth of wrong learners. The model's
 *    own verdict is taken as well, but only ever to *refuse*, never to admit.
 *
 * 2. **A refusal writes nothing.** Reading happens here, writing happens in the
 *    controller, and nothing is written until the whole document has been
 *    accepted — so a sheet that turns out to be the wrong form cannot leave
 *    half a class enrolled behind it.
 *
 * 3. **Unreadable is not absent.** A learner whose name or LRN could not be
 *    read confidently is returned as an *error* against their line, never as a
 *    row with a guessed LRN. An LRN is a national identifier that becomes the
 *    key of a child's health record: a transposed digit is a new learner, and a
 *    new learner nobody notices. The model is told to leave a field blank
 *    rather than complete it, and a blank one is reported.
 *
 * 4. **The sheet's own bands are where sex comes from.** The form has no Sex
 *    column — it lists the boys under a row reading MALE and the girls under
 *    one reading FEMALE — so the model is asked which band each learner sits
 *    under, exactly as StudentImportSheet reads it off the spreadsheet.
 *
 * Unlike the attendance scanner, an accepted read *is* written: an adviser
 * uploading their own class list is enrolling learners they are responsible
 * for, every field lands on a record they can correct, and nothing here decides
 * whether a child is fed. Each row still passes the enrolment rules, the class
 * scope and the refusal of an LRN belonging to another class.
 *
 * Requires GEMINI_API_KEY. Without one the route is disabled and the
 * spreadsheet import is unaffected.
 */
class MasterlistSheetScanner
{
    /** Beyond this a single upload is refused: a class is not a school. */
    public const MAX_ROWS = StudentImportSheet::MAX_ROWS;

    /** What a camera or a scanner produces. */
    public const ACCEPTED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    /**
     * Whether this upload is a picture of the masterlist rather than a workbook.
     *
     * The adviser has one file picker for both, so something has to tell them
     * apart, and the file itself is the only honest answer — a teacher should
     * not have to declare which kind of file they are holding before choosing
     * it. Read off the name the browser sent, with the detected type as a
     * fallback for a phone that uploads "image.tmp".
     */
    public static function handles(UploadedFile $file): bool
    {
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->guessExtension() ?: ''));

        if (in_array($extension, self::ACCEPTED_EXTENSIONS, true)) {
            return true;
        }

        $mime = strtolower((string) $file->getMimeType());

        return str_starts_with($mime, 'image/') || $mime === 'application/pdf';
    }

    public static function isConfigured(): bool
    {
        return filled(config('services.gemini.key'));
    }

    public static function maxUploadKb(): int
    {
        return max(1, (int) config('services.gemini.max_upload_kb', 10240));
    }

    /**
     * @return array{matches_template: bool, mismatches: list<string>, document_seen: string, rows: list<array{line: int, data: array<string, string>}>, errors: list<array{line: int, message: string}>, total: int, note: string}
     */
    public function scan(UploadedFile $sheet): array
    {
        if (! self::isConfigured()) {
            throw new RuntimeException('Masterlist scanning is not configured. Set GEMINI_API_KEY.');
        }

        $payload = $this->ask($sheet);

        return $this->parse($payload);
    }

    /**
     * One call to Gemini, with the sheet inline and the answer shape pinned.
     *
     * @return array<string, mixed>
     */
    private function ask(UploadedFile $sheet): array
    {
        $endpoint = rtrim((string) config('services.gemini.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $model = (string) config('services.gemini.model', 'gemini-3.8-flash');

        $contents = @file_get_contents((string) $sheet->getRealPath());

        if ($contents === false || $contents === '') {
            throw new RuntimeException('The uploaded file could not be read.');
        }

        try {
            $response = Http::withHeaders([
                // The key travels in a header rather than the query string:
                // a URL is the one part of a request that gets logged.
                'x-goog-api-key' => (string) config('services.gemini.key'),
            ])
                ->timeout(max(30, (int) config('services.gemini.timeout', 120)))
                // A busy model answers 503, and a class of forty learners is too
                // much work to lose to a spike that clears in a second. Only the
                // transient statuses are retried — a 400 or a 404 is an answer,
                // and asking it again gets the same one. `throw: false` leaves
                // the failure to the check below, so one path reports it.
                ->retry(3, 1500, function (Throwable $e): bool {
                    return $e instanceof ConnectionException
                        || ($e instanceof RequestException
                            && in_array($e->response->status(), [408, 429, 500, 502, 503, 504], true));
                }, throw: false)
                ->acceptJson()
                ->asJson()
                ->post($endpoint.'/models/'.$model.':generateContent', [
                    'system_instruction' => [
                        'parts' => [['text' => $this->systemPrompt()]],
                    ],
                    'contents' => [[
                        'role' => 'user',
                        'parts' => [
                            [
                                'inline_data' => [
                                    'mime_type' => $this->mediaType($sheet),
                                    'data' => base64_encode($contents),
                                ],
                            ],
                            ['text' => $this->userPrompt()],
                        ],
                    ]],
                    'generationConfig' => [
                        // Transcription, not composition: the same sheet has to
                        // read the same way twice.
                        'temperature' => 0,
                        'responseMimeType' => 'application/json',
                        'responseSchema' => $this->responseSchema(),
                    ],
                ]);
        } catch (Throwable $e) {
            throw new RuntimeException('The masterlist could not be sent for reading. '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            // A busy or rate-limited reader is the one failure a teacher can do
            // something about, so it is said in those terms rather than as a
            // status code. It survives the retries above often enough to reach
            // here, and "HTTP 503" on an enrolment screen tells nobody to
            // simply try again in a minute.
            if (in_array($response->status(), [429, 503], true)) {
                throw new RuntimeException('The reader is busy at the moment and nothing was enrolled. Try again in a few minutes.');
            }

            // Everything else keeps the provider's own message: a wrong model
            // id and an expired key both arrive here and look identical without
            // it.
            $reason = trim((string) $response->json('error.message', ''));

            throw new RuntimeException('The reader returned HTTP '.$response->status().($reason !== '' ? ': '.$reason : '.'));
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('The reader returned an unreadable result. Please try again.');
        }

        return $body;
    }

    private function systemPrompt(): string
    {
        $caption = ClassMasterlistTemplate::NAME_CAPTION;
        $title = ClassMasterlistTemplate::TITLE;
        $bands = implode(' and ', ClassMasterlistTemplate::BANDS);

        return <<<PROMPT
        You transcribe photographed and scanned DepEd CLASS MASTERLIST forms for
        a Philippine school. You do two things: report what document you are
        looking at, and transcribe the learners listed on it.

        The form you are being shown, when it is the right one, looks like this:
        - A heading block: the school name, its address, "{$title}", the grade
          and section, "MASTERLIST", and the school year.
        - A table headed NO, then one merged caption over three name columns
          reading "{$caption}", then LRN, then REMARKS.
        - The list split into bands: a row reading {$bands} and nothing else,
          with that band's learners numbered from 1 underneath it.
        - The adviser's name and the word ADVISER under the table.

        Reporting the document:
        - Copy the heading lines, the name caption, the column headings and the
          band rows exactly as they are printed. Do not tidy them, translate
          them, correct their spelling or supply one you expect to be there. If
          the document has no such line, leave it out.
        - This matters more than anything else you do here. Those lines are what
          decides whether the upload is accepted at all, and reporting a heading
          the paper does not carry would let an unrelated document through.
        - Say in one short phrase what the document appears to be, whatever it
          turns out to be.

        Transcribing the learners:
        - One entry per learner, in the order they appear, top to bottom.
        - Split each name into last name, first name and middle name exactly as
          the three columns divide it. A middle initial goes in the middle name.
        - Copy the LRN digit by digit. It is normally 12 digits.
        - Set sex from the band the learner is listed under: MALE or FEMALE.
        - **Leave a field blank rather than completing it.** If a name is cut
          off, smudged, overwritten or obscured, if you cannot tell which digit
          an LRN character is, or if a row is too blurred to read, leave that
          field empty. A blank field is reported to the teacher and fixed by
          hand. A guessed one becomes a child's health record under the wrong
          identifier, and nobody finds out.
        - Never invent a learner, never carry a name over from a neighbouring
          row, and never complete a partial LRN from the pattern of the others.
        - Skip the band rows, the heading block, blank numbered lines nobody has
          written on, and the adviser's signature. They are not learners.
        PROMPT;
    }

    private function userPrompt(): string
    {
        return 'Read the attached document. Report the headings, the name caption, '
            .'the column headings and the band rows exactly as printed, say what the '
            .'document is, and transcribe every learner listed on it.';
    }

    /**
     * Gemini takes an OpenAPI-subset schema and returns JSON matching it, so
     * nothing here parses free text. `propertyOrdering` is Gemini's own hint
     * and keeps the answer in the order the sheet reads.
     *
     * @return array<string, mixed>
     */
    private function responseSchema(): array
    {
        $stringList = fn (string $description): array => [
            'type' => 'ARRAY',
            'description' => $description,
            'items' => ['type' => 'STRING'],
        ];

        return [
            'type' => 'OBJECT',
            'properties' => [
                'document' => [
                    'type' => 'OBJECT',
                    'description' => 'What is printed on the document, copied exactly.',
                    'properties' => [
                        'headings' => $stringList('Every heading line above the table, top to bottom, exactly as printed.'),
                        'name_caption' => [
                            'type' => 'STRING',
                            'description' => 'The caption printed over the name column(s), exactly as printed. Empty when there is none.',
                        ],
                        'columns' => $stringList('The table column headings, left to right, exactly as printed.'),
                        'bands' => $stringList('Rows that carry one word and nothing else, such as MALE or FEMALE, exactly as printed.'),
                        'seen' => [
                            'type' => 'STRING',
                            'description' => 'One short phrase naming what this document appears to be.',
                        ],
                    ],
                    'required' => ['headings', 'name_caption', 'columns', 'bands', 'seen'],
                    'propertyOrdering' => ['headings', 'name_caption', 'columns', 'bands', 'seen'],
                ],
                'is_class_masterlist' => [
                    'type' => 'BOOLEAN',
                    'description' => 'True only when this is a DepEd CLASS MASTERLIST of learners.',
                ],
                'learners' => [
                    'type' => 'ARRAY',
                    'description' => 'One entry per learner listed, in the order they appear.',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'last_name' => ['type' => 'STRING', 'description' => 'Family name, or empty when it cannot be read.'],
                            'first_name' => ['type' => 'STRING', 'description' => 'Given name, or empty when it cannot be read.'],
                            'middle_name' => ['type' => 'STRING', 'description' => 'Middle name or initial, empty when the sheet carries none.'],
                            'lrn' => ['type' => 'STRING', 'description' => 'The LRN exactly as printed, or empty when any character is uncertain.'],
                            'sex' => ['type' => 'STRING', 'description' => 'MALE or FEMALE, from the band this learner is listed under. Empty when there is no band.'],
                        ],
                        'required' => ['last_name', 'first_name', 'middle_name', 'lrn', 'sex'],
                        'propertyOrdering' => ['last_name', 'first_name', 'middle_name', 'lrn', 'sex'],
                    ],
                ],
                'note' => [
                    'type' => 'STRING',
                    'description' => 'One short sentence for the teacher about image quality or anything odd.',
                ],
            ],
            'required' => ['document', 'is_class_masterlist', 'learners', 'note'],
            'propertyOrdering' => ['document', 'is_class_masterlist', 'learners', 'note'],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{matches_template: bool, mismatches: list<string>, document_seen: string, rows: list<array{line: int, data: array<string, string>}>, errors: list<array{line: int, message: string}>, total: int, note: string}
     */
    private function parse(array $body): array
    {
        $payload = $this->decodePayload($body);

        $document = is_array($payload['document'] ?? null) ? $payload['document'] : [];
        $seen = trim((string) ($document['seen'] ?? ''));
        $mismatches = ClassMasterlistTemplate::mismatches($document);

        // The model's own verdict can only ever refuse. It is a second lock on
        // the same door, never a key: a document that reads as this form but
        // that the model says is something else is still refused, while one the
        // model calls a masterlist but whose headings are absent is refused too.
        if ($mismatches === [] && ! (bool) ($payload['is_class_masterlist'] ?? false)) {
            $mismatches[] = 'anything identifying it as a class masterlist';
        }

        $note = trim((string) ($payload['note'] ?? ''));

        if ($mismatches !== []) {
            return [
                'matches_template' => false,
                'mismatches' => $mismatches,
                'document_seen' => $seen,
                'rows' => [],
                'errors' => [],
                'total' => 0,
                'note' => $note,
            ];
        }

        $learners = array_values(array_filter((array) ($payload['learners'] ?? []), 'is_array'));

        $rows = [];
        $errors = [];

        foreach ($learners as $index => $learner) {
            $line = $index + 1;

            $lastName = $this->text($learner['last_name'] ?? '');
            $firstName = $this->text($learner['first_name'] ?? '');
            $middleName = $this->text($learner['middle_name'] ?? '');
            $lrn = $this->lrn($learner['lrn'] ?? '');
            $sex = $this->sex($learner['sex'] ?? '');

            // A blank row the model returned anyway is not a learner and not an
            // error — the form is full of numbered lines nobody has written on.
            if ($lastName === '' && $firstName === '' && $lrn === '') {
                continue;
            }

            if ($lastName === '' || $firstName === '') {
                $errors[] = ['line' => $line, 'message' => 'The learner\'s name could not be read from the sheet.'];

                continue;
            }

            if ($lrn === '') {
                $errors[] = [
                    'line' => $line,
                    'message' => 'The LRN for '.trim($lastName.', '.$firstName).' could not be read from the sheet.',
                ];

                continue;
            }

            $rows[] = [
                'line' => $line,
                'data' => [
                    'last_name' => $lastName,
                    'first_name' => $firstName,
                    'middle_name' => $middleName,
                    'lrn' => $lrn,
                    'gender' => $sex,
                ],
            ];
        }

        if (count($rows) > self::MAX_ROWS) {
            throw new RuntimeException('A single upload is limited to '.self::MAX_ROWS.' learners.');
        }

        return [
            'matches_template' => true,
            'mismatches' => [],
            'document_seen' => $seen,
            'rows' => $rows,
            'errors' => $errors,
            'total' => count($rows) + count($errors),
            'note' => $note,
        ];
    }

    /**
     * The JSON the model returned, or a refusal said plainly.
     *
     * A blocked prompt and an empty candidate both come back HTTP 200, so a
     * refusal read carelessly looks exactly like a sheet with nobody on it.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function decodePayload(array $body): array
    {
        $blocked = trim((string) data_get($body, 'promptFeedback.blockReason', ''));

        if ($blocked !== '') {
            throw new RuntimeException('The image could not be processed ('.$blocked.'). Try a clearer photo of the masterlist.');
        }

        $finish = trim((string) data_get($body, 'candidates.0.finishReason', ''));

        if ($finish !== '' && ! in_array($finish, ['STOP', 'MAX_TOKENS'], true)) {
            throw new RuntimeException('The image could not be processed ('.$finish.'). Try a clearer photo of the masterlist.');
        }

        $text = '';
        foreach ((array) data_get($body, 'candidates.0.content.parts', []) as $part) {
            if (is_array($part) && isset($part['text'])) {
                $text .= (string) $part['text'];
            }
        }

        $decoded = json_decode(trim($text), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('The scan returned an unreadable result. Please try again.');
        }

        return $decoded;
    }

    private function text(mixed $value): string
    {
        // Collapse the runs of whitespace a transcription picks up off a ruled
        // form, so "DELA  CRUZ" and "DELA CRUZ" are one name.
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    /**
     * An LRN is digits. A reading that is not is a reading, not an identifier —
     * it comes back empty and is reported against its line rather than being
     * written to a child's record.
     */
    private function lrn(mixed $value): string
    {
        // Separators an adviser or a form may have written in are not part of
        // the number; anything else means the read is not trustworthy.
        $digits = (string) preg_replace('/[\s\-]/', '', (string) $value);

        return $digits !== '' && ctype_digit($digits) ? $digits : '';
    }

    /** The band's own word, normalised to what the roster stores. */
    private function sex(mixed $value): string
    {
        return match (mb_strtolower(trim((string) $value))) {
            'male', 'm', 'boy' => 'Male',
            'female', 'f', 'girl' => 'Female',
            default => '',
        };
    }

    private function mediaType(UploadedFile $sheet): string
    {
        return match (strtolower((string) ($sheet->getClientOriginalExtension() ?: $sheet->guessExtension() ?: ''))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            default => 'image/jpeg',
        };
    }
}
