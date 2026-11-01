<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Reads one year of Philippine public holidays from the Calendarific API.
 *
 *     GET https://calendarific.com/api/v2/holidays?api_key=…&country=PH&year=2026&type=national
 *
 * The request carries a country and a year and nothing else: no learner, no
 * school and no account ever leaves the application through this call.
 *
 * The answer is not trusted as it arrives. `interpret()` keeps only what can
 * close a school — a national holiday on a real date inside the year asked
 * for — and drops observances, regional holidays (BARMM) and "special working
 * days", which are holidays in name only. An answer that leaves nothing
 * usable is a failure rather than an empty year, because the sync replaces a
 * year's holidays with what this returns and a bad reply must never wipe the
 * ones already on file.
 */
class CalendarificClient
{
    /** Statuses worth asking again: the provider's own trouble, not ours. */
    private const TRANSIENT = [500, 502, 503, 504];

    public static function isConfigured(): bool
    {
        return trim((string) config('services.calendarific.key')) !== '';
    }

    /**
     * @return array{holidays: list<array{date: string, name: string, external_id: ?string}>, skipped: int}
     */
    public function holidays(int $year): array
    {
        if (! self::isConfigured()) {
            throw new HolidayApiFailure('Holiday sync is not configured. Set CALENDARIFIC_API_KEY.');
        }

        if ($year < 2000 || $year > 2100) {
            throw new HolidayApiFailure('The year '.$year.' is outside what can be synced.');
        }

        return self::interpret($this->request($year), $year);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(int $year): array
    {
        $key = trim((string) config('services.calendarific.key'));
        $endpoint = rtrim((string) config('services.calendarific.endpoint', 'https://calendarific.com/api/v2'), '/');

        $query = [
            // Calendarific takes the key in the query string and nowhere else,
            // which is why every failure below is logged with it redacted.
            'api_key' => $key,
            'country' => (string) config('services.calendarific.country', 'PH'),
            'year' => $year,
            'type' => 'national',
        ];

        $location = trim((string) config('services.calendarific.location', ''));
        if ($location !== '') {
            $query['location'] = $location;
            $query['type'] = 'national,local';
        }

        try {
            $response = Http::timeout(max(5, (int) config('services.calendarific.timeout', 20)))
                ->acceptJson()
                // A provider hiccup is retried; a refused key (401), an expired
                // plan (403) and a spent monthly quota (429) are answers, and
                // every request counts against the 500 a month the free plan
                // allows, so asking again would only spend more of it.
                ->retry(3, 1000, function (Throwable $e): bool {
                    return $e instanceof ConnectionException
                        || ($e instanceof RequestException
                            && in_array($e->response->status(), self::TRANSIENT, true));
                }, throw: false)
                ->get($endpoint.'/holidays', $query);
        } catch (Throwable $e) {
            report(new RuntimeException('Calendarific request failed: '.self::redact($e->getMessage(), $key)));

            throw new HolidayApiFailure('Calendarific could not be reached. Check the connection and try again.');
        }

        $body = $response->json();
        $code = is_array($body) ? (int) data_get($body, 'meta.code', $response->status()) : $response->status();

        // Calendarific reports a refusal both as the HTTP status and as
        // meta.code, and either one alone is enough to stop.
        if ($response->failed() || $code !== 200) {
            $status = $response->failed() ? $response->status() : $code;
            $detail = is_array($body) ? trim((string) data_get($body, 'meta.error_detail', '')) : '';

            report(new RuntimeException('Calendarific answered '.$status.($detail !== '' ? ': '.self::redact($detail, $key) : '')));

            throw new HolidayApiFailure(self::messageFor($status));
        }

        if (! is_array($body)) {
            throw new HolidayApiFailure('Calendarific returned a response that could not be read. Nothing was changed.');
        }

        return $body;
    }

    /**
     * Keep what can close a school and drop everything else.
     *
     * @param  array<string, mixed>  $body
     * @return array{holidays: list<array{date: string, name: string, external_id: ?string}>, skipped: int}
     */
    public static function interpret(array $body, int $year): array
    {
        $entries = data_get($body, 'response.holidays');

        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new HolidayApiFailure('Calendarific returned a response that could not be read. Nothing was changed.');
        }

        $location = strtolower(trim((string) config('services.calendarific.location', '')));
        $kept = [];
        $skipped = 0;

        foreach ($entries as $entry) {
            $holiday = is_array($entry) ? self::holiday($entry, $year, $location) : null;

            if ($holiday === null) {
                $skipped++;

                continue;
            }

            // Calendarific lists some days twice (Black Saturday, 2026).
            $kept[$holiday['date'].'|'.mb_strtolower($holiday['name'])] = $holiday;
        }

        if ($kept === []) {
            throw new HolidayApiFailure('Calendarific returned no public holidays for '.$year.'. Nothing was changed.');
        }

        $holidays = array_values($kept);
        usort($holidays, fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        return ['holidays' => $holidays, 'skipped' => $skipped];
    }

    /**
     * One entry, or null when it cannot close a school.
     *
     * @param  array<string, mixed>  $entry
     * @return array{date: string, name: string, external_id: ?string}|null
     */
    private static function holiday(array $entry, int $year, string $location): ?array
    {
        $name = trim(strip_tags((string) ($entry['name'] ?? '')));
        // A timed observance arrives as "2026-03-20T22:46:00+08:00"; the day
        // is the first ten characters.
        $iso = substr((string) data_get($entry, 'date.iso', ''), 0, 10);

        if ($name === '' || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $parts)) {
            return null;
        }

        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || (int) $parts[1] !== $year) {
            return null;
        }

