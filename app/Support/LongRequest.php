<?php

namespace App\Support;

/**
 * Room for a request that waits on somebody else's server.
 *
 * PHP stops a request at max_execution_time, 30 seconds on the deployment, and
 * that stop is a fatal error: no catch runs, so a photo reader that is merely
 * slow reaches the person as a bare 500 instead of the message that says to try
 * again. It restarts the clock with the time the wait may really take.
 *
 * A process with no limit (the command line, the test suite) keeps having none.
 */
final class LongRequest
{
    public static function allow(int $seconds): void
    {
        if ((int) ini_get('max_execution_time') === 0) {
            return;
        }

        @set_time_limit($seconds);
    }
}
