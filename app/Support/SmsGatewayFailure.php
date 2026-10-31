<?php

namespace App\Support;

use RuntimeException;

/**
 * The SMS gateway did not take a message, said in words staff can act on.
 *
 * `retryable` separates the failures that may clear on their own (a busy
 * gateway, a dropped connection) from the ones that will not (a refused key,
 * an empty account, a number the gateway rejects), so the log can offer
 * Retry only where trying again could help.
 */
class SmsGatewayFailure extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
