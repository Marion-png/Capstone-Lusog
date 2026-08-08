<?php

namespace App\Support;

use Anthropic\Client;
use Anthropic\Messages\Base64ImageSource;
use Anthropic\Messages\ImageBlockParam;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Throwable;

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
 * A DMIRIE sheet is a grid, not a single session: names down the left, one
 * dated column per feeding day. So the scan reads the whole grid — every dated
 * column header, and every learner's mark underneath it — and returns one
 * session per column. Reading the dates off the sheet rather than trusting a
 * single date typed into the form is what keeps a photographed week from
 * collapsing into one day.
 *
 * Three marks come back per learner per session: present, absent, or unclear.
 * "Unclear" is a first-class answer, not a failure: the model is told
 * explicitly to use it rather than guess, and every unclear mark is surfaced to
 * staff for a human decision. Anything the model leaves out is unclear too — a
 * learner missing from a column's three lists is never assumed absent. Nothing
 * here writes to the database — the caller owns that, so a failed scan can
 * never leave a half-written period behind.
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
     * @param  string|null  $anchorDate  the date the coordinator says the sheet covers;
     *                                   used only to resolve partial column headers
     *                                   ("8", "Oct 8") and as the date of a lone
     *                                   column whose own header cannot be read
     * @return array{sessions: list<array{date: string, label: string, marks: array<int,string>}>, unreadable: bool, note: string}
     */
    public function scan(UploadedFile $photo, array $roster, ?string $anchorDate = null): array
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
                    ['type' => 'text', 'text' => $this->userPrompt($roster, $anchorDate)],
                ],
            ]],
        );

        return $this->parse($message, $roster, $anchorDate);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You read photographed school feeding-programme attendance sheets.

        The sheet is a grid: learners run down the left-hand side, and each
        column to the right is one feeding session, headed by its date. You are
        given the roster of enrolled learners and a photo of the sheet. For every
        dated column, decide which roster learners are marked present and which
        are marked absent in that column.

        Work column by column, and within a column row by row. Before you record
        a mark, check that the cell is in the column you think it is and on the
        row you think it is — a mark read off a neighbouring column or row is the
        most damaging mistake you can make here, and it is silent.

        Reading the dates:
        - Read each session column's date from its own header, exactly as the
          sheet gives it, and return it as YYYY-MM-DD.
        - Headers are often partial ("8", "Oct 8", "M 8"). Use the reference date
          given in the request to fill in the missing month or year, and use the
          neighbouring column headers to confirm the sequence makes sense.
        - If you cannot work out a column's date at all, still return the column,
          with date set to null and its label copied from the header. Do not
          invent a date, and do not reuse another column's date.
        - Return only columns that record attendance. Ignore total, remarks,
          signature, and summary columns.

        Reading the marks:
        - Common conventions: a tick, "P", "/", or a filled box means present. An
          "X", "A", or a dash means absent. If the sheet has a legend that
          contradicts this, follow the sheet.
        - Put each roster learner into exactly one of the column's three lists:
          present, absent, or unclear. Never put the same learner in two lists.
        - Use "unclear" whenever you are not certain: the mark is ambiguous, the
          cell is blank in a column where blank could mean either, the row is
          blurred, cut off, smudged, or obscured, two marks conflict, or you
          cannot tell which row or column a mark belongs to. Unclear is the
          correct, expected answer in those cases — a human reviews every one of
          them. Guessing is worse, because these records decide whether a child
          is flagged for malnutrition follow-up. Never infer a mark from what
          seems typical, from other learners' marks, or from the sheet's overall
          pattern.
        - A learner on the roster with no row on the sheet is unclear, not
          absent. A learner you omit from all three lists is treated as unclear.

        Match marks to the roster names you were given. Do not transcribe names
        from the photo, and never report a learner who is not on the roster.
        PROMPT;
    }

    /** @param  list<array{id:int,name:string,grade:string,section:string}>  $roster */
    private function userPrompt(array $roster, ?string $anchorDate): string
    {
        $lines = [];
        foreach ($roster as $entry) {
            $where = trim(($entry['grade'] ?? '').' '.($entry['section'] ?? ''));
            $lines[] = $entry['id'].'. '.$entry['name'].($where !== '' ? ' — '.$where : '');
        }

        $reference = $anchorDate !== null && $anchorDate !== ''
            ? "Reference date for this sheet: {$anchorDate}. Use it only to fill in a month or year a column header leaves out.\n"
            : '';

        return $reference
            ."Today's date: ".now()->toDateString()."\n\n"
            ."Roster (roster_id. name — grade/section):\n"
            .implode("\n", $lines)."\n\n"
            .'Read the attached sheet and return one entry per dated session column, '
            .'listing the roster_ids marked present, absent, and unclear under that date.';
    }

    /**
     * Structured output pins the shape so no free-text parsing is needed.
     *
     * Marks come back as three lists of roster_ids per session rather than one
     * object per learner per column: on a full roster across a week's columns
     * that is the difference between a compact answer and one large enough to
     * risk being truncated mid-grid.
     */
    private function responseSchema(): array
    {
        $rosterIdList = fn (string $description): array => [
            'type' => 'array',
            'description' => $description,
            'items' => ['type' => 'integer'],
        ];

        return [
            'type' => 'json_schema',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'sessions' => [
                        'type' => 'array',
                        'description' => 'One entry per dated attendance column on the sheet, left to right.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'date' => [
                                    'type' => ['string', 'null'],
                                    'description' => 'The column date as YYYY-MM-DD, or null when the header cannot be read.',
                                ],
                                'label' => [
                                    'type' => 'string',
                                    'description' => 'The column header exactly as printed on the sheet.',
                                ],
                                'present' => $rosterIdList('roster_ids marked present in this column.'),
                                'absent' => $rosterIdList('roster_ids marked absent in this column.'),
                                'unclear' => $rosterIdList('roster_ids whose mark in this column could not be read confidently.'),
                            ],
                            'required' => ['date', 'label', 'present', 'absent', 'unclear'],
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
                'required' => ['sessions', 'sheet_unreadable', 'note'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * Anything the model leaves out, duplicates, or returns for an unknown
     * learner resolves to "unclear" rather than being dropped or guessed — a
     * missing answer is still a session a human has to look at.
     *
     * A column whose date cannot be resolved is dropped, not guessed: a mark we
     * cannot attribute to a day is worse than no mark, because it would land on
     * whatever date we invented and count there forever. The one exception is a
     * single-column sheet, where the date the coordinator entered is the only
     * date in play and can stand in for the unread header.
     *
     * @param  list<array{id:int,name:string,grade:string,section:string}>  $roster
     * @return array{sessions: list<array{date: string, label: string, marks: array<int,string>}>, unreadable: bool, note: string}
     */
    private function parse(object $message, array $roster, ?string $anchorDate): array
    {
        $payload = $this->decodePayload($message);

        $rosterIds = array_column($roster, 'id');
        $rows = array_values(array_filter((array) ($payload['sessions'] ?? []), 'is_array'));
        $unreadable = (bool) ($payload['sheet_unreadable'] ?? false);
        $notes = [];
        $note = trim((string) ($payload['note'] ?? ''));
        if ($note !== '') {
            $notes[] = $note;
        }

        $sessions = [];
        $seenDates = [];

        foreach ($rows as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            $date = $this->resolveSessionDate($row['date'] ?? null);

            // The lone-column fallback: only safe when there is nothing to
            // confuse it with.
            if ($date === null && count($rows) === 1) {
                $date = $this->resolveSessionDate($anchorDate);
            }

            if ($date === null) {
                $notes[] = 'A column'.($label !== '' ? ' ("'.$label.'")' : '').' was skipped because its date could not be read.';

                continue;
            }

            if (isset($seenDates[$date])) {
                $notes[] = 'Two columns read as '.$date.'; only the first was kept.';

                continue;
            }

            $seenDates[$date] = true;
            $sessions[] = [
                'date' => $date,
                'label' => $label !== '' ? $label : $date,
                // A sheet the model calls unreadable is treated as entirely
                // unconfirmed, whatever else it returned — one review queue, no
                // partial trust.
                'marks' => $unreadable
                    ? array_fill_keys($rosterIds, self::MARK_UNCLEAR)
                    : $this->marksForSession($row, $rosterIds),
            ];
        }

        return [
            'sessions' => $sessions,
            'unreadable' => $unreadable,
            'note' => trim(implode(' ', $notes)),
        ];
    }

    /**
     * Builds one learner→mark map from a column's three id lists. Every roster
     * learner starts unclear and is only moved by an unambiguous listing: an id
     * named in two lists stays unclear, because two answers is not an answer.
     *
     * @param  array<string, mixed>  $row
     * @param  list<int>  $rosterIds
     * @return array<int, string>
     */
    private function marksForSession(array $row, array $rosterIds): array
    {
        $marks = array_fill_keys($rosterIds, self::MARK_UNCLEAR);
        $claimed = [];

        foreach ([self::MARK_PRESENT => 'present', self::MARK_ABSENT => 'absent'] as $mark => $key) {
            foreach ((array) ($row[$key] ?? []) as $rawId) {
                $id = (int) $rawId;

                if (! array_key_exists($id, $marks)) {
                    continue; // Not a learner we asked about.
                }

                if (isset($claimed[$id])) {
                    $marks[$id] = self::MARK_UNCLEAR;

                    continue;
                }

                $claimed[$id] = true;
                $marks[$id] = $mark;
            }
        }

        return $marks;
    }

    /** A column date is usable only as a real, non-future YYYY-MM-DD day. */
    private function resolveSessionDate(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));

        if ($raw === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('Y-m-d', $raw);
        } catch (Throwable) {
            return null;
        }

        if ($date === false || $date->format('Y-m-d') !== $raw) {
            return null;
        }

        return $date->startOfDay()->isFuture() ? null : $raw;
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
