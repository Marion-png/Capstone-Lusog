<?php

namespace App\Support;

use App\Models\HealthAssessment;
use App\Models\HealthConsentForm;
use App\Models\ParentalConsentForm;
use App\Models\StudentHealthRecord;

/**
 * Every allergy recorded for one learner, wherever it was recorded.
 *
 * An allergy reaches the system four ways: the class adviser ticks it on the
 * health card (Sheet 1, `student_details.health_history`), a parent writes it
 * on the Sulat-Pahibalo (`health_consent_forms`), a returned paper consent is
 * uploaded with its answers (`parental_consent_forms`), and the older health
 * assessment carried it (`health_assessments`). Whoever is about to hand a
 * child a medicine needs all of them at once, so this reads all four, for
 * every school year the learner has at this school — an allergy written last
 * year is still an allergy.
 *
 * A line that says nothing ("wala", "none", "N/A", a dash) is not an allergy
 * (ConsentFormScanner::saysSomething, the same reading the consent scan uses).
 * A box ticked with nothing written beside it is: the allergy is recorded and
 * the detail is not, and the reader is told exactly that rather than being
 * shown nothing.
 *
 * Scoped to one school by its id, and reads nothing without one.
 */
class LearnerAllergies
{
    public const KIND_MEDICINE = 'Medicine';

    public const KIND_FOOD = 'Food';

    public const KIND_GENERAL = 'Allergy';

    /** What a ticked box with nothing written beside it reads as. */
    public const NOT_SPECIFIED = 'Not specified';

    /**
     * @return list<array{kind: string, text: string, sources: list<string>}>
     */
    public static function for(?int $institutionId, string $lrn): array
    {
        $lrn = trim($lrn);

        if (! $institutionId || $lrn === '' || ! SchemaCache::hasTable('student_health_records')) {
            return [];
        }

        $records = StudentHealthRecord::query()
            ->where('institution_id', $institutionId)
            ->where('student_id', $lrn)
            ->orderByDesc('school_year')
            ->orderByDesc('id')
            ->get();

        if ($records->isEmpty()) {
            return [];
        }

        $found = [];

        // The health card is carried from year to year, so the latest row's
        // is the one on file.
        $details = is_array($records->first()->student_details) ? $records->first()->student_details : [];
        $history = is_array($details['health_history'] ?? null) ? $details['health_history'] : [];
        self::add(
            $found,
            self::KIND_GENERAL,
            (bool) ($history['med_allergies'] ?? false),
            (string) ($history['allergies_detail'] ?? ''),
            'Health card (Sheet 1)'
        );

        if (SchemaCache::hasTable('health_consent_forms')) {
            $forms = HealthConsentForm::query()
                ->where('institution_id', $institutionId)
                ->where('student_lrn', $lrn)
                ->orderByDesc('school_year')
                ->get();

            foreach ($forms as $form) {
                $source = "Parent's consent form, S.Y. ".$form->school_year;
                self::add($found, self::KIND_MEDICINE, false, (string) $form->allergy_medicine, $source);
                self::add($found, self::KIND_FOOD, false, (string) $form->allergy_food, $source);
            }
        }

        $recordIds = $records->pluck('id')->all();

        if (SchemaCache::hasTable('parental_consent_forms')) {
            $uploads = ParentalConsentForm::query()
                ->whereIn('student_health_record_id', $recordIds)
                ->orderByDesc('school_year')
                ->get();

            foreach ($uploads as $upload) {
                $source = 'Returned consent form, S.Y. '.$upload->school_year;
                self::add($found, self::KIND_MEDICINE, (bool) $upload->allergy_medicine, (string) $upload->allergy_medicine_detail, $source);
                self::add($found, self::KIND_FOOD, (bool) $upload->allergy_food, (string) $upload->allergy_food_detail, $source);
            }
        }

        if (SchemaCache::hasTable('health_assessments')) {
            $assessments = HealthAssessment::query()
                ->whereIn('student_health_record_id', $recordIds)
                ->orderByDesc('school_year')
                ->get();

            foreach ($assessments as $assessment) {
                self::add(
                    $found,
                    self::KIND_GENERAL,
                    (bool) $assessment->med_allergies,
                    (string) $assessment->med_allergies_detail,
                    'Health assessment, S.Y. '.$assessment->school_year
                );
            }
        }

        return array_values($found);
    }

    /**
     * One recorded allergy, merged with the same allergy recorded elsewhere.
     *
     * The same text from two places is one allergy with two sources; the
     * specific kind (medicine, food) wins over the general one.
     *
     * @param  array<string, array{kind: string, text: string, sources: list<string>}>  $found
     */
    private static function add(array &$found, string $kind, bool $ticked, string $text, string $source): void
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $says = $text !== '' && ConsentFormScanner::saysSomething($text);

        if (! $says && ! $ticked) {
            return;
        }

        $text = $says ? $text : self::NOT_SPECIFIED;
        $key = $says ? 'text:'.mb_strtolower($text) : 'unspecified:'.$kind;

        if (isset($found[$key])) {
            if (! in_array($source, $found[$key]['sources'], true)) {
                $found[$key]['sources'][] = $source;
            }
            if ($found[$key]['kind'] === self::KIND_GENERAL) {
                $found[$key]['kind'] = $kind;
            }

            return;
        }

        $found[$key] = ['kind' => $kind, 'text' => $text, 'sources' => [$source]];
    }
}
