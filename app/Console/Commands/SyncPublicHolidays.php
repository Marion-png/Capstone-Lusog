<?php

namespace App\Console\Commands;

use App\Models\ParentalConsentForm;
use App\Support\HolidayApiFailure;
use App\Support\PublicHolidaySync;
use App\Support\SchoolCalendar;
use Illuminate\Console\Command;

/**
 * Reads the Philippine public holidays from Calendarific into the school
 * calendar.
 *
 *     php artisan holidays:sync              both years of the current school year
 *     php artisan holidays:sync 2027         one calendar year
 *
 * Each year is its own sync: one that fails leaves its holidays as they were
 * and does not stop the next.
 */
class SyncPublicHolidays extends Command
{
    protected $signature = 'holidays:sync {year?* : Calendar years to sync (default: both years of the current school year)}';

    protected $description = 'Read the Philippine public holidays from Calendarific into the school calendar';

    public function handle(PublicHolidaySync $sync): int
    {
        $years = $this->years();

        if ($years === null) {
            $this->error('Each year must be a four-digit year, e.g. 2027.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($years as $year) {
            try {
                $result = $sync->run($year);
            } catch (HolidayApiFailure $e) {
                $failed = true;
                $this->error($year.': '.$e->getMessage());

                continue;
            }

            $this->info(sprintf(
                '%d: %d public holidays saved (replaced %d, ignored %d that do not close a school)',
                $year,
                $result['saved'],
                $result['replaced'],
                $result['skipped'],
            ));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<int>|null */
    private function years(): ?array
    {
        $given = (array) $this->argument('year');

        if ($given === []) {
            return SchoolCalendar::yearsOf(ParentalConsentForm::currentSchoolYear());
        }

        $years = [];
        foreach ($given as $year) {
            if (! preg_match('/^\d{4}$/', (string) $year)) {
                return null;
            }
            $years[] = (int) $year;
        }

        return array_values(array_unique($years));
    }
}
