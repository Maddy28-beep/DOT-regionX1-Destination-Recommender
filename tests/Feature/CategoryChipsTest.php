<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Package;
use App\Models\Region;
use App\Models\Restaurant;
use App\Models\TourOperator;
use App\Support\CategoryChips;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A category chip needs enough places behind it; near-duplicate cuisines share one chip; catch-alls come last. */
class CategoryChipsTest extends TestCase
{
    use RefreshDatabase;

    /** The category chips' own markup, apart from the cards below them. */
    private function chipsOf(string $html): string
    {
        preg_match('/<nav class="catalog-categories".*?<\/nav>/s', $html, $m);

        return $m[0] ?? '';
    }

    private function destinations(string $type, int $count): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        for ($i = 1; $i <= $count; $i++) {
            Destination::create([
                'slug' => str($type)->slug().'-'.$i, 'name' => 'Place '.str($type)->slug().' '.$i, 'location' => 'Davao City', 'region_id' => $region->id,
                'type' => $type, 'is_accredited' => true, 'rating' => 4, 'review_count' => 1, 'price_tier' => 'Mid-range',
            ]);
        }
    }

    private function restaurants(string $cuisine, int $count): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        for ($i = 1; $i <= $count; $i++) {
            Restaurant::create([
                'slug' => str($cuisine)->slug().'-'.$i, 'name' => "$cuisine $i", 'location' => 'Davao City', 'region_id' => $region->id,
                'cuisine_type' => $cuisine, 'is_accredited' => true, 'rating' => 4, 'review_count' => 1, 'price_tier' => 'Mid-range',
            ]);
        }
    }

    public function test_a_category_with_fewer_than_three_places_gets_no_chip(): void
    {
        $this->destinations('Beach & Leisure', 3);
        $this->destinations('Events & Conventions', 1);

        $chips = $this->chipsOf($this->get(route('destinations.index'))->assertOk()->getContent());

        $this->assertStringContainsString('Beach &amp; Leisure', $chips);
        $this->assertStringNotContainsString('Events &amp; Conventions', $chips, 'its one place still shows on its card, just not as a chip');
    }

    public function test_the_category_already_chosen_keeps_its_chip_however_small(): void
    {
        $this->destinations('Beach & Leisure', 3);
        $this->destinations('Events & Conventions', 1);

        $chips = $this->chipsOf($this->get(route('destinations.index', ['type' => 'Events & Conventions']))->assertOk()->getContent());

        $this->assertStringContainsString('Events &amp; Conventions', $chips);
    }

    public function test_archived_places_are_not_counted(): void
    {
        $this->destinations('Farm Tourism', 3);
        Destination::where('type', 'Farm Tourism')->limit(2)->update(['archived_at' => now()]);

        $this->assertSame([], CategoryChips::visible(Destination::publiclyVisible(), 'type')->all());
    }

    public function test_cuisines_that_say_the_same_thing_share_one_chip(): void
    {
        $this->restaurants('Filipino', 1);
        $this->restaurants('Filipino & International', 1);
        $this->restaurants('Cafe & Filipino', 1);

        $this->assertSame(['Filipino'], CategoryChips::cuisines()->all(), 'three restaurants, one Filipino chip');
        $this->assertSame(3, $this->get(route('restaurants.index', ['cuisine_type' => 'Filipino']))->assertOk()->original->getData()['restaurants']->total());
    }

    public function test_a_cuisine_of_its_own_is_found_by_its_exact_name(): void
    {
        $this->restaurants('Farm-to-Table', 2);
        $this->restaurants('Filipino', 1);

        $this->assertSame(2, $this->get(route('restaurants.index', ['cuisine_type' => 'Farm-to-Table']))->original->getData()['restaurants']->total());
        $this->assertNull(CategoryChips::cuisineMembers('Farm-to-Table'));
    }

    public function test_tour_operators_show_the_catch_all_last_and_never_the_label_every_operator_has(): void
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        foreach (['General Travel & Tours' => 5, 'Island Cruises' => 3, 'Tour Operator' => 4] as $spec => $count) {
            for ($i = 1; $i <= $count; $i++) {
                TourOperator::create([
                    'slug' => str($spec)->slug().'-'.$i, 'name' => "$spec $i", 'region_id' => $region->id,
                    'specialization' => $spec, 'is_accredited' => true, 'rating' => 4, 'review_count' => 1,
                ]);
            }
        }

        $list = $this->get(route('tour-operators.index'))->assertOk()->original->getData()['specializations'];

        $this->assertSame(['Island Cruises', 'General Travel & Tours'], $list->all());
    }

    public function test_package_chips_wait_until_there_are_more_than_ten_packages(): void
    {
        $few = $this->get(route('packages.index'))->assertOk();
        $this->assertFalse($few->original->getData()['showCategories']);
        $few->assertDontSee('class="catalog-categories"', false);

        $region = Region::firstOrCreate(['name' => 'Davao City']);
        $operator = TourOperator::create(['slug' => 'op', 'name' => 'Op', 'region_id' => $region->id, 'is_accredited' => true, 'rating' => 4, 'review_count' => 1]);
        for ($i = 1; $i <= 11; $i++) {
            Package::create([
                'slug' => "pkg-$i", 'name' => "Package $i", 'tour_operator_id' => $operator->id, 'type' => 'Nature & Adventure',
                'is_accredited' => true, 'status' => Package::STATUS_LIVE, 'price' => 1000, 'duration_days' => 2,
            ]);
        }

        $many = $this->get(route('packages.index'))->assertOk();
        $this->assertTrue($many->original->getData()['showCategories']);
        $many->assertSee('class="catalog-categories"', false)->assertSee('Nature &amp; Adventure', false);
    }
}
