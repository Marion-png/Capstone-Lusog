<?php

namespace App\Support;

use App\Models\FhirTransmission;
use App\Models\StudentHealthRecord;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends one learner's FHIR bundle to the configured receiving server over
 * HTTPS, and records that it did.
 *
 * The one outbound path. The exchange screen, the console command and the
 * automatic send after an examination all call `transmit()`, so there is one
 * place that decides whether a send may happen and one shape for the record
 * it leaves behind.
 *
 * Four rules:
 *
 *  - **HTTPS or nothing.** An endpoint that is not https:// is refused before
 *    a byte leaves the server, and the refusal is itself recorded (status
 *    `blocked`) and audited. Certificates are verified and TLS 1.2 is the
 *    floor. There is no switch to relax either.
 *  - **The record is written before the request is sent.** A `pending` row
 *    carrying the encrypted payload and its SHA-256 exists first, so even a
 *    send that dies half-way leaves evidence that a disclosure was attempted.
 *  - **Credentials never leave the environment.** The bearer token or Basic
 *    credentials are read from config at send time and go on the request
 *    only; the stored endpoint has its user-info and query string removed.
 *  - **Every attempt reaches the audit trail**, sent, failed or blocked, with
 *    the receiving host and the payload digest — never the payload.
 *
 * Retrying is safe because every bundle entry is a conditional update keyed
 * on an identifier (see FhirBundle): a request that timed out after the
 * server applied it is applied again as an update, not a duplicate.
 */
final class FhirTransmitter
{
    /** Who may preview, download and send a learner's bundle. */
    public const ROLES = ['school_nurse'];

    /** Statuses worth one more try; anything else is the server's answer. */
    private const TRANSIENT = [408, 429, 500, 502, 503, 504];

    /** The longest server reply kept on the transmission record. */
    private const MAX_RESPONSE_BYTES = 262144;

    public static function endpoint(): string
    {
        return trim((string) config('services.fhir.endpoint', ''));
    }

    public static function isConfigured(): bool
    {
        return self::endpoint() !== '';
    }

    public static function isSecure(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && trim((string) ($parts['host'] ?? '')) !== '';
    }

    public static function endpointHost(): string
    {
        return strtolower((string) (parse_url(self::endpoint(), PHP_URL_HOST) ?? ''));
    }

    /**
     * The endpoint as it may be stored and shown: scheme, host, port and path,
     * without user-info or query string.
     */
    public static function displayEndpoint(): string
    {
        $parts = parse_url(self::endpoint());
        if (! is_array($parts) || empty($parts['host'])) {
            return '';
        }

        return strtolower((string) ($parts['scheme'] ?? '')).'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '');
    }

    public static function authMethod(): string
    {
        return match (true) {
            trim((string) config('services.fhir.auth_token', '')) !== '' => 'Bearer token',
            trim((string) config('services.fhir.username', '')) !== '' => 'HTTP Basic',
            default => 'None',
        };
    }

    /**
     * Who is sending, from the signed-in session; a console run has none.
     *
     * @return array{name: ?string, username: ?string, role: ?string}
     */
    public static function actorFromSession(): array
    {
        $request = request();
        $session = $request?->hasSession() ? $request->session() : null;

        return [
            'name' => $session?->get('active_name'),
            'username' => $session?->get('active_username'),
            'role' => $session?->get('active_role'),
        ];
    }

