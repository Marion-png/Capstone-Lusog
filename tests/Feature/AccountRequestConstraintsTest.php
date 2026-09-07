<?php

namespace Tests\Feature;

use App\Models\Institution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Registration is limited to one school, and the form's constraints are the
 * server's, not the browser's.
 */
class AccountRequestConstraintsTest extends TestCase
{
    use RefreshDatabase;

    private function school(): Institution
    {
        return Institution::firstOrCreate(
            ['name' => Institution::REGISTRATION_SCHOOL],
            ['status' => 'active']
        );
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Maria Santos',
            'username' => 'msantos',
            'password' => 'goodpass1',
            'password_confirmation' => 'goodpass1',
            'role' => 'school_nurse',
            'institution_id' => $this->school()->id,
        ], $overrides);
    }

    public function test_the_form_offers_only_the_one_school(): void
    {
        $this->school();
        Institution::firstOrCreate(['name' => 'Wireless ES'], ['status' => 'active']);

        $response = $this->get('/account-request');

        $response->assertOk();
        $response->assertSee(Institution::REGISTRATION_SCHOOL);
        $response->assertDontSee('Wireless ES');
    }

    public function test_the_institutions_api_returns_only_the_one_school(): void
    {
        $this->school();
        Institution::firstOrCreate(['name' => 'Wireless ES'], ['status' => 'active']);

        $response = $this->getJson('/api/institutions');

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['name' => Institution::REGISTRATION_SCHOOL]);
    }

    public function test_it_refuses_a_registration_for_another_school(): void
    {
        $this->school();
        $other = Institution::firstOrCreate(['name' => 'Wireless ES'], ['status' => 'active']);

        $response = $this->post('/account-request', $this->payload([
            'institution_id' => $other->id,
        ]));

        $response->assertSessionHasErrors('institution_id');
        $this->assertDatabaseCount('account_requests', 0);
    }

    public function test_it_accepts_a_registration_for_the_one_school(): void
    {
        $response = $this->post('/account-request', $this->payload());

        $response->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('account_requests')->count());
    }

    /** @dataProvider badFields */
    public function test_it_refuses_junk_input(string $field, array $overrides): void
    {
        $this->school();

        $response = $this->post('/account-request', $this->payload($overrides));

        $response->assertSessionHasErrors($field);
        $this->assertDatabaseCount('account_requests', 0);
    }

    public static function badFields(): array
    {
        return [
            'name with digits' => ['name', ['name' => 'Maria 123']],
            'name too short' => ['name', ['name' => 'M']],
            'username too short' => ['username', ['username' => 'abc']],
            'username with spaces' => ['username', ['username' => 'maria santos']],
            'username uppercase' => ['username', ['username' => 'MSantos']],
            'password too short' => ['password', ['password' => 'good1', 'password_confirmation' => 'good1']],
            'password letters only' => ['password', ['password' => 'password', 'password_confirmation' => 'password']],
            'password digits only' => ['password', ['password' => '12345678', 'password_confirmation' => '12345678']],
            'password unconfirmed' => ['password', ['password_confirmation' => 'different1']],
        ];
    }
}
