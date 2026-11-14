<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The listing grids are 1, 2, 3 or 4 cards wide, so a page of 12 always ends on a full row. */
class ListingPageSizeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_listing_page_holds_twelve_cards(): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        for ($i = 1; $i <= 13; $i++) {
            Destination::create([
                'slug' => "place-$i", 'name' => "Place $i", 'location' => 'Davao City', 'region_id' => $region->id,
                'type' => 'Beach & Leisure', 'is_accredited' => true, 'rating' => 4, 'review_count' => 1, 'price_tier' => 'Mid-range',
            ]);
        }

        $page = $this->get(route('destinations.index'))->assertOk()->original->getData()['destinations'];

        $this->assertSame(12, $page->perPage());
        $this->assertCount(12, $page->items());
        $this->assertSame(2, $page->lastPage());
        $this->assertSame(0, 12 % 4 + 12 % 3 + 12 % 2, 'twelve fills a row of 2, 3 or 4');
    }
}
