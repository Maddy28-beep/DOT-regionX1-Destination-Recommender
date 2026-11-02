<?php

namespace Tests\Feature;

use App\Models\ListingPhoto;
use Tests\TestCase;

class AccommodationGalleryLayoutTest extends TestCase
{
    private function gallery(array $categories, bool $categorized = true, bool $keepOtherPhotos = false): string
    {
        return view('partials.gallery-hero', [
            'photos' => collect($categories)->map(fn ($category, $index) => new ListingPhoto([
                'id' => $index + 1, 'path' => 'listings/'.strtolower($category).'.jpg', 'category' => $category,
            ])),
            'categoryLayout' => $categorized, 'title' => 'Example Stay', 'subtitle' => 'Davao City',
            'keepOtherPhotos' => $keepOtherPhotos,
            'isAccredited' => false, 'rating' => '0.0', 'reviewCount' => 0, 'fallbackGradient' => '#075642',
        ])->render();
    }

    public function test_gallery_slots_use_their_categories_and_open_the_matching_photo(): void
    {
        $html = $this->gallery(['Interior', 'Food', 'Exterior', 'General']);
        $this->assertStringContainsString('data-category="General" data-open="0"', $html);
        $this->assertStringContainsString('data-category="Exterior" data-open="1"', $html);
        $this->assertStringContainsString('data-category="Interior" data-open="2"', $html);
        $this->assertStringNotContainsString('listings/food.jpg', $html);
        $this->assertStringContainsString('exterior.jpg', $html);
        $this->assertStringContainsString('interior.jpg', $html);
    }

    public function test_a_missing_category_is_not_filled_with_a_different_photo(): void
    {
        $html = $this->gallery(['General', 'Exterior']);
        $this->assertStringContainsString('Interior photo not available', $html);
        $this->assertStringNotContainsString('g-thumb-span', $html);
        $this->assertStringContainsString('data-category="Exterior" data-open="1"', $html);
    }

    public function test_other_galleries_keep_their_categories_and_thumbnail_indices(): void
    {
        $html = $this->gallery(['General', 'Food', 'Views'], false);
        $this->assertStringContainsString('listings/food.jpg', $html);
        $this->assertStringContainsString('listings/views.jpg', $html);
        $this->assertStringContainsString('class="g-thumb " data-open="1"', $html);
        $this->assertStringContainsString('class="g-thumb " data-open="2"', $html);
    }

    public function test_category_slots_can_preserve_food_and_other_photos_in_the_viewer(): void
    {
        $html = $this->gallery(['Food', 'Interior', 'General', 'Exterior'], true, true);
        $this->assertStringContainsString('listings/food.jpg', $html);
        $this->assertStringContainsString('data-category="General" data-open="0"', $html);
        $this->assertStringContainsString('data-category="Exterior" data-open="1"', $html);
        $this->assertStringContainsString('data-category="Interior" data-open="2"', $html);
        $this->assertStringContainsString('Food (1)', $html);
    }
}
