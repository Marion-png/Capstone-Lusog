<?php

namespace App\Support;

use App\Models\SmsNotification;

/**
 * Asks the gateway what became of the messages it accepted.
 *
 * Semaphore answers a send with "Pending" and moves the message to "Sent" or
 * "Failed" once the network reports back. It offers no callback, so the
 * answer is fetched: from the System Admin's Refresh button and from
 * `php artisan sms:refresh-status`. Only messages still awaiting a report and
 * younger than three days are asked about; past that the network has
 * reported whatever it is going to.
 */
class SmsDeliveryReports
{
    public function __construct(private readonly SemaphoreClient $client) {}

    /**
     * @return array{checked: int, delivered: int, failed: int, error: ?string}
     */
    public function refresh(int $limit = 50): array
    {
        $result = ['checked' => 0, 'delivered' => 0, 'failed' => 0, 'error' => null];

        if (! $this->client->isConfigured() || ! SchemaCache::hasTable('sms_notifications')) {
            return $result;
        }

        $pending = SmsNotification::query()
            ->where('status', SmsNotification::STATUS_ACCEPTED)
            ->whereNotNull('provider_message_id')
            ->where('sent_at', '>=', now()->subDays(3))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($pending as $row) {
            try {
                $report = $this->client->message((string) $row->provider_message_id);
            } catch (SmsGatewayFailure $e) {
                // One unreadable report stops the round: the next is likely to
                // fail the same way, and each call spends the rate limit.
                $result['error'] = $e->getMessage();
                break;
            }

            $result['checked']++;
            $status = SemaphoreClient::localStatus($report['status']);

            $row->fill([
                'status' => $status,
                'provider_status' => $report['status'],
                'network' => $report['network'] ?? $row->network,
                'status_checked_at' => now(),
            ]);

            if ($status === SmsNotification::STATUS_FAILED) {
                $row->error = 'The network could not deliver the message ('.$report['status'].').';
                $result['failed']++;
            } elseif ($status === SmsNotification::STATUS_DELIVERED) {
                $result['delivered']++;
            }

            $row->save();
        }

        return $result;
    }
}
