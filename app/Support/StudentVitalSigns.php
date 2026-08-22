<?php

namespace App\Support;

use App\Models\StudentHealthRecord;

/**
 * Temperature, pulse rate and blood pressure — the school nurse's fields.
 *
 * The class adviser measures height and weight; those are the two readings a
 * teacher takes with a tape and a scale, and they are what the feeding
 * programme's BMI is built from. Temperature, pulse and blood pressure are a
 * clinical observation, and the nurse is the person qualified to take one —
 * so the adviser's form shows them and never asks for them.
 *
 * They live in the encrypted `student_details` blob alongside the rest of the
 * learner's card rather than in columns of their own, because that is where
 * they already were; moving them would orphan every reading on file. This
 * class is the only thing that reads or writes that corner of the blob, so a
 * partial update from the nurse can never clobber the adviser's half of the
 * card and the two roles cannot disagree about what a vital sign is.
 */
class StudentVitalSigns
{
    /**
     * The three fields, and the label each is known by on screen.
     *
     * @var array<string, string>
     */
    public const FIELDS = [
        'temperature_c' => 'Temperature',
        'pulse_bpm' => 'Pulse rate',
        'blood_pressure' => 'Blood pressure',
    ];

    /** Attribution, kept beside the readings so a figure is never anonymous. */
    public const RECORDED_BY = 'vitals_recorded_by';

    public const RECORDED_AT = 'vitals_recorded_at';

    /**
     * What is on file for this learner.
     *
     * @return array{temperature_c: ?string, pulse_bpm: ?string, blood_pressure: ?string, recorded_by: ?string, recorded_at: ?string, has_any: bool}
     */
    public static function read(?StudentHealthRecord $record): array
    {
        $details = is_array($record?->student_details) ? $record->student_details : [];

        $values = [];
        foreach (array_keys(self::FIELDS) as $field) {
            $raw = $details[$field] ?? null;
            $values[$field] = self::isBlank($raw) ? null : (string) $raw;
        }

        return $values + [
            'recorded_by' => self::isBlank($details[self::RECORDED_BY] ?? null)
                ? null
                : (string) $details[self::RECORDED_BY],
            'recorded_at' => self::isBlank($details[self::RECORDED_AT] ?? null)
                ? null
                : (string) $details[self::RECORDED_AT],
            'has_any' => collect($values)->filter(fn ($v) => $v !== null)->isNotEmpty(),
        ];
    }

    /**
     * Merge a nurse's reading into the learner's card.
     *
     * Only the three fields and their attribution are touched — the whole
     * blob is written back because `student_details` is one encrypted column,
     * so read-modify-write is the only shape available. Everything else in it
     * is carried across untouched.
     *
     * A field left blank is cleared, not ignored: a nurse correcting a
     * mistyped temperature to "no reading taken" must be able to.
     *
     * @param  array<string, mixed>  $input
     */
    public static function write(StudentHealthRecord $record, array $input, string $staffName): void
    {
        $details = is_array($record->student_details) ? $record->student_details : [];

        foreach (array_keys(self::FIELDS) as $field) {
            $value = $input[$field] ?? null;
            $details[$field] = self::isBlank($value) ? null : trim((string) $value);
        }

        $details[self::RECORDED_BY] = $staffName;
        $details[self::RECORDED_AT] = now()->toDateTimeString();

        // Through the model, never a raw update: the cast is what keeps the
        // blob encrypted, and the Auditable trait is what records the change.
        $record->forceFill(['student_details' => $details])->save();
    }

    /**
     * Carry the stored reading across an adviser's save.
     *
     * The adviser's form no longer asks for these, but it still posts the
     * whole card, so their half of the blob is rebuilt from scratch on every
     * edit. Without this the nurse's reading would be dropped by a teacher
     * correcting a phone number.
     *
     * @param  array<string, mixed>  $details  the row the adviser's save is about to write
     * @return array<string, mixed>
     */
    public static function preserve(array $details, ?StudentHealthRecord $existing): array
    {
        $stored = is_array($existing?->student_details) ? $existing->student_details : [];

        foreach ([...array_keys(self::FIELDS), self::RECORDED_BY, self::RECORDED_AT] as $field) {
            $details[$field] = $stored[$field] ?? null;
        }

        return $details;
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }
}
