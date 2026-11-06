<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The partner account request page: every field, option and rule survives the redesign. */
class EstablishmentRegisterPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_field_option_and_the_consent_rule_are_still_there(): void
    {
        $html = $this->get(route('portal.establishment.register'))->assertOk()->getContent();

        foreach (['business_name', 'listing_kind', 'claimed_accreditation_number', 'email', 'password', 'password_confirmation', 'contact_person', 'contact_number', 'terms_accepted'] as $name) {
            $this->assertStringContainsString('name="'.$name.'"', $html, $name);
        }
        foreach (['accommodation', 'restaurant', 'package', 'souvenir_center', 'tour_operator'] as $kind) {
            $this->assertStringContainsString('value="'.$kind.'"', $html, $kind);
        }
        $this->assertMatchesRegularExpression('/name="terms_accepted" value="1" required/', $html);
        $this->assertStringContainsString('minlength="8"', $html);
        $this->assertStringContainsString('Request a partner account', $html);
        $this->assertStringContainsString('does not issue accreditation itself', $html);
        $this->assertStringContainsString('What happens next', $html);
        $this->assertStringContainsString('Already approved?', $html);
    }

    public function test_a_failed_submission_keeps_what_was_typed_and_shows_the_errors(): void
    {
        $this->from(route('portal.establishment.register'))
            ->post(route('portal.establishment.register'), ['business_name' => 'Calinan Farm Stay', 'listing_kind' => 'accommodation', 'email' => 'not-an-email'])
            ->assertRedirect(route('portal.establishment.register'));

        $html = $this->get(route('portal.establishment.register'))->assertOk()->getContent();
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('value="Calinan Farm Stay"', $html);
    }
}
