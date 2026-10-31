<?php

namespace App\Http\Controllers;

use App\Jobs\SendParentSms;
use App\Models\Institution;
use App\Models\SmsNotification;
use App\Support\AuditTrail;
use App\Support\ParentNotifier;
use App\Support\SchemaCache;
use App\Support\SemaphoreClient;
use App\Support\SmsDeliveryReports;
use App\Support\SmsGatewayFailure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * The System Admin's console for parent text messages.
 *
 * It answers three questions — is the gateway working, what has been sent,
 * and what went wrong — and offers three actions: send a sample to a number,
 * fetch delivery reports, and retry a message the gateway never took. It
 * lists every school's messages because the System Admin is the one
 * unscoped role; each row prints only the last four digits of the number.
 */
class SmsNotificationController extends Controller
{
    private const ROLE = 'system_admin';

    public function index(Request $request, SemaphoreClient $client): View|RedirectResponse
    {
        if ($redirect = $this->guard($request)) {
            return $redirect;
        }

        $hasTable = SchemaCache::hasTable('sms_notifications');
        $status = (string) $request->query('status', '');
        $event = (string) $request->query('event', '');
        $status = array_key_exists($status, SmsNotification::STATUSES) ? $status : '';
        $event = array_key_exists($event, SmsNotification::EVENTS) ? $event : '';

        $rows = $hasTable
            ? SmsNotification::query()
                ->when($status !== '', fn ($q) => $q->where('status', $status))
                ->when($event !== '', fn ($q) => $q->where('event', $event))
                ->orderByDesc('id')
                ->limit(200)
                ->get()
            : collect();

        $counts = $hasTable
            ? SmsNotification::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all()
            : [];

        return view('dashboard.system-admin-sms', [
            'hasTable' => $hasTable,
            'rows' => $rows,
            'counts' => $counts,
            'filterStatus' => $status,
            'filterEvent' => $event,
            'schools' => Institution::query()->pluck('name', 'id')->all(),
            'configured' => $client->isConfigured(),
            'dryRun' => $client->isDryRun(),
            'senderName' => trim((string) config('services.semaphore.sender_name', '')),
            'dailyCap' => (int) config('services.semaphore.daily_cap', 300),
            'account' => $this->account($client),
        ]);
    }

    /** Send the sample message to a number the admin types. */
    public function test(Request $request, ParentNotifier $notifier): RedirectResponse
    {
        if ($redirect = $this->guard($request)) {
            return $redirect;
        }

        $validated = $request->validate([
            'number' => ['required', 'string', 'max:30'],
        ]);

        if (! SchemaCache::hasTable('sms_notifications')) {
            return back()->with('error', 'Run the pending migrations first (php artisan migrate).');
        }

        if (! app(SemaphoreClient::class)->isAvailable()) {
            return back()->with('error', 'Set SEMAPHORE_API_KEY (or SMS_DRY_RUN=true) to send a message.');
        }

        try {
            $row = $notifier->test($validated['number'], null);
        } catch (SmsGatewayFailure $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        Cache::forget('semaphore.account');

        return match ($row->status) {
            SmsNotification::STATUS_FAILED => back()->with('error', 'The sample message was not sent: '.$row->error),
            SmsNotification::STATUS_SIMULATED => back()->with('success', 'Dry run: the sample message was composed and logged, not sent.'),
            default => back()->with('success', 'Sample message accepted by Semaphore (message id '.$row->provider_message_id.'). Refresh delivery status in a minute.'),
        };
    }

    /** Fetch delivery reports for messages still on their way. */
    public function refresh(Request $request, SmsDeliveryReports $reports): RedirectResponse
    {
        if ($redirect = $this->guard($request)) {
            return $redirect;
        }

        $result = $reports->refresh();

        if ($result['error'] !== null) {
            return back()->with('error', 'Delivery reports could not be fetched: '.$result['error']);
        }

        return back()->with('success', $result['checked'] === 0
            ? 'No message is waiting for a delivery report.'
            : "Checked {$result['checked']} message(s): {$result['delivered']} delivered, {$result['failed']} failed.");
    }

    /** Try again a message the gateway never took. */
    public function retry(Request $request, SmsNotification $notification): RedirectResponse
    {
        if ($redirect = $this->guard($request)) {
            return $redirect;
        }

        if (! $notification->canRetry()) {
            return back()->with('error', 'Only a message the gateway never accepted can be retried.');
        }

        $notification->fill(['status' => SmsNotification::STATUS_QUEUED, 'error' => null])->save();
        AuditTrail::record('sms_retried', 'SmsNotification', $notification->id, 'A failed text message to a parent was retried');

        SendParentSms::dispatchSync($notification->id);
        $notification->refresh();

        return $notification->status === SmsNotification::STATUS_FAILED
            ? back()->with('error', 'The retry failed: '.$notification->error)
            : back()->with('success', 'The message was sent again.');
    }

    /**
     * The account's name and balance, held for a minute: the gateway rate-
     * limits this call and the page is reloaded after every action.
     *
     * @return array{ok: bool, name?: string, credit_balance?: ?float, error?: string}|null
     */
    private function account(SemaphoreClient $client): ?array
    {
        if (! $client->isConfigured()) {
            return null;
        }

        $cached = Cache::get('semaphore.account');
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $account = ['ok' => true] + $client->account();
            Cache::put('semaphore.account', $account, 60);

            return $account;
        } catch (SmsGatewayFailure $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function guard(Request $request): ?RedirectResponse
    {
        if ($request->session()->get('active_role') === self::ROLE) {
            return null;
        }

        return redirect()->route('login')->with('error', 'Only the System Admin can manage text messages.');
    }
}
