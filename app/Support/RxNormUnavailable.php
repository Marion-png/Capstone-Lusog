<?php

namespace App\Support;

use RuntimeException;

/** RxNav could not be reached, refused the request, or answered in a shape it never uses. */
class RxNormUnavailable extends RuntimeException {}
