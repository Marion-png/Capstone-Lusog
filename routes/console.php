<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * The retention purge (SIGLA School Year Transition spec, "Archive and
 * scheduled deletion"; the window is config('retention.years')).
 *
 * Daily: every archived learner whose `delete_after` date has passed is
 * permanently deleted with every file they uploaded. Only archived learners
 * are candidates, and restoring a learner clears the date, so nothing in a
 * section — or waiting for one — is ever touched. The Archived tabs show each
 * learner's deletion date so staff can act before it.
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
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * The public holidays (App\Support\PublicHolidaySync). Monthly, because a
 * proclamation can add or move a special non-working day partway through the
 * year; two requests a month against the free plan's 500. The same caveat as
 * the purge applies: until something runs `schedule:run`, the System Admin's
 * Sync Holidays button is how the calendar gets read.
 */
Schedule::command('holidays:sync')
    ->monthlyOn(1, '03:00')
    ->withoutOverlapping()
    ->onOneServer();

/*
 * The RxNorm allergy check (App\Support\RxNormService). Daily: everything
 * RxNav could not answer when it was saved is asked again, and concepts older
 * than 90 days are refreshed. Same caveat: until something runs
 * `schedule:run`, it is a command a human runs.
 */
Schedule::command('rxnorm:retry-unverified')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer();
