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

    public function test_policy_describes_the_private_link_and_no_longer_mentions_a_traveler_account(): void
    {
        $keptDays = config('privacy.kept_retention_days');

        $html = $this->get(route('legal.privacy'))->assertOk()->getContent();
        $text = preg_replace('/[\s\x{00A0}]+/u', ' ', html_entity_decode(strip_tags($html)));

        $this->assertStringContainsString('Keeping a plan with a private link', $text);
        $this->assertStringContainsString("deleted automatically {$keptDays} days after it was last opened", $text);
        $this->assertStringContainsString('only a one-way hash', $text);
        $this->assertStringNotContainsString('Optional traveler account', $text);
        $this->assertStringNotContainsString('alias and password', $text);
        $this->assertStringNotContainsString('alias-based account', $text);
    }
}
