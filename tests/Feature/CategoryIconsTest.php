<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use App\Support\CategoryIcons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Each category chip on the listing pages has an icon chosen from the words in its name. */
class CategoryIconsTest extends TestCase
{
    use RefreshDatabase;

    public function test_names_map_to_fitting_icons(): void
    {
        foreach ([
            'All Types' => 'grid', 'All Cuisines' => 'grid',
            'Nature & Adventure' => 'mountain', 'Beach & Leisure' => 'umbrella', 'Culture & Heritage' => 'landmark',
            'Cultural Heritage' => 'landmark', 'Wellness & Spa' => 'heart', 'Adventure & Hiking' => 'boot', 'Beach & Surfing' => 'wind',
            'Events & Conventions' => 'calendar', 'Farm Tourism' => 'leaf',
            'Hotel' => 'building', 'Resort' => 'pool', 'Beach Resort' => 'umbrella', 'Homestay' => 'home', 'Condominium Unit' => 'key',
            'Mountain Resort' => 'mountain', 'Island Resort' => 'umbrella', 'Apartment Hotel' => 'building',
            'Tourist Transport' => 'van', 'General Travel & Tours' => 'compass', 'MICE Organizer' => 'briefcase',
        ] as $label => $icon) {
            $this->assertSame($icon, CategoryIcons::for($label), $label);
        }
    }

    public function test_all_inclusive_is_a_kind_of_stay_not_the_reset_chip(): void
    {
        $this->assertSame('sparkles', CategoryIcons::for('All Inclusive Resort'));
    }

    public function test_a_keyword_is_found_at_the_start_of_a_word_only(): void
    {
        $this->assertSame('tag', CategoryIcons::for('Dining Experience'), '"inn" is not found inside "dining"');
    }

    public function test_an_unknown_name_gets_the_pages_fallback(): void
    {
        $this->assertSame('tag', CategoryIcons::for('Zzz'));
        $this->assertSame('utensils', CategoryIcons::for('Zzz', 'utensils'));
        $this->assertSame('utensils', CategoryIcons::for('Japanese', 'utensils'));
    }

    public function test_every_icon_named_exists_in_the_icon_set(): void
    {
        $set = file_get_contents(resource_path('views/components/icon.blade.php'));
        $source = file_get_contents(app_path('Support/CategoryIcons.php'));
        preg_match_all("/=> '([a-z-]+)'/", $source, $m);

        foreach (array_unique($m[1]) as $icon) {
            $this->assertStringContainsString("@case('{$icon}')", $set, "{$icon} is an icon the site has");
        }
    }

    public function test_the_chips_on_a_listing_page_carry_their_icons(): void
    {
        // A chip needs three places behind it.
        foreach ([1, 2, 3] as $n) {
            Destination::create([
                'slug' => "a-beach-$n", 'name' => "A Beach $n", 'location' => 'Davao City', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
                'type' => 'Beach & Leisure', 'is_accredited' => true, 'rating' => 4, 'review_count' => 1, 'price_tier' => 'Mid-range',
            ]);
        }

        $html = $this->get(route('destinations.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/class="chip[^"]*"[^>]*>\s*<svg[^>]*chip__icon/s', $html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'chip__icon'));
    }
}
