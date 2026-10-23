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
