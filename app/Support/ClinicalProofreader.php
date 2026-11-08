<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * An AI check of a clinical note's grammar and terms, so the clinic needs no
 * paid writing assistant.
 *
 * The nurse writes an incident report in a hurry, often in a second language,
 * and the report is printed and signed by the adviser, the curriculum head and
 * the principal. This proposes a corrected version of each text field —
 * spelling, grammar, punctuation, and the standard clinical word where a lay or
 * misspelled one was used — through the same Gemini key the photo readers use.
 *
 * Four rules, in the spirit of every other reader here:
 *
 * 1. **The AI proposes; the nurse decides.** Nothing is saved from here. The
 *    form shows each suggestion beside what was written and the nurse accepts
 *    or dismisses it; the report is saved through the one write path as typed.
 * 2. **A correction never changes a fact.** A suggestion whose numbers differ
 *    from the original — a temperature, a time, a pain score, a dose — is
 *    dropped on the server, whatever the model says it did. So is one that
 *    loses or invents a name placeholder. A dropped field stays as written.
 * 3. **Names stay at the school.** The learner's own names and the witnesses'
 *    are replaced with placeholders before the text is sent, and put back on
 *    the way out; only the clinical wording leaves the server.
 * 4. **Unavailable is said plainly.** A busy model, a missing key or a refusal
 *    is a ProofreadUnavailable with a sentence the nurse can act on, and the
 *    form is left exactly as it was.
 *
 * The model is `services.gemini.text_model` when set, so a school can point the
 * check at a model whose free allowance is not the one the consent reader
 * spends; otherwise it is the readers' own `services.gemini.model`.
 */
final class ClinicalProofreader
{
    /** Placeholder for a masked name: [[N1]], [[N2]], ... */
    private const PLACEHOLDER = '/\[\[N\d+\]\]/';

    /** Shorter tokens are not masked: they are initials and particles ("De", "M."), not names. */
    private const MIN_NAME_LENGTH = 3;

    public static function isConfigured(): bool
    {
        return filled(config('services.gemini.key'));
    }

    public static function model(): string
    {
        $text = trim((string) config('services.gemini.text_model', ''));

        return $text !== '' ? $text : (string) config('services.gemini.model', 'gemini-3.5-flash');
    }

    /**
     * Corrected text for each field that needed it.
     *
     * @param  array<string, array{label: string, text: string}>  $fields  key => what the field is called and what was written
     * @param  list<string>  $names  names that must not leave the server
     * @return array<string, array{original: string, suggested: string}> only the fields with a usable correction
     */
    public function proofread(array $fields, array $names = []): array
    {
        if (! self::isConfigured()) {
            throw new ProofreadUnavailable('The grammar and terms check is not set up on this server.');
        }

        $fields = array_filter($fields, fn (array $field): bool => trim($field['text']) !== '');

        if ($fields === []) {
            return [];
        }

        [$masked, $restore] = $this->mask($fields, $names);

        $answer = $this->decode($this->ask($masked));

        $suggestions = [];

        foreach ($fields as $key => $field) {
            $proposed = $answer[$key] ?? null;

            if (! is_string($proposed)) {
                continue;
            }

            $proposed = trim($proposed);

            if ($proposed === '' || ! $this->keepsFacts($masked[$key]['text'], $proposed)) {
                continue;
            }

            $suggested = strtr($proposed, $restore);
            $original = trim($field['text']);

            if ($suggested !== $original) {
                $suggestions[$key] = ['original' => $original, 'suggested' => $suggested];
            }
        }

        return $suggestions;
    }

    /**
     * The fields with every name replaced by a placeholder, and the map that
     * puts each one back exactly as it was written.
     *
     * @param  array<string, array{label: string, text: string}>  $fields
     * @param  list<string>  $names
     * @return array{0: array<string, array{label: string, text: string}>, 1: array<string, string>}
     */
    private function mask(array $fields, array $names): array
    {
        $tokens = [];
        foreach ($names as $name) {
            foreach (preg_split('/[\s,;\/]+/u', (string) $name) ?: [] as $token) {
                $token = trim($token, " .\t\n\r\0\x0B");
                if (mb_strlen($token) >= self::MIN_NAME_LENGTH) {
                    $tokens[mb_strtolower($token)] = $token;
                }
            }
        }

        if ($tokens === []) {
            return [$fields, []];
        }

        // Longest first, so "Dela Cruz" is not half-masked by "Cruz" first.
        uasort($tokens, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $pattern = '/(?<![\p{L}\p{N}])('.implode('|', array_map(fn (string $t): string => preg_quote($t, '/'), $tokens)).')(?![\p{L}\p{N}])/iu';

        $byWritten = [];
        $restore = [];

        foreach ($fields as $key => $field) {
            $fields[$key]['text'] = (string) preg_replace_callback($pattern, function (array $match) use (&$byWritten, &$restore): string {
                // One placeholder per spelling as written, so it comes back
                // in the nurse's own capitalisation.
                if (! isset($byWritten[$match[1]])) {
                    $placeholder = '[[N'.(count($byWritten) + 1).']]';
                    $byWritten[$match[1]] = $placeholder;
                    $restore[$placeholder] = $match[1];
                }

                return $byWritten[$match[1]];
            }, $field['text']);
        }

        return [$fields, $restore];
    }

    /**
     * Whether a correction kept every figure and every placeholder of the
     * text it corrects — the same numbers, as many times each, and the same
     * names. Wording may change; a reading may not.
     */
    private function keepsFacts(string $original, string $proposed): bool
    {
        return $this->figures($original) === $this->figures($proposed)
            && $this->placeholders($original) === $this->placeholders($proposed);
    }

    /** @return list<string> */
    private function figures(string $text): array
    {
        // Placeholders carry digits of their own; they are compared apart.
        $text = (string) preg_replace(self::PLACEHOLDER, ' ', $text);
        preg_match_all('/\d+(?:[.,]\d+)?/', $text, $matches);

        $figures = array_map(fn (string $figure): string => str_replace(',', '.', $figure), $matches[0]);
        sort($figures);

        return $figures;
    }

    /** @return list<string> */
    private function placeholders(string $text): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $matches);
        $found = $matches[0];
        sort($found);

