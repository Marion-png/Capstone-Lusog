<?php

namespace App\Support;

use App\Models\StudentHealthRecord;
use Illuminate\Support\Collection;

/**
 * Whether the feeding programme is working: the beneficiaries' nutritional
 * status at baseline against the same roll at endline, and how many of them
 * actually improved.
 *
 * The improvement figure is computed here rather than typed into a form,
 * because it is the number the programme is judged on and a hand-entered one
 * cannot be audited. One rule, one place: the dashboard and any report that
 * later needs it read the same answer.
 *
 * The scale is the wasting scale, in the direction the programme moves
 * learners: Severely Wasted → Wasted → Normal. "Improved" means a learner
 * climbed it. Overshooting into Overweight or Obese is deliberately NOT an
 * improvement — the learner has left wasting, but into a different problem the
 * programme did not set out to cause, and counting it as success would let the
 * headline rise for the wrong reason.
 */
class FeedingNutritionProgress
{
    /** The rungs a beneficiary can be on, worst first. Order is the ranking. */
    public const SCALE = ['Severely Wasted', 'Wasted', 'Normal'];

    /** Above Normal — off the wasting scale, and never counted as improvement. */
    public const ABOVE_NORMAL = 'Obese';

    /**
     * @param  Collection<int, StudentHealthRecord>  $beneficiaries
     * @param  callable(StudentHealthRecord): string  $baselineStatus  normalized baseline status
     * @param  callable(StudentHealthRecord): string  $endlineStatus  normalized endline status, '' when unmeasured
     * @return array<string, mixed>
     */
    public static function build(Collection $beneficiaries, callable $baselineStatus, callable $endlineStatus): array
    {
        $rows = array_map(
            fn (string $label): array => ['label' => $label, 'baseline' => 0, 'endline' => 0],
            array_merge(self::SCALE, [self::ABOVE_NORMAL])
        );
        $index = array_flip(array_column($rows, 'label'));

        $total = $beneficiaries->count();
        $measured = 0;
        $improved = 0;
        $unchanged = 0;
        $declined = 0;

        foreach ($beneficiaries as $record) {
            $baseline = $baselineStatus($record);
            $endline = $endlineStatus($record);

            if (isset($index[$baseline])) {
                $rows[$index[$baseline]]['baseline']++;
            }

            if ($endline === '') {
                continue;
            }

            $measured++;
            if (isset($index[$endline])) {
                $rows[$index[$endline]]['endline']++;
            }

            $from = self::rank($baseline);
            $to = self::rank($endline);

            // A learner whose endline is off the wasting scale (Overweight or
            // Obese) has no rank to compare, so they are neither improved nor
            // declined — they are measured, and the bars show where they landed.
            if ($from === null || $to === null) {
                continue;
            }

            if ($to > $from) {
                $improved++;
            } elseif ($to < $from) {
                $declined++;
            } else {
                $unchanged++;
            }
        }

        // Denominator is every beneficiary, not only those with an endline
        // reading: "73.6% improved" must not creep upward simply because few
        // learners have been measured. `measured` is reported alongside it so
        // the figure can be read for what it is.
        $rate = $total > 0 ? round(($improved / $total) * 100, 1) : 0.0;

        $peak = max(1, max(array_merge(
            array_column($rows, 'baseline'),
            array_column($rows, 'endline')
        )));

        return [
            'total' => $total,
            'measured' => $measured,
            'improved' => $improved,
            'unchanged' => $unchanged,
            'declined' => $declined,
            'rate' => $rate,
            'rows' => array_map(
                fn (array $row): array => $row + [
                    // Bar lengths share one scale across both series, so a
                    // baseline bar and an endline bar of equal length are
                    // equal counts.
                    'baseline_pct' => round(($row['baseline'] / $peak) * 100, 1),
                    'endline_pct' => round(($row['endline'] / $peak) * 100, 1),
                ],
                $rows
            ),
        ];
    }

    /** Position on the wasting scale, or null for a status that is off it. */
    public static function rank(string $status): ?int
    {
        $rank = array_search($status, self::SCALE, true);

        return $rank === false ? null : (int) $rank;
    }
}
