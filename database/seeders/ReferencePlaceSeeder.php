<?php

namespace Database\Seeders;

use App\Models\Destination;
use App\Models\Region;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Well-known Davao Region attractions that published itineraries keep
 * mentioning but that are not DOT-accredited listings in this catalogue.
 *
 * They are stored as destinations with is_accredited = false on purpose:
 *   - publiclyVisible() requires accreditation, so none of them appears on the
 *     site, in search, in the survey's place picker, or in a recommendation,
 *     and this site never presents an unaccredited place as accredited;
 *   - they exist so itinerary baskets coded from published sources can be
 *     matched to a real row (and Apriori can count them), and so a DOT Admin
 *     can promote one to an accredited listing if it later qualifies.
 *
 * Only the name, municipality/province and a broad type are recorded. No
 * coordinates, fees, hours or ratings: none of those has been verified, and
 * the catalogue's rule is that unknown stays blank rather than guessed.
 *
 * Re-runnable (matched on slug):
 *   php artisan db:seed --class=ReferencePlaceSeeder
 */
class ReferencePlaceSeeder extends Seeder
{
    private const NOTE = 'Reference place: not DOT-accredited and not shown to travelers. Recorded so published itineraries that mention it can be matched.';

    /** [name, location, region name, type] */
    private const PLACES = [
        ['Museo Dabawenyo', 'Davao City', 'Davao City', 'Cultural Heritage'],
        ['San Pedro Cathedral', 'Davao City', 'Davao City', 'Cultural Heritage'],
        ['Magsaysay Park', 'Davao City', 'Davao City', 'Cultural Heritage'],
        ['Roxas Night Market', 'Davao City', 'Davao City', 'Cultural Heritage'],
        ['Japanese Tunnel', 'Davao City', 'Davao City', 'Cultural Heritage'],
        ['Bankerohan Public Market', 'Davao City', 'Davao City', 'Cultural Heritage'],
        ['Shrine of the Holy Infant Jesus of Prague', 'Davao City', 'Davao City', 'Cultural Heritage'],
        ['Hagimit Falls', 'Island Garden City of Samal, Davao del Norte', 'Island Garden City of Samal', 'Nature & Leisure'],
        ['Talikud Island', 'Island Garden City of Samal, Davao del Norte', 'Island Garden City of Samal', 'Beach & Leisure'],
        ['Isla Reta', 'Island Garden City of Samal, Davao del Norte', 'Island Garden City of Samal', 'Beach & Leisure'],
        ['Coral Garden', 'Island Garden City of Samal, Davao del Norte', 'Island Garden City of Samal', 'Beach & Leisure'],
        ['Vanishing Island', 'Island Garden City of Samal, Davao del Norte', 'Island Garden City of Samal', 'Beach & Leisure'],
        ['Wishing Island', 'Island Garden City of Samal, Davao del Norte', 'Island Garden City of Samal', 'Beach & Leisure'],
        ['Sabang Cliff', 'Island Garden City of Samal, Davao del Norte', 'Island Garden City of Samal', 'Beach & Leisure'],
        ['Kaputian Beach', 'Island Garden City of Samal, Davao del Norte', 'Island Garden City of Samal', 'Beach & Leisure'],
        ['Giant Clam Sanctuary', 'Island Garden City of Samal, Davao del Norte', 'Island Garden City of Samal', 'Nature & Leisure'],
        ['Malipano Island', 'Island Garden City of Samal, Davao del Norte', 'Island Garden City of Samal', 'Beach & Leisure'],
        ['Monfort Bat Cave', 'Island Garden City of Samal, Davao del Norte', 'Island Garden City of Samal', 'Nature & Leisure'],
        ['Dayang Beach', 'Island Garden City of Samal, Davao del Norte', 'Island Garden City of Samal', 'Beach & Leisure'],
        ['Diaz Island', 'Island Garden City of Samal, Davao del Norte', 'Island Garden City of Samal', 'Beach & Leisure'],
        ['Angels Cove', 'Island Garden City of Samal, Davao del Norte', 'Island Garden City of Samal', 'Beach & Leisure'],
        ['Aliwagwag Falls', 'Cateel, Davao Oriental', 'Davao Oriental', 'Nature & Leisure'],
        ['Subangan Museum', 'City of Mati, Davao Oriental', 'Davao Oriental', 'Cultural Heritage'],
        ['San Salvador del Mundo Church', 'City of Mati, Davao Oriental', 'Davao Oriental', 'Cultural Heritage'],
        ['Pusan Point', 'Caraga, Davao Oriental', 'Davao Oriental', 'Nature & Leisure'],
    ];

    public function run(): void
    {
        $regions = Region::pluck('id', 'name');

        foreach (self::PLACES as [$name, $location, $region, $type]) {
            Destination::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'location' => $location,
                    'region_id' => $regions[$region] ?? null,
                    'type' => $type,
                    'description' => self::NOTE,
                    'is_accredited' => false,
                    'featured' => false,
                ],
            );
        }
    }
}
