<?php

namespace App\Support;

use RuntimeException;

/**
 * Why an uploaded sheet or photograph could not be read, worded for the
 * person who uploaded it.
 *
 * Only this message is ever put on their screen. Anything else thrown while
 * reading an upload — a library's own exception, a PHP warning, a transport
 * error — can carry a server path, an endpoint or an account detail, so the
 * controllers log those and show a plain sentence instead.
 */
class UploadReadFailure extends RuntimeException {}
