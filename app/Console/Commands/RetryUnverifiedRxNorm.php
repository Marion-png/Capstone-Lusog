<?php

namespace App\Console\Commands;

use App\Models\LearnerMedicineAllergy;
use App\Models\Medicine;
use App\Models\RxConcept;
use App\Models\StudentHealthRecord;
use App\Support\MedicineAllergies;
use App\Support\RxNormService;
use App\Support\RxNormUnavailable;
use Illuminate\Console\Command;

/**
 * Ask RxNav again for everything it could not answer (App\Support\RxNormService),
 * so a short outage fixes itself: every unverified medicine and medicine
 * allergy is looked up again, and every stored concept older than 90 days is
 * fetched afresh.
 *
 * --all-learners first reads every learner's recorded allergies and looks up
 * the ones never looked up — run it once after migrating, for allergies that
 * were recorded before the check existed.
 */
class RetryUnverifiedRxNorm extends Command
{
    protected $signature = 'rxnorm:retry-unverified
        {--all-learners : Read every learner\'s recorded allergies first}';

    protected $description = 'Look up unverified medicines and allergies in RxNorm again, and refresh stale concepts';

    public function handle(RxNormService $rx): int
    {
        if (! RxNormService::supported()) {
            $this->error('Run php artisan migrate first: the RxNorm tables are missing.');

            return self::FAILURE;
        }

        if ($this->option('all-learners')) {
            $learners = StudentHealthRecord::query()
                ->whereNotNull('institution_id')
                ->select('institution_id', 'student_id')
                ->distinct()
                ->get();

            foreach ($learners as $learner) {
                MedicineAllergies::sync((int) $learner->institution_id, (string) $learner->student_id, 'System');
            }

            $this->info('Read the recorded allergies of '.$learners->count().' learners.');
        }

        // A supply is not a drug and is never looked up (Medicine::isSupply).
        $medicines = Medicine::query()
            ->where('rx_status', RxNormService::UNVERIFIED)
            ->whereNotNull('generic_name')
            ->get()
            ->reject(fn (Medicine $medicine): bool => $medicine->isSupply())
            ->values();

        foreach ($medicines as $medicine) {
            $medicine->applyRxNorm((string) $medicine->generic_name, $rx->lookup((string) $medicine->generic_name));
        }

        $allergies = LearnerMedicineAllergy::query()
            ->where('match_status', RxNormService::UNVERIFIED)
            ->get();

        foreach ($allergies as $allergy) {
            MedicineAllergies::retry($allergy);
        }

        $stale = RxConcept::query()
            ->where(fn ($query) => $query->whereNull('fetched_at')->orWhere('fetched_at', '<', now()->subDays(RxNormService::CACHE_DAYS)))
            ->get();

        $refreshed = 0;
        foreach ($stale as $concept) {
            try {
                $rx->concept($concept->rxcui, $concept->name, refresh: true);
                $refreshed++;
            } catch (RxNormUnavailable) {
                // Kept as it is; the next run asks again.
            }
        }

        $this->info(sprintf(
            'Medicines: %d of %d verified. Allergies: %d of %d verified. Concepts refreshed: %d of %d.',
            $medicines->filter(fn (Medicine $m) => $m->rx_status !== RxNormService::UNVERIFIED)->count(),
            $medicines->count(),
            $allergies->filter(fn (LearnerMedicineAllergy $a) => $a->match_status !== RxNormService::UNVERIFIED)->count(),
            $allergies->count(),
            $refreshed,
            $stale->count(),
        ));

        return self::SUCCESS;
    }
}
