<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * The retention purge (docs/open-decisions.md entry 2, now settled at
 * config('retention.years')).
 *
 * Monthly rather than daily, on purpose. A record becomes due on a date five
 * years out, so checking tonight rather than in three weeks changes nothing
 * about the outcome — and this is the only destructive job in the application,
 * so it should run as seldom as the policy allows and leave a wide window in
 * which somebody noticing the "will be deleted on …" warning can re-enrol the
 * learner first.
 *
 * `withoutOverlapping()` because a long purge must not have a second copy
 * start behind it, and `onOneServer()` so a multi-instance deployment runs it
 * once.
 *
 * NOTE: nothing invokes `schedule:run` on the deployment yet — Railway runs no
 * cron and `RAILPACK_SKIP_MIGRATIONS=true` means even migrations are
 * deliberate. Until a Railway cron service or a worker calls
 * `php artisan schedule:run` every minute, this entry is the declaration of
 * when the purge *should* happen and the command stays something a human runs
 * (`--dry-run` first).
 */
Schedule::command('students:purge-expired')
    ->monthlyOn(1, '02:30')
    ->withoutOverlapping()
    ->onOneServer();
