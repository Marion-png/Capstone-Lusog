<?php

namespace App\Support;

use Anthropic\Client;
use Anthropic\Messages\Base64ImageSource;
use Anthropic\Messages\ImageBlockParam;
use App\Models\HealthConsentForm;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Throwable;

/**
 * Reads a photographed, signed Sulat-Pahibalo and proposes how the on-screen
 * consent form should be filled in.
 *
 * The class adviser collects these on paper. Re-typing every ticked box for a
 * class of forty is the work this removes.
 *
 * Three rules shape the whole design:
 *
 * 1. **It proposes; it never records.** What comes back is a draft the adviser
 *    reviews and saves. A parent's consent authorises medical procedures on a
 *    child — deworming, an injection, a tooth extraction — and no machine
 *    reading of a photograph is good enough to stand as that authorisation.
 *    This mirrors the attendance scanner, where an unconfirmed mark is not an
 *    absence, but the stakes here are higher, so nothing is ever auto-saved.
 *
 * 2. **The model matches, it does not invent.** It is handed the exact service
 *    list from HealthConsentForm::SERVICES and may only answer about those
 *    keys. It cannot return a service the form does not have, and it is never
 *    asked to identify anybody from a signature — only whether one is present.
 *
 * 3. **"Unclear" is a first-class answer.** A box that is smudged, half-shaded
 *    or ambiguous comes back UNCLEAR rather than being guessed either way, and
 *    the adviser is shown exactly those. Anything the model omits is unclear
 *    too: a missing answer is not a "no".
 *
 * Requires ANTHROPIC_API_KEY. Without one the feature is simply unavailable
 * and the adviser fills the form in by hand, exactly as before.
 */
class ConsentFormScanner
{
    public const TICKED = 'yes';

    public const NOT_TICKED = 'no';

    public const UNCLEAR = 'unclear';

    /**
     * What a blank reads as when the handwriting cannot be made out at all.
     *
     * Blurred, smeared, over-written, cut off by the edge of the photograph —
     * whatever the cause, the honest answer is that nobody read it. It is a
     * word on the screen rather than an empty field for a reason: an empty
     * field says "the parent wrote nothing here", which is a different claim
     * about a child's allergies, and the adviser cannot tell the two apart
     * once the form is saved.
     */
    public const UNREADABLE_TEXT = 'Unreadable';

    /**
     * The blanks the parent writes in by hand, keyed by the column each one
     * fills. A tick says *whether*; these say *what* — which services were
     * excepted, why consent was refused, what the child reacts to — and a
     * consent form read without them is a form the adviser still has to
     * re-type from the paper.
     *
     * @var array<string, string>
     */
    public const WRITTEN_FIELDS = [
        'consent_exceptions' => 'Services the parent excepted',
        'refusal_reason' => 'Reason for refusing',
        'allergy_food' => 'Food allergy',
        'allergy_medicine' => 'Medicine allergy',
        'prev_immunization' => 'Reaction to a previous immunization',
        'other_illness' => 'Current or other illness',
    ];

    public function __construct(private readonly ?Client $client = null) {}

    public static function isConfigured(): bool
    {
        return filled(config('services.anthropic.key'));
    }

    /**
     * Every answerable box on the paper form, flattened — parents tick the
     * parent rows and the indented children under Deworming and Immunization.
     *
     * @return array<string, string>
     */
    public static function serviceKeys(): array
    {
        $keys = [];

        foreach (HealthConsentForm::SERVICES as $key => $service) {
            $keys[$key] = (string) ($service['label'] ?? $key);

            foreach (($service['children'] ?? []) as $childKey => $childLabel) {
                $keys[$childKey] = (string) $childLabel;
            }
        }

        return $keys;
    }

