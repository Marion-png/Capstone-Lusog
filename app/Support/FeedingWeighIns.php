<?php

namespace App\Support;

use App\Models\FeedingWeighIn;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use Illuminate\Support\Collection;

/**
 * Optional progress weigh-ins between a beneficiary's baseline and endline
 * (testers' report #33).
 *
 * Sta. Ana's coordinator weighs beneficiaries every 30 days; other schools
 * weigh at baseline and endline only. So it is a school's own setting, OFF by
 * default, and with it off nothing on any page changes — baseline and endline
 * stay the only required weighings, and weigh-ins already taken are kept.
 *
 * With it on, the coordinator records a weigh-in around feeding days 30, 60
 * and 90. Each is classified with the very functions the baseline and endline
 * are (BmiClassifier: WHO 2007 BMI-for-age and height-for-age, for the
 * learner's sex and age in months on the day) after the same plausibility
 * checks (MeasurementPlausibility). The trend reads baseline → day 30 → 60 →
 * 90 → endline, and a beneficiary whose latest weighing is no better than
 * their baseline is flagged "not improving", so they can be acted on before
 * the programme ends.
 */
final class FeedingWeighIns
{
    /** The feeding days a progress weigh-in is taken around. */
    public const CHECKPOINTS = [30, 60, 90];

    public const IMPROVING = 'improving';

    public const NOT_IMPROVING = 'not_improving';

    /** Whether the tables are there to hold it. */
    public static function supported(): bool
    {
        return SchemaCache::hasTable('feeding_weigh_ins')
            && SchemaCache::hasColumn('institutions', 'feeding_progress_weigh_ins');
    }

    /** Whether this school records progress weigh-ins. OFF unless it said so. */
    public static function enabled(?int $institutionId): bool
    {
        if (! $institutionId || ! self::supported()) {
            return false;
        }

        return (bool) RequestMemo::remember(
            'weigh-ins:enabled:'.$institutionId,
            fn () => Institution::query()->whereKey($institutionId)->value('feeding_progress_weigh_ins'),
        );
    }

    /**
     * Every weigh-in these learners carry, keyed by learner row and ordered by
     * checkpoint. One query, however long the list.
     *
     * @param  Collection<int, StudentHealthRecord>|list<int>  $records
     * @return Collection<int, Collection<int, FeedingWeighIn>>
     */
    public static function forRecords(Collection|array $records): Collection
    {
        $ids = collect($records)->map(fn ($record): int => (int) ($record instanceof StudentHealthRecord ? $record->id : $record))->filter()->values();

        if ($ids->isEmpty() || ! self::supported()) {
            return collect();
        }

        return FeedingWeighIn::query()
            ->whereIn('student_health_record_id', $ids->all())
            ->orderBy('feeding_day')
            ->get()
            ->groupBy('student_health_record_id');
    }

    /**
     * Classify one weigh-in for this learner, as the baseline is classified.
     *
     * @return array{bmi: float, status: string, height_for_age: string}
     */
    public static function classify(StudentHealthRecord $record, float $heightCm, float $weightKg, string $weighedOn): array
    {
        $details = is_array($record->student_details) ? $record->student_details : [];
        $ageInMonths = BmiClassifier::ageAtMeasurement($details, $weighedOn, $record->baseline_age);
        $sex = isset($details['gender']) ? (string) $details['gender'] : null;
        $metres = $heightCm / 100;
        $bmi = round($weightKg / ($metres * $metres), 2);

        return [
            'bmi' => $bmi,
            'status' => BmiClassifier::bmiForAge($bmi, $ageInMonths, $sex),
            'height_for_age' => BmiAssessmentReport::classifyHeightForAge($heightCm, $ageInMonths, $sex),
        ];
    }

