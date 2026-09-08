<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureVisitorToken;
use App\Models\Destination;
use App\Models\Region;
use App\Models\Review;
use App\Models\TouristVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Nothing in the application could create a review before this: establishments
 * could read and reply to them, the cards and the recommender ranked listings
 * by them, but every row in the table came from a seeder -- which is why 383 of
 * 396 listings carry no rating at all.
 *
 * Reviewing is gated on having scanned the listing's QR code on site. On a
 * government accreditation platform a rating anyone can post about a real
 * business is worth little and is trivial to brigade, so the gate is the
 * feature, and these cover it from both sides.
 */
class ReviewSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private function destination(): Destination
    {
        return Destination::create([
            'slug' => 'eden-nature-park', 'name' => 'Eden Nature Park', 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Nature & Leisure', 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
        ]);
    }

    private function token(): string
    {
        return (string) Str::uuid();
    }

    private function checkIn(string $token, Destination $destination): void
    {
        TouristVisit::create([
            'visitor_token' => $token,
            'listing_kind' => 'destination',
            'listing_id' => $destination->id,
            'visit_date' => now()->toDateString(),
            'source' => 'qr',
        ]);
    }

    private function postReview(string $token, Destination $destination, array $data = [])
    {
        return $this->withCookie(EnsureVisitorToken::COOKIE, $token)
            ->post("/reviews/destinations/{$destination->id}", $data + ['rating' => 5, 'comment' => 'Genuinely lovely.']);
    }

    public function test_someone_who_checked_in_can_review_the_place(): void
    {
        $destination = $this->destination();
        $token = $this->token();
        $this->checkIn($token, $destination);

        $this->postReview($token, $destination)->assertRedirect(route('destinations.show', $destination));

        $review = Review::sole();
        $this->assertSame(5, (int) $review->rating);
        $this->assertSame('Genuinely lovely.', $review->comment);
        $this->assertSame('Verified visitor', $review->author_name,
            'Reviews stay anonymous -- the QR check-in is what vouches for them, not a name.');
        $this->assertSame($token, $review->visitor_token);
    }

    /** The whole point: no check-in, no review. */
    public function test_someone_who_never_checked_in_cannot_review(): void
    {
        $destination = $this->destination();

        $this->postReview($this->token(), $destination)->assertSessionHasErrors('rating');

        $this->assertSame(0, Review::count());
    }

    /** A check-in at one place must not unlock reviewing a different one. */
    public function test_checking_in_elsewhere_does_not_unlock_this_listing(): void
    {
        $eden = $this->destination();
        $other = Destination::create([
            'slug' => 'samal-island', 'name' => 'Samal Island', 'location' => 'Samal',
            'region_id' => Region::sole()->id, 'type' => 'Beach & Leisure', 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
        ]);

        $token = $this->token();
        $this->checkIn($token, $other);

        $this->postReview($token, $eden)->assertSessionHasErrors('rating');

        $this->assertSame(0, Review::count());
    }

    public function test_one_browser_cannot_review_the_same_listing_twice(): void
    {
        $destination = $this->destination();
        $token = $this->token();
        $this->checkIn($token, $destination);

        $this->postReview($token, $destination);
        $this->postReview($token, $destination, ['rating' => 1])->assertSessionHasErrors('rating');

        $this->assertSame(1, Review::count(), 'The unique index should stop a second review.');
        $this->assertSame(5, (int) Review::sole()->rating, 'The original review must not be overwritten.');
    }

    /**
     * The listing's own rating/review_count drive the cards, the catalogue
     * sort and the recommender's Ratings and Popularity factors. Until now
     * only a seeder ever wrote them, so they could never move.
     */
    public function test_the_listings_rating_is_recomputed_from_real_reviews(): void
    {
        $destination = $this->destination();

        foreach ([5, 4, 3] as $rating) {
            $token = $this->token();
            $this->checkIn($token, $destination);
            $this->postReview($token, $destination, ['rating' => $rating]);
        }

        $destination->refresh();

        $this->assertSame(3, (int) $destination->review_count);
        $this->assertEqualsWithDelta(4.0, (float) $destination->rating, 0.01,
            'Three reviews of 5, 4 and 3 should average to 4.0.');
    }

    public function test_a_rating_outside_one_to_five_is_rejected(): void
    {
        $destination = $this->destination();
        $token = $this->token();
        $this->checkIn($token, $destination);

        foreach ([0, 6, -1] as $bad) {
            $this->postReview($token, $destination, ['rating' => $bad])->assertSessionHasErrors('rating');
        }

        $this->assertSame(0, Review::count());
    }

    public function test_an_archived_listing_accepts_no_reviews(): void
    {
        $destination = $this->destination();
        $token = $this->token();
        $this->checkIn($token, $destination);
        $destination->archive();

        $this->postReview($token, $destination)->assertNotFound();

        $this->assertSame(0, Review::count());
    }

    /** The page has to say why the form is missing, or it just looks broken. */
    public function test_the_page_explains_the_gate_to_someone_who_has_not_checked_in(): void
    {
        $destination = $this->destination();

        $html = $this->get(route('destinations.show', $destination))->assertOk()->getContent();

        $this->assertStringContainsString('Scan the DOT QR code', $html);
        $this->assertStringNotContainsString('Post my review', $html,
            'The form itself should not be offered to someone who cannot use it.');
    }

    public function test_the_form_is_offered_once_the_visitor_has_checked_in(): void
    {
        $destination = $this->destination();
        $token = $this->token();
        $this->checkIn($token, $destination);

        $html = $this->withCookie(EnsureVisitorToken::COOKIE, $token)
            ->get(route('destinations.show', $destination))->assertOk()->getContent();

        $this->assertStringContainsString('Post my review', $html);
    }
}
