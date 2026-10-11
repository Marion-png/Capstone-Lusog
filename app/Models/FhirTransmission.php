<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * One outbound HL7 FHIR disclosure — see App\Support\FhirTransmitter.
 *
 * The payload is the exact bytes that were sent, kept so the school can show
 * what it disclosed, to whom and when; `payload_sha256` is the digest taken
 * before sending, so the stored copy can be checked against it.
 */
class FhirTransmission extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** Refused before anything left the server — the endpoint was not HTTPS. */
    public const STATUS_BLOCKED = 'blocked';

    protected $fillable = [
        'uuid',
        'institution_id',
        'student_health_record_id',
        'student_lrn',
        'endpoint_host',
        'endpoint_url',
        'trigger',
        'deidentified',
        'resource_count',
        'payload',
        'payload_sha256',
        'status',
        'http_status',
        'response_body',
        'error_message',
        'sent_by_name',
        'sent_by_username',
        'sent_by_role',
        'sent_at',
    ];

    protected $casts = [
        'payload' => EncryptedString::class,
        'response_body' => EncryptedString::class,
        'error_message' => EncryptedString::class,
        'sent_by_name' => EncryptedString::class,
        'deidentified' => 'boolean',
        'resource_count' => 'integer',
        'http_status' => 'integer',
        'sent_at' => 'datetime',
    ];

    /** The stored payload still hashes to the digest taken before it was sent. */
    public function payloadIntact(): bool
    {
        return $this->payload !== null
            && $this->payload_sha256 !== null
            && hash_equals((string) $this->payload_sha256, hash('sha256', (string) $this->payload));
    }

    /**
     * A location the server assigned: `Patient/96973/_history/1`, or the same
     * at the end of an absolute URL. FHIR ids are 1–64 of [A-Za-z0-9-.].
     */
    private const LOCATION = '~(?:^|/)([A-Z][A-Za-z]+)/([A-Za-z0-9\-.]{1,64})(?:/_history/([A-Za-z0-9\-.]{1,64}))?/?$~';

    /**
     * What the receiving server said it did with each entry, read off a
     * transaction-response Bundle: the status, the location it assigned and
     * the logical id inside it — the id a GET on `<endpoint>/<type>/<id>`
     * reads the resource back by.
     *
     * A transaction-response lists its entries in the order the request did,
     * so entry n of the reply is paired with entry n of the payload to say
     * which resource it was.
     *
     * @return list<array{status: string, location: string, resource_type: string, id: string, version: string, label: string, created: bool, url_prefix: string, url: string}>
     */
    public function serverOutcomes(): array
    {
        $entries = $this->responseEntries();
        if ($entries === []) {
            return [];
        }

        $sent = json_decode((string) $this->payload, true);
        $requested = is_array($sent['entry'] ?? null) ? array_values($sent['entry']) : [];

        $out = [];
        foreach ($entries as $i => $response) {
            $resource = is_array($requested[$i]['resource'] ?? null) ? $requested[$i]['resource'] : [];
            $out[] = $this->outcome($response, $resource);
        }

        return $out;
    }

    /**
     * The learner's Patient as the receiving server holds it, or null when
     * the server assigned none (not sent, refused, or no location returned).
     * Reads the reply only, so a list of transmissions never decrypts a
     * payload to show it.
     *
     * @return array{status: string, location: string, resource_type: string, id: string, version: string, label: string, created: bool, url_prefix: string, url: string}|null
     */
    public function patientOutcome(): ?array
    {
        if ($this->status !== self::STATUS_SENT) {
            return null;
        }

        foreach ($this->responseEntries() as $response) {
            $outcome = $this->outcome($response, []);
            if ($outcome['resource_type'] === 'Patient' && $outcome['id'] !== '') {
                return $outcome;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function responseEntries(): array
    {
        $body = json_decode((string) $this->response_body, true);

        if (! is_array($body) || ($body['resourceType'] ?? null) !== 'Bundle') {
            return [];
        }

        $out = [];
        foreach ($body['entry'] ?? [] as $entry) {
            $out[] = is_array($entry['response'] ?? null) ? $entry['response'] : [];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $resource
     * @return array{status: string, location: string, resource_type: string, id: string, version: string, label: string, created: bool, url_prefix: string, url: string}
     */
    private function outcome(array $response, array $resource): array
    {
        $status = trim((string) ($response['status'] ?? ''));
        $location = trim((string) ($response['location'] ?? ''));
        $parsed = preg_match(self::LOCATION, $location, $m) === 1;

        $type = $parsed ? $m[1] : (string) ($resource['resourceType'] ?? '');
        $id = $parsed ? $m[2] : '';
        $base = rtrim((string) $this->endpoint_url, '/');
        $prefix = ($id !== '' && $base !== '') ? $base.'/'.$type.'/' : '';

        return [
            'status' => $status,
            'location' => $location,
            'resource_type' => $type,
            'id' => $id,
            'version' => $parsed ? ($m[3] ?? '') : '',
            'label' => self::describe($resource),
            'created' => str_starts_with($status, '201'),
            'url_prefix' => $prefix,
            'url' => $prefix !== '' ? $prefix.$id : '',
        ];
    }

    /** What one payload entry was, in the words the resource itself carries. */
    private static function describe(array $resource): string
    {
        return match ($resource['resourceType'] ?? '') {
            'Organization' => (string) ($resource['name'] ?? ''),
            'Patient' => (string) ($resource['name'][0]['text'] ?? 'Pseudonymised learner'),
            'Observation', 'Condition' => (string) ($resource['code']['text'] ?? ''),
            default => '',
        };
    }
}
