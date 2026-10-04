<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use App\Models\SavedListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The header's three actions: a quiet Log in link, an icon-only Saved heart
 * carrying the number of saved places, and the one solid Plan My Trip button.
 */
class HeaderActionsTest extends TestCase
{
    use RefreshDatabase;

    private function saveForToken(string $token, int $times): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        for ($i = 0; $i < $times; $i++) {
            $destination = Destination::create([
                'slug' => "spot-{$i}", 'name' => "Spot {$i}", 'location' => 'Davao City', 'region_id' => $region->id,
                'type' => 'Nature', 'is_accredited' => true, 'rating' => 4, 'review_count' => 1, 'price_tier' => 'Mid-range',
            ]);
            SavedListing::create(['visitor_token' => $token, 'listing_kind' => 'destination', 'listing_id' => $destination->id, 'saved_at' => now()]);
        }
    }

    public function test_the_header_has_the_heart_the_login_link_and_the_plan_button(): void
    {
        $this->get('/destinations')->assertOk()
            ->assertSee('class="header-saved"', false)
            ->assertSee('Log in')
            ->assertSee('Plan My Trip');
    }

    public function test_the_count_badge_is_hidden_until_something_is_saved(): void
    {
        $html = $this->get('/destinations')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-saved-count\s+hidden\s*>0</', $html);
        $this->assertStringContainsString('aria-label="Saved places"', $html);
    }

    public function test_the_badge_shows_how_many_places_this_browser_has_saved(): void
    {
        $token = (string) Str::uuid();
        $this->saveForToken($token, 3);

        $html = $this->withCookie('visitor_token', $token)->get('/destinations')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-saved-count\s*>3</', $html);
        $this->assertStringContainsString('aria-label="Saved places, 3 saved"', $html);
    }

    public function test_another_browsers_saved_places_are_not_counted(): void
    {
        $this->saveForToken((string) Str::uuid(), 2);

        $html = $this->withCookie('visitor_token', (string) Str::uuid())->get('/destinations')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-saved-count\s+hidden\s*>0</', $html);
    }

    public function test_plan_my_trip_keeps_the_primary_green_button_class(): void
    {
        $this->get('/destinations')->assertOk()->assertSee('btn btn-primary header-plan', false);
    }
}