    /**
     * @return array{
     *     consent_choice: string,
     *     services: array<string, string>,
     *     written: array<string, array{text: string, confident: bool, readable: bool}>,
     *     parent_guardian_name: string,
     *     signature_present: bool,
     *     unclear: list<string>,
     *     needs_review: bool,
     *     unreadable: bool,
     *     note: string
     * }
     */
    public function scan(UploadedFile $photo): array
    {
        if (! self::isConfigured()) {
            throw new RuntimeException('Consent scanning is not configured. Set ANTHROPIC_API_KEY.');
        }

        $client = $this->client ?? new Client(apiKey: (string) config('services.anthropic.key'));

        $message = $client->messages->create(
            model: (string) config('services.anthropic.model', 'claude-opus-5'),
            maxTokens: 16000,
            system: $this->systemPrompt(),
            // Accuracy over latency: a misread box becomes a medical
            // authorisation, or the withdrawal of one.
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
                    ['type' => 'text', 'text' => $this->userPrompt()],
                ],
            ]],
        );

        return $this->parse($message);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You read photographed parental consent forms (the DepEd "Sulat-Pahibalo"
        for school health services, written in Cebuano and English).

        A parent or guardian has ticked or shaded boxes to say which health
        services the school may give their child, signed the form, and sent it
        back with the learner. Your job is to report which boxes are marked.

        You are given the exact list of services the form offers. Answer only
        about those. Never invent a service, and never report one you were not
        given.

        For every service, answer exactly one of:
          - "yes"     the box is clearly ticked, shaded, crossed or filled
          - "no"      the box is clearly empty
          - "unclear" you cannot tell

        "unclear" is a real answer and the safest one. Use it whenever a mark is
        smudged, partially shaded, ambiguous, cut off by the edge of the photo,
        obscured by glare or a shadow, or where you cannot be certain which row
        a mark belongs to. Do not guess. A wrongly reported "yes" authorises a
        medical procedure on a child that the parent did not agree to; a wrongly
        reported "no" withdraws one they did. Both are worse than "unclear".

        Read the row labels, not just the positions of the marks. Filipino
        consent forms often indent sub-items beneath a parent item — check that
        a mark belongs to the row you think it does before recording it.

        The parent also WRITES in some blanks by hand. For each one, transcribe
        exactly what is written and say whether you are confident you read it
        correctly.

        There are exactly three outcomes for a blank, and they are different
        claims about the child. Do not blur them together:

          - EMPTY: the parent wrote nothing. Set text to an empty string,
            readable true. An empty blank is an answer — "no food allergy" —
            so never fill it with something plausible.
          - READ: transcribe exactly what is on the paper, readable true. Do
            not correct spelling, expand an abbreviation, translate, or
            complete a half-written word. Set confident to false if you read it
            but are not certain of every word: these forms are written in
            Cebuano/Bisaya, English or a mixture, often in hurried handwriting,
            and Cebuano medical vocabulary frequently will not be certain. An
            unsure reading is useful — the adviser is holding the paper and
            will correct it. A confident wrong transcription is the failure to
            avoid.
          - UNREADABLE: something is written there but you cannot make out the
            words at all — blurred, smeared, over-written, too faint, or cut
            off by the edge of the photograph. Set readable to FALSE and leave
            text empty. Do not guess at it and do not describe it. "Something
            is written and nobody read it" is the answer, and it is not the
            same answer as "nothing is written".

        Never set confident to true for a blank you had to infer from context
        rather than read.

        Also report:
          - consent_choice: "all" if the parent agreed to every service,
            "specific" if they agreed to some but not all, "deny" if they
            refused all services or the form clearly states a refusal, and
            "unclear" if you cannot tell.
          - parent_guardian_name: the printed name of the parent or guardian if
            it is legible. Empty string if not. Copy exactly what is written;
            do not correct or complete a name.
          - signature_present: true only if a handwritten signature mark is
            visible in the signature area.

        You are never asked to identify a person from their signature or
        handwriting, and you must not attempt to. Report only whether a
        signature is present.

        If the photograph is too blurred, too dark, cropped or otherwise
        unusable to read reliably, set unreadable to true and explain briefly in
        the note. Do not return guessed answers alongside unreadable.
        PROMPT;
    }

    private function userPrompt(): string
    {
        $lines = [];

        foreach (self::serviceKeys() as $key => $label) {
            $lines[] = '- '.$key.': '.$label;
        }

        $services = implode("\n", $lines);

        $writtenLines = [];
        foreach (self::WRITTEN_FIELDS as $key => $label) {
            $writtenLines[] = '- '.$key.': '.$label;
        }
        $written = implode("\n", $writtenLines);

        return <<<PROMPT
        Read the attached consent form.

        These are the only services on the form. Answer for every one of them,
        using the key on the left:

        {$services}

        Then transcribe each handwritten blank, with a confidence flag:

        {$written}

        Return "yes", "no" or "unclear" for each service key, the written
        blanks, consent_choice, the parent or guardian's printed name if
        legible, and whether a signature is present.
        PROMPT;
    }

    /** @return array<string, mixed> */
    private function responseSchema(): array
    {
        $answer = [
            'type' => 'string',
            'enum' => [self::TICKED, self::NOT_TICKED, self::UNCLEAR],
        ];

        $properties = [];
        foreach (array_keys(self::serviceKeys()) as $key) {
            $properties[$key] = $answer;
        }

        $written = [];
        foreach (self::WRITTEN_FIELDS as $key => $label) {
            $written[$key] = [
                'type' => 'object',
                'description' => $label.' — empty text if the blank is empty.',
                'properties' => [
                    'text' => ['type' => 'string'],
                    'confident' => ['type' => 'boolean'],
                    'readable' => [
                        'type' => 'boolean',
                        'description' => 'False only when something is written there and cannot be made out.',
                    ],
                ],
                'required' => ['text', 'confident', 'readable'],
                'additionalProperties' => false,
            ];
        }

        return [
            'type' => 'json_schema',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'consent_choice' => [
                        'type' => 'string',
                        'enum' => [
                            HealthConsentForm::CONSENT_ALL,
                            HealthConsentForm::CONSENT_SPECIFIC,
                            HealthConsentForm::CONSENT_DENY,
                            self::UNCLEAR,
                        ],
                        'description' => 'Whether the parent agreed to all services, some, or none.',
                    ],
                    'services' => [
                        'type' => 'object',
                        'description' => 'One answer per service key on the form.',
                        'properties' => $properties,
                        'additionalProperties' => false,
                    ],
                    'written' => [
                        'type' => 'object',
                        'description' => 'One entry per handwritten blank on the form.',
                        'properties' => $written,
                        'additionalProperties' => false,
                    ],
                    'parent_guardian_name' => [
                        'type' => 'string',
                        'description' => 'Printed name of the parent or guardian, or an empty string.',
                    ],
                    'signature_present' => ['type' => 'boolean'],
                    'unreadable' => ['type' => 'boolean'],
                    'note' => [
                        'type' => 'string',
                        'description' => 'Anything the adviser should check by eye. Empty if nothing.',
                    ],
                ],
                'required' => ['consent_choice', 'services', 'written', 'signature_present', 'unreadable'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(mixed $message): array
    {
        return self::interpret($this->decode($message));
    }

    /**
     * Turn whatever the model returned into the draft the adviser reviews.
     *
     * Public and pure so the safety rules can be tested directly rather than
     * through a faked scanner — the rules are the point of this class, and a
     * test that stubs them out proves nothing. Both err the same way:
     *
     *  - anything omitted, or answered with something not on the list, is
     *    UNCLEAR — never "no". A missing answer is not a refusal.
     *  - an unreadable photo answers nothing at all, rather than returning
     *    guesses beside the warning.
     *  - a handwritten blank the model was not sure of is carried WITH its
     *    reading and named as needing a look. Dropping the text would make the
     *    adviser type it from the paper anyway; presenting it as certain is the
     *    error. So it arrives flagged, and the review screen shows it flagged.
     *  - a blank nobody could read at all reads "Unreadable", not blank. An
     *    empty field is the claim that the parent wrote nothing, which is a
     *    different thing to say about a child's allergies and cannot be told
     *    apart from the real thing once the form is saved.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function interpret(array $payload): array
    {
        $unreadable = (bool) ($payload['unreadable'] ?? false);
        $answers = is_array($payload['services'] ?? null) ? $payload['services'] : [];

        $services = [];
        $unclear = [];

        foreach (self::serviceKeys() as $key => $label) {
            $answer = strtolower(trim((string) ($answers[$key] ?? '')));

            // A sheet the model calls unreadable answers nothing at all.
            if ($unreadable || ! in_array($answer, [self::TICKED, self::NOT_TICKED], true)) {
                $services[$key] = self::UNCLEAR;
                $unclear[] = $label;

                continue;
            }

            $services[$key] = $answer;
        }

        // The handwritten blanks. An unreadable sheet transcribes nothing, and
        // a blank the model was unsure of keeps its text and is named.
        $writtenIn = is_array($payload['written'] ?? null) ? $payload['written'] : [];
        $written = [];

        foreach (self::WRITTEN_FIELDS as $key => $label) {
            $entry = is_array($writtenIn[$key] ?? null) ? $writtenIn[$key] : [];
            $text = $unreadable ? '' : trim((string) ($entry['text'] ?? ''));

            // Something is written there and nobody read it. The field says so
            // in words rather than arriving blank, because a blank field is the
            // claim that the parent wrote nothing — a different thing to say
            // about a child's allergies, and indistinguishable once saved.
            // `readable` defaults to true, so a model that omits it is taken to
            // have read what it returned rather than silently marking every
            // blank unreadable.
            $illegible = ! $unreadable
                && array_key_exists('readable', $entry)
                && ! (bool) $entry['readable'];

            if ($illegible) {
                $written[$key] = ['text' => self::UNREADABLE_TEXT, 'confident' => false, 'readable' => false];
                $unclear[] = $label;

                continue;
            }

            // Confidence is only meaningful about something that was read.
            $confident = $text !== '' && (bool) ($entry['confident'] ?? false);

            $written[$key] = ['text' => $text, 'confident' => $confident, 'readable' => true];

            if ($text !== '' && ! $confident) {
                $unclear[] = $label;
            }
        }

        $choice = strtolower(trim((string) ($payload['consent_choice'] ?? '')));
        $validChoices = [
            HealthConsentForm::CONSENT_ALL,
            HealthConsentForm::CONSENT_SPECIFIC,
            HealthConsentForm::CONSENT_DENY,
        ];
        $choice = (! $unreadable && in_array($choice, $validChoices, true)) ? $choice : self::UNCLEAR;
        $signature = ! $unreadable && (bool) ($payload['signature_present'] ?? false);

        return [
            'consent_choice' => $choice,
            'services' => $services,
            'written' => $written,
            'parent_guardian_name' => $unreadable ? '' : trim((string) ($payload['parent_guardian_name'] ?? '')),
            'signature_present' => $signature,
            'unclear' => $unclear,
            // Said once, by the reader, so no screen has to work it out for
            // itself and reach a different answer. A form with nothing flagged
            // still goes to the adviser to confirm — this only says whether
            // anything on it needs a second look first.
            'needs_review' => $unreadable
                || $unclear !== []
                || $choice === self::UNCLEAR
                || ! $signature,
            'unreadable' => $unreadable,
            'note' => trim((string) ($payload['note'] ?? '')),
        ];
    }

    /** @return array<string, mixed> */
    private function decode(mixed $message): array
    {
        foreach (($message->content ?? []) as $block) {
            $text = is_array($block) ? ($block['text'] ?? null) : ($block->text ?? null);

            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            try {
                $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // No usable answer is the same as an unreadable sheet: everything
        // unclear, nothing assumed.
        return ['unreadable' => true, 'note' => 'The reader returned no usable answer.'];
    }

    private function mediaType(UploadedFile $photo): string
    {
        $mime = strtolower((string) $photo->getMimeType());

        return in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)
            ? $mime
            : 'image/jpeg';
    }
}
