<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A public tunnel to this machine may reach the exit survey and nothing else.
 */
class RestrictTunnelToSurveyTest extends TestCase
{
    use RefreshDatabase;

    private const TUNNEL = 'https://calm-river-demo.trycloudflare.com';

    public function test_the_survey_is_reachable_through_a_tunnel(): void
    {
        $this->get(self::TUNNEL.'/exit-survey')->assertOk();
    }

    public function test_the_root_of_a_tunnel_goes_to_the_survey(): void
    {
        $this->get(self::TUNNEL.'/')->assertRedirect('/exit-survey');
    }

    public function test_admin_partner_account_and_browse_pages_are_hidden_from_a_tunnel(): void
    {
        foreach (['/portal/login', '/portal/admin', '/account/login', '/destinations', '/plan/start', '/advisories'] as $path) {
            $this->get(self::TUNNEL.$path)->assertNotFound();
        }
    }

    public function test_a_tunnel_cannot_post_anywhere_but_the_survey(): void
    {
        $this->post(self::TUNNEL.'/portal/login', ['email' => 'a@b.c', 'password' => 'x'])->assertNotFound();
        $this->post(self::TUNNEL.'/chatbot', ['message' => 'hi'])->assertNotFound();
    }

    public function test_a_survey_can_be_submitted_through_a_tunnel(): void
    {
        $this->post(self::TUNNEL.'/exit-survey', [
            'overall_rating' => 4, 'would_recommend' => 'Yes',
        ])->assertRedirect();

        $this->assertDatabaseHas('exit_surveys', ['overall_rating' => 4, 'data_source' => 'real']);
    }

    public function test_cloudflare_edge_headers_alone_also_mark_a_request_as_tunnelled(): void
    {
        $this->withHeaders(['Cf-Ray' => 'abc123-MNL', 'Cf-Connecting-Ip' => '203.0.113.9'])
            ->get('/portal/login')
            ->assertNotFound();
    }

    public function test_local_use_is_unaffected(): void
    {
        $this->get('/')->assertOk();
        $this->get('/destinations')->assertOk();
        $this->get('/portal/login')->assertOk();
    }

    public function test_debug_output_is_off_for_tunnelled_requests(): void
    {
        config(['app.debug' => true]);

        $this->get(self::TUNNEL.'/exit-survey')->assertOk();

        $this->assertFalse(config('app.debug'));
    }
}
