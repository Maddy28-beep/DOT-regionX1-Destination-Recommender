<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Region;
use Database\Seeders\MabuhayAccommodationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 143 of the 222 accredited accommodations came off the DOT list typed
 * "Mabuhay Accommodation" -- an accreditation category, not a kind of lodging.
 * Since `type` is the column the trip planner filters on when a traveller
 * states an accommodation preference, every one of them was unreachable by
 * anyone who asked for anything in particular.
 */
class MabuhayAccommodationTypeSeederTest extends TestCase
{
    use RefreshDatabase;

    private function mabuhay(string $name, string $slug): Accommodation
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        return Accommodation::create([
            'slug' => $slug, 'name' => $name, 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => MabuhayAccommodationTypeSeeder::RAW_CATEGORY,
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0,
        ]);
    }

    /**
     * The establishment's own name is the only evidence used. Retyping the
     * whole category as "Hotel" would have offered Altavista Beach Resort to a
     * traveller who asked for a hotel -- the very confusion the accommodation
     * preference exists to prevent.
     */
    public function test_the_category_is_resolved_from_the_establishments_own_name(): void
    {
        $cases = [
            'Altavista Beach Resort' => 'Beach Resort',
            'Coral Dive beach resort' => 'Beach Resort',
            'Ayuste Highland Mountain Resort' => 'Resort',
            'Adecor Garden Resort' => 'Resort',
            'Hotel Esse' => 'Hotel',
            'Casa Leticia Boutique Hotel' => 'Hotel',
            'Ritz Inn' => 'Hotel',
            'Kim Bernice Homestay' => 'Homestay',
        ];

        foreach ($cases as $name => $expected) {
            $this->assertSame($expected,
                MabuhayAccommodationTypeSeeder::lodgingTypeFor($name, MabuhayAccommodationTypeSeeder::RAW_CATEGORY),
                "\"{$name}\" should be typed {$expected}.");
        }
    }

    /** A name that says nothing either way falls back to the general case. */
    public function test_an_unlabelled_name_falls_back_to_hotel(): void
    {
        foreach (["Edar's Place", 'Casa Julieta', 'Domicilio Lorenzo'] as $name) {
            $this->assertSame('Hotel',
                MabuhayAccommodationTypeSeeder::lodgingTypeFor($name, MabuhayAccommodationTypeSeeder::RAW_CATEGORY));
        }
    }

    /**
     * Most of the DOT list is typed properly already; only the Mabuhay rows
     * need interpreting, so everything else must pass straight through --
     * including a resort that really is called a hotel.
     */
    public function test_a_real_lodging_type_is_never_reinterpreted(): void
    {
        foreach (['Hotel', 'Resort', 'Beach Resort', 'Condominium Unit', null] as $rawType) {
            $this->assertSame($rawType,
                MabuhayAccommodationTypeSeeder::lodgingTypeFor('Pearl Farm Beach Resort', $rawType),
                'Only the raw accreditation category should ever be reinterpreted.');
        }
    }

    public function test_it_retypes_existing_rows_and_leaves_others_alone(): void
    {
        $resort = $this->mabuhay('Anrana Beach Resort', 'anrana-beach-resort');
        $hotel = $this->mabuhay('Hotel Cabaguio', 'hotel-cabaguio');

        $region = Region::sole();
        $untouched = Accommodation::create([
            'slug' => 'already-typed', 'name' => 'Somewhere Beach Resort', 'location' => 'Davao City',
            'region_id' => $region->id, 'type' => 'Condominium Unit',
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0,
        ]);

        (new MabuhayAccommodationTypeSeeder())->run();

        $this->assertSame('Beach Resort', $resort->fresh()->type);
        $this->assertSame('Hotel', $hotel->fresh()->type);
        $this->assertSame('Condominium Unit', $untouched->fresh()->type,
            'A row already carrying a real lodging type must not be rewritten.');
    }

    public function test_it_is_idempotent(): void
    {
        $accommodation = $this->mabuhay('Anrana Beach Resort', 'anrana-beach-resort');

        (new MabuhayAccommodationTypeSeeder())->run();
        (new MabuhayAccommodationTypeSeeder())->run();

        $this->assertSame('Beach Resort', $accommodation->fresh()->type);
        $this->assertSame(0, Accommodation::where('type', MabuhayAccommodationTypeSeeder::RAW_CATEGORY)->count());
    }
}
