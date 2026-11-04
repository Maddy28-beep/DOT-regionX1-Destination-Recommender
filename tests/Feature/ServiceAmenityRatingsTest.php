<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureVisitorToken;
use App\Models\Accommodation;
use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\EstablishmentAccount;
use App\Models\Region;
use App\Models\Review;
use App\Models\TouristVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Objective 1.3.2.6: separate Service and Amenities ratings beside the overall
 * rating, and the statistics pages that analyse them.
 */
class ServiceAmenityRatingsTest extends TestCase
{
    use RefreshDatabase;

    private function destination(string $name = 'Eden Nature Park'): Destination
    {
        return Destination::create([
            'slug' => Str::slug($name), 'name' => $name, 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Nature & Leisure', 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
        ]);
    }

    private function checkedInToken(Destination $d): string
    {
        $token = (string) Str::uuid();
        TouristVisit::create([
            'visitor_token' => $token, 'listing_kind' => 'destination', 'listing_id' => $d->id,
            'visit_date' => now()->toDateString(), 'source' => 'qr',
        ]);

        return $token;
    }

    private function submit(string $token, Destination $d, array $data)
    {
        return $this->withCookie(EnsureVisitorToken::COOKIE, $token)->post("/reviews/destinations/{$d->id}", $data);
    }

    private function addReview(Destination $d, int $rating, ?int $service, ?int $amenities, $at = null): Review
    {
        $review = Review::create([
            'listing_kind' => 'destination', 'listing_id' => $d->id, 'visitor_token' => (string) Str::uuid(),
            'author_name' => 'Verified visitor', 'rating' => $rating, 'service_rating' => $service, 'amenities_rating' => $amenities,
        ]);
        if ($at) {
            $review->forceFill(['created_at' => $at])->save();
        }

        return $review;
    }

    // ---- migration ---------------------------------------------------------------------------------------------

