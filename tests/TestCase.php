<?php

namespace Tests;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every test runs on the same weekday.
     *
     * The feeding programme has no session on a Saturday or a Sunday, so a suite
     * that reads `now()` as "today" answers differently depending on the day it
     * is run: a test recording today's attendance passes Monday to Friday and
     * fails at the weekend, and one that marks `now()->subDay()` counts two
     * feeding days midweek and one on a Monday. Neither is a real defect, and a
     * suite that goes red on Saturdays teaches people to ignore it.
     *
     * The clock is pinned to the Friday of the current week, so "today" is
     * always a feeding day and the four days behind it are too — which is the
     * span the fixtures reach back over. It stays inside the current week on
     * purpose: the school year is derived from the clock, and a fixed absolute
     * date would quietly freeze the suite in one school year forever.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(
            Carbon::now()->startOfWeek(Carbon::MONDAY)->addDays(4)->setTime(9, 0)
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
