<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The policy must describe what the chatbot actually does with a question. */
class PrivacyPolicyChatbotTest extends TestCase
{
    use RefreshDatabase;

    public function test_with_no_outside_ai_key_the_policy_says_nothing_leaves_the_site(): void
    {
        config(['services.groq.key' => null]);

        $this->get('/privacy-policy')->assertOk()->assertSee('does not')->assertDontSee('Groq');
    }

    public function test_with_an_outside_ai_key_the_policy_names_the_service(): void
    {
        config(['services.groq.key' => 'test-key']);

        $this->get('/privacy-policy')->assertOk()->assertSee('Groq')->assertSee('outside the Philippines');
    }

    public function test_the_address_search_provider_named_in_the_policy_follows_the_key(): void
    {
        config(['services.geoapify.key' => null]);
        $this->get('/privacy-policy')->assertDontSee('Geoapify');

        config(['services.geoapify.key' => 'test-key']);
        $this->get('/privacy-policy')->assertSee('Geoapify');
    }
}
