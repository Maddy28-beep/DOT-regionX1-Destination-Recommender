<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two small protections:
 *  - sign-ins and the trip builder are rate limited, so a password cannot be guessed
 *    thousands of times a minute and the recommendation pipeline cannot be hammered;
 *  - a closed place is marked on its listing card, not only on its own page.
 */
class RateLimitAndClosedChipTest extends TestCase
{
    use RefreshDatabase;

    private function badLogin(string $who = 'nobody@example.test'): \Illuminate\Testing\TestResponse
    {
        return $this->post('/portal/login', ['portal' => 'establishment', 'identifier' => $who, 'password' => 'wrong-password']);
    }

    public function test_the_sixth_failed_sign_in_for_one_account_is_blocked_with_a_friendly_message(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->badLogin()->assertSessionMissing('error');
        }

        $this->badLogin()->assertRedirect()->assertSessionHas('error', 'Please slow down');
    }

    public function test_a_script_gets_a_429_with_retry_after(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/portal/login', ['portal' => 'establishment', 'identifier' => 'a@example.test', 'password' => 'x']);
        }

        $this->postJson('/portal/login', ['portal' => 'establishment', 'identifier' => 'a@example.test', 'password' => 'x'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_blocking_one_account_does_not_block_a_different_one(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->badLogin('first@example.test');
        }

        $this->badLogin('second@example.test')->assertSessionMissing('error');
    }

    public function test_the_trip_builder_is_rate_limited_but_ordinary_use_is_not(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->post('/plan', [])->assertSessionMissing('error'); // an empty form fails validation, which still counts
        }

        $this->post('/plan', [])->assertSessionHas('error', 'Please slow down');
    }

    public function test_a_closed_place_is_marked_on_its_listing_card(): void
    {
        $region = Region::create(['name' => 'Davao City']);
        $make = fn (string $name, array $extra = []) => Destination::create($extra + [
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $region->id,
            'type' => 'Nature & Leisure', 'is_accredited' => true,
        ]);
        $make('Open Park');
        $make('Repair Park', ['operating_status' => 'temporarily_closed', 'reopens_on' => now()->addMonth()->toDateString()]);
        $make('Gone Park', ['operating_status' => 'closed']);
        $make('Undated Park', ['operating_status' => 'temporarily_closed']);

        $html = $this->get('/destinations')->assertOk()->getContent();

        $this->assertSame(3, substr_count($html, 'data-closed='));
        $this->assertStringContainsString('data-closed="Closed until '.now()->addMonth()->format('M j').'"', $html);
        $this->assertStringContainsString('data-closed="Closed"', $html);
        $this->assertStringContainsString('data-closed="Temporarily closed"', $html);
    }

    public function test_the_chip_label_follows_the_reopening_date(): void
    {
        $region = Region::create(['name' => 'Davao City']);
        $place = Destination::create([
            'slug' => 'late-park', 'name' => 'Late Park', 'location' => 'Davao City', 'region_id' => $region->id,
            'type' => 'Nature & Leisure', 'is_accredited' => true,
            'operating_status' => 'temporarily_closed', 'reopens_on' => now()->subDay()->toDateString(),
        ]);

        $this->assertNull($place->operatingBadge(), 'a reopening date in the past means the place is open again');
    }
}
