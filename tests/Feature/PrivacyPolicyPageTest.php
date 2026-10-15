<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The policy has to keep matching what the system does (see config/privacy.php and the purge job). */
class PrivacyPolicyPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_states_the_retention_the_system_actually_applies(): void
    {
        $days = config('privacy.retention_days');
        $chatDays = config('privacy.chatbot_retention_days');

        $html = $this->get(route('legal.privacy'))->assertOk()->getContent();
        $text = preg_replace('/[\s\x{00A0}]+/u', ' ', html_entity_decode(strip_tags($html)));

        $this->assertStringContainsString("{$days} days after the plan was made", $text);
        $this->assertStringContainsString("deleted automatically after {$chatDays} days", $text);
        $this->assertStringContainsString('audit log', $text);
        $this->assertStringContainsString('National Privacy Commission', $text);
        $this->assertStringNotContainsString('not linked to your trip plan', $text, 'the exit survey can be tied to the same-session trip plan');
    }
}
