<?php

namespace Tests\Unit;

use App\Models\ListingPhoto;
use App\Support\RelatedListings;
use PHPUnit\Framework\TestCase;

/** The places under a listing's page: ones with a real photograph first, the caller's order kept inside each group. */
class RelatedListingsTest extends TestCase
{
    private function place(string $name, ?string $path, string $category = 'General'): object
    {
        $photo = $path === null ? null : new ListingPhoto(['path' => $path, 'category' => $category]);

        return new class($name, $photo)
        {
            public function __construct(public string $name, private ?ListingPhoto $photo)
            {
            }

            public function coverPhoto(): ?ListingPhoto
            {
                return $this->photo;
            }
        };
    }

    public function test_places_with_a_real_photo_come_before_ones_with_an_illustration_or_none(): void
    {
        $found = collect([
            $this->place('Stock one', 'listings/samples/a.webp', 'Sample image'),
            $this->place('Real one', 'listings/destination/1/a.jpg'),
            $this->place('No photo', null),
            $this->place('Drawing', 'listings/destination/2/a.svg'),
            $this->place('Real two', 'listings/destination/3/a.jpg'),
        ]);

        $this->assertSame(['Real one', 'Real two', 'Stock one'], RelatedListings::pick($found)->pluck('name')->all());
    }

    public function test_when_too_few_have_a_real_photo_the_others_fill_in_and_the_given_order_is_kept(): void
    {
        $found = collect([
            $this->place('A', null),
            $this->place('B', 'listings/destination/1/b.jpg'),
            $this->place('C', null),
        ]);

        $this->assertSame(['B', 'A', 'C'], RelatedListings::pick($found)->pluck('name')->all());
    }
}
