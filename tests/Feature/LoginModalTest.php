<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use App\Models\TouristAccount;
use App\Models\TouristSavedDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The heart on a place asks a visitor who is not signed in to sign in, in a modal over the page (the same card as the Sign In
 * page), and saves the place for them as they sign in.
 */
class LoginModalTest extends TestCase
{
    use RefreshDatabase;

    private function place(string $name = 'Eden Nature Park', array $extra = []): Destination
    {
        return Destination::create($extra + [
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'type' => 'Nature & Leisure',
            'is_accredited' => true, 'rating' => 4.5, 'review_count' => 3, 'price_tier' => 'Mid-range',
        ]);
    }

    private function account(): TouristAccount
    {
        return TouristAccount::create(['alias' => 'explorer1', 'password_hash' => Hash::make('a-long-test-password')]);
    }

    private function modalLogin(array $extra = [], string $password = 'a-long-test-password')
    {
        return $this->postJson(route('account.login'), ['alias' => 'explorer1', 'password' => $password] + $extra);
    }

    // ---- the page

    public function test_a_guests_heart_asks_for_sign_in_and_names_the_place(): void
    {
        $place = $this->place();

        $html = $this->get(route('destinations.show', $place))->assertOk()->getContent();

        $this->assertStringContainsString('data-login-required', $html);
        $this->assertStringContainsString('data-save-type="destinations"', $html);
        $this->assertStringContainsString('data-save-id="'.$place->id.'"', $html);
        $this->assertStringContainsString('data-save-name="Eden Nature Park"', $html);
        // The modal is on the page, hidden, with the Sign In page's own card, and loads its styles only when opened.
        $this->assertStringContainsString('data-login-modal hidden', $html);
        $this->assertStringContainsString('role="dialog" aria-modal="true"', $html);
        $this->assertStringContainsString('Keep exploring, wherever you left off.', $html);
        $this->assertStringContainsString('data-styles=', $html);
        $this->assertStringNotContainsString('<link rel="stylesheet" href="'.asset('css/login-modal.css'), $html);
        $this->assertStringContainsString('js/login-modal.js', $html);
        // Without script the form still posts, so the heart keeps working.
        $this->assertStringContainsString('action="'.route('saved.toggle', ['destinations', $place->id]).'"', $html);
    }

    public function test_the_landing_page_has_the_modal_too(): void
    {
        $this->place();

        $this->get('/')->assertOk()->assertSee('data-login-modal', false);
    }

    public function test_a_signed_in_tourist_gets_no_modal_and_a_plain_heart(): void
    {
        $place = $this->place();

        $html = $this->actingAs($this->account(), 'tourist')->get(route('destinations.show', $place))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-login-modal', $html);
        $this->assertStringNotContainsString('data-login-required', $html);
        $this->assertStringContainsString(route('account.saved.toggle', ['destinations', $place->id]), $html);
    }

    public function test_the_sign_in_and_sign_up_pages_do_not_carry_a_second_login_form(): void
    {
        $this->get(route('account.login'))->assertOk()->assertDontSee('data-login-modal', false);
        $this->get(route('account.register'))->assertOk()->assertDontSee('data-login-modal', false);
    }

    // ---- the sign-in

    public function test_a_wrong_password_comes_back_as_json_for_the_modal_and_nothing_is_saved(): void
    {
        $this->account();
        $place = $this->place();

        $this->modalLogin(['save_type' => 'destinations', 'save_id' => $place->id], 'not-the-password')
            ->assertStatus(422)
            ->assertJsonPath('errors.alias.0', 'Those credentials do not match our records.');

        $this->assertGuest('tourist');
        $this->assertSame(0, TouristSavedDestination::count());
    }

    public function test_signing_in_from_the_modal_saves_the_place_once_and_keeps_it_on_a_second_sign_in(): void
    {
        $account = $this->account();
        $place = $this->place();
        $intent = ['save_type' => 'destinations', 'save_id' => $place->id];

        $this->modalLogin($intent)->assertOk()->assertJson(['ok' => true, 'saved' => true]);
        $this->assertAuthenticated('tourist');
        $this->assertSame(1, TouristSavedDestination::where(['tourist_account_id' => $account->id, 'listing_kind' => 'destination', 'listing_id' => $place->id])->count());
        $this->assertSame('Added to Saved Places', session('status'), 'the page shows this message after it reloads');

        // A place already in the list is not toggled off by signing in again for it.
        $this->post(route('account.logout'));
        $this->modalLogin($intent)->assertOk()->assertJson(['saved' => true]);
        $this->assertSame(1, TouristSavedDestination::count());
    }

    public function test_signing_in_without_a_place_just_signs_in(): void
    {
        $this->account();

        $this->modalLogin()->assertOk()->assertJson(['ok' => true, 'saved' => false]);

        $this->assertAuthenticated('tourist');
        $this->assertSame(0, TouristSavedDestination::count());
        $this->assertSame('Welcome back', session('status'));
    }

    public function test_a_place_that_is_not_public_or_a_type_that_does_not_exist_is_ignored_but_the_sign_in_works(): void
    {
        $this->account();
        $hidden = $this->place('Closed Park', ['is_accredited' => false]);
        $archived = $this->place('Old Park', ['archived_at' => now()]);

        foreach ([['destinations', $hidden->id], ['destinations', $archived->id], ['destinations', 999999], ['castles', 1]] as [$type, $id]) {
            $this->post(route('account.logout'));
            $this->modalLogin(['save_type' => $type, 'save_id' => $id])->assertOk()->assertJson(['ok' => true, 'saved' => false]);
        }

        $this->assertSame(0, TouristSavedDestination::count());
    }

