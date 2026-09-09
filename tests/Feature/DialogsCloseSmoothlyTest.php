<?php

namespace Tests\Feature;

use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every dialog leaves the screen; none of them vanishes.
 *
 * They all animated *in* and then disappeared on the frame the close button
 * was pressed. That reads as a glitch rather than a close — the eye loses
 * where the panel went, and because these dialogs sit on a blurred backdrop,
 * the whole page appears to jump when the blur is dropped in one frame.
 *
 * Four separate dialog systems grew up in this codebase, so the fix is the
 * same shape in four places and the timings are deliberately identical: one
 * product should not feel like several. Out is quicker than in and travels
 * less — arriving is worth watching, leaving is only worth following.
 *
 * Two things every one of them must get right, and the reason this is a test
 * rather than four hopeful comments:
 *
 *  - the panel keeps its open class while it leaves, so the page behind
 *    cannot scroll under a half-faded dialog;
 *  - the close is finished by a timeout as well as by `animationend`, which
 *    never fires on a hidden tab. Without the backstop a dialog closed in a
 *    background tab stays half-open forever and blocks the page.
 */
class DialogsCloseSmoothlyTest extends TestCase
{
    use RefreshDatabase;

    private Institution $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = Institution::create(['name' => 'Sta. Ana NHS', 'status' => 'active']);
    }

    private function sessionFor(string $role): array
    {
        $base = [
            'active_role' => $role,
            'active_name' => 'Staff Member',
            'active_school_name' => 'Sta. Ana NHS',
            'active_institution_id' => $this->school->id,
            'school_health_card_records' => [],
        ];

        if ($role === 'class_adviser') {
            $base['assigned_grade_level'] = 'Grade 10';
            $base['assigned_section'] = 'Dalton';
        }

        return $base;
    }

    private function page(string $role, string $route, array $params = []): string
    {
        return $this->withSession($this->sessionFor($role))
            ->get(route($route, $params))
            ->assertOk()
            ->getContent();
    }

    // ── The shared .bmodal system ────────────────────────────────────

    /** Consultations, announcements and events all run on this one. */
    #[Test]
    public function the_shared_dialog_animates_out(): void
    {
        $html = $this->page('school_nurse', 'dashboard.consultation-log');

        $this->assertStringContainsString('@keyframes bmodalOut', $html);
        $this->assertStringContainsString('.bmodal.is-closing .bmodal-panel', $html);
        $this->assertStringContainsString("modal.classList.add('is-closing');", $html);
    }

    /**
     * It keeps `open` while it leaves. `unlockBody()` looks for `.bmodal.open`
     * to decide whether the page may scroll again — drop the class first and
     * the page unlocks under a panel that is still on screen.
     */
    #[Test]
    public function the_shared_dialog_stays_open_while_it_leaves(): void
    {
        $html = $this->page('school_nurse', 'dashboard.consultation-log');

        $this->assertStringContainsString('.bmodal.open, .bmodal.is-closing { display: flex; }', $html);
        $this->assertStringContainsString("modal.classList.remove('open', 'is-closing');", $html);
    }

    /** Re-opening mid-exit cancels the leave rather than racing it. */
    #[Test]
    public function reopening_the_shared_dialog_cancels_the_exit(): void
    {
        $html = $this->page('school_nurse', 'dashboard.consultation-log');

        $this->assertStringContainsString("modal.classList.remove('is-closing');", $html);
    }

    /** Escape and a backdrop click can both land before the animation ends. */
    #[Test]
    public function the_shared_dialog_cannot_be_closed_twice(): void
    {
        $html = $this->page('school_nurse', 'dashboard.consultation-log');

        $this->assertStringContainsString("if (modal.classList.contains('is-closing')) return;", $html);
    }

    // ── The other three ──────────────────────────────────────────────

    /** The clinic's consultation-photo dialog. */
    #[Test]
    public function the_photo_dialog_animates_out(): void
    {
        $html = $this->page('school_nurse', 'dashboard.consultation-log');

        $this->assertStringContainsString('@keyframes cphotoOut', $html);
        $this->assertStringContainsString("backdrop.classList.add('is-closing');", $html);
    }

    /**
     * `.cphoto-backdrop` sets `display: flex`, which outranks the browser's
     * own `[hidden] { display: none }` — without the override the dialog
     * would sit open over the page from first paint. The same trap hid behind
     * the profile avatar.
     */
    #[Test]
    public function the_photo_dialog_actually_hides(): void
    {
        $html = $this->page('school_nurse', 'dashboard.consultation-log');

        $this->assertStringContainsString('.cphoto-backdrop[hidden] { display: none !important; }', $html);
    }

    /** The adviser's Review & Submit confirmation. */
    #[Test]
    public function the_review_confirmation_animates_out(): void
    {
        $html = $this->page('class_adviser', 'dashboard.class-adviser', ['tab' => 'form']);

        $this->assertStringContainsString('@keyframes ca-modal-out', $html);
        $this->assertStringContainsString('.confirm-overlay.open, .confirm-overlay.is-closing { display: flex; }', $html);
        $this->assertStringContainsString("modal.classList.add('is-closing');", $html);
    }

    /** The Feeding Coordinator's enrolment dialog. */
    #[Test]
    public function the_enrolment_dialog_animates_out(): void
    {
        $html = $this->page('feeding_coor', 'dashboard.feedingcor-dashboard');

        $this->assertStringContainsString('@keyframes fc-modal-out', $html);
        $this->assertStringContainsString('.modal-backdrop.open, .modal-backdrop.is-closing { display: flex; }', $html);
        $this->assertStringContainsString("backdrop.classList.add('is-closing');", $html);
    }

    /**
     * Its `open` flag goes false immediately, before the animation, so Escape
     * and the records pulse stop acting on a dialog already on its way out.
     */
    #[Test]
    public function the_enrolment_dialog_stops_listening_the_moment_it_closes(): void
    {
        $html = $this->page('feeding_coor', 'dashboard.feedingcor-dashboard');

        $this->assertStringContainsString('open = false;', $html);
        $this->assertStringContainsString("backdrop.classList.remove('open', 'is-closing');", $html);
    }

    // ── Rules every one of them keeps ────────────────────────────────

    /**
     * A timeout finishes the close as well as `animationend`, which never
     * fires on a hidden tab. Without it a dialog closed in a background tab
     * stays half-open and blocks the page behind it.
     */
    #[Test]
    public function every_dialog_has_a_backstop(): void
    {
        $pages = [
            'nurse' => $this->page('school_nurse', 'dashboard.consultation-log'),
            'adviser' => $this->page('class_adviser', 'dashboard.class-adviser', ['tab' => 'form']),
            'coordinator' => $this->page('feeding_coor', 'dashboard.feedingcor-dashboard'),
        ];

        foreach ($pages as $who => $html) {
            $this->assertStringContainsString(
                'setTimeout(settle, 300);',
                $html,
                "The {$who}'s dialog has no backstop — a close in a background tab would hang."
            );
        }
    }

    /** Somebody who asked their system for less motion gets none. */
    #[Test]
    public function every_dialog_respects_reduced_motion(): void
    {
        $pages = [
            'nurse' => $this->page('school_nurse', 'dashboard.consultation-log'),
            'adviser' => $this->page('class_adviser', 'dashboard.class-adviser', ['tab' => 'form']),
            'coordinator' => $this->page('feeding_coor', 'dashboard.feedingcor-dashboard'),
        ];

        foreach ($pages as $who => $html) {
            $this->assertStringContainsString(
                "window.matchMedia('(prefers-reduced-motion: reduce)')",
                $html,
                "The {$who}'s dialog animates regardless of the reader's setting."
            );
            $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $html);
        }
    }
}
