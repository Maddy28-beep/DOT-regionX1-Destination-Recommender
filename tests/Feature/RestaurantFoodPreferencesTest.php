<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantFoodPreference as Preference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestaurantFoodPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private function restaurant(string $name): Restaurant
    {
        return Restaurant::create(['name' => $name, 'slug' => str($name)->slug(), 'location' => 'Davao City', 'is_accredited' => true]);
    }

    private function evidence(Restaurant $restaurant, string $key, array $extra = []): Preference
    {
        return $restaurant->foodPreferences()->create($extra + ['preference' => $key, 'status' => 'confirmed',
            'source_url' => 'https://example.com/operator-menu', 'source_name' => 'Operator menu',
            'evidence' => 'The operator explicitly lists these options.', 'checked_on' => today()->toDateString()]);
    }

    private function names(array $filters = []): array
    {
        $response = $this->get(route('restaurants.index', $filters))->assertOk();
        return collect($response->original->getData()['restaurants']->items())->pluck('name')->sort()->values()->all();
    }

    public function test_home_dining_search_passes_verified_preferences_without_affecting_other_tabs(): void
    {
        $both = $this->restaurant('Both Choices');
        $this->evidence($both, 'vegetarian');
        $this->evidence($both, 'vegan');
        $this->restaurant('Unknown Choices');
        $response = $this->get('/search?'.http_build_query([
            'purpose' => 'restaurants', 'food' => ['vegetarian', 'vegan', 'bad', ['invalid'], 'vegan'],
            'meal' => 'lunch', 'date' => '2026-12-01', 'cuisine_type' => 'Cafe',
        ]))->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame(['vegetarian', 'vegan'], $query['food']);
        $this->assertSame('lunch', $query['meal']);
        $this->assertSame('2026-12-01', $query['date']);
        $this->assertSame('Cafe', $query['cuisine_type']);
        $filtered = $this->get('/search?'.http_build_query(['purpose' => 'restaurants', 'food' => ['vegetarian', 'vegan']]))->assertRedirect();
        $this->get($filtered->headers->get('Location'))->assertOk()->assertSee('Both Choices')->assertDontSee('Unknown Choices');
        foreach (['destinations', 'accommodations', 'packages', 'souvenir_centers'] as $purpose) {
            $other = $this->get('/search?'.http_build_query(['purpose' => $purpose, 'food' => ['vegan']]))->assertRedirect();
            $this->assertStringNotContainsString('food', $other->headers->get('Location'));
        }
    }

    public function test_unknowns_are_not_inferred_from_names_or_cuisine_and_filters_require_all_choices(): void
    {
        $unknown = $this->restaurant('Halal Vegan Name Only');
        $veg = $this->restaurant('Vegetarian Choice');
        $both = $this->restaurant('Both Choices');
        $this->evidence($veg, 'vegetarian');
        $this->evidence($both, 'vegetarian');
        $this->evidence($both, 'vegan');
        $this->assertCount(3, $this->names());
        $this->assertSame(['Both Choices', 'Vegetarian Choice'], $this->names(['food' => ['vegetarian']]));
        $this->assertSame(['Both Choices'], $this->names(['food' => ['vegetarian', 'vegan']]));
        $this->assertSame([], $this->names(['food' => ['halal_certified']]));
        $this->assertCount(3, $this->names(['food' => ['invalid', ['vegan']]]));
        $this->assertSame(['Both Choices'], $this->names(['food' => ['vegan'], 'q' => 'Both']));
        $this->get('/restaurants/'.$unknown->slug)->assertOk()->assertSee('Not confirmed')->assertSee('Food preferences');
        $this->get('/restaurants/'.$both->slug)->assertOk()->assertSee('Available')->assertSee('Operator menu')->assertSee('Checked');
    }

    public function test_halal_requires_a_current_complete_certificate_and_menu_evidence_cannot_promote_it(): void
    {
        $current = $this->restaurant('Current Certificate');
        $expired = $this->restaurant('Expired Certificate');
        $claim = $this->restaurant('No Pork Claim');
        $future = $this->restaurant('Future Certificate');
        $certificate = ['certifier' => 'Test certification body', 'certificate_reference' => 'TEST-123', 'coverage' => 'Entire kitchen',
            'valid_from' => today()->subMonth()->toDateString(), 'expires_on' => today()->addMonth()->toDateString()];
        $this->evidence($current, 'halal_certified', $certificate);
        $this->evidence($expired, 'halal_certified', array_replace($certificate, ['expires_on' => today()->subDay()->toDateString()]));
        $this->evidence($claim, 'halal_certified');
        $this->evidence($future, 'halal_certified', array_replace($certificate, ['valid_from' => today()->addDay()->toDateString()]));
        $this->assertSame(['Current Certificate'], $this->names(['food' => ['halal_certified']]));
        $this->get('/restaurants/'.$expired->slug)->assertOk()->assertSee('Not confirmed')->assertDontSee('Valid until');
        $this->get('/restaurants/'.$current->slug)->assertOk()->assertSee('Entire kitchen')->assertSee('Test certification body')->assertSee('Valid until');
        $current->foodPreferences()->update(['source_url' => null]);
        $this->assertSame([], $this->names(['food' => ['halal_certified']]));
    }

    public function test_evidence_import_preserves_restaurant_data_and_is_repeat_safe(): void
    {
        $entry = collect(json_decode(file_get_contents(database_path('data/restaurant-food-preferences-2026-11-24.json')), true))->firstWhere('name', 'The Vegan Dinosaur');
        $restaurant = Restaurant::create(['name' => $entry['name'], 'slug' => $entry['slug'], 'location' => $entry['location'], 'is_accredited' => true]);
        $before = $restaurant->fresh()->getRawOriginal();
        $this->artisan('restaurants:food-preferences')->expectsOutputToContain('3 preference records ready')->assertSuccessful();
        $this->assertSame(0, Preference::count());
        $this->artisan('restaurants:food-preferences', ['--apply' => true])->assertSuccessful();
        $this->artisan('restaurants:food-preferences', ['--apply' => true])->expectsOutputToContain('0 preference records saved')->assertSuccessful();
        $this->assertSame(3, Preference::count());
        $this->assertSame(2, Preference::confirmed()->count());
        $this->assertSame($before, $restaurant->fresh()->getRawOriginal());
    }
}
