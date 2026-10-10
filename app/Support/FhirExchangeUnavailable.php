<?php

namespace App\Support;

use RuntimeException;

/** The FHIR exchange cannot send at all — there is no endpoint to send to. */
class FhirExchangeUnavailable extends RuntimeException {}
