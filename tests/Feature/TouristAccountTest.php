<?php

namespace Tests\Feature;

use App\Models\TouristAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The optional tourist account: an alias and a password, nothing else. These
 * tests guard the account lifecycle itself (registration, login, logout) --
 * separately from what an account is actually used for (saving itineraries,
 * covered in TouristItineraryTest).
 */
class TouristAccountTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return $overrides + [
            'alias' => 'explorer1',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];
    }

    public function test_a_visitor_can_register_with_just_an_alias_and_password(): void
    {
        $this->post(route('account.register'), $this->validPayload())
            ->assertRedirect(route('account.itineraries'));

        $account = TouristAccount::sole();
        $this->assertSame('explorer1', $account->alias);
        $this->assertTrue(auth('tourist')->check());
    }

    public function test_no_personal_information_is_collected_at_registration(): void
    {
        $this->post(route('account.register'), $this->validPayload());

        $account = TouristAccount::sole();
        $this->assertArrayNotHasKey('email', $account->getAttributes());
        $this->assertArrayNotHasKey('full_name', $account->getAttributes());
        $this->assertArrayNotHasKey('phone', $account->getAttributes());
    }

    public function test_the_password_is_hashed_not_stored_plainly(): void
    {
        $this->post(route('account.register'), $this->validPayload());

        $account = TouristAccount::sole();
        $this->assertNotSame('password123', $account->password_hash);
        $this->assertTrue(Hash::check('password123', $account->password_hash));
    }

    public function test_the_password_hash_is_never_exposed(): void
    {
        $this->post(route('account.register'), $this->validPayload());

        $account = TouristAccount::sole();
        $this->assertArrayNotHasKey('password_hash', $account->toArray());
    }

    public function test_a_duplicate_alias_is_rejected(): void
    {
        TouristAccount::create(['alias' => 'explorer1', 'password_hash' => Hash::make('x')]);

        $this->post(route('account.register'), $this->validPayload())
            ->assertSessionHasErrors('alias');

        $this->assertSame(1, TouristAccount::count());
    }

    public function test_a_mismatched_password_confirmation_is_rejected(): void
    {
        $this->post(route('account.register'), $this->validPayload(['password_confirmation' => 'somethingelse']))
            ->assertSessionHasErrors('password');

        $this->assertSame(0, TouristAccount::count());
    }

    public function test_a_too_short_password_is_rejected(): void
    {
        $this->post(route('account.register'), $this->validPayload([
            'password' => 'short', 'password_confirmation' => 'short',
        ]))->assertSessionHasErrors('password');

        $this->assertSame(0, TouristAccount::count());
    }

    public function test_an_alias_that_is_too_short_is_rejected(): void
    {
        $this->post(route('account.register'), $this->validPayload(['alias' => 'ab']))
            ->assertSessionHasErrors('alias');
    }

    public function test_a_registered_tourist_can_log_in(): void
    {
        TouristAccount::create(['alias' => 'explorer1', 'password_hash' => Hash::make('password123')]);

        $this->post(route('account.login'), ['alias' => 'explorer1', 'password' => 'password123'])
            ->assertRedirect(route('account.itineraries'));

        $this->assertTrue(auth('tourist')->check());
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        TouristAccount::create(['alias' => 'explorer1', 'password_hash' => Hash::make('password123')]);

        $this->post(route('account.login'), ['alias' => 'explorer1', 'password' => 'wrongpassword'])
            ->assertSessionHasErrors('alias');

        $this->assertFalse(auth('tourist')->check());
    }

    public function test_logout_ends_the_session(): void
    {
        $account = TouristAccount::create(['alias' => 'explorer1', 'password_hash' => Hash::make('password123')]);
        $this->actingAs($account, 'tourist');

        $this->post(route('account.logout'))->assertRedirect(route('home'));

        $this->assertFalse(auth('tourist')->check());
    }

    public function test_a_guest_hitting_a_protected_account_route_is_sent_to_tourist_login(): void
    {
        $this->get(route('account.itineraries'))->assertRedirect(route('account.login'));
    }

    /** The tourist guard must carry zero administrative privileges over the admin/establishment portals. */
    public function test_a_tourist_account_cannot_reach_admin_or_establishment_routes(): void
    {
        $account = TouristAccount::create(['alias' => 'explorer1', 'password_hash' => Hash::make('password123')]);
        $this->actingAs($account, 'tourist');

        $this->get('/portal/admin')->assertRedirect(route('portal.login'));
        $this->get('/portal/establishment')->assertRedirect(route('portal.login'));
    }
}
