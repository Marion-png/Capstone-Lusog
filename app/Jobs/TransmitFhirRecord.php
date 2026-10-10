<?php

namespace App\Jobs;

use App\Models\StudentHealthRecord;
use App\Support\FhirExchangeUnavailable;
use App\Support\FhirTransmitter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The automatic send: a learner's record follows their examination out to
 * the receiving FHIR server (FHIR_AUTO_TRANSMIT).
 *
 * Dispatched after the response, so the nurse who saved the examination
 * never waits on another organisation's server; and it goes through
 * FhirTransmitter like every other send, so the HTTPS rule, the transmission
 * record and the audit entry are the same as for a send from the screen.
 */
class TransmitFhirRecord implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;

    /**
     * @param  array{name: ?string, username: ?string, role: ?string}  $actor
     */
    public function __construct(
        public int $recordId,
        public array $actor,
        public string $trigger = 'examination',
    ) {}

    public function handle(FhirTransmitter $transmitter): void
    {
        $record = StudentHealthRecord::query()->find($this->recordId);

        if ($record === null) {
            return;
        }

        try {
            $transmitter->transmit($record, $this->trigger, $this->actor);
        } catch (FhirExchangeUnavailable) {
            // Switched on with no endpoint: nothing to send to, and nothing
            // was sent. The setting is the problem, not the examination.
        }
    }
}
