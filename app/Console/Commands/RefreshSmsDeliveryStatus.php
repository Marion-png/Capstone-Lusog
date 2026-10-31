<?php

namespace App\Console\Commands;

use App\Support\SmsDeliveryReports;
use Illuminate\Console\Command;

/**
 * Fetches delivery reports from Semaphore for every parent text message still
 * on its way. The same work as the System Admin's "Refresh delivery status"
 * button, for a scheduler or a human at a terminal.
 */
class RefreshSmsDeliveryStatus extends Command
{
    protected $signature = 'sms:refresh-status
        {--limit=50 : The most messages to ask about in one run}';

    protected $description = 'Ask the SMS gateway what became of the parent text messages it accepted';

    public function handle(SmsDeliveryReports $reports): int
    {
        $result = $reports->refresh(max(1, (int) $this->option('limit')));

        if ($result['error'] !== null) {
            $this->error($result['error']);

            return self::FAILURE;
        }

        $this->info("Checked {$result['checked']} message(s): {$result['delivered']} delivered, {$result['failed']} failed.");

        return self::SUCCESS;
    }
}
