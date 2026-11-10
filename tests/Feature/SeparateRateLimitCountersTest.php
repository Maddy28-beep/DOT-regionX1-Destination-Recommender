<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Post-trial prep (4): each throttled route keeps its own counter, so chatbot traffic cannot use up the QR check-in allowance. */
class SeparateRateLimitCountersTest extends TestCase
{
    use RefreshDatabase;

    public function test_chatbot_messages_do_not_use_up_the_check_in_allowance(): void
    {
        config(['services.groq.key' => null]);
        for ($i = 0; $i < 25; $i++) {
            $this->postJson('/chatbot/message', ['message' => 'hello'])->assertOk();
        }

        // Before: the 25 messages above had already pushed this address past the shared counter's 20, so this was a 429.
        $this->get('/check-in/destination/999999')->assertStatus(404);
    }

    public function test_the_chatbot_still_stops_at_30_a_minute(): void
    {
        config(['services.groq.key' => null]);
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/chatbot/message', ['message' => 'hello'])->assertOk();
        }

        $this->postJson('/chatbot/message', ['message' => 'hello'])->assertStatus(429);
    }

    public function test_check_in_still_stops_at_20_a_minute(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->get('/check-in/destination/999999')->assertStatus(404);
        }

        $this->get('/check-in/destination/999999')->assertStatus(429);
    }
}
