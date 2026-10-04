<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\PostcardSlide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The homepage "Popular Right Now" carousel renders from postcard_slides
 * records (caption, button, photo, alt text, focal point), and DOT admins
 * manage those records, with the photo resized to WebP variants on upload.
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

    public function test_migration_carries_over_the_four_original_slides(): void
    {
        $this->assertSame(4, PostcardSlide::count());
    }

    public function test_homepage_renders_caption_button_alt_and_focal_point_from_the_record(): void
    {
        PostcardSlide::query()->delete();
        $this->slide();

        $this->get('/')->assertOk()
            ->assertSee('Test Beach')
            ->assertSee('View destination')
            ->assertSee('alt="Aerial view of a turquoise island coastline"', false)
            ->assertSee('--focal: 30% 45%', false)
            ->assertSee('fetchpriority="high"', false);
    }

    public function test_a_single_slide_has_no_arrows_thumbnails_or_pause_button(): void
    {
        PostcardSlide::query()->delete();
        $this->slide();

        $this->get('/')->assertOk()
            ->assertDontSee('pc-arrow', false)
            ->assertDontSee('pc-thumb', false)
            ->assertDontSee('pc-toggle', false);
    }

    public function test_inactive_slides_are_not_shown_and_an_empty_carousel_is_omitted(): void
    {
        PostcardSlide::query()->update(['is_active' => false]);

        $this->get('/')->assertOk()->assertDontSee('Popular Right Now');
    }

    public function test_only_the_first_image_is_eager(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'fetchpriority="high"'));
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
