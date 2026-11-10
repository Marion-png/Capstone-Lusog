<?php

namespace Tests\Feature;

use App\Models\FeedingWeighIn;
use App\Models\Institution;
use App\Models\StudentHealthRecord;
use App\Support\FeedingWeighIns;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Testers' report #33: optional progress weigh-ins around feeding days 30,
 * 60 and 90, a school setting the coordinator turns on (off by default).
 */
class ProgressWeighInsTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    private StudentHealthRecord $learner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-09 10:00:00', 'Asia/Manila'));
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);

        // A girl born 22 August 2013, wasted at baseline: 150 cm, 32 kg.
        $this->learner = StudentHealthRecord::create([
            'institution_id' => $this->school->id,
            'school_year' => StudentHealthRecord::currentSchoolYear(),
            'student_name' => 'Bituin, Ana',
            'student_id' => '700000000101',
            'school_name' => 'Sta. Ana NHS',
            'section' => 'Grade 7 / Matiyaga',
            'weight' => 32, 'bmi_value' => 14.22, 'nutritional_status' => 'Wasted',
            'baseline_age' => 13, 'baseline_height_cm' => 150, 'baseline_weight_kg' => 32,
            'baseline_bmi_value' => 14.22, 'baseline_nutritional_status' => 'Wasted',
            'baseline_recorded_at' => '2026-07-01',
            'student_details' => ['gender' => 'Female', 'birth_year' => 2013, 'birth_month' => 8, 'birth_day' => 22],
            'feeding_enrolled_at' => now()->subMonths(4),
        ]);
    }

    private function coordinator(): array
    {
        return [
            'active_role' => 'feeding_coor',
            'active_name' => 'Lorna Reyes',
            'active_username' => 'feedcor.test',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
        ];
    }

    private function weighIn(float $weight, int $day = 30)
    {
        return $this->withSession($this->coordinator())
            ->from(route('feedingcor-program.beneficiary', $this->learner->id))
            ->post(route('feedingcor-program.beneficiary.weigh-in', $this->learner->id), [
                'feeding_day' => $day,
                'weighed_on' => '2026-08-15',
                'height_cm' => 150,
                'weight_kg' => $weight,
            ]);
    }

    private function turn(bool $on)
    {
        return $this->withSession($this->coordinator())
            ->post(route('settings.progress-weigh-ins'), ['enabled' => $on ? '1' : '0']);
    }

    #[Test]
    public function off_by_default_nothing_changes(): void
    {
        $this->assertFalse(FeedingWeighIns::enabled($this->school->id));

        $record = $this->withSession($this->coordinator())->get(route('feedingcor-program.beneficiary', $this->learner->id))->assertOk();
        $record->assertDontSee('data-tab="weighins"', false);

        $this->weighIn(31)->assertSessionHas('error');
        $this->assertSame(0, FeedingWeighIn::count());

        $this->withSession($this->coordinator())->get(route('dashboard.feedingcor-dashboard'))->assertOk()->assertDontSee('data-not-improving', false);
    }

    #[Test]
    public function only_the_coordinator_turns_it_on(): void
    {
        $this->withSession(['active_role' => 'class_adviser', 'active_institution_id' => $this->school->id])
            ->post(route('settings.progress-weigh-ins'), ['enabled' => '1'])
            ->assertForbidden();
        $this->assertFalse(FeedingWeighIns::enabled($this->school->id));

        $this->turn(true)->assertSessionHas('success');
        $this->assertTrue((bool) $this->school->fresh()->feeding_progress_weigh_ins);
        $this->withSession($this->coordinator())->get(route('settings'))->assertOk()->assertSee('Progress weigh-ins');
    }

    #[Test]
    public function a_day_30_weigh_in_is_classified_and_a_learner_not_improving_is_flagged(): void
    {
        $this->turn(true);

        // Lighter than at baseline: no better.
        $this->weighIn(31)->assertSessionHasNoErrors()->assertSessionHas('success');

        $weighIn = FeedingWeighIn::firstOrFail();
        $this->assertSame(30, $weighIn->feeding_day);
        $this->assertEqualsWithDelta(13.78, (float) $weighIn->bmi_value, 0.01);
        $this->assertNotSame('', (string) $weighIn->nutritional_status);
        $this->assertSame('Lorna Reyes', $weighIn->recorded_by_name);
        $this->assertSame(FeedingWeighIns::NOT_IMPROVING, FeedingWeighIns::progress($this->learner->fresh(), FeedingWeighIn::all()));

        $page = $this->withSession($this->coordinator())->get(route('feedingcor-program.beneficiary', $this->learner->id))->assertOk();
        $page->assertSee('data-tab="weighins"', false);
        $page->assertSee('data-weighin-trend', false);
        $page->assertSee('Day 30');
        $page->assertSee('data-progress="not_improving"', false);

        $this->withSession($this->coordinator())->get(route('dashboard.feedingcor-dashboard'))
            ->assertOk()->assertSee('data-not-improving', false)->assertSee('Bituin, Ana');

        // Weighed again for day 30, heavier: the checkpoint is corrected, not doubled.
        $this->weighIn(35)->assertSessionHas('success');
        $this->assertSame(1, FeedingWeighIn::count());
        $this->assertSame(FeedingWeighIns::IMPROVING, FeedingWeighIns::progress($this->learner->fresh(), FeedingWeighIn::all()));
        $this->withSession($this->coordinator())->get(route('dashboard.feedingcor-dashboard'))->assertDontSee('data-not-improving', false);
    }

    #[Test]
    public function turned_off_the_weigh_ins_are_hidden_and_kept(): void
    {
        $this->turn(true);
        $this->weighIn(31);
        $this->turn(false);

        $this->assertSame(1, FeedingWeighIn::count());
        $this->withSession($this->coordinator())->get(route('feedingcor-program.beneficiary', $this->learner->id))
            ->assertOk()->assertDontSee('data-tab="weighins"', false);
    }
}
