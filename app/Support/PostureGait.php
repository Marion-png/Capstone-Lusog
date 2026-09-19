<?php

namespace App\Support;

/**
 * The Sheet 1 Posture / Gait answer: Good or Poor.
 *
 * The field used to read Normal / Abnormal, and records saved under those
 * words are still in the database and in live sessions. One mapping, read on
 * the way in (AdviserController) and on the way out (StudentRosterSync), so a
 * legacy answer is shown and re-saved under the current words rather than a
 * roster carrying three spellings of the same two answers.
 */
class PostureGait
{
    public const GOOD = 'Good';

    public const POOR = 'Poor';

    /** @var list<string> */
    public const OPTIONS = [self::GOOD, self::POOR];

    /** @var array<string, string> */
    private const LEGACY = [
        'normal' => self::GOOD,
        'abnormal' => self::POOR,
        'good' => self::GOOD,
        'poor' => self::POOR,
    ];

    /** A stored answer under the current words; anything unrecognised is kept as typed. */
    public static function normalize(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return self::LEGACY[strtolower($value)] ?? $value;
    }

    /**
     * @param  array<string, mixed>  $history
     * @return array<string, mixed>
     */
    public static function normalizeHistory(array $history): array
    {
        if (array_key_exists('posture', $history)) {
            $history['posture'] = self::normalize($history['posture']);
        }

        return $history;
    }
}
