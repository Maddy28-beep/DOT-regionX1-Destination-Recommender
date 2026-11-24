<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FillRestaurantCuisinesTest extends TestCase
{
    use RefreshDatabase;

    private function restaurant(string $name, ?string $cuisine): Restaurant
    {
        return Restaurant::create(['name' => $name, 'slug' => str($name)->slug()->toString(), 'location' => 'Davao City', 'cuisine_type' => $cuisine]);
    }

    public function test_the_dry_run_changes_nothing(): void
    {
        $tsuru = $this->restaurant('Tsuru Japanese Restaurant & Sushi Bar', null);

        $this->artisan('restaurants:fill-cuisines')->expectsOutputToContain('1 cuisines ready (dry run).')->assertSuccessful();

        $this->assertNull($tsuru->fresh()->cuisine_type);
    }

    public function test_it_fills_only_empty_cuisines_and_leaves_the_rest_alone(): void
    {
        $empty = $this->restaurant('Tsuru Japanese Restaurant & Sushi Bar', '');
        $none = $this->restaurant('Diskanso Cafe', null);
        $set = $this->restaurant('Vikings', 'Buffet');
        $unknown = $this->restaurant('A Place Not On The List', null);

        $this->artisan('restaurants:fill-cuisines', ['--apply' => true])->assertSuccessful();

        $this->assertSame('Japanese', $empty->fresh()->cuisine_type);
        $this->assertSame('Cafe', $none->fresh()->cuisine_type);
        $this->assertSame('Buffet', $set->fresh()->cuisine_type);
        $this->assertNull($unknown->fresh()->cuisine_type);
    }
}
