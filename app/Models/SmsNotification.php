<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * One text message the school tried to send to a parent.
 *
 * Written by App\Support\ParentNotifier and sent by App\Jobs\SendParentSms
 * through the Semaphore gateway. The number, the message and the gateway's
 * error text are encrypted at rest; the screens print `recipient_last4` and
 * never the whole number.
 */
class SmsNotification extends Model
{
    use Auditable;

    /** Written, not yet handed to the gateway. */
    public const STATUS_QUEUED = 'queued';

    /** The gateway took it and is passing it to the network. */
    public const STATUS_ACCEPTED = 'accepted';

    /** The network reports it reached the phone. */
    public const STATUS_DELIVERED = 'delivered';

    /** The gateway refused it, or the network could not deliver it. */
    public const STATUS_FAILED = 'failed';

    /** Dry-run mode: composed and logged, never sent. */
    public const STATUS_SIMULATED = 'simulated';

    public const STATUSES = [
        self::STATUS_QUEUED => 'Queued',
        self::STATUS_ACCEPTED => 'Sent to network',
        self::STATUS_DELIVERED => 'Delivered',
        self::STATUS_FAILED => 'Failed',
        self::STATUS_SIMULATED => 'Simulated',
    ];

    public const EVENT_CLINIC_VISIT = 'clinic_visit';

    public const EVENT_ATTENDANCE_NOTICE = 'attendance_notice';

    public const EVENT_TEST = 'test';

    public const EVENTS = [
        self::EVENT_CLINIC_VISIT => 'Clinic visit',
        self::EVENT_ATTENDANCE_NOTICE => 'Feeding absences',
        self::EVENT_TEST => 'Test message',
    ];

    protected $fillable = [
        'institution_id',
        'student_lrn',
        'event',
        'dedupe_key',
        'recipient',
        'recipient_last4',
        'message',
        'language',
        'status',
        'provider_message_id',
        'provider_status',
        'network',
        'attempts',
        'error',
        'requested_by_name',
        'requested_by_role',
        'sent_at',
        'status_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'recipient' => EncryptedString::class,
            'message' => EncryptedString::class,
            'error' => EncryptedString::class,
            'requested_by_name' => EncryptedString::class,
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'status_checked_at' => 'datetime',
        ];
    }

    /** A message the gateway may still change its mind about. */
    public function awaitsDeliveryReport(): bool
    {
        return $this->status === self::STATUS_ACCEPTED && $this->provider_message_id !== null;
    }

    /** A failed send that never reached the gateway's queue can be tried again. */
    public function canRetry(): bool
    {
        return $this->status === self::STATUS_FAILED && $this->provider_message_id === null;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            self::STATUS_DELIVERED => 'badge-normal',
            self::STATUS_ACCEPTED, self::STATUS_QUEUED => 'badge-info',
            self::STATUS_FAILED => 'badge-critical',
            default => 'badge-neutral',
        };
    }

    public function eventLabel(): string
    {
        return self::EVENTS[$this->event] ?? (string) $this->event;
    }
}
