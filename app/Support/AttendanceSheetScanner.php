<?php

namespace App\Support;

use Anthropic\Client;
use Anthropic\Messages\Base64ImageSource;
use Anthropic\Messages\ImageBlockParam;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Reads a photographed feeding-attendance sheet with Claude vision.
 *
 * The design decision that matters: the roster is sent WITH the photo, and the
 * model is asked to match a mark to an already-known learner rather than read
 * names cold. Matching a tick to a name we already hold is a far easier and
 * more reliable task than transcribing handwriting, and it means a misread name
 * cannot invent a learner who does not exist. The model is never asked to
 * identify anyone — it is given the names and asked which row is marked.
 *
 * Three marks come back per learner: present, absent, or unclear. "Unclear" is
 * a first-class answer, not a failure: the model is told explicitly to use it
 * rather than guess, and every unclear mark is surfaced to staff for a human
 * decision. Nothing here writes to the database — the caller owns that, so a
 * failed scan can never leave a half-written period behind.
 */
class AttendanceSheetScanner
{
    public const MARK_PRESENT = 'P';

    public const MARK_ABSENT = 'A';

    public const MARK_UNCLEAR = '?';

    /** Claude vision tops out at 2576px on the long edge; larger is wasted cost. */
    private const MAX_EDGE_PX = 2576;

    public function __construct(private readonly ?Client $client = null) {}

    public static function isConfigured(): bool
    {
        return filled(config('services.anthropic.key'));
    }

    /**
     * @param  list<array{id:int,name:string,grade:string,section:string}>  $roster
     * @return array{marks: array<int,string>, unreadable: bool, note: string}
     */
    public function scan(UploadedFile $photo, array $roster, string $sessionDate): array
    {
        if ($roster === []) {
            throw new RuntimeException('No learners on file to match against.');
        }

        if (! self::isConfigured()) {
            throw new RuntimeException('Attendance scanning is not configured. Set ANTHROPIC_API_KEY.');
        }

        $client = $this->client ?? new Client(apiKey: (string) config('services.anthropic.key'));

        $message = $client->messages->create(
            model: (string) config('services.anthropic.model', 'claude-opus-5'),
            maxTokens: 16000,
            system: $this->systemPrompt(),
            // Accuracy matters more than latency here: a misread mark becomes a
            // health record for a child flagged for malnutrition risk.
            outputConfig: [
                'effort' => 'high',
                'format' => $this->responseSchema(),
            ],
            messages: [[
                'role' => 'user',
                'content' => [
                    ImageBlockParam::with(
                        source: Base64ImageSource::with(
                            data: base64_encode((string) file_get_contents($photo->getRealPath())),
                            mediaType: $this->mediaType($photo),
                        ),
                    ),
                    ['type' => 'text', 'text' => $this->userPrompt($roster, $sessionDate)],
                ],
            ]],
        );

        return $this->parse($message, $roster);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You read photographed school feeding-programme attendance sheets.

        You are given the roster of enrolled learners and a photo of the sheet
        for one session. Your job is to decide, for each learner ON THE ROSTER,
        whether their row is marked present or absent.

        Match marks to the roster names you were given. Do not transcribe names
        from the photo and do not report a learner who is not on the roster. If
        a roster learner has no row on the sheet, their mark is "?".

        Report "?" whenever you are not certain: the mark is ambiguous, the row
        is blurred, cut off, smudged, or obscured, two marks conflict, or you
        cannot tell which row a mark belongs to. "?" is the correct, expected
        answer in those cases — a human will review every one of them. Guessing
        is worse than "?" here, because these records decide whether a child is
        flagged for malnutrition follow-up. Never infer a mark from what seems
        typical, from other learners' marks, or from the sheet's overall pattern.

        Common conventions: a tick, "P", "/", or a filled box means present. An
        "X", "A", a dash, or an empty cell in an otherwise-filled column means
        absent. If the sheet uses a legend that contradicts this, follow the
        sheet.

        Return exactly one entry for every roster_id you were given — no more,
        no fewer.
        PROMPT;
    }

