<?php

namespace Database\Seeders;

use App\Models\Event;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * PLACEHOLDER DATA. These rows exist so the Events calendar has something to
 * show during development and demos; the dates, venues and descriptions were
 * written for that purpose and have NOT been confirmed with DOT Region XI or
 * the organisers. Replace them with the official calendar before the site
 * presents them as fact.
 *
 * Not part of DatabaseSeeder: run it deliberately with
 *   php artisan db:seed --class=EventSeeder
 * Safe to re-run; rows are matched on slug.
 */
class EventSeeder extends Seeder
{
    public function run(): void
    {
        $events = [
            ['Kadayawan Festival', 'festival', '2026-08-14', '2026-08-23', 'Davao City', null, 'Davao\'s thanksgiving celebration of the harvest and its indigenous heritage: street dancing, floral floats and food fairs.'],
            ['Durian Harvest Fair', 'food', '2026-08-28', '2026-08-30', 'Calinan, Davao City', null, 'Tastings, farm tours and local growers selling in-season durian and other fruit.'],
            ['Mt. Apo Trail Run', 'sports', '2026-09-19', null, 'Digos City', '5:00 AM', 'A trail run through the foothills of Mt. Apo with short and long routes.'],
            ['Samal Island Food Crawl', 'food', '2026-09-26', '2026-09-27', 'Island Garden City of Samal', null, 'A two-day walk between island eateries and seaside food stalls.'],
            ['T\'boli Cultural Showcase', 'cultural', '2026-10-10', '2026-10-11', 'Davao City', null, 'Traditional T\'boli music, weaving and dance, with a craft market.'],
            ['Philippine Eagle Awareness Walk', 'nature', '2026-10-17', null, 'Malagos, Davao City', '7:00 AM', 'A guided walk raising awareness for the Philippine Eagle and its forest habitat.'],
            ['Dahican Surf Cup', 'sports', '2026-10-23', '2026-10-25', 'Mati, Davao Oriental', null, 'Surf competition on Dahican beach, open to local and visiting riders.'],
            ['Cacao and Chocolate Fair', 'food', '2026-10-31', null, 'Malagos, Davao City', '9:00 AM', 'Davao cacao farmers and chocolate makers, with tastings and tree-to-bar demos.'],
            ['Island Beach Cleanup Day', 'nature', '2026-11-07', null, 'Island Garden City of Samal', '6:30 AM', 'Volunteer shoreline cleanup; bring gloves and a refillable bottle.'],
            ['Indigenous Peoples Gathering', 'cultural', '2026-11-14', '2026-11-15', 'Davao Oriental', null, 'A gathering of the region\'s indigenous communities: rituals, storytelling and crafts.'],
            ['Davao Food Festival', 'food', '2026-11-21', '2026-11-22', 'Davao City', null, 'Regional dishes and street food from across the Davao Region.'],
            ['Lantern Parade', 'festival', '2026-12-12', null, 'Davao City', '6:00 PM', 'An evening parade of handmade lanterns through the city centre.'],
            ['Christmas Market', 'festival', '2026-12-18', '2026-12-20', 'Davao City', null, 'Local makers, seasonal food and live music in the lead-up to Christmas.'],
        ];

        foreach ($events as [$title, $category, $start, $end, $location, $time, $description]) {
            Event::updateOrCreate(
                ['slug' => Str::slug($title)],
                [
                    'title' => $title,
                    'category' => $category,
                    'starts_on' => $start,
                    'ends_on' => $end,
                    'location' => $location,
                    'time_label' => $time,
                    'description' => $description,
                ],
            );
        }
    }
}
