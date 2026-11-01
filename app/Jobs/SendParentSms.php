<?php

namespace App\Jobs;

use App\Models\SmsNotification;
use App\Support\AuditTrail;
use App\Support\SemaphoreClient;
use App\Support\SmsGatewayFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Hands one queued text message to the gateway and records its answer.
 *
 * Dispatched after the response by ParentNotifier, so it needs no queue
 * worker (the deployment runs none) and the staff member who triggered it
 * never waits on the gateway. It acts only on a row still `queued` — a row a
 * rolled-back transaction never committed is simply not found, and a row
 * already sent is never sent again.
 */
class SendParentSms implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $notificationId) {}

    public function handle(SemaphoreClient $client): void
    {
        $row = SmsNotification::query()->find($this->notificationId);

        if ($row === null || $row->status !== SmsNotification::STATUS_QUEUED) {
            return;
        }

        $row->attempts = (int) $row->attempts + 1;

        if ($client->isDryRun()) {
            $row->fill([
                'status' => SmsNotification::STATUS_SIMULATED,
                'provider_status' => 'Dry run',
                'error' => null,
                'sent_at' => now(),
            ])->save();

            return;
        }

        try {
            $sent = $client->send((string) $row->recipient, (string) $row->message);

            $row->fill([
                'status' => SemaphoreClient::localStatus($sent['status']),
                'provider_message_id' => $sent['message_id'],
                'provider_status' => $sent['status'],
                'network' => $sent['network'],
                'error' => null,
                'sent_at' => now(),
            ])->save();
        } catch (SmsGatewayFailure $e) {
            $row->fill([
                'status' => SmsNotification::STATUS_FAILED,
                'provider_status' => $e->httpStatus ? 'HTTP '.$e->httpStatus : null,
                'error' => $e->getMessage(),
            ])->save();

            AuditTrail::record('sms_failed', 'SmsNotification', $row->id, 'A text message to a parent was not sent: '.$e->getMessage());
        }
    }
}
