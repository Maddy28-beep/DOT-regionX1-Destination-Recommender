<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\PostcardSlide;
use App\Models\Region;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The landing page's "What Davao is known for" mood cards render from postcard_slides records (caption, link,
 * photo, focal point), each with the number of places its link leads to, and DOT admins manage those records, with
 * the photo resized to WebP variants on upload.
 */
class PostcardCarouselTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'email' => 'a@x.test', 'password_hash' => Hash::make('x'),
            'full_name' => 'Admin', 'role' => 'super_admin',
        ]);
    }

    private function slide(array $overrides = []): PostcardSlide
    {
        return PostcardSlide::create($overrides + [
            'kicker' => 'sun and sea', 'title' => 'Test Beach', 'location' => 'Samal',
            'cta_label' => 'View destination →', 'cta_url' => '/destinations',
            'image_path' => 'images/postcards/island-beach.webp',
            'thumb_path' => 'images/postcards/island-beach-thumb.webp',
            'alt_text' => 'Aerial view of a turquoise island coastline',
            'focal_x' => 30, 'focal_y' => 45, 'sort_order' => 50,
        ]);
    }

    public function test_the_site_ships_five_mood_cards(): void
    {
        $cards = PostcardSlide::ordered()->pluck('title')->all();

        $this->assertSame(['Islands & Beaches', 'Highlands & Nature', 'Wildlife', 'Culture & Heritage', 'Food & Dining'], $cards);
    }

    public function test_the_homepage_renders_each_card_as_one_link_with_its_words_and_focal_point(): void
    {
        PostcardSlide::query()->delete();
        $this->slide(['title' => 'Test Beach', 'kicker' => 'sun and sea', 'cta_label' => 'Explore →', 'cta_url' => '/destinations']);

        $html = $this->get('/')->assertOk()
            ->assertSee('What Davao is known for')
            ->assertSee('Pick a mood and we', false)
            ->assertSee('sun and sea')
            ->assertSee('Test Beach')
            ->assertSee('--focal: 30% 45%', false)
            ->getContent();

        $this->assertStringContainsString('href="'.url('/destinations').'" class="mood"', $html);
        $this->assertMatchesRegularExpression('/class="mood__cta">Explore </', $html, 'The arrow in a label is replaced by the card own arrow icon.');
        $this->assertStringContainsString('id="known-for"', $html, 'The hero scroll cue lands here.');
        $this->assertStringContainsString('href="#known-for" class="hero-scroll-cue"', $html);
    }

    public function test_a_card_that_links_to_a_catalogue_shows_how_many_places_are_in_it(): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        foreach (['alpha-falls', 'bravo-beach', 'charlie-cove'] as $slug) {
            Destination::create([
                'slug' => $slug, 'name' => ucwords(str_replace('-', ' ', $slug)), 'location' => 'Davao City',
                'region_id' => $region->id, 'type' => 'Beach & Leisure', 'is_accredited' => true,
                'rating' => 4.5, 'review_count' => 3, 'price_tier' => 'Mid-range',
            ]);
        }
        Destination::create([
            'slug' => 'delta-hike', 'name' => 'Delta Hike', 'location' => 'Davao City', 'region_id' => $region->id,
            'type' => 'Hiking', 'is_accredited' => true, 'rating' => 4.0, 'review_count' => 1, 'price_tier' => 'Budget-Friendly',
        ]);

        PostcardSlide::query()->delete();
        $beaches = $this->slide(['title' => 'Beaches', 'cta_url' => '/destinations?type=Beach+%26+Leisure']);
        $one = $this->slide(['title' => 'One place', 'cta_url' => '/destinations/alpha-falls', 'sort_order' => 51]);
        $away = $this->slide(['title' => 'Away', 'cta_url' => 'https://example.test/', 'sort_order' => 52]);

        $this->assertSame(3, $beaches->listingCount(), 'The same filter the catalogue page applies to that link.');
        $this->assertNull($one->listingCount(), 'A single place has no count.');
        $this->assertNull($away->listingCount(), 'An outside link has no count.');

        $html = $this->get('/')->getContent();
        $this->assertStringContainsString('[<span data-count-up>3</span>] places', $html);
        $this->assertSame(1, substr_count($html, 'class="mood__count"'), 'Only the card that leads to a catalogue shows a count.');
        $this->get('/destinations?type=Beach+%26+Leisure')->assertSee('3 destinations found');
    }

    public function test_the_food_card_counts_the_dining_listings(): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        Restaurant::create(['slug' => 'marina-tuna', 'name' => 'Marina Tuna', 'location' => 'Davao City', 'region_id' => $region->id, 'is_accredited' => true, 'rating' => 0, 'review_count' => 0]);

        $this->assertSame(1, PostcardSlide::where('cta_url', '/restaurants')->first()->listingCount());
    }

    public function test_a_card_whose_photo_file_is_missing_shows_the_placeholder_not_a_broken_image(): void
    {
        PostcardSlide::query()->delete();
        $this->slide(['image_path' => 'images/postcards/does-not-exist.webp']);

        $this->get('/')->assertOk()->assertSee('mood__photo mood__photo--none', false)->assertDontSee('does-not-exist', false);
    }

    public function test_cards_use_a_card_sized_copy_of_the_photo(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('island-beach-card.webp', $html, 'Not the 1.4MB full-size photograph.');
        $this->assertStringNotContainsString('-enhanced.webp', $html);
        $this->assertSame(0, substr_count($html, 'fetchpriority="high"'), 'The row is below the fold, so nothing in it is a priority image.');
    }

    public function test_the_row_loads_the_slideshow_script_which_leaves_reduced_motion_alone(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#js/mood-slideshow\.js\?v=\d+#', $html);

        $js = file_get_contents(public_path('js/mood-slideshow.js'));
        $this->assertStringContainsString('prefers-reduced-motion', $js, 'No autoplay for people who ask for less motion.');
        $this->assertStringContainsString('moods__dot', $js, 'Dots show which card is up and let a visitor pick one.');
        $this->assertStringContainsString('touchstart', $js, 'A touch stops the slideshow.');
        $this->assertStringContainsString('visibilitychange', $js, 'It stops while the tab is not showing.');
        $this->assertStringContainsString('(max-width: 1100px)', $js, 'Same breakpoint as the stylesheet: only where the row is a swipe row.');
    }

    public function test_inactive_cards_are_not_shown_and_an_empty_row_is_omitted(): void
    {
        PostcardSlide::query()->update(['is_active' => false]);

        $this->get('/')->assertOk()->assertDontSee('What Davao is known for');
    }

    public function test_admin_pages_render(): void
    {
        $admin = $this->admin();
        $slide = PostcardSlide::first();

        $this->actingAs($admin, 'admin')->get(route('admin.postcard-slides.index'))->assertOk()->assertSee($slide->title);
        $this->actingAs($admin, 'admin')->get(route('admin.postcard-slides.create'))->assertOk()->assertSee('Focal point');
        $this->actingAs($admin, 'admin')->get(route('admin.postcard-slides.edit', $slide))->assertOk()->assertSee($slide->alt_text);
    }

    public function test_a_guest_cannot_reach_the_slides_console(): void
    {
        $this->get(route('admin.postcard-slides.index'))->assertRedirect();
    }

    public function test_admin_uploads_a_photo_and_variants_are_generated(): void
    {
        Storage::fake('public');

        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.postcard-slides.store'), [
                'kicker' => 'wild and protected', 'title' => 'Philippine Eagle Center', 'location' => 'Malagos',
                'cta_label' => 'View destination →', 'cta_url' => '/destinations/philippine-eagle-center',
                'alt_text' => 'A Philippine Eagle perched on a branch', 'focal_x' => 40, 'focal_y' => 60,
                'sort_order' => 9, 'is_active' => 1,
                'image' => UploadedFile::fake()->image('eagle.jpg', 2400, 1400),
            ])->assertRedirect(route('admin.postcard-slides.index'));

        $slide = PostcardSlide::where('title', 'Philippine Eagle Center')->firstOrFail();
        $this->assertSame([640, 1280, 1920], collect($slide->image_srcset)->pluck('w')->all());
        $this->assertStringContainsString('1920.webp', $slide->image_path);
        $this->assertStringContainsString('thumb.webp', $slide->thumb_path);

        foreach ([...collect($slide->image_srcset)->pluck('path'), $slide->thumb_path] as $web) {
            Storage::disk('public')->assertExists(substr($web, strlen('storage/')));
        }

        // Removing the slide removes its files.
        $dir = $slide->uploadDirectory();
        $this->actingAs($admin, 'admin')->delete(route('admin.postcard-slides.destroy', $slide))->assertRedirect();
        Storage::disk('public')->assertMissing($dir.'/thumb.webp');
    }

    public function test_a_photo_that_is_too_small_is_rejected(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.postcard-slides.store'), [
                'kicker' => 'k', 'title' => 'Tiny', 'cta_label' => 'Go', 'cta_url' => '/destinations',
                'alt_text' => 'A small test picture', 'focal_x' => 50, 'focal_y' => 50, 'sort_order' => 1,
                'image' => UploadedFile::fake()->image('small.jpg', 800, 600),
            ])->assertSessionHasErrors('image');
    }

    public function test_alt_text_and_a_site_relative_link_are_required(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->put(route('admin.postcard-slides.update', $this->slide()), [
                'kicker' => 'k', 'title' => 'T', 'cta_label' => 'Go', 'cta_url' => 'javascript:alert(1)',
                'alt_text' => '', 'focal_x' => 50, 'focal_y' => 50, 'sort_order' => 1,
            ])->assertSessionHasErrors(['alt_text', 'cta_url']);
    }

    public function test_focal_point_is_validated(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->put(route('admin.postcard-slides.update', $this->slide()), [
                'kicker' => 'k', 'title' => 'T', 'cta_label' => 'Go', 'cta_url' => '/destinations',
                'alt_text' => 'A valid description', 'focal_x' => 120, 'focal_y' => -1, 'sort_order' => 1,
            ])->assertSessionHasErrors(['focal_x', 'focal_y']);
    }
}
