<?php

namespace App\Support;

use App\Rules\BloodPressure;
use Illuminate\Validation\Rule;

/**
 * The physical examination charted on an incident report.
 *
 * F-DAR's Data is what was observed as well as what the learner said, and the
 * observed half is a physical examination: the vital signs taken at the time,
 * how responsive the learner was, how much pain they reported, which part of
 * the body was hurt and what the nurse found there. It used to be one free-text
 * box, so every nurse wrote the readings differently and the printed form had
 * nowhere to put a temperature. This is the one declaration of that section,
 * read by the form, the endpoint, the list on the profile and the printed form,
 * so the four cannot ask for or print different things.
 *
 * Every field is optional: a nurse charting a behavioural incident takes no
 * blood pressure, and a reading nobody took is left blank rather than refused
 * or invented. The whole section is one encrypted JSON column on the report
 * (`physical_exam`), because a vital sign is health information about a child.
 */
final class IncidentPhysicalExam
{
    /**
     * The vital signs, in the order the form and the printed sheet set them:
     * label, unit, and the short tag the readback uses.
     *
     * The ranges are the ones the clinic's own Vital Signs panel accepts
     * (StudentVitalSignsController), widened only where that panel takes no
     * reading of its own.
     *
     * @var array<string, array{label: string, unit: string, short: string}>
     */
    public const VITALS = [
        'temperature_c' => ['label' => 'Temperature', 'unit' => '°C', 'short' => 'Temp'],
        'pulse_bpm' => ['label' => 'Pulse rate', 'unit' => 'bpm', 'short' => 'PR'],
        'respiratory_rate' => ['label' => 'Respiratory rate', 'unit' => 'breaths/min', 'short' => 'RR'],
        'blood_pressure' => ['label' => 'Blood pressure', 'unit' => 'mmHg', 'short' => 'BP'],
        'oxygen_saturation' => ['label' => 'Oxygen saturation', 'unit' => '%', 'short' => 'SpO₂'],
    ];

    /**
     * Level of consciousness on the AVPU scale — the four answers a first-aid
     * assessment gives, worst last. A fixed list, so it prints as tick boxes.
     *
     * @var array<string, string>
     */
    public const CONSCIOUSNESS = [
        'alert' => 'Alert',
        'voice' => 'Responds to voice',
        'pain' => 'Responds to pain',
        'unresponsive' => 'Unresponsive',
    ];

    /** The pain scale's top: 0 (no pain) to 10 (worst pain). */
    public const PAIN_MAX = 10;

    /**
     * Where on the body, offered as suggestions and never enforced — an
     * injury does not always land on a named region.
     *
     * @var list<string>
     */
    public const BODY_SITES = [
        'Head', 'Forehead', 'Face', 'Left eye', 'Right eye', 'Nose', 'Mouth / teeth', 'Left ear', 'Right ear',
        'Neck', 'Chest', 'Abdomen', 'Back', 'Left shoulder', 'Right shoulder', 'Left arm', 'Right arm',
        'Left elbow', 'Right elbow', 'Left hand', 'Right hand', 'Left hip', 'Right hip', 'Left thigh', 'Right thigh',
        'Left knee', 'Right knee', 'Left leg', 'Right leg', 'Left ankle', 'Right ankle', 'Left foot', 'Right foot',
    ];

    /** Every key the section stores, in the order it is read. */
    public const KEYS = [
        'temperature_c', 'pulse_bpm', 'respiratory_rate', 'blood_pressure', 'oxygen_saturation',
        'consciousness', 'pain_score', 'body_site', 'findings',
    ];

    /** The free-text fields, with their limits — the ones a proofread may touch. */
    public const BODY_SITE_MAX = 255;

    public const FINDINGS_MAX = 2000;