    public function test_the_sign_in_page_still_signs_in_with_a_normal_post_and_extra_fields_cannot_change_who_signs_in(): void
    {
        $this->account();

        $this->post(route('account.login'), ['alias' => 'explorer1', 'password' => 'a-long-test-password'])->assertRedirect();
        $this->assertAuthenticated('tourist');

        $this->post(route('account.logout'));
        // Fields beyond alias and password are never part of the credentials the guard checks.
        $this->modalLogin(['id' => 'x', 'password_hash' => 'x'], 'wrong')->assertStatus(422);
        $this->assertGuest('tourist');
    }

    public function test_the_log_in_links_in_the_top_bar_and_the_phone_menu_open_the_modal(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        // Still ordinary links to the Sign In page (they work without script); the script turns them into the modal.
        $this->assertSame(2, substr_count($html, 'data-login-open'));
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('account.login'), '#').'"[^>]*class="header-account-link" data-login-open#', $html);
        $this->assertStringContainsString('[data-login-open]', file_get_contents(public_path('js/login-modal.js')));
    }

    public function test_a_signed_in_visitor_has_no_log_in_link_to_open_it_from(): void
    {
        $this->actingAs($this->account(), 'tourist')->get('/')->assertOk()->assertDontSee('data-login-open', false);
    }

    // ---- the sign-up side

    private function modalSignUp(array $extra = [])
    {
        return $this->postJson(route('account.register'), $extra + ['alias' => 'newexplorer', 'password' => 'a-long-test-password', 'password_confirmation' => 'a-long-test-password']);
    }

    public function test_the_modal_has_a_sign_up_side_and_the_link_says_sign_up(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('data-signup-pane', $html);
        $this->assertStringContainsString('data-login-switch="signup"', $html);
        $this->assertStringContainsString('data-login-switch="signin"', $html);
        $this->assertStringNotContainsString('Create one for free', $html);
        $this->get(route('account.login'))->assertOk()->assertDontSee('Create one for free');
    }

    public function test_signing_up_from_the_modal_makes_the_account_signs_in_and_saves_the_place(): void
    {
        $place = $this->place();

        $this->modalSignUp(['save_type' => 'destinations', 'save_id' => $place->id])->assertOk()->assertJson(['ok' => true, 'saved' => true]);

        $this->assertAuthenticated('tourist');
        $account = TouristAccount::where('alias', 'newexplorer')->firstOrFail();
        $this->assertTrue(Hash::check('a-long-test-password', $account->password_hash));
        $this->assertSame(1, TouristSavedDestination::where(['tourist_account_id' => $account->id, 'listing_kind' => 'destination', 'listing_id' => $place->id])->count());
        $this->assertSame('Added to Saved Places', session('status'));
    }

    public function test_signing_up_without_a_place_just_makes_the_account(): void
    {
        $this->modalSignUp()->assertOk()->assertJson(['ok' => true, 'saved' => false]);

        $this->assertAuthenticated('tourist');
        $this->assertSame(0, TouristSavedDestination::count());
        $this->assertSame('Account created', session('status'));
    }

    public function test_a_taken_alias_a_short_password_or_a_mismatch_come_back_as_json_errors(): void
    {
        $this->account();

        $this->modalSignUp(['alias' => 'Explorer1'])->assertStatus(422)->assertJsonValidationErrors('alias');
        $this->modalSignUp(['password' => 'short', 'password_confirmation' => 'short'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->modalSignUp(['password_confirmation' => 'something-else-entirely'])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertGuest('tourist');
        $this->assertSame(1, TouristAccount::count());
    }

    public function test_the_sign_up_page_still_works_with_a_normal_post(): void
    {
        $this->post(route('account.register'), ['alias' => 'plainpost', 'password' => 'a-long-test-password', 'password_confirmation' => 'a-long-test-password'])->assertRedirect();

        $this->assertAuthenticated('tourist');
    }

    // ---- the script

    public function test_the_script_covers_open_close_focus_escape_and_the_fetch_sign_in(): void
    {
        $js = file_get_contents(public_path('js/login-modal.js'));
        $css = file_get_contents(public_path('css/login-modal.css'));

        // Runs before the page's own heart handler, which would save to the browser token.
        $this->assertStringContainsString("document.addEventListener('submit'", $js);
        $this->assertStringContainsString('stopImmediatePropagation()', $js);
        $this->assertStringContainsString("form[data-login-required]", $js);
        // Closes three ways and gives focus back.
        $this->assertStringContainsString("event.key === 'Escape'", $js);
        $this->assertStringContainsString('[data-login-close]', $js);
        $this->assertStringContainsString('opener.focus()', $js);
        // Keeps Tab inside the dialog.
        $this->assertStringContainsString("event.key !== 'Tab'", $js);
        // Signs in over fetch and shows what went wrong in the modal; a success reloads the page.
        $this->assertStringContainsString("'Accept': 'application/json'", $js);
        $this->assertStringContainsString('window.location.reload()', $js);
        $this->assertStringContainsString('Too many attempts', $js);
        // Styles are fetched on first open.
        $this->assertStringContainsString('function loadStyles()', $js);
        // The sheet places the card, locks the page behind it and respects reduced motion.
        $this->assertStringContainsString('login-modal-open', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $css);
        $this->assertStringContainsString('overflow-y: auto', $css);
    }
}
