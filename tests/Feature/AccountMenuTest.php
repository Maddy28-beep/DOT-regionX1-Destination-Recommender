<?php

namespace Tests\Feature;

use App\Models\TouristAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The signed-in header: one account menu (avatar, links with counts, Log out) instead of a name
 * link beside a log-out icon, plus the tabs and empty state on the account pages.
 */
class AccountMenuTest extends TestCase
{
    use RefreshDatabase;

    private function signedIn(string $alias = 'dress'): TouristAccount
    {
        $account = TouristAccount::create(['alias' => $alias, 'password_hash' => bcrypt('password123')]);
        $this->actingAs($account, 'tourist');

        return $account;
    }

    public function test_the_header_has_an_account_menu_with_links_and_log_out(): void
    {
        $this->signedIn();

        $this->get('/destinations')->assertOk()
            ->assertSee('class="account-menu"', false)
            ->assertSee('Account menu for dress')
            ->assertSee('Traveler account')
            ->assertSee(route('account.itineraries'), false)
            ->assertSee(route('account.saved'), false)
            ->assertSee('Log out')
            ->assertDontSee('header-account-chip__logout', false);
    }

    public function test_the_avatar_shows_the_first_letter_of_the_alias(): void
    {
        $this->signedIn('maria');

        $this->assertMatchesRegularExpression('/account-menu__avatar"[^>]*>\s*M\s*</', $this->get('/destinations')->getContent());
    }

    public function test_the_current_page_is_marked_in_the_menu(): void
    {
        $this->signedIn();

        $html = $this->get(route('account.itineraries'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/href="[^"]*account\/itineraries"\s+aria-current="page"/', $html);
    }

    public function test_a_visitor_still_sees_log_in_and_no_account_menu(): void
    {
        $this->get('/destinations')->assertOk()
            ->assertSee('Log in')
            ->assertDontSee('class="account-menu"', false);
    }

    public function test_the_account_pages_share_tabs_with_counts(): void
    {
        $this->signedIn();

        $this->get(route('account.itineraries'))->assertOk()
            ->assertSee('account-tabs', false)
            ->assertSee('My itineraries')
            ->assertSee('Saved places');

        $this->get(route('account.saved'))->assertOk()
            ->assertSee('account-tabs', false);
    }

    public function test_an_empty_itineraries_page_invites_the_first_trip(): void
    {
        $this->signedIn();

        $this->get(route('account.itineraries'))->assertOk()
            ->assertSee('Start your first trip')
            ->assertSee('Pick your interests')
            ->assertSee('Browse tour packages')
            ->assertSee(route('plan.edit'), false)
            ->assertDontSee('No saved itineraries yet');
    }
}
