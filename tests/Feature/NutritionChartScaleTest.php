<?php

namespace Tests\Feature;

use App\Models\StudentHealthRecord;
use App\Support\FeedingNutritionProgress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Nutritional Progress chart scales to whatever the school holds.
 *
 * A programme of seven and a programme of twelve hundred are drawn on the same
 * card, so the axis is stepped to the data — 1, 2 or 5 × 10ⁿ, at most four
 * intervals — rather than fixed. Whatever the size, the axis must clear the
 * tallest bar, every bar must end on the plot, and a lone learner beside
 * hundreds must still be a bar of their own.
 */
class NutritionChartScaleTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, list<int>}>
     */
    public static function sizes(): iterable
    {
        // Wasted at baseline, how many of them were re-measured Normal, and
        // the ticks the axis should read (largest first).
        yield 'a small class' => [7, 3, [8, 6, 4, 2, 0]];
        yield 'past forty' => [47, 20, [60, 40, 20, 0]];
        yield 'a large school' => [130, 60, [150, 100, 50, 0]];
        yield 'thousands' => [1234, 700, [1500, 1000, 500, 0]];
    }

    #[Test]
    #[DataProvider('sizes')]
    public function the_axis_is_stepped_to_the_data(int $wasted, int $improved, array $ticks): void
    {
        $roll = collect();
        for ($i = 0; $i < $wasted; $i++) {
            $roll->push(new StudentHealthRecord([
                'baseline_nutritional_status' => 'Wasted',
                'endline_nutritional_status' => $i < $improved ? 'Normal' : null,
            ]));
        }
        // One learner beside hundreds, so a category of one is on the chart.
        $roll->push(new StudentHealthRecord([
            'baseline_nutritional_status' => 'Severely Wasted',
            'endline_nutritional_status' => 'Obese',
        ]));

        $progress = FeedingNutritionProgress::build(
            $roll,
            fn (StudentHealthRecord $r): string => (string) $r->baseline_nutritional_status,
            fn (StudentHealthRecord $r): string => (string) $r->endline_nutritional_status,
        );

        $this->assertSame($ticks, $progress['ticks']);
        $this->assertGreaterThanOrEqual($wasted, $progress['axis_max'], 'The axis clears the tallest bar.');
        $this->assertLessThanOrEqual(5, count($progress['ticks']), 'At most four intervals, however large the school.');

        foreach ($progress['rows'] as $row) {
            foreach (['baseline', 'endline'] as $series) {
                $this->assertLessThanOrEqual(100.0, (float) $row[$series.'_pct'], "{$row['label']} {$series} stays on the plot.");
                // A real count has a real length; the stylesheet floors a
                // sliver to 3px, so any count above 0 must reach the view as
                // a positive width rather than being rounded away.
                if ($row[$series] > 0) {
                    $this->assertGreaterThan(0.0, (float) $row[$series.'_pct']);
                }
            }
        }
    }

    /** The bar that decides the axis ends on a gridline or short of one, never past the last. */
    #[Test]
    public function the_tallest_bar_is_never_wider_than_the_plot(): void
    {
        $roll = collect(array_fill(0, 60, null))->map(fn () => new StudentHealthRecord([
            'baseline_nutritional_status' => 'Wasted',
        ]));

        $progress = FeedingNutritionProgress::build(
            $roll,
            fn (StudentHealthRecord $r): string => (string) $r->baseline_nutritional_status,
            fn (StudentHealthRecord $r): string => '',
        );

        $wasted = collect($progress['rows'])->firstWhere('label', 'Wasted');
        $this->assertSame(60, $progress['axis_max']);
        $this->assertSame(100.0, (float) $wasted['baseline_pct']);
    }
}
