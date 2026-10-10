<?php

namespace App\Console\Commands;

use App\Models\StudentHealthRecord;
use App\Support\FhirBundle;
use App\Support\FhirExchangeUnavailable;
use App\Support\FhirTransmitter;
use Illuminate\Console\Command;

/**
 * Sends one learner's record to the receiving FHIR server from the command
 * line, through the same transmitter the exchange screen uses — the same
 * HTTPS rule, the same transmission record, the same audit entry.
 *
 * `--preview` prints the bundle and sends nothing.
 */
class SendFhirBundle extends Command
{
    protected $signature = 'fhir:transmit
        {lrn : The learner\'s LRN}
        {--institution= : The school the learner belongs to (required: an LRN is only unique within one)}
        {--school-year= : A school year other than the current one}
        {--preview : Print the FHIR bundle and send nothing}';

    protected $description = 'Serialise a learner\'s record as an HL7 FHIR R4 bundle and send it to FHIR_ENDPOINT over HTTPS';

    public function handle(FhirTransmitter $transmitter): int
    {
        $institutionId = (int) $this->option('institution');
        if ($institutionId <= 0) {
            $this->error('--institution is required.');

            return self::FAILURE;
        }

        $record = StudentHealthRecord::currentForStudent(
            (string) $this->argument('lrn'),
            $institutionId,
            $this->option('school-year') ?: null,
        );

        if ($record === null) {
            $this->error('No record for that LRN at that school in that school year.');

            return self::FAILURE;
        }

        if ($this->option('preview')) {
            $this->line(FhirBundle::encode(FhirBundle::forRecord($record)));

            return self::SUCCESS;
        }

        try {
            $transmission = $transmitter->transmit($record, 'console', [
                'name' => 'Console',
                'username' => get_current_user() ?: null,
                'role' => 'console',
            ]);
        } catch (FhirExchangeUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Transmission', 'Status', 'HTTP', 'Server', 'Resources', 'SHA-256'], [[
            $transmission->id,
            $transmission->status,
            $transmission->http_status ?? '—',
            $transmission->endpoint_host,
            $transmission->resource_count,
            $transmission->payload_sha256,
        ]]);

        foreach ($transmission->serverOutcomes() as $outcome) {
            $this->line('  '.$outcome['status'].'  '.$outcome['location']);
        }

        if ($transmission->status !== 'sent') {
            $this->error((string) $transmission->error_message);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
