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

    // ---- stays and dining use the same layout

    private function stay(array $extra = []): Accommodation
    {
        return Accommodation::create($extra + [
            'slug' => 'a-hotel', 'name' => 'A Hotel', 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Hotel', 'dot_classification' => '4-star', 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0, 'price_tier' => 'Premium',
        ]);
    }

    public function test_a_stay_shows_price_per_night_and_a_plan_your_stay_card_with_check_in_and_out(): void
    {
        $stay = $this->stay([
            'price_per_night' => 3500, 'distance_km' => 2.5, 'check_in' => '2:00 PM', 'check_out' => '12:00 NN',
            'latitude' => 7.07, 'longitude' => 125.61,
        ]);

        $html = $this->get(route('accommodations.show', $stay))->assertOk()->getContent();

        $this->assertStringContainsString('stat-strip', $html);
        $this->assertStringContainsString('Per night', $html);
        $this->assertStringContainsString('3,500', $html);
        $this->assertStringContainsString('2.5 km', $html);
        $this->assertSame(3, substr_count($html, 'class="is-on"'), 'Premium lights all three peso signs.');
        $this->assertStringContainsString('Plan your stay', $html);
        $this->assertStringContainsString('2:00 PM', $html);
        $this->assertStringContainsString('12:00 NN', $html);
        $this->assertStringContainsString('visit-card__map', $html);
        $this->assertStringContainsString('Property type', $html);
        $this->assertStringContainsString('<span class="good-for__chip">4-star</span>', $html);
    }

    public function test_a_stay_with_no_check_in_times_does_not_invent_any(): void
    {
        $stay = $this->stay();

        $html = $this->get(route('accommodations.show', $stay))->assertOk()->getContent();

        $this->assertStringNotContainsString('2:00 PM', $html);
        $this->assertStringNotContainsString('12:00 PM', $html);
        $this->assertSame(2, substr_count($html, 'Not listed'));
    }

    public function test_a_restaurant_shows_cuisine_contact_and_an_hours_pill(): void
    {
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-10-09 12:00', \App\Support\OpeningHours::TIMEZONE));

        $eatery = \App\Models\Restaurant::create([
            'slug' => 'a-diner', 'name' => 'A Diner', 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'cuisine_type' => 'Filipino Seafood', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0,
            'price_tier' => 'Mid-range', 'opening_hours' => '8:00 AM–9:00 PM', 'contact_number' => '(082) 123 4567',
        ]);

        $html = $this->get(route('restaurants.show', $eatery))->assertOk()->getContent();
        \Illuminate\Support\Carbon::setTestNow();

        $this->assertStringContainsString('stat-strip', $html);
        $this->assertStringContainsString('Cuisine', $html);
        $this->assertStringContainsString('(082) 123 4567', $html);
        $this->assertStringContainsString('Plan your visit', $html);
        $this->assertStringContainsString('Open now', $html);
        $this->assertStringContainsString('Filipino Seafood', $html);
        $this->assertStringNotContainsString('good-for__chip', $html, 'Cuisine is in the strip; it is not repeated as a chip.');
    }

    public function test_a_restaurant_without_a_phone_number_has_no_contact_tile(): void
    {
        $eatery = \App\Models\Restaurant::create([
            'slug' => 'a-diner', 'name' => 'A Diner', 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Budget-Friendly',
        ]);

        $html = $this->get(route('restaurants.show', $eatery))->assertOk()->getContent();

        $this->assertStringNotContainsString('stat-strip__label">Contact', $html);
        $this->assertStringNotContainsString('Open now', $html);
        $this->assertStringNotContainsString('Closed now', $html);
    }

    // ---- packages, tour operators and souvenir centers

    private function region(): int
    {
        return Region::firstOrCreate(['name' => 'Davao City'])->id;
    }

    public function test_a_package_with_a_schedule_offers_to_plan_with_it_and_links_its_provider(): void
    {
        $operator = \App\Models\TourOperator::create([
            'slug' => 'op', 'name' => 'Island Explorers', 'location' => 'Samal', 'region_id' => $this->region(),
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0,
        ]);
        $package = \App\Models\Package::create([
            'slug' => 'trek', 'name' => 'Summit Trek', 'location' => 'Davao del Sur', 'region_id' => $this->region(),
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_per_pax' => 6500,
            'duration_label' => '3 Days, 2 Nights', 'price_tier' => 'Premium', 'type' => 'Adventure',
            'tour_operator_id' => $operator->id,
        ]);
        $package->itineraryDays()->create(['day_number' => 1, 'title' => 'Trailhead']);

        $html = $this->get(route('packages.show', $package))->assertOk()->getContent();

        $this->assertStringContainsString('6,500', $html);
        $this->assertStringContainsString('3 Days, 2 Nights', $html);
        $this->assertStringContainsString('Plan this package', $html);
        $this->assertStringContainsString('Plan with this Package', $html);
        $this->assertStringContainsString(route('packages.plan-with', $package), $html);
        $this->assertStringContainsString('href="'.route('tour-operators.show', $operator).'"', $html);
        $this->assertStringNotContainsString('save-form--square', $html, 'Packages are booked, not saved.');
    }

    public function test_a_package_without_a_schedule_says_so_and_points_to_plan_my_trip(): void
    {
        $package = \App\Models\Package::create([
            'slug' => 'trek', 'name' => 'Summit Trek', 'location' => 'Davao del Sur', 'region_id' => $this->region(),
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'provider_name' => 'Local guides',
        ]);

        $html = $this->get(route('packages.show', $package))->assertOk()->getContent();

        $this->assertStringContainsString("hasn't published a day-by-day schedule", str_replace('&#039;', "'", $html));
        $this->assertStringContainsString(route('plan.edit'), $html);
        $this->assertStringContainsString('Local guides', $html);
    }

    public function test_a_tour_operator_shows_its_package_count_and_a_tap_to_call_number(): void
    {
        $operator = \App\Models\TourOperator::create([
            'slug' => 'op', 'name' => 'Island Explorers', 'location' => 'Samal', 'region_id' => $this->region(),
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'specialization' => 'Beach & Island',
            'contact_number' => '0917-555-0101',
        ]);

        $html = $this->get(route('tour-operators.show', $operator))->assertOk()->getContent();

        $this->assertStringContainsString('0 packages', $html);
        $this->assertStringContainsString('Get in touch', $html);
        $this->assertStringContainsString('href="tel:09175550101"', $html);
        $this->assertStringNotContainsString('save-form--square', $html);
    }

    public function test_a_souvenir_center_gets_the_visit_card_with_a_save_heart(): void
    {
        $shop = \App\Models\SouvenirCenter::create([
            'slug' => 'shop', 'name' => 'Local Products', 'location' => 'Davao City', 'region_id' => $this->region(),
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0,
        ]);

        $html = $this->get(route('souvenir-centers.show', $shop))->assertOk()->getContent();

        $this->assertStringContainsString('Plan your visit', $html);
        $this->assertStringContainsString('Get Directions', $html);
        $this->assertSame(1, substr_count($html, 'save-form--square'));
        $this->assertStringContainsString('reviews-card', $html);
    }

    // ---- the destination card

    public function test_a_new_destination_card_has_a_new_tag_and_no_rating_row(): void
    {
        $this->place(['distance_km' => 28, 'entry_fee_min' => 150, 'entry_fee_max' => 150]);

        $html = $this->get('/destinations')->assertOk()->getContent();

        $this->assertStringContainsString('<span class="dcard__new">New</span>', $html);
        $this->assertStringNotContainsString('dcard__rating', $html);
        $this->assertStringContainsString('28 km from city', $html);
        $this->assertStringContainsString('DOT accredited', $html);
    }

    public function test_a_reviewed_destination_card_shows_rating_and_count_instead_of_the_new_tag(): void
    {
        $this->place(['rating' => 4.8, 'review_count' => 23]);

        $html = $this->get('/destinations')->assertOk()->getContent();

        $this->assertStringContainsString('<strong>4.8</strong>', $html);
        $this->assertStringContainsString('<span>(23)</span>', $html);
        $this->assertStringNotContainsString('dcard__new', $html);
    }

    public function test_the_card_does_not_repeat_the_region_when_the_location_already_names_it(): void
    {
        $this->place(['location' => 'Malagos, Baguio District, Davao City']);

        $html = $this->get('/destinations')->assertOk()->getContent();

        $this->assertStringContainsString('<span>Malagos, Baguio District, Davao City</span>', $html);
    }
}
