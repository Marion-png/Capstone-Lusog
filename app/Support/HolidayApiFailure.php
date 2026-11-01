<?php

namespace App\Support;

use RuntimeException;

/**
 * Why the public holidays could not be read from Calendarific, worded for the
 * System Admin who asked for them.
 *
 * Only this message reaches a screen. A transport error's own message carries
 * the request URL, and Calendarific takes its API key in the query string, so
 * the raw exception is logged with the key redacted and never shown.
 */
class HolidayApiFailure extends RuntimeException {}
