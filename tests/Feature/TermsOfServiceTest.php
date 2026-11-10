<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Support\TermsOfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SIGLA's Terms of Service: a public page, linked from both sign-in pages, and
 * agreed to — required, on the server — when a person requests an account. The
 * agreement and the version agreed to are kept in the append-only audit trail.
 */
class TermsOfServiceTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        $school = Institution::firstOrCreate(['name' => Institution::REGISTRATION_SCHOOL], ['status' => 'active']);

        return array_merge([
            'name' => 'Maria Santos',
            'username' => 'msantos',
            'password' => 'goodpass1',
            'password_confirmation' => 'goodpass1',
            'role' => 'school_nurse',
            'institution_id' => $school->id,
            'terms' => '1',
        ], $overrides);
    }

    #[Test]
    public function the_terms_are_public_and_describe_this_system(): void
    {
        $this->get(route('terms'))
            ->assertOk()
            ->assertSee('Terms of Service')
            ->assertSee('Version '.TermsOfService::VERSION)
            ->assertSee('Data Privacy Act of 2012')
            ->assertSee('School-Based Feeding Program')
            ->assertSee('Your activity is recorded');
    }

    #[Test]
    public function both_sign_in_pages_link_to_the_terms(): void
    {
        foreach ([route('login'), route('admin.login')] as $url) {
            $this->get($url)->assertOk()->assertSee(route('terms'), false);
        }
    }

    #[Test]
    public function the_account_request_form_asks_for_agreement(): void
    {
        $this->get(route('account.request'))
            ->assertOk()
            ->assertSee('name="terms" value="1" required', false)
            ->assertSee(route('terms'), false);
    }

    #[Test]
    public function an_account_cannot_be_requested_without_agreeing(): void
    {
        $this->post('/account-request', $this->payload(['terms' => null]))
            ->assertSessionHasErrors('terms');

        $this->assertSame(0, DB::table('account_requests')->count());
    }

    #[Test]
    public function the_agreement_is_recorded_with_its_version(): void
    {
        $before = AuditLog::query()->count();

        $this->post('/account-request', $this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('account.request'));

        $this->assertSame(1, DB::table('account_requests')->count());

        $entry = AuditLog::query()->orderByDesc('id')->get()
            ->first(fn (AuditLog $log): bool => $log->subject_type === 'AccountRequest');

        $this->assertGreaterThan($before, AuditLog::query()->count());
        $this->assertNotNull($entry);
        $this->assertStringContainsString('agreed to the Terms of Service v'.TermsOfService::VERSION, (string) $entry->description);
    }
}
