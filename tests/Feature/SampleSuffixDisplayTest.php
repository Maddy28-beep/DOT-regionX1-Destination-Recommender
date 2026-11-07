<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Region;
use App\Support\SampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A visitor never reads " [SAMPLE]" in a name; the stored name and the Sample Data badge are what carry it. */
class SampleSuffixDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_a_trailing_suffix_is_removed(): void
    {
        $this->assertSame('Guang-guang Mangrove Forest', SampleData::displayName('Guang-guang Mangrove Forest [SAMPLE]'));
        $this->assertSame('Tuka Beach ("Little Boracay")', SampleData::displayName('Tuka Beach ("Little Boracay") [sample]'));
        $this->assertSame('Eden Nature Park', SampleData::displayName('Eden Nature Park'));
        $this->assertSame('', SampleData::displayName(null));
    }

    public function test_the_page_and_the_card_show_the_clean_name_but_the_stored_name_is_untouched(): void
    {
        $destination = Destination::create([
            'slug' => 'guang-guang', 'name' => 'Guang-guang Mangrove Forest [SAMPLE]', 'location' => 'Mati',
            'region_id' => Region::firstOrCreate(['name' => 'Davao Oriental'])->id, 'type' => 'Nature & Adventure',
            'is_accredited' => true, 'rating' => 4.0, 'review_count' => 1, 'latitude' => 6.95, 'longitude' => 126.28,
        ]);

        $page = $this->get(route('destinations.show', $destination))->assertOk()->getContent();
        $this->assertStringContainsString('Guang-guang Mangrove Forest', $page);
        $this->assertStringNotContainsString('[SAMPLE]', $page);

        $this->assertSame('Guang-guang Mangrove Forest [SAMPLE]', $destination->fresh()->name);
        $this->assertSame('Guang-guang Mangrove Forest', $destination->displayName());

        $list = $this->get(route('destinations.index'))->assertOk()->getContent();
        // The cards say "Sample Data" by badge; the map's pop-up data (a script, no badge there) carries the plain-text marker.
        $this->assertStringNotContainsString('[SAMPLE]', preg_replace('#<script.*?</script>#s', '', $list));
    }
}
