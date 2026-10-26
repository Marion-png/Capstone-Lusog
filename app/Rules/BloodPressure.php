<?php

namespace App\Rules;

use App\Support\StudentVitalSigns;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A blood pressure reading as the MLAT records it: systolic over diastolic,
 * in mmHg — "110/70".
 *
 * The field used to take any text up to twenty characters, so "normal",
 * "110" or "70/110" were all saved as a learner's blood pressure and printed
 * on the clinic's form. App\Support\StudentVitalSigns::parseBloodPressure()
 * is the one reading of what a valid one is; this only turns its answer into
 * the message the nurse sees. An empty field is left to `nullable`.
 */
class BloodPressure implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || trim((string) $value) === '') {
            return;
        }

        $problem = StudentVitalSigns::bloodPressureProblem($value);

        if ($problem !== null) {
            $fail($problem);
        }
    }
}
