<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\DestinationTag;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A destination card says what kind of place it is. Facilities (Restroom, Parking Area) are not that, and a
 * destination with no category tag falls back to its own type instead of showing nothing.
 */
class DestinationCardTagsTest extends TestCase
{
    use RefreshDatabase;

    private function destination(string $name, string $type, array $tags = []): Destination
    {
        $d = Destination::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => $type, 'is_accredited' => true, 'rating' => 4.0, 'review_count' => 1, 'price_tier' => 'Budget-Friendly',
        ]);

        foreach ($tags as [$kind, $value]) {
            DestinationTag::create(['destination_id' => $d->id, 'kind' => $kind, 'value' => $value]);
        }

        return $d->load('tags');
    }

    public function test_category_tags_are_the_chips(): void
    {
        $d = $this->destination('Island A', 'Beach & Leisure', [['amenity', 'Restroom'], ['category', 'Beach'], ['category', 'Island Hopping'], ['category', 'Family Friendly']]);

        $this->assertSame(['Beach', 'Island Hopping'], $d->posterTags());
    }

    public function test_facilities_never_become_chips(): void
    {
        $d = $this->destination('Park B', 'Nature & Leisure', [['amenity', 'Restroom'], ['amenity', 'Parking Area']]);

        $this->assertSame(['Nature & Leisure'], $d->posterTags());
    }

    public function test_a_destination_with_no_tags_shows_its_type(): void
    {
        $d = $this->destination('Museum C', 'Cultural Heritage');

        $this->assertSame(['Cultural Heritage'], $d->posterTags());
    }

    public function test_the_catalog_card_shows_the_type_for_an_untagged_destination(): void
    {
        $this->destination('Plain Falls', 'Nature & Adventure');

        $this->get(route('destinations.index'))->assertOk()->assertSee('Plain Falls')->assertSee('dpost-tag">Nature &amp; Adventure', false);
    }
}
