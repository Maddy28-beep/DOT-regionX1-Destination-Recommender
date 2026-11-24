<?php

namespace Tests\Feature;

use App\Console\Commands\PublishReferencePlaces;
use App\Models\Destination;
use App\Models\Region;
use Database\Seeders\ReferencePlaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublishReferencePlacesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Davao City', 'Island Garden City of Samal', 'Davao del Norte', 'Davao Oriental'] as $name) {
            Region::firstOrCreate(['name' => $name]);
        }
    }

    public function test_the_dry_run_changes_nothing(): void
    {
        $this->seed(ReferencePlaceSeeder::class);

        $this->artisan('destinations:publish-reference-places')->expectsOutputToContain('Dry run: nothing changed.')->assertSuccessful();

        $this->assertSame(0, Destination::publiclyVisible()->count());
    }

    public function test_it_turns_the_reference_places_into_public_catalogue_listings(): void
    {
        $this->seed(ReferencePlaceSeeder::class);
        $before = Destination::withoutGlobalScopes()->count();

        $this->artisan('destinations:publish-reference-places', ['--apply' => true])->expectsOutputToContain('2 listings published.')->assertSuccessful();

        $this->assertSame($before, Destination::withoutGlobalScopes()->count(), 'The existing rows are updated, not duplicated.');
        $public = Destination::publiclyVisible()->orderBy('name')->get();
        $this->assertSame(['Magsaysay Park', 'Wishing Island'], $public->pluck('name')->all());
        $this->assertSame(['excluded', 'excluded'], $public->pluck('itinerary_role')->all());
        $this->assertNotSame(PublishReferencePlaces::REFERENCE_NOTE, $public->first()->description);
        $this->assertSame('Island Garden City of Samal', $public->last()->region->name);
    }

    public function test_it_creates_them_where_they_do_not_exist_and_a_second_run_changes_nothing(): void
    {
        $this->artisan('destinations:publish-reference-places', ['--apply' => true])->assertSuccessful();
        $this->assertSame(2, Destination::publiclyVisible()->count());

        $this->artisan('destinations:publish-reference-places', ['--apply' => true])->expectsOutputToContain('0 listings ready.')->assertSuccessful();
        $this->assertSame(2, Destination::withoutGlobalScopes()->count());
    }

    public function test_re_running_the_reference_seeder_does_not_hide_them_again(): void
    {
        $this->seed(ReferencePlaceSeeder::class);
        $this->artisan('destinations:publish-reference-places', ['--apply' => true])->assertSuccessful();

        $this->seed(ReferencePlaceSeeder::class);

        $this->assertSame(2, Destination::publiclyVisible()->count());
    }

    public function test_a_live_server_needs_the_flag(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('destinations:publish-reference-places', ['--apply' => true])->expectsOutputToContain('--allow-production')->assertFailed();
        $this->assertSame(0, Destination::withoutGlobalScopes()->count());
    }
}
