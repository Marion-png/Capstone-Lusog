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
     * What the receiving server said it did with each entry, read off a
     * transaction-response Bundle: the status and the location it assigned.
     *
     * @return list<array{status: string, location: string}>
     */
    public function serverOutcomes(): array
    {
        $body = json_decode((string) $this->response_body, true);

        if (! is_array($body) || ($body['resourceType'] ?? null) !== 'Bundle') {
            return [];
        }

        $out = [];
        foreach ($body['entry'] ?? [] as $entry) {
            $response = is_array($entry['response'] ?? null) ? $entry['response'] : [];
            $out[] = [
                'status' => (string) ($response['status'] ?? ''),
                'location' => (string) ($response['location'] ?? ''),
            ];
        }

        return $out;
    }
}
