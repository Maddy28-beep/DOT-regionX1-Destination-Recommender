<?php

namespace Tests\Feature;

use App\Models\Destination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The "-sample" ending is dropped from the address of real places. The old address keeps working, a made-up "Sample ..."
 * destination keeps its address, and an address that is already taken is left alone.
 */
class RenameSampleSlugsTest extends TestCase
{
    use RefreshDatabase;

    private function destination(string $name, string $slug): Destination
    {
        return Destination::create(['name' => $name, 'slug' => $slug, 'location' => 'Davao City', 'type' => 'Nature & Leisure',
            'is_accredited' => true, 'itinerary_role' => 'sightseeing']);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $oak = $this->destination('Oak Island [SAMPLE]', 'oak-island-sample');

        $this->artisan('destinations:rename-sample-slugs')->expectsOutputToContain('1 to rename')->assertSuccessful();

        $this->assertSame('oak-island-sample', $oak->fresh()->slug);
        $this->assertSame(0, DB::table('destination_slug_redirects')->count());
    }

    public function test_real_places_are_renamed_made_up_ones_and_clashes_are_left_alone(): void
    {
        $oak = $this->destination('Oak Island [SAMPLE]', 'oak-island-sample');
        $madeUp = $this->destination('Sample Gulfview Public Beach [SAMPLE]', 'sample-gulfview-public-beach-sample');
        $taken = $this->destination('San Pedro Cathedral', 'san-pedro-cathedral');
        $clash = $this->destination('San Pedro Cathedral [SAMPLE]', 'san-pedro-cathedral-sample');

        $this->artisan('destinations:rename-sample-slugs', ['--apply' => true])->assertSuccessful();

        $this->assertSame('oak-island', $oak->fresh()->slug);
        $this->assertSame('sample-gulfview-public-beach-sample', $madeUp->fresh()->slug, 'A made-up place keeps its address.');
        $this->assertSame('san-pedro-cathedral-sample', $clash->fresh()->slug, 'An address already in use is not taken over.');
        $this->assertSame('san-pedro-cathedral', $taken->fresh()->slug);
        $this->assertSame(1, DB::table('destination_slug_redirects')->count());

        // Running it again changes nothing.
        $this->artisan('destinations:rename-sample-slugs', ['--apply' => true])->assertSuccessful();
        $this->assertSame(1, DB::table('destination_slug_redirects')->count());
    }

    public function test_the_new_address_opens_and_the_old_one_redirects_to_it(): void
    {
        $oak = $this->destination('Oak Island [SAMPLE]', 'oak-island-sample');
        $this->artisan('destinations:rename-sample-slugs', ['--apply' => true])->assertSuccessful();

        $this->get('/destinations/oak-island')->assertOk();
        $this->get('/destinations/oak-island-sample')->assertStatus(301)->assertRedirect(route('destinations.show', 'oak-island'));
        $this->get('/destinations/never-existed')->assertNotFound();
    }

    public function test_the_photo_batch_still_finds_a_destination_after_its_address_changed(): void
    {
        // 47 of the 83 photo entries name an address that ends in -sample.
        $hamiguitan = Destination::create(['name' => 'Mount Hamiguitan Range Wildlife Sanctuary [SAMPLE]', 'slug' => 'mount-hamiguitan-range-wildlife-sanctuary-sample',
            'location' => 'San Isidro, Governor Generoso and Mati', 'type' => 'Nature & Leisure', 'is_accredited' => true, 'itinerary_role' => 'sightseeing']);
        $this->artisan('destinations:rename-sample-slugs', ['--apply' => true])->assertSuccessful();
        $this->assertSame('mount-hamiguitan-range-wildlife-sanctuary', $hamiguitan->fresh()->slug);

        $this->artisan('destinations:apply-photos')->expectsOutputToContain('1 photos ready')->assertSuccessful();
    }

    public function test_undo_puts_the_old_address_back(): void
    {
        $oak = $this->destination('Oak Island [SAMPLE]', 'oak-island-sample');
        $this->artisan('destinations:rename-sample-slugs', ['--apply' => true])->assertSuccessful();

        $this->artisan('destinations:rename-sample-slugs', ['--undo' => true, '--apply' => true])->assertSuccessful();

        $this->assertSame('oak-island-sample', $oak->fresh()->slug);
        $this->assertSame(0, DB::table('destination_slug_redirects')->count());
    }
}