    /**
     * Validation for the section as the form posts it, under `exam[...]`.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'exam' => ['nullable', 'array'],
            'exam.temperature_c' => ['nullable', 'numeric', 'between:25,45'],
            'exam.pulse_bpm' => ['nullable', 'integer', 'between:20,250'],
            'exam.respiratory_rate' => ['nullable', 'integer', 'between:4,80'],
            'exam.blood_pressure' => ['nullable', 'string', 'max:20', new BloodPressure],
            'exam.oxygen_saturation' => ['nullable', 'integer', 'between:50,100'],
            'exam.consciousness' => ['nullable', Rule::in(array_keys(self::CONSCIOUSNESS))],
            'exam.pain_score' => ['nullable', 'integer', 'between:0,'.self::PAIN_MAX],
            'exam.body_site' => ['nullable', 'string', 'max:'.self::BODY_SITE_MAX],
            'exam.findings' => ['nullable', 'string', 'max:'.self::FINDINGS_MAX],
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        $names = [];
        foreach (self::VITALS as $key => $vital) {
            $names['exam.'.$key] = strtolower($vital['label']);
        }

        return $names + [
            'exam.consciousness' => 'level of consciousness',
            'exam.pain_score' => 'pain score',
            'exam.body_site' => 'body part affected',
            'exam.findings' => 'examination findings',
        ];
    }

    /**
     * The section as it is stored: every key, blanks as null, figures in the
     * form they print in. Null when nothing at all was recorded, so a report
     * charted without an examination stores no empty object.
     *
     * @param  array<string, mixed>|null  $input  validated `exam`
     * @return array<string, string|null>|null
     */
    public static function fromInput(?array $input): ?array
    {
        $input ??= [];
        $exam = [];

        foreach (self::KEYS as $key) {
            $value = $input[$key] ?? null;
            $value = is_scalar($value) ? trim((string) $value) : '';

            $exam[$key] = match (true) {
                $value === '' => null,
                $key === 'blood_pressure' => StudentVitalSigns::normalizeBloodPressure($value),
                // "37" and "37.0" are one reading; one decimal is what a
                // clinical thermometer shows.
                $key === 'temperature_c' => rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.'),
                in_array($key, ['pulse_bpm', 'respiratory_rate', 'oxygen_saturation', 'pain_score'], true) => (string) (int) $value,
                default => $value,
            };
        }

        return self::isEmpty($exam) ? null : $exam;
    }

    /**
     * What is on file, with every key present — a stored section from before
     * a key existed reads as blank there rather than failing.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array<string, string>
     */
    public static function read(?array $stored): array
    {
        $exam = [];
        foreach (self::KEYS as $key) {
            $value = $stored[$key] ?? null;
            $exam[$key] = is_scalar($value) ? trim((string) $value) : '';
        }

        return $exam;
    }

    /** @param  array<string, mixed>|null  $exam */
    public static function isEmpty(?array $exam): bool
    {
        foreach (self::KEYS as $key) {
            $value = $exam[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /** "37.8 °C", or '' for a reading nobody took. */
    public static function vitalText(string $key, string $value): string
    {
        if ($value === '' || ! isset(self::VITALS[$key])) {
            return '';
        }

        $unit = self::VITALS[$key]['unit'];

        return $unit === '%' ? $value.'%' : $value.' '.$unit;
    }

    public static function consciousnessLabel(string $value): string
    {
        return self::CONSCIOUSNESS[$value] ?? '';
    }

    /** "3 / 10", or '' where no score was asked. */
    public static function painText(string $value): string
    {
        return $value === '' ? '' : $value.' / '.self::PAIN_MAX;
    }

    /**
     * The section as the profile reads it back: the vital signs on one line,
     * then each other answer under its own label. Only what was recorded —
     * a blank is left out rather than printed as an empty heading.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array{vitals: list<array{key: string, short: string, label: string, value: string}>, lines: list<array{label: string, value: string}>}
     */
    public static function summary(?array $stored): array
    {
        $exam = self::read($stored);

        $vitals = [];
        foreach (self::VITALS as $key => $vital) {
            $text = self::vitalText($key, $exam[$key]);
            if ($text !== '') {
                $vitals[] = ['key' => $key, 'short' => $vital['short'], 'label' => $vital['label'], 'value' => $text];
            }
        }

        $lines = [];
        foreach ([
            'Consciousness' => self::consciousnessLabel($exam['consciousness']),
            'Pain' => self::painText($exam['pain_score']),
            'Body part affected' => $exam['body_site'],
            'Findings' => $exam['findings'],
        ] as $label => $value) {
            if ($value !== '') {
                $lines[] = ['label' => $label, 'value' => $value];
            }
        }

        return ['vitals' => $vitals, 'lines' => $lines];
    }
}
