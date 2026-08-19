<?php

namespace App\Support;

/**
 * Which School Health Card fields a learner's record is still missing.
 *
 * The adviser's job on this screen is data entry, so the figure beside
 * "Total students" should be the one that tells them there is entry left
 * to do. It used to be "Needs Follow-up", which counted learners who were
 * at risk, wasted or had a denied consent — real conditions, but ones the
 * adviser cannot fix by typing, and ones the nurse and the coordinator
 * already own their own screens for. A learner whose guardian and contact
 * number were never entered is the adviser's problem, and nothing on the
 * dashboard reported it.
 *
 * Both adviser tabs read this one class, so the Dashboard's count and the
 * My Students count cannot mean different things.
 *
 * The fields are the ones AdviserController::store requires, minus the two
 * the form treats as genuinely optional (middle name, which many learners
 * do not have on their record, and the region/division pair, which the
 * School Health Card form does not collect). Sex is checked even though
 * the rule marks it nullable: it is on the printed card and every DepEd
 * BMI grid counts by it, so a blank one is missing data, not a choice.
 *
 * The nurse's vitals (temperature, pulse, blood pressure), the health
 * history checklist and the examiner signature are deliberately NOT here.
 * They belong to other desks, or a blank is a valid answer, and counting
 * them would report an adviser incomplete for somebody else's work.
 */
class StudentDataCompleteness
{
    /**
     * Field => label, in the order the enrolment form asks for them, so the
     * missing list reads down the form rather than in an arbitrary order.
     *
     * @var array<string, string>
     */
    private const REQUIRED = [
        'last_name' => 'Last name',
        'first_name' => 'First name',
        'lrn' => 'LRN',
        'birth_date' => 'Birth date',
        'birthplace' => 'Birthplace',
        'gender' => 'Sex',
        'parent_guardian' => 'Parent / guardian',
        'address' => 'Address',
        'telephone_no' => 'Contact number',
        'height_cm' => 'Height',
        'weight_kg' => 'Weight',
    ];

    /**
     * The labels of everything this learner's record is still missing.
     *
     * @param  array<string, mixed>  $row  a roster row, or a student_details array
     * @return list<string>
     */
    public static function missingFor(array $row): array
    {
        $missing = [];

        foreach (self::REQUIRED as $field => $label) {
            $present = $field === 'birth_date'
                ? self::hasBirthDate($row)
                : self::isFilled($row[$field] ?? null);

            if (! $present) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    /** @param  array<string, mixed>  $row */
    public static function isIncomplete(array $row): bool
    {
        return self::missingFor($row) !== [];
    }

    /**
     * How many of the roster still need entry.
     *
     * @param  iterable<array<string, mixed>>  $roster
     */
    public static function countIncomplete(iterable $roster): int
    {
        $count = 0;

        foreach ($roster as $row) {
            if (is_array($row) && self::isIncomplete($row)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The birth date is three separate inputs, and a learner with only a
     * year on file has no birth date — the age every BMI-for-age reading
     * is taken against cannot be worked out from it.
     *
     * @param  array<string, mixed>  $row
     */
    private static function hasBirthDate(array $row): bool
    {
        foreach (['birth_year', 'birth_month', 'birth_day'] as $part) {
            if (! self::isFilled($row[$part] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A measurement of zero is not a measurement — height and weight are
     * stored as numbers and an unentered one arrives as 0 or "0" as often
     * as it arrives as null.
     */
    private static function isFilled(mixed $value): bool
    {
        if ($value === null || $value === []) {
            return false;
        }

        if (is_numeric($value)) {
            return (float) $value > 0;
        }

        return trim((string) $value) !== '';
    }
}
