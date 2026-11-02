<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestinationCategoryGalleryTest extends TestCase
{
    use RefreshDatabase;

    private function destination(): Destination
    {
        return Destination::create([
            'name' => 'Example Park', 'slug' => 'example-park', 'location' => 'Davao City',
            'region_id' => Region::create(['name' => 'Davao City'])->id,
            'type' => 'Nature & Adventure', 'is_accredited' => true,
        ]);
    }

    public function test_destination_photos_use_the_same_category_slots_as_stays(): void
    {
        $place = $this->destination();
        foreach (['Interior', 'General', 'Exterior'] as $category) {
            $place->photos()->create(['path' => strtolower($category).'.jpg', 'category' => $category]);
        }
        $this->get('/destinations/example-park')->assertOk()
            ->assertSee('gallery-categorized', false)
            ->assertSee('data-category="General" data-open="0"', false)
            ->assertSee('data-category="Exterior" data-open="1"', false)
            ->assertSee('data-category="Interior" data-open="2"', false)
            ->assertSee('Entry fee')->assertSee('Plan your visit');
    }

    public function test_destinations_without_photos_retain_the_illustrated_hero(): void
    {
        $this->destination();
        $this->get('/destinations/example-park')->assertOk()->assertSee('dest-detail-hero', false);
    }
}
