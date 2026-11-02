<?php

namespace App\Support;

use App\Models\RxConcept;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every call SIGLA makes to RxNav, the U.S. National Library of Medicine's
 * free RxNorm API (config('services.rxnorm.base_url'), no key).
 *
 * Three endpoints, each asked only when a medicine or an allergy is saved:
 *
 *  1. approximateTerm.json — find a drug by name, tolerating typos;
 *  2. rxcui/{rxcui}/related.json?tty=IN+MIN — the ingredients of a product;
 *  3. rxclass/class/byRxcui.json?relaSource=ATC — an ingredient's ATC classes.
 *
 * What they answer is stored in `rx_concepts` / `rx_concept_ingredients`, so
 * the check at dispense time (App\Support\AllergyCheck) reads the database and
 * never waits on this.
 *
 * Only a medicine name ever leaves the school: never a learner's name, LRN or
 * record. A failure never stops a save; the record is marked unverified and
 * the daily `rxnorm:retry-unverified` asks again. Logs carry the endpoint and
 * status, never the term, since an allergy is health information.
 */
class RxNormService
{
    public const MATCHED = 'matched';

    public const NOT_FOUND = 'not_found';

    public const UNVERIFIED = 'unverified';

    /** No confident match: the nurse chooses from the candidates. Never stored. */
    public const CANDIDATES = 'candidates';

    /** A cached concept older than this is fetched again by the retry job. */
    public const CACHE_DAYS = 90;

    /** What a generic name may contain: letters, digits, spaces, - / and . */
    public const NAME_PATTERN = '#^[A-Za-z0-9 ./-]+$#';

    /** Whether the tables this needs have been migrated. */
    public static function supported(): bool
    {
        return SchemaCache::hasTable('learner_medicine_allergies')
            && SchemaCache::hasTable('dispense_allergy_checks')
            && SchemaCache::hasColumn('medicines', 'rx_status');
    }

    /** The validation rules for a generic name, checked before every lookup. */
    public static function nameRules(): array
    {
        return ['required', 'string', 'min:2', 'max:100', 'regex:'.self::NAME_PATTERN];
    }

