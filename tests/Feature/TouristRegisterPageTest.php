<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Create account page keeps its fields and rules and adds the live checks and the no-recovery notice. */
class TouristRegisterPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_keeps_its_fields_rules_and_the_no_recovery_notice(): void
    {
        $html = $this->get(route('account.register'))->assertOk()->getContent();

        foreach (['name="alias"', 'name="password"', 'name="password_confirmation"', 'minlength="8"', 'maxlength="30"', 'pattern="[A-Za-z0-9_\-]+"'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringContainsString('There is no password recovery.', $html);
        $this->assertStringContainsString('Create a free account', $html);
        $this->assertStringContainsString('js/register-checks.js', $html);
        $this->assertStringContainsString('data-alias-ok', $html);
        $this->assertStringContainsString('data-strength', $html);
        $this->assertStringContainsString(route('account.login'), $html);
    }

    public function test_a_failed_registration_still_shows_the_errors_and_keeps_the_alias(): void
    {
        $this->from(route('account.register'))->post(route('account.register'), ['alias' => 'sunrise_trekker', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertRedirect(route('account.register'));

        $html = $this->get(route('account.register'))->assertOk()->getContent();
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('value="sunrise_trekker"', $html);
    }
}
