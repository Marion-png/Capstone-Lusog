<?php

namespace App\Support;

use App\Models\StudentHealthRecord;

/**
 * One learner's nutritional health status, baseline against endline.
 *
 * Height, weight, BMI with its BMI-for-age classification, and the
 * height-for-age classification — for the opening weigh-in and the closing
 * one. It is the one reading behind the profile's Nutritional Health Status
 * tab, so the two phases are built the same way and cannot disagree about
 * where a figure came from.
 *
 * **A reading nobody took is blank, never a plausible value.** An unmeasured
 * phase reports `measured => false` and empty figures; nothing is carried
 * across from the other phase and nothing is estimated. That is the same rule
 * SchoolHeadOverview keeps ("not measured", never Normal) and the reason the
 * feeding roster prints an em dash rather than `current − 0.7`.
 *
 * Classification is never re-implemented here: BMI-for-age is
 * `BmiClassifier::bmiForAge()` and height-for-age is
 * `BmiAssessmentReport::classifyHeightForAge()`, the same functions the DepEd
 * grids count with. A stored classification wins over a recomputed one — it is
 * what the adviser recorded at the time.
 */
class NutritionalHealthStatus
{
    /**
     * @return array{
     *   baseline: array<string, mixed>,
     *   endline: array<string, mixed>,
     *   has_any: bool
     * }
     */
    public static function forRecord(StudentHealthRecord $record): array
    {
        $details = is_array($record->student_details) ? $record->student_details : [];

        $baseline = self::phase(
            height: $record->baseline_height_cm ?? ($details['height_cm'] ?? null),
            weight: $record->baseline_weight_kg ?? ($details['weight_kg'] ?? null),
            age: $record->baseline_age ?? ($details['age'] ?? null),
            bmi: $record->baseline_bmi_value ?? $record->bmi_value,
            // The adviser's own reading at entry time, then the summary column.
            storedBmiStatus: $record->baseline_nutritional_status ?: $record->nutritional_status,
            storedHfaStatus: $details['nutritional_status_height_for_age'] ?? null,
            recordedAt: $record->baseline_recorded_at?->toDateString(),
        );

        $endline = self::phase(
            height: $record->endline_height_cm,
            weight: $record->endline_weight_kg,
            age: $record->endline_age,
            bmi: $record->endline_bmi_value,
            storedBmiStatus: $record->endline_nutritional_status,
            // Endline height-for-age is not stored anywhere, so it is computed
            // from the endline height and age — exactly as the Final BMI grid
            // does it, and left blank when either is missing.
            storedHfaStatus: null,
            recordedAt: $record->endline_recorded_at?->toDateString(),
        );

        return [
            'baseline' => $baseline,
            'endline' => $endline,
            'has_any' => $baseline['measured'] || $endline['measured'],
        ];
    }

    /**
     * @return array{
     *   measured: bool, height_cm: string, weight_kg: string, age: string,
     *   bmi: string, bmi_status: string, hfa_status: string, recorded_at: string
     * }
     */
    private static function phase(
        mixed $height,
        mixed $weight,
        mixed $age,
        mixed $bmi,
        mixed $storedBmiStatus,
        mixed $storedHfaStatus,
        ?string $recordedAt,
    ): array {
        $heightCm = self::number($height);
        $weightKg = self::number($weight);
        $years = self::number($age);
        $bmiValue = self::number($bmi);

        // A BMI nobody stored can still be computed from the pair that decides
        // it; one that needs a height or a weight that was never taken cannot.
        if ($bmiValue === null && $heightCm !== null && $weightKg !== null && $heightCm > 0) {
            $metres = $heightCm / 100;
            $bmiValue = round($weightKg / ($metres * $metres), 1);
        }

        $bmiStatus = trim((string) $storedBmiStatus);
        if ($bmiStatus === '' && $bmiValue !== null) {
            $bmiStatus = BmiClassifier::bmiForAge($bmiValue, $years !== null ? (int) $years : null);
        }

        $hfaStatus = trim((string) $storedHfaStatus);
        if ($hfaStatus === '' && $heightCm !== null && $years !== null) {
            $hfaStatus = BmiAssessmentReport::classifyHeightForAge($heightCm, (int) $years);
        }

        // Measured means somebody took a height or a weight. A classification
        // with no measurement behind it is a leftover, not a weigh-in.
        $measured = $heightCm !== null || $weightKg !== null;

        return [
            'measured' => $measured,
            'height_cm' => $heightCm !== null ? self::trimZero($heightCm) : '',
            'weight_kg' => $weightKg !== null ? self::trimZero($weightKg) : '',
            'age' => $years !== null ? self::trimZero($years) : '',
            'bmi' => $bmiValue !== null ? number_format($bmiValue, 1) : '',
            'bmi_status' => $measured ? $bmiStatus : '',
            'hfa_status' => $measured ? $hfaStatus : '',
            'recorded_at' => (string) $recordedAt,
        ];
    }

    private static function number(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return $number > 0 ? $number : null;
    }

    private static function trimZero(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
