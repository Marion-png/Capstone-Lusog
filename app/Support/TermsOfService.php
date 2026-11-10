<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * SIGLA's Terms of Service: which version is in force, and since when.
 *
 * The text lives in `auth/terms.blade.php` (route `terms`, public). A person
 * agrees to it when they request an account — the form's required checkbox —
 * and the agreement, with this version, is written to the append-only audit
 * trail beside the request, so it can be shown later who agreed to what.
 *
 * Change VERSION and EFFECTIVE whenever the text changes in substance.
 */
final class TermsOfService
{
    public const VERSION = '1.0';

    /** The date the current version took effect (Y-m-d). */
    public const EFFECTIVE = '2026-11-10';

    /** "November 10, 2026" — the date as the page and the form print it. */
    public static function effectiveLabel(): string
    {
        return Carbon::parse(self::EFFECTIVE)->format('F j, Y');
    }
}