    public function test_migration_adds_nullable_columns_and_reverses_without_touching_existing_rows(): void
    {
        $this->assertTrue(Schema::hasColumns('reviews', ['service_rating', 'amenities_rating']));

        $d = $this->destination();
        $this->addReview($d, 4, null, null);

        $migration = require base_path('database/migrations/2026_11_05_100000_add_service_and_amenities_ratings_to_reviews.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('reviews', 'service_rating'));
        $this->assertFalse(Schema::hasColumn('reviews', 'amenities_rating'));
        $this->assertSame(4, (int) Review::sole()->rating, 'Rolling back must leave the existing review intact.');

        $migration->up();
        $this->assertTrue(Schema::hasColumns('reviews', ['service_rating', 'amenities_rating']));
        $review = Review::sole();
        $this->assertSame(4, (int) $review->rating);
        $this->assertNull($review->service_rating, 'Existing rows stay NULL: nothing is back-filled.');
        $this->assertNull($review->amenities_rating);
    }

    // ---- validation / storing ----------------------------------------------------------------------------------

    public function test_the_aspects_are_optional(): void
    {
        $d = $this->destination();

        $this->submit($this->checkedInToken($d), $d, ['rating' => 4])->assertSessionHasNoErrors();

        $review = Review::sole();
        $this->assertSame(4, (int) $review->rating);
        $this->assertNull($review->service_rating);
        $this->assertNull($review->amenities_rating);
    }

    public function test_the_aspects_are_stored_when_given(): void
    {
        $d = $this->destination();

        $this->submit($this->checkedInToken($d), $d, ['rating' => 4, 'service_rating' => 5, 'amenities_rating' => 2])->assertSessionHasNoErrors();

        $review = Review::sole();
        $this->assertSame(5, (int) $review->service_rating);
        $this->assertSame(2, (int) $review->amenities_rating);
    }

    public function test_an_empty_aspect_is_treated_as_not_answered(): void
    {
        $d = $this->destination();

        $this->submit($this->checkedInToken($d), $d, ['rating' => 3, 'service_rating' => '', 'amenities_rating' => ''])->assertSessionHasNoErrors();

        $this->assertNull(Review::sole()->service_rating);
    }

    public function test_the_overall_rating_stays_required(): void
    {
        $d = $this->destination();

        $this->submit($this->checkedInToken($d), $d, ['service_rating' => 5, 'amenities_rating' => 5])->assertSessionHasErrors('rating');
        $this->assertSame(0, Review::count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badValues')]
    public function test_aspects_outside_one_to_five_are_rejected(string $field, mixed $value): void
    {
        $d = $this->destination();

        $this->submit($this->checkedInToken($d), $d, ['rating' => 4, $field => $value])->assertSessionHasErrors($field);
        $this->assertSame(0, Review::count());
    }

    public static function badValues(): array
    {
        return [
            'service 0' => ['service_rating', 0],
            'service 6' => ['service_rating', 6],
            'service text' => ['service_rating', 'great'],
            'amenities 0' => ['amenities_rating', 0],
            'amenities 6' => ['amenities_rating', 6],
            'amenities fraction' => ['amenities_rating', 2.5],
        ];
    }

    public function test_the_listing_average_is_still_the_overall_rating_only(): void
    {
        $d = $this->destination();
        $this->submit($this->checkedInToken($d), $d, ['rating' => 5, 'service_rating' => 1, 'amenities_rating' => 1]);
        $this->submit($this->checkedInToken($d), $d, ['rating' => 3]);

        $d->refresh();
        $this->assertSame(2, (int) $d->review_count);
        $this->assertEqualsWithDelta(4.0, (float) $d->rating, 0.001); // (5 + 3) / 2, the aspects play no part
    }

    // ---- the form ----------------------------------------------------------------------------------------------

    public function test_the_form_offers_service_and_amenities_stars_only_to_someone_who_checked_in(): void
    {
        $d = $this->destination();

        $html = $this->withCookie(EnsureVisitorToken::COOKIE, $this->checkedInToken($d))->get(route('destinations.show', $d))->assertOk()->getContent();
        $this->assertStringContainsString('name="rating"', $html);
        $this->assertStringContainsString('name="service_rating"', $html);
        $this->assertStringContainsString('name="amenities_rating"', $html);
        $this->assertMatchesRegularExpression('/<input type="radio" name="rating"[^>]*required/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input type="radio" name="service_rating"[^>]*required/', $html);

        $other = $this->withCookie(EnsureVisitorToken::COOKIE, (string) Str::uuid())->get(route('destinations.show', $d))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="service_rating"', $other);
    }

    public function test_public_pages_show_the_overall_average_only(): void
    {
        $d = $this->destination();
        for ($i = 0; $i < 5; $i++) {
            $this->addReview($d, 4, 5, 3);
        }

        $html = $this->get(route('destinations.show', $d))->assertOk()->getContent();
        $this->assertStringNotContainsString('Service quality index', $html);
        $this->assertStringNotContainsString('Feedback analysis', $html);
        $this->assertStringNotContainsString('Std. deviation', $html);
    }

    // ---- establishment reviews page ----------------------------------------------------------------------------

    private function partner(): array
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        $listing = Accommodation::create([
            'slug' => 'test-resort', 'name' => 'Test Resort', 'location' => 'Davao City', 'region_id' => $region->id,
            'type' => 'Hotel', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range', 'price_per_night' => 3500,
        ]);
        $account = EstablishmentAccount::create([
            'business_name' => 'Test Resort', 'listing_kind' => 'accommodation', 'matched_listing_id' => $listing->id,
            'portal_key' => (string) Str::uuid(), 'email' => 'partner@test.example.com', 'password_hash' => Hash::make('x'),
            'contact_person' => 'Test Partner', 'contact_number' => '09170000000', 'status' => 'approved', 'submitted_at' => now(),
        ]);

        return [$account, $listing];
    }

    private function addStayReview(Accommodation $a, int $rating, ?int $service, ?int $amenities): void
    {
        Review::create([
            'listing_kind' => 'accommodation', 'listing_id' => $a->id, 'visitor_token' => (string) Str::uuid(),
            'author_name' => 'Verified visitor', 'rating' => $rating, 'service_rating' => $service, 'amenities_rating' => $amenities,
        ]);
    }

    public function test_the_establishment_reviews_page_shows_its_own_statistics(): void
    {
        [$account, $listing] = $this->partner();
        // overall 5,4,4,2,5 (mean 4, sd 1.2247); service 4,3,5 (mean 4, sd 1); amenities 3,3 (mean 3)
        foreach ([[5, 4, 3], [4, null, 3], [4, 3, null], [2, null, null], [5, 5, null]] as [$o, $s, $a]) {
            $this->addStayReview($listing, $o, $s, $a);
        }
        // a different listing's review must not leak into this page
        $this->addReview($this->destination(), 1, 1, 1);

        $html = $this->actingAs($account, 'establishment')->get('/portal/establishment/reviews')->assertOk()->getContent();

        $this->assertStringContainsString('Feedback analysis', $html);
        $this->assertStringContainsString('1.22', $html);           // overall sd, sqrt(1.5)
        $this->assertStringContainsString('66.7', $html);           // quality index, computed by hand in ReviewStatisticsTest
        $this->assertStringContainsString('Service 4/5', $html);    // the per-review aspect line
        $this->assertStringNotContainsString('No ratings yet', $html);
    }

    public function test_the_establishment_page_is_honest_when_aspects_have_no_data_and_when_there_are_few_reviews(): void
    {
        [$account, $listing] = $this->partner();
        $this->addStayReview($listing, 4, null, null);
        $this->addStayReview($listing, 5, null, null);

        $html = $this->actingAs($account, 'establishment')->get('/portal/establishment/reviews')->assertOk()->getContent();

        $this->assertStringContainsString('No ratings yet', $html);                    // service and amenities
        $this->assertStringContainsString('Not enough reviews (fewer than 5)', $html); // no index from two reviews
        $this->assertStringNotContainsString('66.7', $html);
    }

    public function test_the_establishment_page_with_no_reviews(): void
    {
        [$account] = $this->partner();

        $html = $this->actingAs($account, 'establishment')->get('/portal/establishment/reviews')->assertOk()->getContent();
        $this->assertStringContainsString('No ratings yet', $html);
        $this->assertStringNotContainsString('Service quality index', $html);
    }

    // ---- admin page --------------------------------------------------------------------------------------------

    private function admin(): AdminUser
    {
        return AdminUser::create(['email' => 'a@x.test', 'password_hash' => Hash::make('x'), 'full_name' => 'Admin', 'role' => 'super_admin']);
    }

    public function test_the_admin_page_needs_an_admin(): void
    {
        $this->get('/portal/admin/feedback-analysis')->assertRedirect();
        [$account] = $this->partner();
        $this->actingAs($account, 'establishment')->get('/portal/admin/feedback-analysis')->assertRedirect();
    }

    public function test_the_admin_page_is_in_the_nav_and_renders_empty(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')->get('/portal/admin/feedback-analysis')->assertOk()->getContent();

        $this->assertStringContainsString('Feedback Analysis', $html);
        $this->assertStringContainsString('No ratings yet in this period', $html);
    }

    public function test_the_admin_page_filters_by_dates_and_establishment(): void
    {
        $eden = $this->destination('Eden Nature Park');
        $other = $this->destination('Mount Apo View');
        $inRange = now()->subDays(3);
        for ($i = 0; $i < 5; $i++) {
            $this->addReview($eden, 4, 5, 3, $inRange);
        }
        $this->addReview($other, 1, 1, 1, $inRange);
        $this->addReview($eden, 2, 2, 2, now()->subDays(200)); // outside the default 30-day window

        $admin = $this->admin();

        // default window, all establishments: 6 in range (5 + 1); the 200-day-old one is excluded
        $all = $this->actingAs($admin, 'admin')->get('/portal/admin/feedback-analysis')->assertOk()->getContent();
        $this->assertStringContainsString('6 reviews between', $all);
        $this->assertStringContainsString('By establishment', $all);
        $this->assertStringContainsString('Mount Apo View', $all);

        // one establishment: five 4s -> mean 4.00, sd 0.00; index (mean of 4,5,3 = 4 -> 75.0)
        $one = $this->actingAs($admin, 'admin')->get('/portal/admin/feedback-analysis?establishment=destination:'.$eden->id)->assertOk()->getContent();
        $this->assertStringContainsString('5 reviews between', $one);
        $this->assertStringContainsString('75.0', $one);
        $this->assertStringNotContainsString('By establishment', $one);

        // widening the dates pulls the old review in
        $from = now()->subDays(365)->toDateString();
        $wide = $this->actingAs($admin, 'admin')->get('/portal/admin/feedback-analysis?from='.$from.'&to='.now()->toDateString().'&establishment=destination:'.$eden->id)->assertOk()->getContent();
        $this->assertStringContainsString('6 reviews between', $wide);

        // junk filters fall back instead of erroring
        $this->actingAs($admin, 'admin')->get('/portal/admin/feedback-analysis?from=nope&to=2026-99-99&establishment=x:y')->assertOk();
    }

    public function test_the_reports_page_is_unchanged_by_this_feature(): void
    {
        $html = $this->actingAs($this->admin(), 'admin')->get('/portal/admin/reports')->assertOk()->getContent();
        $this->assertStringNotContainsString('Service quality index', $html);
    }
}