    /** @param  list<array{id:int,name:string,grade:string,section:string}>  $roster */
    private function userPrompt(array $roster, string $sessionDate): string
    {
        $lines = [];
        foreach ($roster as $entry) {
            $where = trim(($entry['grade'] ?? '').' '.($entry['section'] ?? ''));
            $lines[] = $entry['id'].'. '.$entry['name'].($where !== '' ? ' — '.$where : '');
        }

        return "Session date: {$sessionDate}\n\n"
            ."Roster (roster_id. name — grade/section):\n"
            .implode("\n", $lines)."\n\n"
            .'Read the attached sheet and return the mark for each roster_id above.';
    }

    /** Structured output pins the shape so no free-text parsing is needed. */
    private function responseSchema(): array
    {
        return [
            'type' => 'json_schema',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'marks' => [
                        'type' => 'array',
                        'description' => 'One entry per roster_id supplied.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'roster_id' => ['type' => 'integer'],
                                'mark' => [
                                    'type' => 'string',
                                    'enum' => [self::MARK_PRESENT, self::MARK_ABSENT, self::MARK_UNCLEAR],
                                ],
                            ],
                            'required' => ['roster_id', 'mark'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'sheet_unreadable' => [
                        'type' => 'boolean',
                        'description' => 'True when the photo is too poor to read at all.',
                    ],
                    'note' => [
                        'type' => 'string',
                        'description' => 'One short sentence for staff about photo quality or anything odd.',
                    ],
                ],
                'required' => ['marks', 'sheet_unreadable', 'note'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * Anything the model leaves out, duplicates, or returns for an unknown
     * learner resolves to "unclear" rather than being dropped or guessed — a
     * missing answer is still a session a human has to look at.
     *
     * @param  list<array{id:int,name:string,grade:string,section:string}>  $roster
     * @return array{marks: array<int,string>, unreadable: bool, note: string}
     */
    private function parse(object $message, array $roster): array
    {
        $payload = $this->decodePayload($message);

        $valid = [self::MARK_PRESENT, self::MARK_ABSENT, self::MARK_UNCLEAR];
        $rosterIds = array_column($roster, 'id');
        $marks = array_fill_keys($rosterIds, self::MARK_UNCLEAR);

        foreach ((array) ($payload['marks'] ?? []) as $row) {
            $id = (int) ($row['roster_id'] ?? 0);
            $mark = strtoupper(trim((string) ($row['mark'] ?? '')));

            if (array_key_exists($id, $marks) && in_array($mark, $valid, true)) {
                $marks[$id] = $mark;
            }
        }

        // A sheet the model calls unreadable is treated as entirely unconfirmed,
        // whatever else it returned — one review queue, no partial trust.
        $unreadable = (bool) ($payload['sheet_unreadable'] ?? false);
        if ($unreadable) {
            $marks = array_fill_keys($rosterIds, self::MARK_UNCLEAR);
        }

        return [
            'marks' => $marks,
            'unreadable' => $unreadable,
            'note' => trim((string) ($payload['note'] ?? '')),
        ];
    }

    private function decodePayload(object $message): array
    {
        // A refusal returns HTTP 200 with empty/partial content — check before
        // reading, or a declined scan looks like a sheet with no marks.
        if (($message->stopReason ?? null) === 'refusal') {
            throw new RuntimeException('The image could not be processed. Try a clearer photo of the attendance sheet.');
        }

        $text = '';
        foreach ($message->content as $block) {
            if (($block->type ?? null) === 'text') {
                $text .= $block->text;
            }
        }

        $decoded = json_decode(trim($text), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('The scan returned an unreadable result. Please try again.');
        }

        return $decoded;
    }

    private function mediaType(UploadedFile $photo): string
    {
        return match (strtolower((string) $photo->getClientOriginalExtension())) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }

    public static function maxEdgePixels(): int
    {
        return self::MAX_EDGE_PX;
    }
}
