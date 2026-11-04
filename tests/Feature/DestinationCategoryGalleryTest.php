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

    public function test_destination_photos_show_as_a_main_photo_with_thumbnails_and_no_type_labels(): void
    {
        $place = $this->destination();
        foreach (['Interior', 'General', 'Exterior', 'General'] as $i => $category) {
            $place->photos()->create(['path' => 'photo-'.$i.'.jpg', 'category' => $category]);
        }
        $this->get('/destinations/example-park')->assertOk()
            ->assertDontSee('gallery-categorized', false)
            ->assertDontSee('photo not available')
            ->assertDontSee('gallery-cats', false)
            ->assertSee('class="g-main"', false)
            ->assertSee('+1 more')
            ->assertSee('Entry fee')->assertSee('Plan your visit');
    }

    public function test_destinations_without_photos_retain_the_illustrated_hero(): void
    {
        $this->destination();
        $this->get('/destinations/example-park')->assertOk()->assertSee('dest-detail-hero', false);
    }
}
