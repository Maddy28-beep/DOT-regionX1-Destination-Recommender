<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureVisitorToken;
use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\DestinationTag;
use App\Models\Region;
use App\Models\Review;
use App\Models\TouristVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The destination page's main column: the three-fact strip, the About card with its "Good for" and
 * "Amenities on site" chips, and the Traveler Reviews card with its star-rating box.
 */
class DestinationDetailRedesignTest extends TestCase
{
    use RefreshDatabase;

    private function place(array $extra = []): Destination
    {
        return Destination::create($extra + [
            'slug' => 'eden-nature-park', 'name' => 'Eden Nature Park', 'location' => 'Toril, Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id, 'type' => 'Nature & Adventure',
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
            'description' => 'A mountain park above the city.',
        ]);
    }

    private function tag(Destination $place, string $kind, string $value): void
    {
        DestinationTag::create(['destination_id' => $place->id, 'kind' => $kind, 'value' => $value]);
    }

    private function page(Destination $place, ?string $token = null): string
    {
        $request = $token ? $this->withCookie(EnsureVisitorToken::COOKIE, $token) : $this;

        return $request->get(route('destinations.show', $place))->assertOk()->getContent();
    }

    // ---- the stat strip

    public function test_the_strip_shows_the_tier_with_its_band_the_entry_fee_range_and_the_distance(): void
    {
        $place = $this->place(['entry_fee_min' => 150, 'entry_fee_max' => 400, 'distance_km' => 7.5]);

        $html = $this->page($place);

        $this->assertStringContainsString('stat-strip', $html);
        $this->assertStringContainsString('Mid-range', $html);
        $this->assertSame(2, substr_count($html, 'class="is-on"'), 'Mid-range lights two of the three peso signs.');
        $this->assertStringContainsString('150–400', $html);
        $this->assertStringContainsString('7.5 km', $html);
    }

    public function test_free_is_only_claimed_when_the_fee_is_recorded_as_zero(): void
    {
        $free = $this->page($this->place(['entry_fee_min' => 0, 'entry_fee_max' => 0]));
        $this->assertMatchesRegularExpression('/Entry fee<\/span>\s*<span class="stat-strip__value">\s*<span class="stat-strip__text">\s*Free/', $free);

        $unknown = $this->page($this->place(['slug' => 'other', 'name' => 'Other Park', 'entry_fee_min' => null, 'entry_fee_max' => null]));
        $this->assertDoesNotMatchRegularExpression('/Entry fee<\/span>\s*<span class="stat-strip__value">\s*<span class="stat-strip__text">\s*Free/', $unknown);
    }

    // ---- the About card

    public function test_good_for_lists_categories_and_amenities_open_a_viewer(): void
    {
        $place = $this->place();
        $this->tag($place, 'category', 'Wildlife');
        $this->tag($place, 'amenity', 'Parking Area');
        $this->tag($place, 'amenity', 'Wi-Fi');

        $html = $this->page($place);

        $this->assertStringContainsString('Good for', $html);
        $this->assertStringContainsString('<span class="good-for__chip">Wildlife</span>', $html);
        $this->assertStringContainsString('Amenities on site', $html);
        $this->assertSame(2, substr_count($html, 'class="amenity-chip"'));
        $this->assertSame(2, substr_count($html, 'data-open='));
        $this->assertStringContainsString('data-lightbox-el', $html);
    }

    public function test_a_place_with_no_tags_shows_neither_group(): void
    {
        $html = $this->page($this->place());

        $this->assertStringContainsString('A mountain park above the city.', $html);
        $this->assertStringNotContainsString('Good for', $html);
        $this->assertStringNotContainsString('Amenities on site', $html);
        $this->assertStringNotContainsString('data-lightbox-el', $html);
    }

    // ---- reviews

    public function test_the_empty_state_and_count_pill(): void
    {
        $html = $this->page($this->place());

        $this->assertStringContainsString('0 reviews', $html);
        $this->assertStringContainsString('No reviews yet', $html);
    }

    public function test_the_count_pill_is_singular_for_one_review_and_the_review_is_listed(): void
    {
        $place = $this->place();
        Review::create([
            'listing_kind' => 'destination', 'listing_id' => $place->id, 'rating' => 4,
            'comment' => 'Cool air, good trails.', 'author_name' => 'Verified visitor', 'source' => 'qr',
        ]);

        $html = $this->page($place->fresh());

        $this->assertStringContainsString('1 review<', str_replace('</span>', '<', $html));
        $this->assertStringContainsString('Cool air, good trails.', $html);
        $this->assertStringNotContainsString('No reviews yet', $html);
    }

    public function test_a_visitor_who_checked_in_gets_star_inputs_not_a_dropdown(): void
    {
        $place = $this->place();
        $token = (string) Str::uuid();
        TouristVisit::create([
            'visitor_token' => $token, 'listing_kind' => 'destination', 'listing_id' => $place->id,
            'visit_date' => now()->toDateString(), 'source' => 'qr',
        ]);

        $html = $this->page($place, $token);

        $this->assertSame(5, substr_count($html, 'type="radio" name="rating"'));
        $this->assertStringContainsString('Tap a star to rate', $html);
        $this->assertStringContainsString('0/500', $html);
        $this->assertStringContainsString('Post my review', $html);
        $this->assertStringNotContainsString('<select id="rating-', $html);
    }

    public function test_the_same_reviews_card_is_used_on_the_other_listing_types(): void
    {
        $stay = Accommodation::create([
            'slug' => 'a-hotel', 'name' => 'A Hotel', 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Hotel', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0,
        ]);

        $html = $this->get(route('accommodations.show', $stay))->assertOk()->getContent();

        $this->assertStringContainsString('reviews-card', $html);
        $this->assertStringContainsString('0 reviews', $html);
        $this->assertStringContainsString('Scan the DOT QR code', $html);
    }

    public function test_other_listing_pages_put_the_title_on_the_photo_not_under_it(): void
    {
        $stay = Accommodation::create([
            'slug' => 'a-hotel', 'name' => 'A Hotel', 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Hotel', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0,
        ]);
        $stay->photos()->create(['path' => 'hotel.jpg', 'category' => 'Exterior', 'is_primary' => true]);

        $html = $this->get(route('accommodations.show', $stay))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'class="gallery-hero__title"'), 'Once for the desktop grid and once for the phone carousel.');
        $this->assertSame(2, substr_count($html, '<h1>A Hotel</h1>'));
        $this->assertStringNotContainsString('class="poster-title" style="margin:20px 0 4px', $html);
    }
}
