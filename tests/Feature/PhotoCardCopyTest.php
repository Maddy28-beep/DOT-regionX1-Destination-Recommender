<?php

namespace Tests\Feature;

use App\Models\ListingPhoto;
use App\Support\PhotoCardCopy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Card copies: smaller WebP files next to the originals, so the listing grids load faster. The originals must never change,
 * a dry run must write nothing, and a card must fall back to the original when no copy exists.
 */
class PhotoCardCopyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! PhotoCardCopy::supported()) {
            $this->markTestSkipped('GD with WebP support is not available.');
        }

        Storage::fake('public');
    }

    /** A noisy picture (so it does not compress to nothing), as PNG or JPEG bytes. */
    private function picture(int $w, int $h, string $type = 'png'): string
    {
        // Building a big noisy picture is slow, so each size is made once and reused by every test.
        static $made = [];

        return $made["{$w}x{$h}.{$type}"] ??= (function () use ($w, $h, $type) {
            $img = imagecreatetruecolor($w, $h);
            mt_srand(7);
            for ($i = 0; $i < 1500; $i++) {
                imagefilledrectangle($img, mt_rand(0, $w), mt_rand(0, $h), mt_rand(0, $w), mt_rand(0, $h), imagecolorallocate($img, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
            }
            ob_start();
            $type === 'jpg' ? imagejpeg($img, null, 95) : imagepng($img);

            return (string) ob_get_clean();
        })();
    }

    /** Insert an EXIF segment that carries the given orientation into JPEG bytes. */
    private function withOrientation(string $jpeg, int $orientation): string
    {
        $tiff = "MM\x00\x2A\x00\x00\x00\x08"          // big-endian, first IFD at offset 8
            ."\x00\x01"                                // one entry
            ."\x01\x12\x00\x03\x00\x00\x00\x01".pack('n', $orientation)."\x00\x00" // tag 0x0112, SHORT, count 1
            ."\x00\x00\x00\x00";                       // no next IFD
        $payload = "Exif\0\0".$tiff;
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
    }

    private function photo(string $path): ListingPhoto
    {
        return ListingPhoto::create(['listing_kind' => 'destination', 'listing_id' => 1, 'path' => $path, 'category' => 'Exterior']);
    }

    public function test_the_copy_is_named_next_to_the_original(): void
    {
        $this->assertSame('listings/verified/destination-4.card.webp', PhotoCardCopy::variantPath('listings/verified/destination-4.png'));
        $this->assertSame('listings/a.b/photo.card.webp', PhotoCardCopy::variantPath('listings/a.b/photo.jpg'));
        $this->assertTrue(PhotoCardCopy::isVariant('listings/x.card.webp'));
        $this->assertFalse(PhotoCardCopy::isVariant('listings/x.webp'));
    }

    public function test_a_copy_is_webp_no_wider_than_asked_and_smaller(): void
    {
        $png = $this->picture(2400, 1600);
        $webp = PhotoCardCopy::encode($png, 800, 80);

        $this->assertNotNull($webp);
        $size = getimagesizefromstring($webp);
        $this->assertSame(IMAGETYPE_WEBP, $size[2]);
        $this->assertSame(800, $size[0]);
        $this->assertSame(533, $size[1]);
        $this->assertLessThan(strlen($png), strlen($webp));
    }

    public function test_a_narrow_picture_is_never_enlarged(): void
    {
        $webp = PhotoCardCopy::encode($this->picture(400, 300), 800, 80);

        $this->assertSame([400, 300], array_slice(getimagesizefromstring($webp), 0, 2));
    }

    public function test_the_rotation_flag_is_read_and_obeyed(): void
    {
        $jpeg = $this->picture(400, 200, 'jpg');

        $this->assertSame(1, PhotoCardCopy::jpegOrientation($jpeg));
        $turned = $this->withOrientation($jpeg, 6);
        $this->assertSame(6, PhotoCardCopy::jpegOrientation($turned));
        $this->assertSame([200, 400], PhotoCardCopy::displaySize($turned));

        // A photo stored sideways but flagged "turn to display" must come out upright: 400x200 stored, 200x400 shown.
        $webp = PhotoCardCopy::encode($turned, 800, 80);
        $this->assertSame([200, 400], array_slice(getimagesizefromstring($webp), 0, 2));
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        Storage::disk('public')->put('listings/big.png', $this->picture(2400, 1600));
        $this->photo('listings/big.png');

        $this->artisan('photos:make-card-copies', ['--min-kb' => 10])
            ->expectsOutputToContain('Would write 1 card copies')
            ->expectsOutputToContain('Dry run only: nothing was written')
            ->assertSuccessful();

        Storage::disk('public')->assertMissing('listings/big.card.webp');
    }

    public function test_apply_writes_a_copy_and_leaves_the_original_byte_for_byte_alone(): void
    {
        $png = $this->picture(2400, 1600);
        Storage::disk('public')->put('listings/big.png', $png);
        $this->photo('listings/big.png');

        $this->artisan('photos:make-card-copies', ['--apply' => true, '--min-kb' => 10])->assertSuccessful();

        Storage::disk('public')->assertExists('listings/big.card.webp');
        $this->assertSame(md5($png), md5(Storage::disk('public')->get('listings/big.png')));
        $this->assertLessThan(strlen($png), Storage::disk('public')->size('listings/big.card.webp'));
    }

    public function test_an_existing_copy_is_left_alone_and_small_or_unsupported_files_are_skipped(): void
    {
        Storage::disk('public')->put('listings/big.png', $this->picture(2400, 1600));
        Storage::disk('public')->put('listings/tiny.png', $this->picture(40, 30));
        Storage::disk('public')->put('listings/art.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        foreach (['listings/big.png', 'listings/tiny.png', 'listings/art.svg', 'listings/missing.png'] as $p) {
            $this->photo($p);
        }

        $this->artisan('photos:make-card-copies', ['--apply' => true, '--min-kb' => 10])->assertSuccessful();
        $first = md5(Storage::disk('public')->get('listings/big.card.webp'));

        Storage::disk('public')->assertMissing('listings/tiny.card.webp');
        Storage::disk('public')->assertMissing('listings/art.card.webp');

        // Run again: the copy that exists is not rebuilt.
        $this->artisan('photos:make-card-copies', ['--apply' => true, '--min-kb' => 10])
            ->expectsOutputToContain('Wrote 0 card copies')
            ->assertSuccessful();
        $this->assertSame($first, md5(Storage::disk('public')->get('listings/big.card.webp')));
    }

    public function test_a_card_uses_the_copy_when_there_is_one_and_the_original_when_there_is_not(): void
    {
        Storage::disk('public')->put('listings/with-copy.png', $this->picture(1200, 800));
        Storage::disk('public')->put('listings/with-copy.card.webp', 'x');
        Storage::disk('public')->put('listings/no-copy.png', $this->picture(1200, 800));

        $with = $this->photo('listings/with-copy.png');
        $without = $this->photo('listings/no-copy.png');

        $this->assertStringEndsWith('listings/with-copy.card.webp', $with->cardUrl());
        $this->assertStringEndsWith('listings/no-copy.png', $without->cardUrl());
        // Detail pages and galleries always keep the original.
        $this->assertStringEndsWith('listings/with-copy.png', $with->url());
    }

    public function test_the_list_cards_ask_for_the_card_copy_and_the_detail_hero_does_not(): void
    {
        foreach (['listing-card', 'listing-poster-card'] as $card) {
            $this->assertStringContainsString('$coverPhoto->cardUrl()', file_get_contents(resource_path("views/partials/{$card}.blade.php")));
        }
        $this->assertStringNotContainsString('cardUrl()', file_get_contents(resource_path('views/partials/dest-detail-hero.blade.php')));
    }
}