        $types = array_map('strval', array_filter((array) ($entry['type'] ?? []), 'is_scalar'));
        $primary = (string) ($entry['primary_type'] ?? '');
        $labels = implode(' ', array_merge($types, [$primary, $name]));

        // Only a national holiday closes a school. An observance, a season or
        // a religious day that is not also a public holiday does not.
        $national = preg_match('/\bnational\b|\bpublic holiday\b|\bregular holiday\b|non[\s-]?working/i', implode(' ', $types).' '.$primary) === 1;
        $local = $location !== '' && preg_match('/\blocal\b/i', implode(' ', $types)) === 1;

        if (! $national && ! $local) {
            return null;
        }

        // A "special working day" is a holiday on which work and classes go
        // on, so it is not a day off at all.
        if (preg_match('/\bworking\s+(day|holiday)\b/i', $labels) === 1 && preg_match('/non[\s-]?working/i', $labels) !== 1) {
            return null;
        }

        // A regional holiday (BARMM, a city's founding day) closes only the
        // schools of that region, so it is kept only when the deployment has
        // asked for its own location.
        $where = strtolower(trim((string) ($entry['locations'] ?? 'All')));
        if ($where !== '' && $where !== 'all' && ($location === '' || ! str_contains($where, $location))) {
            return null;
        }

        $externalId = trim((string) ($entry['urlid'] ?? ''));

        return [
            'date' => $iso,
            'name' => mb_substr($name, 0, 160),
            'external_id' => $externalId === '' ? null : mb_substr($externalId, 0, 160),
        ];
    }

    /** What the System Admin is told, by the status Calendarific answered. */
    private static function messageFor(int $status): string
    {
        return match (true) {
            $status === 401 => 'Calendarific refused the API key. Check CALENDARIFIC_API_KEY.',
            $status === 403 => 'The Calendarific subscription has expired. The holidays already on file are still in use.',
            $status === 429 => 'The monthly Calendarific request limit has been reached. The holidays already on file are still in use.',
            in_array($status, self::TRANSIENT, true) => 'Calendarific is unavailable at the moment. The holidays already on file are still in use.',
            default => 'Calendarific answered with an error (HTTP '.$status.'). Nothing was changed.',
        };
    }

    private static function redact(string $message, string $key): string
    {
        return $key === '' ? $message : str_replace($key, '[redacted]', $message);
    }
}
