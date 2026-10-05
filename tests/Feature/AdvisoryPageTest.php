<?php

namespace Tests\Feature;

use App\Models\Advisory;
use App\Models\Destination;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The /advisories hub: level tiles that double as filters, cards with the facts a traveller
 * needs (where, until when, who issued it), and an all-clear state.
 */
class AdvisoryPageTest extends TestCase
{
    use RefreshDatabase;

    private function advisory(string $title, string $severity, array $extra = []): Advisory
    {
        return Advisory::create(['title' => $title, 'message' => 'Details here.', 'severity' => $severity] + $extra);
    }

    public function test_the_page_summarises_how_many_notices_are_active(): void
    {
        $this->advisory('Mt. Apo closed', 'danger');
        $this->advisory('Heavy rain', 'warning');

        $this->get('/advisories')->assertOk()
            ->assertSee('2 active notices')
            ->assertSee('Last updated')
            ->assertSee('Showing all 2');
    }

    public function test_every_level_has_a_tile_even_when_nothing_is_posted_at_it(): void
    {
        $this->advisory('Mt. Apo closed', 'danger');

        $html = $this->get('/advisories')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/advisory-tile__count">1</', $html);
        $this->assertSame(2, substr_count($html, 'advisory-tile__count">0<'));
        $this->assertStringContainsString('advisory-tile--info', $html);
    }

    public function test_a_tile_filters_by_level_and_offers_to_clear(): void
    {
        $this->advisory('Mt. Apo closed', 'danger');
        $this->advisory('Heavy rain', 'warning');

        $this->get('/advisories?severity=warning')->assertOk()
            ->assertSee('advisory-card--warning', false)
            ->assertDontSee('advisory-card--danger', false)
            ->assertSee('show all 2');
    }

    public function test_a_card_shows_where_until_when_and_who_issued_it(): void
    {
        $this->advisory('Island trips delayed', 'warning', ['ends_at' => now()->addDays(3)->toDateString()]);
        $this->advisory('Mt. Apo closed', 'danger');

        $this->get('/advisories')->assertOk()
            ->assertSee('All of the Davao Region')
            ->assertSee('Until '.now()->addDays(3)->format('M j'))
            ->assertSee('Until further notice')
            ->assertSee('DOT Region XI');
    }

    public function test_a_place_specific_advisory_links_to_that_place(): void
    {
        $region = Region::create(['name' => 'Davao City']);
        $destination = Destination::create([
            'slug' => 'mt-apo', 'name' => 'Mount Apo', 'location' => 'Davao del Sur', 'region_id' => $region->id,
            'type' => 'Nature', 'is_accredited' => true, 'rating' => 4, 'review_count' => 1, 'price_tier' => 'Mid-range',
        ]);
        $this->advisory('Trail closed', 'danger', ['listing_kind' => 'destination', 'listing_id' => $destination->id]);

        $this->get('/advisories')->assertOk()
            ->assertSee('View Mount Apo')
            ->assertSee(route('destinations.show', $destination), false);
    }

    public function test_with_nothing_posted_the_page_says_all_clear(): void
    {
        $this->get('/advisories')->assertOk()
            ->assertSee('All clear for now')
            ->assertDontSee('advisory-tiles', false);
    }
}