    /**
     * @param  array{name: ?string, username: ?string, role: ?string}  $actor
     *
     * @throws FhirExchangeUnavailable when no endpoint is configured
     */
    public function transmit(StudentHealthRecord $record, string $trigger = 'manual', ?array $actor = null): FhirTransmission
    {
        $endpoint = self::endpoint();
        if ($endpoint === '') {
            throw new FhirExchangeUnavailable('No FHIR endpoint is configured. Set FHIR_ENDPOINT to the receiving server\'s https:// base URL.');
        }

        $actor ??= self::actorFromSession();
        $deidentified = FhirBundle::deidentifies();
        $bundle = FhirBundle::forRecord($record, $deidentified);
        $json = FhirBundle::encode($bundle);
        $secure = self::isSecure($endpoint);

        $transmission = FhirTransmission::create([
            'uuid' => (string) $bundle['identifier']['value'],
            'institution_id' => $record->institution_id,
            'student_health_record_id' => $record->id,
            'student_lrn' => (string) $record->student_id,
            'endpoint_host' => self::endpointHost(),
            'endpoint_url' => self::displayEndpoint(),
            'trigger' => $trigger,
            'deidentified' => $deidentified,
            'resource_count' => count($bundle['entry']),
            'payload' => $json,
            'payload_sha256' => hash('sha256', $json),
            'status' => $secure ? FhirTransmission::STATUS_PENDING : FhirTransmission::STATUS_BLOCKED,
            'error_message' => $secure ? null : 'Refused: the endpoint is not HTTPS, so nothing was sent.',
            'sent_by_name' => $actor['name'] ?? null,
            'sent_by_username' => $actor['username'] ?? null,
            'sent_by_role' => $actor['role'] ?? null,
        ]);

        if (! $secure) {
            $this->audit($record, $transmission, 'transmission_blocked',
                'Blocked a FHIR transmission for LRN '.$record->student_id.': endpoint is not HTTPS');

            return $transmission;
        }

        try {
            $response = $this->request($transmission)->withBody($json, FhirBundle::MIME)->post($endpoint);
            $this->settle($transmission, $response);
        } catch (ConnectionException $e) {
            $transmission->update([
                'status' => FhirTransmission::STATUS_FAILED,
                'error_message' => 'The receiving server could not be reached: '.$e->getMessage(),
                'sent_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
            $transmission->update([
                'status' => FhirTransmission::STATUS_FAILED,
                'error_message' => 'The transmission failed: '.$e->getMessage(),
                'sent_at' => now(),
            ]);
        }

        $sent = $transmission->status === FhirTransmission::STATUS_SENT;

        $this->audit(
            $record,
            $transmission,
            $sent ? 'transmitted' : 'transmission_failed',
            sprintf(
                '%s FHIR R4 bundle (%d resources) for LRN %s to %s%s',
                $sent ? 'Transmitted' : 'Failed to transmit',
                $transmission->resource_count,
                $record->student_id,
                $transmission->endpoint_host,
                $transmission->http_status ? ' — HTTP '.$transmission->http_status : '',
            ),
        );

        return $transmission;
    }

    private function request(FhirTransmission $transmission): PendingRequest
    {
        $options = ['verify' => true];
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            // The floor, not the ceiling: TLS 1.3 is still negotiated where
            // the server offers it.
            $options['crypto_method'] = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }

        $request = Http::withOptions($options)
            ->withHeaders([
                'Accept' => FhirBundle::MIME,
                'X-Request-ID' => $transmission->uuid,
                'User-Agent' => 'LUSOG-FHIR/1.0',
            ])
            ->timeout(max(5, (int) config('services.fhir.timeout', 30)))
            ->retry(3, fn (int $attempt) => $attempt * 500, function (Throwable $e): bool {
                return $e instanceof ConnectionException
                    || ($e instanceof RequestException && in_array($e->response->status(), self::TRANSIENT, true));
            }, throw: false);

        $token = trim((string) config('services.fhir.auth_token', ''));
        $username = trim((string) config('services.fhir.username', ''));

        if ($token !== '') {
            $request = $request->withToken($token);
        } elseif ($username !== '') {
            $request = $request->withBasicAuth($username, (string) config('services.fhir.password', ''));
        }

        return $request;
    }

    private function settle(FhirTransmission $transmission, Response $response): void
    {
        $body = (string) $response->body();
        $ok = $response->successful();

        $transmission->update([
            'status' => $ok ? FhirTransmission::STATUS_SENT : FhirTransmission::STATUS_FAILED,
            'http_status' => $response->status(),
            'response_body' => $body !== '' ? substr($body, 0, self::MAX_RESPONSE_BYTES) : null,
            'error_message' => $ok ? null : $this->failureReason($response),
            'sent_at' => now(),
        ]);
    }

    /** The server's own words, from an OperationOutcome when it sent one. */
    private function failureReason(Response $response): string
    {
        $json = $response->json();

        if (is_array($json) && ($json['resourceType'] ?? null) === 'OperationOutcome') {
            $messages = collect($json['issue'] ?? [])
                ->map(fn ($issue) => trim((string) ($issue['diagnostics'] ?? ($issue['details']['text'] ?? ''))))
                ->filter()
                ->take(3)
                ->implode(' · ');

            if ($messages !== '') {
                return 'HTTP '.$response->status().': '.$messages;
            }
        }

        return 'The receiving server answered HTTP '.$response->status().'.';
    }

    private function audit(StudentHealthRecord $record, FhirTransmission $transmission, string $action, string $description): void
    {
        AuditTrail::record($action, 'StudentHealthRecord', (int) $record->id, $description, [
            'fhir_transmission_id' => $transmission->id,
            'fhir_transmission_uuid' => $transmission->uuid,
            'endpoint_host' => $transmission->endpoint_host,
            'payload_sha256' => $transmission->payload_sha256,
            'resource_count' => $transmission->resource_count,
            'http_status' => $transmission->http_status,
            'deidentified' => $transmission->deidentified,
            'trigger' => $transmission->trigger,
            'sent_by' => $transmission->sent_by_username,
        ]);
    }
}
