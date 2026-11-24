<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RestaurantContactNumberLengthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_restaurant_can_list_two_numbers(): void
    {
        $number = '(082) 317 2217 / +63 968 854 4516';
        $this->assertGreaterThan(20, strlen($number));

        $restaurant = Restaurant::create(['name' => 'Two Numbers', 'slug' => 'two-numbers', 'location' => 'Davao City', 'contact_number' => $number]);

        $this->assertSame($number, $restaurant->fresh()->contact_number);
        $this->assertTrue(Schema::hasColumn('restaurants', 'contact_number'));
    }
}
