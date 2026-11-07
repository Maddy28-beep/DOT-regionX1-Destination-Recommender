<?php

namespace Tests\Feature;

use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Culinary Highlights on the landing page: six cards, each one link to that area's dining list, with the dishes as chips. */
class CulinaryHighlightsTest extends TestCase
{
    use RefreshDatabase;

    private function section(): string
    {
        $html = $this->get('/')->assertOk()->getContent();
        $start = strpos($html, 'id="culinary-highlights"');

        $this->assertNotFalse($start);

        return substr($html, $start, strpos($html, '</section>', $start) - $start);
    }

    public function test_each_card_is_one_link_to_that_areas_dining_list(): void
    {
        foreach (['Davao City', 'Davao Occidental', 'Davao del Sur', 'Davao del Norte', 'Davao de Oro', 'Davao Oriental'] as $name) {
            Region::create(['name' => $name]);
        }

        $section = $this->section();

        $this->assertSame(6, substr_count($section, '<a class="culinary__card"'), 'six links');
        $this->assertStringNotContainsString('<article class="culinary__card"', $section, 'not a div or article with a click handler');

        foreach (['Davao City', 'Davao Occidental', 'Davao del Sur', 'Davao del Norte', 'Davao de Oro', 'Davao Oriental'] as $name) {
            $regionId = Region::where('name', $name)->value('id');
            $this->assertStringContainsString('restaurants?region_id='.$regionId, $section, $name);
            $this->assertStringContainsString('<h3>'.$name.'</h3>', $section);
        }
    }

    public function test_the_dishes_are_chips_in_groups_of_three_not_a_sentence(): void
    {
        $section = $this->section();

        $this->assertSame(6, substr_count($section, '<ul class="culinary__chips">'));
        $this->assertSame(18, substr_count($section, '<li>'), 'three dishes on each of the six cards');

        foreach (['Chocolate', 'Tropical fruits', 'Grilled tuna panga', 'Davao coffee', 'Bibingka', 'Nilotlot', 'Dumang', 'Empanada', 'Lobster'] as $dish) {
            $this->assertStringContainsString('<li>'.$dish.'</li>', $section, $dish);
        }

        $this->assertStringNotContainsString('Chocolate, tropical fruits', $section, 'no comma-separated sentence');
    }

    public function test_the_photo_area_carries_no_badge_or_overlay_text(): void
    {
        $section = $this->section();

        preg_match_all('/<div class="culinary__photo">(.*?)<\/div>/s', $section, $photos);

        $this->assertCount(6, $photos[1]);

        foreach ($photos[1] as $photo) {
            $this->assertSame('', trim(strip_tags($photo)), 'nothing but the picture in the photo area');
        }
    }
}
