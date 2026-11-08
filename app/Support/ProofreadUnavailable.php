<?php

namespace App\Support;

use RuntimeException;

/**
 * The grammar and terminology check could not answer. The message is written
 * for the nurse, never a status code or the provider's endpoint, and nothing
 * on the form has been changed when it is thrown.
 */
class ProofreadUnavailable extends RuntimeException {}