    /**
     * The learner's weighings in order: baseline, each progress weigh-in on
     * file, endline. A weighing nobody took is left out, never a row of
     * zeroes.
     *
     * @param  Collection<int, FeedingWeighIn>  $weighIns
     * @return list<array{label: string, date: string, height_cm: string, weight_kg: string, bmi: string, status: string}>
     */
    public static function trend(StudentHealthRecord $record, Collection $weighIns): array
    {
        $growth = NutritionalHealthStatus::forRecord($record);
        $points = [];

        if ($growth['baseline']['measured']) {
            $points[] = self::point('Baseline', $record->baseline_recorded_at?->format('M j, Y') ?? '', $growth['baseline']);
        }

        foreach ($weighIns->sortBy('feeding_day') as $weighIn) {
            $points[] = [
                'label' => 'Day '.$weighIn->feeding_day,
                'date' => $weighIn->weighed_on?->format('M j, Y') ?? '',
                'height_cm' => self::figure($weighIn->height_cm),
                'weight_kg' => self::figure($weighIn->weight_kg),
                'bmi' => is_numeric($weighIn->bmi_value) ? number_format((float) $weighIn->bmi_value, 1) : '',
                'status' => (string) $weighIn->nutritional_status,
            ];
        }

        if ($growth['endline']['measured']) {
            $points[] = self::point('Endline', $record->endline_recorded_at?->format('M j, Y') ?? '', $growth['endline']);
        }

        return $points;
    }

    /**
     * Whether a beneficiary is improving at their latest weighing after the
     * baseline — a progress weigh-in, or the endline once it is taken — or
     * null when there is nothing to compare yet.
     *
     * Not improving: their BMI-for-age status is no better than at baseline,
     * and their BMI has not risen. A learner who moved up the scale is
     * improving whatever the BMI did; one who stayed put is improving only if
     * their BMI went up.
     *
     * @param  Collection<int, FeedingWeighIn>  $weighIns
     */
    public static function progress(StudentHealthRecord $record, Collection $weighIns): ?string
    {
        $baselineBmi = self::number($record->baseline_bmi_value ?? $record->bmi_value);
        $baselineStatus = SchoolHeadOverview::toScale((string) ($record->baseline_nutritional_status ?: $record->nutritional_status));

        [$latestBmi, $latestStatus] = $record->hasEndlineMeasurement()
            ? [self::number($record->endline_bmi_value), SchoolHeadOverview::toScale((string) $record->endline_nutritional_status)]
            : (($last = $weighIns->sortBy('feeding_day')->last()) !== null
                ? [self::number($last->bmi_value), SchoolHeadOverview::toScale((string) $last->nutritional_status)]
                : [null, '']);

        if ($baselineBmi === null || $latestBmi === null) {
            return null;
        }

        $from = FeedingNutritionProgress::rank($baselineStatus);
        $to = FeedingNutritionProgress::rank($latestStatus);

        if ($from !== null && $to !== null && $to > $from) {
            return self::IMPROVING;
        }

        if ($from !== null && $to !== null && $to < $from) {
            return self::NOT_IMPROVING;
        }

        return $latestBmi > $baselineBmi ? self::IMPROVING : self::NOT_IMPROVING;
    }

    /**
     * The latest weight taken after the baseline — the endline, else the most
     * recent progress weigh-in — or null when nobody has re-weighed the
     * learner. A baseline weight is not a "current" one: comparing it with
     * itself is how an unweighed learner read "No change" (report #7).
     *
     * @param  Collection<int, FeedingWeighIn>  $weighIns
     */
    public static function latestWeight(StudentHealthRecord $record, Collection $weighIns): ?float
    {
        if ($record->hasEndlineMeasurement()) {
            return self::number($record->endline_weight_kg);
        }

        $last = $weighIns->sortBy('feeding_day')->last();

        return $last !== null ? self::number($last->weight_kg) : null;
    }

    /**
     * @param  array<string, mixed>  $phase  NutritionalHealthStatus phase
     * @return array{label: string, date: string, height_cm: string, weight_kg: string, bmi: string, status: string}
     */
    private static function point(string $label, string $date, array $phase): array
    {
        return [
            'label' => $label,
            'date' => $date,
            'height_cm' => (string) $phase['height_cm'],
            'weight_kg' => (string) $phase['weight_kg'],
            'bmi' => (string) $phase['bmi'],
            'status' => (string) $phase['bmi_status'],
        ];
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    private static function figure(mixed $value): string
    {
        return is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.') : '';
    }
}
