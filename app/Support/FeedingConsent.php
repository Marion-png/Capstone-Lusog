<?php

namespace App\Support;

use App\Models\ParentalConsentForm;
use App\Models\StudentHealthRecord;
use Illuminate\Support\Collection;

/**
 * Whether a learner's parent has consented to the feeding programme.
 *
 * A measurement decides whether a learner *qualifies*; it never puts them on
 * the feeding line. The Feeding Coordinator enrols, and only a learner whose
 * parent or guardian has signed the School-Based Feeding Program consent may
 * be enrolled. The class adviser files that signed form through the same
 * upload dialog as the Sulat-Pahibalo, as its own kind of form
 * (`ParentalConsentForm::PROGRAM_FEEDING`), so neither is ever read as the
 * other.
 *
 * The rule ConsentStanding keeps holds here too: an answer is only taken from
 * a form the school actually holds. A consent keyed in with no signed scan
 * behind it authorises nothing, and neither does a scan nobody has recorded
 * the answer of.
 */
final class FeedingConsent
{
    public const CONSENTED = 'consented';

    public const DECLINED = 'declined';

    public const NONE = 'none';

    /** @var array<string, string> */
    public const LABELS = [
        self::CONSENTED => 'Parent\'s consent on file',
        self::DECLINED => 'Parent declined',
        self::NONE => 'Waiting for parent\'s consent',
    ];

    /**
     * Each learner's standing for their own school year, keyed by record id,
     * in one query for the whole list.
     *
     * @param  Collection<int, StudentHealthRecord>  $records
     * @return array<int, string>
     */
    public static function forRecords(Collection $records): array
    {
        $standings = $records->mapWithKeys(fn (StudentHealthRecord $record): array => [$record->id => self::NONE])->all();

        if ($records->isEmpty() || ! SchemaCache::hasTable('parental_consent_forms')) {
            return $standings;
        }

        $years = $records->pluck('school_year', 'id');

        // Oldest first, so keyBy leaves the latest form filed for each learner:
        // a parent who changed their answer is read by the form they signed last.
        $forms = ParentalConsentForm::feeding()
            ->whereIn('student_health_record_id', $records->pluck('id'))
            ->get()
            ->filter(fn (ParentalConsentForm $form): bool => (string) $form->school_year === (string) ($years[$form->student_health_record_id] ?? ''))
            ->sortBy('created_at')
            ->keyBy('student_health_record_id');

        foreach ($forms as $recordId => $form) {
            $standings[$recordId] = self::standingOf($form);
        }

        return $standings;
    }

    public static function forRecord(StudentHealthRecord $record): string
    {
        return self::forRecords(collect([$record]))[$record->id] ?? self::NONE;
    }

    /** The one test the enrolment endpoint applies. */
    public static function allowsEnrolment(string $standing): bool
    {
        return $standing === self::CONSENTED;
    }

    public static function label(string $standing): string
    {
        return self::LABELS[$standing] ?? self::LABELS[self::NONE];
    }

    private static function standingOf(ParentalConsentForm $form): string
    {
        if ($form->file_path === null) {
            return self::NONE;
        }

        return match ((string) $form->consent_type) {
            'full' => self::CONSENTED,
            'refused' => self::DECLINED,
            default => self::NONE,
        };
    }
}