        return $found;
    }

    /**
     * One call to Gemini with every field at once — one request per check,
     * whatever the number of fields, because the free allowance is counted in
     * requests.
     *
     * @param  array<string, array{label: string, text: string}>  $fields
     * @return array<string, mixed>
     */
    private function ask(array $fields): array
    {
        $endpoint = rtrim((string) config('services.gemini.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $timeout = max(15, min(60, (int) config('services.gemini.timeout', 120)));

        LongRequest::allow(2 * $timeout + 15);

        $properties = [];
        $input = [];
        foreach ($fields as $key => $field) {
            $properties[$key] = ['type' => 'STRING', 'description' => 'The corrected '.$field['label'].'.'];
            $input[] = ['field' => $key, 'name' => $field['label'], 'text' => $field['text']];
        }

        try {
            $response = Http::withHeaders(['x-goog-api-key' => (string) config('services.gemini.key')])
                ->timeout($timeout)
                // Twice, not three times: a check is one click to repeat, and a
                // failed attempt still spends the day's free allowance.
                ->retry(2, 1000, function (Throwable $e): bool {
                    return $e instanceof ConnectionException
                        || ($e instanceof RequestException
                            && in_array($e->response->status(), [408, 429, 500, 502, 503, 504], true));
                }, throw: false)
                ->acceptJson()
                ->asJson()
                ->post($endpoint.'/models/'.self::model().':generateContent', [
                    'system_instruction' => ['parts' => [['text' => $this->systemPrompt()]]],
                    'contents' => [[
                        'role' => 'user',
                        'parts' => [['text' => json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
                    ]],
                    'generationConfig' => [
                        'temperature' => 0,
                        'responseMimeType' => 'application/json',
                        'responseSchema' => [
                            'type' => 'OBJECT',
                            'properties' => $properties,
                            'required' => array_keys($properties),
                            'propertyOrdering' => array_keys($properties),
                        ],
                    ],
                ]);
        } catch (Throwable $e) {
            report($e);

            throw new ProofreadUnavailable('The text could not be sent for checking. Check the connection and try again.', 0, $e);
        }

        if ($response->failed()) {
            if (in_array($response->status(), [429, 503], true)) {
                throw new ProofreadUnavailable('The checker is busy at the moment. Try again in a few minutes.');
            }

            // The status and the provider's reason go to the log, where a wrong
            // model id or an expired key can be told apart; the nurse is told
            // the form is unchanged.
            report(new ProofreadUnavailable('Gemini proofread returned HTTP '.$response->status().': '.trim((string) $response->json('error.message', ''))));

            throw new ProofreadUnavailable('The checker could not be reached. Nothing on the form was changed.');
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new ProofreadUnavailable('The checker returned an unreadable answer. Try again.');
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function decode(array $body): array
    {
        if (trim((string) data_get($body, 'promptFeedback.blockReason', '')) !== '') {
            throw new ProofreadUnavailable('The checker declined this text. Nothing on the form was changed.');
        }

        $finish = trim((string) data_get($body, 'candidates.0.finishReason', ''));

        if ($finish !== '' && $finish !== 'STOP') {
            throw new ProofreadUnavailable('The checker could not finish. Nothing on the form was changed.');
        }

        $text = '';
        foreach ((array) data_get($body, 'candidates.0.content.parts', []) as $part) {
            if (is_array($part) && isset($part['text'])) {
                $text .= (string) $part['text'];
            }
        }

        $decoded = json_decode(trim($text), true);

        if (! is_array($decoded)) {
            throw new ProofreadUnavailable('The checker returned an unreadable answer. Try again.');
        }

        return $decoded;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You proofread a school clinic's incident report, charted in F-DAR (Focus,
        Data, Action, Response) by a school nurse in the Philippines. You receive a
        JSON list of fields, each with its name and the text the nurse wrote, and
        you return the corrected text of every field.

        Correct:
        - spelling, grammar, punctuation and capitalisation;
        - the clinical or nursing term, where the nurse used a misspelled or
          informal word for the same thing ("abraision" -> "abrasion", "nose
          bleed" -> "epistaxis (nosebleed)", "BP" may stay "BP");
        - tense and person, into plain, objective charting language, in complete
          or concise charting sentences as the nurse chose.

        Never:
        - add, remove or change a fact, an observation, a time, a date, a figure,
          a unit, a dose, a medicine or a body part;
        - add a diagnosis, an assessment, a cause or anything the nurse did not
          write;
        - translate. Keep the language the nurse wrote in, and keep anything the
          learner is quoted as saying exactly as written;
        - change, remove, add or move a placeholder such as [[N1]]. Each stands
          for a person's name and must come back exactly as it was given;
        - expand the text into a paragraph it was not. Keep its length close to
          the original.

        If a field needs no correction, return its text unchanged.
        PROMPT;
    }
}