    /** Is this a name worth sending? 2 to 100 characters, letters, digits, spaces, - / . only. */
    public static function isValidName(string $name): bool
    {
        $name = trim($name);

        return mb_strlen($name) >= 2 && mb_strlen($name) <= 100 && preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /** The name RxNorm files a Philippine name under (config/rxnorm.php), lower case. */
    public static function normalize(string $name): string
    {
        $key = self::key($name);

        return (string) (config('rxnorm.synonyms')[$key] ?? $key);
    }

    /** One spelling for comparing names: lower case, single spaces, " / " between parts. */
    public static function key(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = (string) preg_replace('#\s*/\s*#', ' / ', $name);

        return trim((string) preg_replace('/\s+/', ' ', $name));
    }

    /**
     * Look a typed name up: synonyms, then endpoint 1. A top candidate whose
     * name is the name asked for is taken, with its ingredients and classes
     * (endpoints 2 and 3); anything less certain is returned as candidates for
     * the nurse to choose from, never picked automatically.
     *
     * @return array{status: string, concept: ?RxConcept, candidates: list<array{rxcui: string, name: string}>}
     */
    public function lookup(string $name): array
    {
        if (! self::isValidName($name)) {
            return self::result(self::NOT_FOUND);
        }

        $term = self::normalize($name);

        try {
            $candidates = $this->candidates($term);

            if ($candidates === []) {
                return self::result(self::NOT_FOUND);
            }

            if (in_array($term, $candidates[0]['names'], true)) {
                return self::result(self::MATCHED, $this->concept($candidates[0]['rxcui'], $candidates[0]['name']));
            }

            return self::result(self::CANDIDATES, null, self::offered($candidates));
        } catch (RxNormUnavailable) {
            return self::result(self::UNVERIFIED);
        }
    }

    /**
     * The nurse chose one of the candidates lookup() offered for this name.
     * The list is asked for again, so only a candidate RxNav actually offered
     * can be chosen.
     *
     * @return array{status: string, concept: ?RxConcept, candidates: list<array{rxcui: string, name: string}>}
     */
    public function choose(string $name, string $rxcui): array
    {
        if (! self::isValidName($name)) {
            return self::result(self::NOT_FOUND);
        }

        try {
            $offered = self::offered($this->candidates(self::normalize($name)));

            foreach ($offered as $candidate) {
                if ($candidate['rxcui'] === $rxcui) {
                    return self::result(self::MATCHED, $this->concept($candidate['rxcui'], $candidate['name']));
                }
            }

            return self::result($offered === [] ? self::NOT_FOUND : self::CANDIDATES, null, $offered);
        } catch (RxNormUnavailable) {
            return self::result(self::UNVERIFIED);
        }
    }

    /**
     * Endpoint 1: the concepts RxNav finds for a name, best first, one entry
     * per RxCUI. `names` holds every spelling RxNav gave it, for the
     * exact-match test; a concept no source named is left out.
     *
     * @return list<array{rxcui: string, name: string, names: list<string>}>
     */
    public function candidates(string $term): array
    {
        $body = $this->get('approximateTerm.json', ['term' => $term, 'maxEntries' => 3]);
        $group = $body['approximateGroup'] ?? null;

        if (! is_array($group)) {
            throw $this->unexpected('approximateTerm.json');
        }

        $rows = $group['candidate'] ?? [];
        $rows = is_array($rows) ? array_filter($rows, 'is_array') : [];
        usort($rows, fn (array $a, array $b): int => (int) ($a['rank'] ?? 99) <=> (int) ($b['rank'] ?? 99));

        $found = [];
        foreach ($rows as $row) {
            $rxcui = (string) ($row['rxcui'] ?? '');
            $name = trim((string) ($row['name'] ?? ''));

            if (! ctype_digit($rxcui)) {
                continue;
            }

            $found[$rxcui] ??= ['rxcui' => $rxcui, 'name' => '', 'names' => []];

            if ($name !== '') {
                $found[$rxcui]['names'][] = self::key($name);
                // RxNorm's own spelling, when it gave one, is the one shown.
                if ($found[$rxcui]['name'] === '' || ($row['source'] ?? '') === 'RXNORM') {
                    $found[$rxcui]['name'] = $name;
                }
            }
        }

        return array_values(array_filter($found, fn (array $candidate): bool => $candidate['name'] !== ''));
    }

    /**
     * Endpoint 2: a concept's ingredients (the IN group; the MIN group lists
     * every combination product containing it, which is not what it contains)
     * and the concept's own term type, when RxNav lists it.
     *
     * @return array{0: list<array{rxcui: string, name: string}>, 1: ?string}
     */
    public function ingredients(string $rxcui): array
    {
        $body = $this->get('rxcui/'.$rxcui.'/related.json?tty=IN+MIN');
        $related = $body['relatedGroup'] ?? null;

        if (! is_array($related)) {
            throw $this->unexpected('related.json');
        }

        $ingredients = [];
        $tty = null;

        foreach ((array) ($related['conceptGroup'] ?? []) as $group) {
            foreach ((array) ($group['conceptProperties'] ?? []) as $concept) {
                $id = (string) ($concept['rxcui'] ?? '');

                if ($id === $rxcui) {
                    $tty = (string) ($concept['tty'] ?? '') ?: null;
                }

                if (($group['tty'] ?? '') === 'IN' && ctype_digit($id)) {
                    $ingredients[$id] = ['rxcui' => $id, 'name' => (string) ($concept['name'] ?? $id)];
                }
            }
        }

        return [array_values($ingredients), $tty];
    }

    /**
     * Endpoint 3: an ingredient's ATC classes. A class reached only through a
     * combination product (ibuprofen's N02AJ, "opioids in combination") is
     * not the ingredient's own and is left out. No class at all is an answer:
     * the ingredient match still works, and the class caution is skipped.
     *
     * @return list<array{code: string, name: string}>
     */
    public function atcClasses(string $rxcui): array
    {
        $body = $this->get('rxclass/class/byRxcui.json', ['rxcui' => $rxcui, 'relaSource' => 'ATC']);

        $classes = [];
        foreach ((array) data_get($body, 'rxclassDrugInfoList.rxclassDrugInfo', []) as $info) {
            $code = strtoupper((string) data_get($info, 'rxclassMinConceptItem.classId', ''));
            $viaCombination = data_get($info, 'minConcept.tty') === 'MIN'
                && (string) data_get($info, 'minConcept.rxcui') !== $rxcui;

            if ($code === '' || $viaCombination) {
                continue;
            }

            $classes[$code] = ['code' => $code, 'name' => (string) data_get($info, 'rxclassMinConceptItem.className', '')];
        }

        return array_values($classes);
    }

    /**
     * The stored concept for an RxCUI, fetched (endpoints 2 and 3) when it is
     * not stored yet, when it is older than CACHE_DAYS, or when asked to.
     */
    public function concept(string $rxcui, string $name, bool $refresh = false): RxConcept
    {
        $stored = RxConcept::query()->with('ingredients')->where('rxcui', $rxcui)->first();

        if ($stored !== null && ! $refresh && $stored->fetched_at?->gt(now()->subDays(self::CACHE_DAYS))) {
            return $stored;
        }

        [$ingredients, $tty] = $this->ingredients($rxcui);
        if ($ingredients === []) {
            $ingredients = [['rxcui' => $rxcui, 'name' => $name]];
        }

        $classes = [];
        foreach ($ingredients as $ingredient) {
            foreach ($this->atcClasses($ingredient['rxcui']) as $class) {
                $classes[$class['code']] = $class;
            }
        }

        return DB::transaction(function () use ($rxcui, $name, $tty, $ingredients, $classes): RxConcept {
            $concept = RxConcept::query()->updateOrCreate(['rxcui' => $rxcui], [
                'name' => $name,
                'tty' => $tty,
                'atc_codes' => array_values($classes),
                'fetched_at' => now(),
            ]);

            $concept->ingredients()->delete();
            foreach ($ingredients as $ingredient) {
                $concept->ingredients()->create([
                    'ingredient_rxcui' => $ingredient['rxcui'],
                    'ingredient_name' => $ingredient['name'],
                ]);
            }

            return $concept->load('ingredients');
        });
    }

    /**
     * One GET, HTTPS with certificate verification left on, five seconds, two
     * attempts half a second apart on a dropped connection, a 429 or a 5xx.
     */
    private function get(string $path, array $query = []): array
    {
        $base = rtrim((string) config('services.rxnorm.base_url'), '/');
        $endpoint = strtok($path, '?');

        try {
            $response = Http::baseUrl($base)
                ->timeout(5)
                ->acceptJson()
                ->retry(2, 500, fn (Throwable $e): bool => $e instanceof ConnectionException
                    || ($e instanceof RequestException && ($e->response->status() === 429 || $e->response->serverError())), throw: false)
                // An empty query array would replace the one in the path.
                ->get($path, $query === [] ? null : $query);
        } catch (Throwable $e) {
            // The message carries the URL, and the URL carries the term.
            Log::warning('RxNav request failed', ['endpoint' => $endpoint, 'error' => class_basename($e)]);

            throw new RxNormUnavailable('RxNav could not be reached.');
        }

        if ($response->failed()) {
            Log::warning('RxNav refused the request', ['endpoint' => $endpoint, 'status' => $response->status()]);

            throw new RxNormUnavailable('RxNav answered '.$response->status().'.');
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw $this->unexpected($endpoint, $response);
        }

        return $body;
    }

    private function unexpected(string $endpoint, ?Response $response = null): RxNormUnavailable
    {
        Log::warning('RxNav answered in an unexpected shape', array_filter([
            'endpoint' => $endpoint,
            'status' => $response?->status(),
            'bytes' => $response !== null ? strlen($response->body()) : null,
        ], fn ($value): bool => $value !== null));

        return new RxNormUnavailable('RxNav answered in an unexpected shape.');
    }

    /**
     * The top three candidates, as the nurse is shown them.
     *
     * @param  list<array{rxcui: string, name: string, names: list<string>}>  $candidates
     * @return list<array{rxcui: string, name: string}>
     */
    private static function offered(array $candidates): array
    {
        return array_map(
            fn (array $candidate): array => ['rxcui' => $candidate['rxcui'], 'name' => $candidate['name']],
            array_slice($candidates, 0, 3)
        );
    }

    /** @param  list<array{rxcui: string, name: string}>  $candidates */
    private static function result(string $status, ?RxConcept $concept = null, array $candidates = []): array
    {
        return ['status' => $status, 'concept' => $concept, 'candidates' => $candidates];
    }
}
