<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use Illuminate\Console\Command;

/**
 * Gives a cuisine to restaurants that have none, so they appear under a category on the restaurants page.
 *
 * Only an empty cuisine is filled; one that is already set is never changed. The names are matched exactly. Only restaurants
 * whose kind of food is clear from their name or from a published source are listed; the others stay empty rather than guessed.
 * Without --apply it only shows the plan.
 */
class FillRestaurantCuisines extends Command
{
    protected $signature = 'restaurants:fill-cuisines {--apply : Write the cuisines}';
    protected $description = 'Fill the cuisine of restaurants that have none (empty ones only), from a reviewed list.';

    /** Exact restaurant name => cuisine. The labels reuse the ones the restaurants page already groups into chips. */
    public const CUISINES = [
        'Axl Dave Bibingka Food Corporation' => 'Filipino',
        "Qan's Bibingka and Eatery" => 'Filipino',
        'Kusina Kabacan' => 'Filipino',
        'Kusina Dabaw — San Pedro Street' => 'Filipino',
        'George BBQ' => 'Filipino barbecue',
        'Braytside Cakes Catering Services' => 'Bakery & cakes',
        'ClareBites Cafe' => 'Cafe',
        'Diskanso Cafe' => 'Cafe',
        "Ibing's Cafe" => 'Cafe',
        'Sabor Elas Cafe' => 'Cafe',
        "Jenniel's Caf" => 'Cafe',
        'Zorbox Cafe' => 'Cafe',
        'Viewdeck Cafe — Malagos Garden Resort' => 'Cafe',
        'Koi Cafe — Malagos Garden Resort' => 'Cafe',
        "Delmor's Grill House" => 'Grill house',
        'PortSide Grill House Food Corporation' => 'Grill house',
        'Hugo - Montevista Branch' => 'Italian & pizza',
        "Hugo's Pizza" => 'Italian & pizza',
        'Pizzakaya - Maco' => 'Italian & pizza',
        'Marinatuna' => 'Seafood',
        'Marinatuna — Rogen Inn Compound' => 'Seafood',
        'New Davao Famous Restaurant Corp' => 'Chinese',
        'Tsuru Incorporated(Camus)' => 'Japanese',
        'Tsuru Japanese Restaurant & Sushi Bar' => 'Japanese',
        'Vikings' => 'International buffet',
        'Matina Swiss Deli and Restaurant' => 'European & Deli',
        'VANDA — Park Inn by Radisson Davao' => 'Filipino & International',
        'Rhapsody Asian Fusion — Aria Hotel & Residences' => 'Asian fusion',
        'Jollibee Davao Bolton' => 'Fast food',
        'Vista Restaurant — Eden Nature Park' => 'Filipino & International',
        'Resto-Beach — Paradise Island Park & Beach Resort' => 'Filipino & International',
        'Chow2 Restaurant & Catering Services' => 'Filipino',
        "Leonora's Restaurant" => 'Cafe',
    ];

    public function handle(): int
    {
        $filled = 0;
        foreach (self::CUISINES as $name => $cuisine) {
            $rows = Restaurant::withoutGlobalScopes()->where('name', $name)->where(fn ($q) => $q->whereNull('cuisine_type')->orWhere('cuisine_type', ''))->get();
            foreach ($rows as $restaurant) {
                $this->line(sprintf('%-52s -> %s', $restaurant->name, $cuisine));
                if ($this->option('apply')) {
                    $restaurant->forceFill(['cuisine_type' => $cuisine])->saveQuietly();
                }
                $filled++;
            }
        }

        $left = Restaurant::withoutGlobalScopes()->where(fn ($q) => $q->whereNull('cuisine_type')->orWhere('cuisine_type', ''))->orderBy('name')->pluck('name');
        $this->info($filled.($this->option('apply') ? ' cuisines filled.' : ' cuisines ready (dry run).'));
        if ($this->option('apply') && $left->isNotEmpty()) {
            $this->warn($left->count().' still have none: '.$left->implode('; '));
        }

        return self::SUCCESS;
    }
}
