<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\ListingPhoto;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a visitor sees with a photograph: the credit the owner agreed to (or, failing that, the recorded source),
 * a way to ask for removal, and a plain label on anything that is not a photograph of the place.
 */
class ListingPhotoCreditTest extends TestCase
{
    use RefreshDatabase;

    private const LABEL = 'Illustration, not a photo of this place';

    private function hotel(string $slug = 'city-inn', string $name = 'City Inn'): Accommodation
    {
        return Accommodation::create([
            'slug' => $slug, 'name' => $name, 'location' => 'Davao City', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Hotel', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
        ]);
    }

    private function park(): Destination
    {
        return Destination::create([
            'slug' => 'eden-nature-park', 'name' => 'Eden Nature Park', 'location' => 'Davao City', 'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Nature & Adventure', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
        ]);
    }

    private function photo($listing, string $path, array $over = []): ListingPhoto
    {
        return $listing->photos()->create($over + ['path' => $path, 'category' => 'General', 'is_primary' => true]);
    }

    // ---- the credit -------------------------------------------------------------------------------

    public function test_a_photo_with_an_agreed_credit_shows_it_and_a_removal_link_when_an_address_is_set(): void
    {
        config(['listing_photos.report_email' => 'photos@example.org']);
        $hotel = $this->hotel();
        $this->photo($hotel, 'listings/verified/inn.jpg', ['credit_text' => 'City Inn Davao', 'verification_status' => 'approved']);

        $page = $this->get('/accommodations/city-inn')->assertOk();

        $page->assertSee('Photo: City Inn Davao');
        $page->assertSee('Report a photo');
        $page->assertSee('mailto:photos@example.org?subject='.rawurlencode('Photo on ExploreDVO: City Inn'), false);
        $page->assertDontSee(self::LABEL);
    }

    public function test_without_a_configured_address_there_is_no_report_link_but_the_credit_still_shows(): void
    {
        config(['listing_photos.report_email' => null]);
        $this->photo($this->hotel(), 'listings/verified/inn.jpg', ['credit_text' => 'City Inn Davao']);

        $this->get('/accommodations/city-inn')->assertOk()->assertSee('Photo: City Inn Davao')->assertDontSee('Report a photo');
    }

    public function test_an_owner_who_asked_for_no_credit_has_none_shown(): void
    {
        $this->photo($this->hotel(), 'listings/verified/inn.jpg', ['credit_text' => 'City Inn Davao', 'show_credit' => false]);

        $this->get('/accommodations/city-inn')->assertOk()->assertDontSee('Photo: City Inn Davao')->assertDontSee('photo-credit');
    }

    public function test_an_older_photo_with_only_a_recorded_source_names_that_source(): void
    {
        $this->photo($this->hotel(), 'listings/verified/inn.jpg', ['source_name' => 'Davao City Tourism']);

        $this->get('/accommodations/city-inn')->assertOk()->assertSee('Source: Davao City Tourism');
    }

    public function test_a_credit_is_escaped(): void
    {
        $this->photo($this->hotel(), 'listings/verified/inn.jpg', ['credit_text' => '<b>Bold</b> Studio']);

        $this->get('/accommodations/city-inn')->assertOk()->assertSee('Photo: &lt;b&gt;Bold&lt;/b&gt; Studio', false)->assertDontSee('<b>Bold</b> Studio', false);
    }

    public function test_the_lightbox_carries_the_credit_for_each_photo(): void
    {
        $hotel = $this->hotel();
        $this->photo($hotel, 'listings/verified/a.jpg', ['credit_text' => 'City Inn Davao']);
        $this->photo($hotel, 'listings/verified/b.jpg', ['is_primary' => false, 'sort_order' => 1]);

        $html = $this->get('/accommodations/city-inn')->assertOk()->getContent();
        preg_match("/data-photos='([^']+)'/", $html, $m);
        $photos = json_decode(html_entity_decode($m[1]), true);

        $this->assertSame('Photo: City Inn Davao', $photos[0]['credit']);
        $this->assertNull($photos[1]['credit'], 'a photo with nothing to credit says nothing');
    }

    // ---- illustrations ----------------------------------------------------------------------------

    public function test_a_listing_with_only_a_sample_picture_says_it_is_not_a_photo_of_the_place(): void
    {
        $this->photo($this->hotel(), 'listings/samples/accommodations.webp', ['category' => 'Sample image', 'credit_text' => 'ignored']);

        $page = $this->get('/accommodations/city-inn')->assertOk();

        $page->assertSee(self::LABEL);
        $page->assertSee('alt="Illustration, not a photo of City Inn"', false);
        $page->assertDontSee('Photo: ignored', false);
        $page->assertDontSee('photo-credit');
    }

    public function test_a_real_photograph_pushes_out_the_sample_and_the_label(): void
    {
        $hotel = $this->hotel();
        $this->photo($hotel, 'listings/samples/accommodations.webp', ['category' => 'Sample image', 'is_primary' => false]);
        $this->photo($hotel, 'listings/verified/inn.jpg', ['credit_text' => 'City Inn Davao']);

        $this->get('/accommodations/city-inn')->assertOk()->assertDontSee(self::LABEL)->assertDontSee('accommodations.webp')->assertSee('Photo: City Inn Davao');
    }

    public function test_the_listing_grid_marks_a_sample_card_and_leaves_a_real_photo_unmarked(): void
    {
        $this->photo($this->hotel('sample-inn', 'Sample Inn'), 'listings/samples/accommodations.webp', ['category' => 'Sample image']);
        $this->photo($this->hotel('real-inn', 'Real Inn'), 'listings/verified/real.jpg');

        $html = $this->get('/accommodations')->assertOk()->assertSee('Sample Inn')->assertSee('Real Inn')->getContent();

        $this->assertSame(1, substr_count($html, 'photo-illustration-tag photo-illustration-tag--card'), 'only the sample card carries the tag');
    }

    public function test_the_destination_page_labels_a_sample_and_credits_a_real_photograph(): void
    {
        $park = $this->park();
        $this->photo($park, 'listings/samples/landscape.webp', ['category' => 'Sample image']);
        $this->get('/destinations/eden-nature-park')->assertOk()->assertSee(self::LABEL)->assertDontSee('Photo: Eden');

        $park->photos()->delete();
        $this->photo($park, 'listings/verified/eden.jpg', ['credit_text' => 'Eden Nature Park']);
        $this->get('/destinations/eden-nature-park')->assertOk()->assertDontSee(self::LABEL)->assertSee('Photo: Eden Nature Park');
    }

    // ---- the model --------------------------------------------------------------------------------

    public function test_credit_line_rules(): void
    {
        $line = fn (array $a) => (new ListingPhoto($a + ['path' => 'a.jpg', 'category' => 'General']))->creditLine();

        $this->assertSame('Photo: Maria Cruz', $line(['credit_text' => 'Maria Cruz', 'source_name' => 'X']), 'the agreed credit wins');
        $this->assertSame('Source: X', $line(['source_name' => 'X']));
        $this->assertNull($line([]), 'nothing known, nothing said');
        $this->assertNull($line(['credit_text' => 'Maria Cruz', 'show_credit' => false]));
        $this->assertNull($line(['credit_text' => 'Maria Cruz', 'path' => 'x.svg']), 'a drawing has no author');
        $this->assertNull($line(['credit_text' => 'Maria Cruz', 'category' => 'Sample image']));
    }
}
