<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The Semaphore SMS gateway (https://api.semaphore.co/docs, API v4).
 *
 * Three calls and nothing else:
 *   - send()    POST /messages          one message to one number
 *   - message() GET  /messages/{id}     what the network has done with it since
 *   - account() GET  /account           the credit balance, for the admin page
 *
 * It knows nothing about learners, parents or consent — ParentNotifier decides
 * whether a message may be sent and what it says; this class only carries it.
 * Every failure leaves as an SmsGatewayFailure whose message a person can act
 * on, never as a transport exception naming the endpoint.
 *
 * Retrying a send is deliberately narrow. A 429 or a 5xx means the gateway did
 * not take the message, so asking again is safe. A connection that dropped
 * after the request went out may already have been accepted, and a parent
 * texted twice about one clinic visit is worse than a message staff retry by
 * hand — so a dropped connection is reported, not repeated.
 */
class SemaphoreClient
{
    /** Statuses that mean "not taken; try again shortly". */
    private const TRANSIENT = [429, 500, 502, 503, 504];

    /** What a single message may cost: three 160-character parts. */
    public const MAX_LENGTH = 480;

    public function isConfigured(): bool
    {
        return trim((string) config('services.semaphore.key', '')) !== '';
    }

    public function isDryRun(): bool
    {
        return (bool) config('services.semaphore.dry_run', false);
    }

    /** True when a message would go somewhere: to the gateway, or to the dry-run log. */
    public function isAvailable(): bool
    {
        return $this->isDryRun() || $this->isConfigured();
    }

    /**
     * Send one message.
     *
     * @return array{message_id: string, status: string, network: ?string}
     *
     * @throws SmsGatewayFailure
     */
    public function send(string $number, string $message): array
    {
        $number = PhilippineMobileNumber::normalize($number)
            ?? throw new SmsGatewayFailure('The number is not a Philippine mobile number.');

        $message = trim($message);

        if ($message === '') {
            throw new SmsGatewayFailure('The message is empty.');
        }

        // Semaphore silently drops any message that begins with "TEST": it
        // would be charged as sent and never arrive.
        if (preg_match('/^test/i', $message) === 1) {
            throw new SmsGatewayFailure('A message may not begin with the word "TEST"; the gateway discards it.');
        }

        if (mb_strlen($message) > self::MAX_LENGTH) {
            throw new SmsGatewayFailure('The message is longer than '.self::MAX_LENGTH.' characters.');
        }

        $payload = [
            // In the form body, not the query string: a URL is the part of a
            // request that ends up in logs.
            'apikey' => $this->key(),
            'number' => $number,
            'message' => $message,
        ];

        $sender = trim((string) config('services.semaphore.sender_name', ''));
        if ($sender !== '') {
            $payload['sendername'] = $sender;
        }

        $response = $this->call(fn (PendingRequest $http) => $http
            ->retry(3, 1000, fn (Throwable $e): bool => $e instanceof RequestException
                && in_array($e->response->status(), self::TRANSIENT, true), throw: false)
            ->asForm()
            ->post($this->url('/messages'), $payload));

        $first = $this->firstMessage($response);

        if ($first === null || trim((string) ($first['message_id'] ?? '')) === '') {
            throw $this->failureFrom($response);
        }

        return [
            'message_id' => (string) $first['message_id'],
            'status' => (string) ($first['status'] ?? 'Pending'),
            'network' => isset($first['network']) ? (string) $first['network'] : null,
        ];
    }

    /**
     * What the network has done with a message since it was sent.
     *
     * @return array{status: string, network: ?string}
     *
     * @throws SmsGatewayFailure
     */
    public function message(string $messageId): array
    {
        $response = $this->call(fn (PendingRequest $http) => $http
            ->get($this->url('/messages/'.rawurlencode($messageId)), ['apikey' => $this->key()]));

        $first = $this->firstMessage($response);

        if ($first === null || ! isset($first['status'])) {
            throw $this->failureFrom($response);
        }

        return [
            'status' => (string) $first['status'],
            'network' => isset($first['network']) ? (string) $first['network'] : null,
        ];
    }

    /**
     * The account the key belongs to and what is left on it.
     *
     * @return array{name: string, status: string, credit_balance: ?float}
     *
     * @throws SmsGatewayFailure
     */
    public function account(): array
    {
        $response = $this->call(fn (PendingRequest $http) => $http
            ->get($this->url('/account'), ['apikey' => $this->key()]));

        $body = $response->json();

        if ($response->failed() || ! is_array($body) || ! array_key_exists('credit_balance', $body)) {
            throw $this->failureFrom($response);
        }

        return [
            'name' => (string) ($body['account_name'] ?? ''),
            'status' => (string) ($body['status'] ?? ''),
            'credit_balance' => is_numeric($body['credit_balance']) ? (float) $body['credit_balance'] : null,
        ];
    }

    /**
     * The gateway's word for a message, in this application's.
     *
     * Semaphore reports Queued / Pending while the message is on its way,
     * Sent once the network has it, and Failed / Refunded when it could not be
     * delivered (a refunded message is charged back because it failed).
     */
    public static function localStatus(string $providerStatus): string
    {
        return match (strtolower(trim($providerStatus))) {
            'sent', 'delivered', 'success' => 'delivered',
            'failed', 'refunded', 'rejected', 'expired' => 'failed',
            default => 'accepted',
        };
    }

    /** One request, with every transport failure turned into words. */
    private function call(callable $send): Response
    {
        if (! $this->isConfigured()) {
            throw new SmsGatewayFailure('No Semaphore API key is set (SEMAPHORE_API_KEY).');
        }

        try {
            /** @var Response $response */
            $response = $send(Http::acceptJson()->timeout(max(5, (int) config('services.semaphore.timeout', 15))));
        } catch (ConnectionException $e) {
            report($e);

            throw new SmsGatewayFailure('The SMS gateway could not be reached. Check the connection and try again.', true, null, $e);
        } catch (Throwable $e) {
            report($e);

            throw new SmsGatewayFailure('The SMS gateway could not be reached.', true, null, $e);
        }

        return $response;
    }

    /** The first message object of a list response, or null when the body is not one. */
    private function firstMessage(Response $response): ?array
    {
        if ($response->failed()) {
            return null;
        }

        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        // A list of messages is the documented answer; one bare object is
        // accepted too, so a gateway that changes the shape still reads.
        $first = array_is_list($body) ? ($body[0] ?? null) : $body;

        return is_array($first) ? $first : null;
    }

    /** The gateway's refusal, as something a person can act on. */
    private function failureFrom(Response $response): SmsGatewayFailure
    {
        $status = $response->status();
        $reason = $this->reasonIn($response->json());
        $lower = strtolower($reason);

        return match (true) {
            str_contains($lower, 'insufficient') || str_contains($lower, 'credit') || str_contains($lower, 'balance') => new SmsGatewayFailure('The SMS account has run out of credits. Top up the Semaphore account, then retry.', false, $status),
            str_contains($lower, 'apikey') || str_contains($lower, 'api key') || in_array($status, [401, 403], true) => new SmsGatewayFailure('The Semaphore API key was refused. Check SEMAPHORE_API_KEY.', false, $status),
            str_contains($lower, 'sendername') || str_contains($lower, 'sender name') => new SmsGatewayFailure('The sender name is not approved on the Semaphore account. Check SEMAPHORE_SENDER_NAME.', false, $status),
            $status === 429 => new SmsGatewayFailure('The SMS gateway is busy. Try again in a minute.', true, $status),
            $status >= 500 => new SmsGatewayFailure('The SMS gateway is unavailable at the moment. Try again shortly.', true, $status),
            default => new SmsGatewayFailure('The SMS gateway refused the message'.($reason !== '' ? ': '.$reason : ' (HTTP '.$status.').'), false, $status),
        };
    }

    /**
     * Every string in an error body, joined. Semaphore answers a refusal with
     * a validation-style object ({"number": ["The number format is invalid."]})
     * or a single message, so the words are collected wherever they sit.
     */
    private function reasonIn(mixed $body): string
    {
        if (is_string($body)) {
            return trim($body);
        }

        if (! is_array($body)) {
            return '';
        }

        $parts = [];
        array_walk_recursive($body, function ($value) use (&$parts): void {
            if (is_string($value) && trim($value) !== '') {
                $parts[] = trim($value);
            }
        });

        return mb_substr(implode(' ', array_unique($parts)), 0, 300);
    }

    private function key(): string
    {
        return trim((string) config('services.semaphore.key', ''));
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.semaphore.endpoint', 'https://api.semaphore.co/api/v4'), '/').$path;
    }
}
