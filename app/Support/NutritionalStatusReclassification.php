<?php

namespace App\Support;

use App\Models\StudentHealthRecord;

/**
 * What one learner's stored nutritional statuses become when they are read
 * against the WHO 2007 reference instead of the adult cut-offs they were saved
 * under.
 *
 * Every status written before the fix was BMI < 16 / < 18.5 / ≥ 25 whatever
 * the learner's age or sex, and every height-for-age was a straight line of
 * 70 cm + 5 cm a year. The weights, heights, BMIs, birth dates and weigh-in
 * dates are all still on the record, so each status can be read again from
 * exactly what the adviser measured — nothing is estimated that was not
 * already there.
 *
 * Four rules:
 *
 * - **A status is re-read only from the measurement it was read from.** The
 *   baseline status from the baseline BMI and the baseline weigh-in date, the
 *   endline from the endline's; the summary `nutritional_status` follows
 *   whichever of the two its `bmi_value` is. A status with no BMI behind it is
 *   left as it is.
 * - **A learner the reference cannot read is left alone and reported.** With
 *   no sex or no birth date / age on file the WHO tables cannot be chosen, so
 *   the old status stays and the learner is listed (`unreadable`) for the
 *   adviser to complete — overwriting it with "Not enough data" would take a
 *   child off the feeding roll on the strength of a missing field.
 * - **Nothing else on the record moves**: not the measurements, not the
 *   enrolment. A learner enrolled on a status that now reads Normal stays
 *   enrolled; the coordinator decides, and the command reports how many.
 * - **The write goes through the model**, so every change is on the audit
 *   trail with its old and new value.
 */
final class NutritionalStatusReclassification
{
    /** Two BMIs this close are the same reading, stored at different precisions. */
    private const SAME_BMI = 0.051;

    /**
     * @return array{
     *   changes: array<string, array{0: ?string, 1: string}>,
     *   unreadable: list<string>,
     *   details: array<string, mixed>|null
     * }  `changes` is column (or "student_details.<key>") => [old, new];
     *    `details` is the student_details array to write, or null when it is unchanged.
     */
    public static function for(StudentHealthRecord $record): array
    {
        $details = is_array($record->student_details) ? $record->student_details : [];
        $sex = isset($details['gender']) ? (string) $details['gender'] : null;

        $changes = [];
        $unreadable = [];

        $baselineBmi = self::number($record->baseline_bmi_value);
        $endlineBmi = self::number($record->endline_bmi_value);
        $currentBmi = self::number($record->bmi_value);

        $baselineAge = BmiClassifier::ageAtMeasurement($details, $record->baseline_recorded_at, $record->baseline_age ?? ($details['age'] ?? null));
        $endlineAge = BmiClassifier::ageAtMeasurement($details, $record->endline_recorded_at, $record->endline_age);

        $baselineStatus = self::read($baselineBmi, fn (float $bmi) => BmiClassifier::bmiForAge($bmi, $baselineAge, $sex), 'baseline', $unreadable);
        $endlineStatus = self::read($endlineBmi, fn (float $bmi) => BmiClassifier::bmiForAge($bmi, $endlineAge, $sex), 'endline', $unreadable);

        self::compare($changes, 'baseline_nutritional_status', $record->baseline_nutritional_status, $baselineStatus);
        self::compare($changes, 'endline_nutritional_status', $record->endline_nutritional_status, $endlineStatus);

        // The summary column is whichever weigh-in its BMI came from.
        $currentStatus = match (true) {
            $currentBmi === null => null,
            $endlineBmi !== null && abs($currentBmi - $endlineBmi) < self::SAME_BMI => $endlineStatus,
            $baselineBmi !== null && abs($currentBmi - $baselineBmi) < self::SAME_BMI => $baselineStatus,
            // A record with no baseline columns (the oldest rows) carries its
            // one reading here alone, measured at the baseline.
            $baselineBmi === null && $endlineBmi === null => self::read($currentBmi, fn (float $bmi) => BmiClassifier::bmiForAge($bmi, $baselineAge, $sex), 'current', $unreadable),
            default => null,
        };
        self::compare($changes, 'nutritional_status', $record->nutritional_status, $currentStatus);

        // The adviser's own copies on Sheet 1, which the roster reads.
        $newDetails = $details;
        $sheetBmiStatus = $baselineStatus ?? ($baselineBmi === null && $endlineBmi === null ? $currentStatus : null);
        if ($sheetBmiStatus !== null && array_key_exists('nutritional_status_bmi_for_age', $details)) {
            if (self::compare($changes, 'student_details.nutritional_status_bmi_for_age', $details['nutritional_status_bmi_for_age'], $sheetBmiStatus)) {
                $newDetails['nutritional_status_bmi_for_age'] = $sheetBmiStatus;
            }
        }

        $baselineHeight = self::number($record->baseline_height_cm) ?? self::number($details['height_cm'] ?? null);
        if ($baselineHeight !== null) {
            $hfa = BmiClassifier::heightForAge($baselineHeight, $baselineAge, $sex);

            if ($hfa === BmiClassifier::NOT_ENOUGH_DATA) {
                $unreadable[] = 'height-for-age';
            } elseif (self::compare($changes, 'student_details.nutritional_status_height_for_age', $details['nutritional_status_height_for_age'] ?? null, $hfa)) {
                $newDetails['nutritional_status_height_for_age'] = $hfa;
            }
        }

        return [
            'changes' => $changes,
            'unreadable' => array_values(array_unique($unreadable)),
            'details' => $newDetails !== $details ? $newDetails : null,
        ];
    }

    /**
     * Write a reading's changes to the record, through the model so the audit
     * trail carries every old and new value. Returns whether anything changed.
     *
     * @param  array{changes: array<string, array{0: ?string, 1: string}>, details: array<string, mixed>|null}  $result
     */
    public static function apply(StudentHealthRecord $record, array $result): bool
    {
        if ($result['changes'] === []) {
            return false;
        }

        foreach ($result['changes'] as $field => [, $new]) {
            if (! str_starts_with($field, 'student_details.')) {
                $record->{$field} = $new;
            }
        }

        if ($result['details'] !== null) {
            $record->student_details = $result['details'];
        }

        $record->save();

        return true;
    }

    /**
     * @param  list<string>  $unreadable
     */
    private static function read(?float $bmi, callable $classify, string $phase, array &$unreadable): ?string
    {
        if ($bmi === null) {
            return null;
        }

        $status = $classify($bmi);

        if ($status === BmiClassifier::NOT_ENOUGH_DATA) {
            $unreadable[] = $phase;

            return null;
        }

        return $status;
    }

    /**
     * Record a change when the new reading differs from the stored one.
     *
     * @param  array<string, array{0: ?string, 1: string}>  $changes
     */
    private static function compare(array &$changes, string $field, mixed $old, ?string $new): bool
    {
        if ($new === null) {
            return false;
        }

        $old = $old === null ? null : trim((string) $old);

        if ($old === $new) {
            return false;
        }

        $changes[$field] = [$old === '' ? null : $old, $new];

        return true;
    }

    private static function number(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value > 0 ? (float) $value : null;
    }
}
