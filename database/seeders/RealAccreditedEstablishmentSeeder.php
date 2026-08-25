<?php

namespace Database\Seeders;

use App\Models\Accommodation;
use App\Models\AccreditationRecord;
use App\Models\Region;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use Illuminate\Database\Seeder;

/**
 * Real DOT Region XI accredited establishments, curated from the official
 * "LIST OF DOT ACCREDITED TOURISM ESTABLISHMENTS, REGION XI (DAVAO REGION)"
 * spreadsheet (as of 31 July 2026, supplied by the user). Every accreditation
 * number, address, and expiry date below is copied from that real government
 * record verbatim -- nothing here is invented. Coordinates were geocoded from
 * each real address via OpenStreetMap Nominatim (matching the project's
 * existing Leaflet/OSM stack, no API key required).
 *
 * Deliberately left at rating=0/review_count=0 rather than assigning a
 * plausible-looking fake rating: these are real businesses with no real
 * review data behind them yet, so "Not yet rated" is the honest state.
 * (See the accompanying fix to the rating-pill partials, which previously
 * rendered a bare "Star 0.0" for any listing with zero reviews.)
 *
 * Must run before AccreditationSeeder, which now skips any listing that
 * already has an AccreditationRecord -- so the real accreditation numbers
 * created here are never overwritten by that seeder's synthetic ones.
 */
class RealAccreditedEstablishmentSeeder extends Seeder
{
    public function run(): void
    {
        $regions = Region::pluck('id', 'name');

        $this->seedAccommodations($regions);
        $this->seedRestaurants($regions);
        $this->seedTourOperators($regions);
        $this->seedSouvenirCenters($regions);
    }

    private function seedAccommodations($regions): void
    {
        $accommodations = [
            [
                'slug' => 'acacia-hotel-davao', 'name' => 'Acacia Hotel Davao', 'type' => 'Hotel',
                'location' => 'JP Laurel Ave., Lanang, Brgy. Wilfredo Aquino, Davao City', 'region' => 'Davao City',
                'latitude' => 7.0997238, 'longitude' => 125.6245101, 'distance_km' => 3.2,
                'price_tier' => 'Mid-range', 'rooms' => 260.0,
                'accno' => 'DOT-R11-HTL-00112-2021', 'issued' => '2025-03-04', 'expiry' => '2026-10-31',
                'contact' => '(082) 298 8088', 'email' => 'acaciahotelsdavao@gmail.com',
            ],
            [
                'slug' => 'apo-view-hotel', 'name' => 'Apo View Hotel', 'type' => 'Hotel',
                'location' => '150 J. Camus St., Brgy 4-A, Davao City', 'region' => 'Davao City',
                'latitude' => 7.0700062, 'longitude' => 125.6075976, 'distance_km' => 0.7,
                'price_tier' => 'Mid-range', 'rooms' => 150.0,
                'accno' => 'DOT-R11-HTL-01326-2024', 'issued' => '2024-06-11', 'expiry' => '2026-10-31',
                'contact' => '082 221 6430', 'email' => 'apoview.bc@gmail.com',
            ],
            [
                'slug' => 'dusit-d2-davao', 'name' => 'Dusit D2 Davao', 'type' => 'Hotel',
                'location' => 'Stella Hizon Reyes Drive, Brgy. Pampanga, Davao City', 'region' => 'Davao City',
                'latitude' => 7.1190572, 'longitude' => 125.6486787, 'distance_km' => 6.5,
                'price_tier' => 'Premium', 'rooms' => 221.0,
                'accno' => 'DOT-R11-HTL-00625-2023', 'issued' => '2025-06-20', 'expiry' => '2026-10-31',
                'contact' => '0906 5876506', 'email' => 'leah.puyod@dusit.com',
            ],
            [
                'slug' => 'gohotels-davao', 'name' => 'GoHotels Davao', 'type' => 'Hotel',
                'location' => 'Km.7 J.P. Laurel Ave., cor.N.Arroyo St., Lanang, Davao City', 'region' => 'Davao City',
                'latitude' => 7.0983160, 'longitude' => 125.6346620, 'distance_km' => 3.7,
                'price_tier' => 'Mid-range', 'rooms' => 183.0,
                'accno' => 'DOT-R11-HTL-00484-2022', 'issued' => '2024-09-10', 'expiry' => '2026-10-31',
                'contact' => '082 2283836', 'email' => 'rodjave.repollo@robinsonsland.com',
            ],
            [
                'slug' => 'grand-regal-hotel-davao', 'name' => 'Grand Regal Hotel Davao', 'type' => 'Hotel',
                'location' => 'Km 7, JP Laurel Avenue, Davao City', 'region' => 'Davao City',
                'latitude' => 7.1039607, 'longitude' => 125.6412286, 'distance_km' => 4.6,
                'price_tier' => 'Mid-range', 'rooms' => 216.0,
                'accno' => 'DOT-R11-HTL-00080-2021', 'issued' => '2024-06-11', 'expiry' => '2026-10-31',
                'contact' => '(082) 235 0888', 'email' => 'executive@thegrandregalhotel.com',
            ],
            [
                'slug' => 'microtel-by-wyndham-davao', 'name' => 'Microtel by Wyndham - Davao', 'type' => 'Hotel',
                'location' => 'Damosa Gateway Complex, Angliongto Road, Lanang, Davao City', 'region' => 'Davao City',
                'latitude' => 7.0983160, 'longitude' => 125.6346620, 'distance_km' => 3.7,
                'price_tier' => 'Mid-range', 'rooms' => 51.0,
                'accno' => 'DOT-R11-HTL-00029-2020', 'issued' => '2024-10-28', 'expiry' => '2026-10-31',
                'contact' => '(082) 233-2333', 'email' => 'davao@microtel.ph',
            ],
            [
                'slug' => 'park-inn-by-radisson-davao', 'name' => 'Park Inn by Radisson Davao', 'type' => 'Hotel',
                'location' => 'SM Lanang Complex, J.P. Laurel Ave., Lanang, Davao City', 'region' => 'Davao City',
                'latitude' => 7.0983160, 'longitude' => 125.6346620, 'distance_km' => 3.7,
                'price_tier' => 'Premium', 'rooms' => 202.0,
                'accno' => 'DOT-R11-HTL-00118-2021', 'issued' => '2024-07-11', 'expiry' => '2026-10-31',
                'contact' => '(082) 272 7600', 'email' => 'Flordeliza.Gamo@parkinn.com',
            ],
            [
                'slug' => 'seda-abreeza-hotel', 'name' => 'Seda Abreeza Hotel', 'type' => 'Hotel',
                'location' => 'JP Laurel Ave., Bajada, Davao City', 'region' => 'Davao City',
                'latitude' => 7.0912881, 'longitude' => 125.6098813, 'distance_km' => 2.0,
                'price_tier' => 'Premium', 'rooms' => 186.0,
                'accno' => 'DOT-R11-HTL-00545-2023', 'issued' => '2024-06-11', 'expiry' => '2026-10-31',
                'contact' => '(082) 244 3020', 'email' => 'amomas.kimberly@sedahotels.com',
            ],
            [
                'slug' => 'waterfront-insular-hotel-davao', 'name' => 'Waterfront Insular Hotel Davao', 'type' => 'Hotel',
                'location' => 'Km. 7, Lanang, Davao City', 'region' => 'Davao City',
                'latitude' => 7.0983160, 'longitude' => 125.6346620, 'distance_km' => 3.7,
                'price_tier' => 'Premium', 'rooms' => 159.0,
                'accno' => 'DOT-R11-HTL-00047-2021', 'issued' => '2024-12-13', 'expiry' => '2026-10-31',
                'contact' => '0998 5948542', 'email' => 'wihd@waterfronthotels.net',
            ],
            [
                'slug' => 'the-royal-mandaya-hotel', 'name' => 'The Royal Mandaya Hotel', 'type' => 'Hotel',
                'location' => 'J. Palma Gil St., Brgy. 34-D, Davao City', 'region' => 'Davao City',
                'latitude' => 7.0687122, 'longitude' => 125.6119479, 'distance_km' => 0.5,
                'price_tier' => 'Premium', 'rooms' => 181.0,
                'accno' => 'DOT-R11-HTL-00512-2022', 'issued' => '2025-12-02', 'expiry' => '2026-10-31',
                'contact' => '(082) 225-8888', 'email' => 'info@theroyalmandayahotel.com',
            ],
            [
                'slug' => 'camp-holiday-resort-and-recreation-area', 'name' => 'Camp Holiday Resort and Recreation Area', 'type' => 'Resort',
                'location' => 'Brgy. Kinawitnon, Babak, Island Garden City of Samal', 'region' => 'Island Garden City of Samal',
                'latitude' => 7.1196022, 'longitude' => 125.6753633, 'distance_km' => 8.6,
                'price_tier' => 'Mid-range', 'rooms' => 79.0,
                'accno' => 'DOT-R11-RES-00314-2022', 'issued' => '2024-06-12', 'expiry' => '2026-10-31',
                'contact' => '0917 1028835', 'email' => 'campholidayresort@gmail.com',
            ],
            [
                'slug' => 'costa-marina-beach-resort', 'name' => 'Costa Marina Beach Resort', 'type' => 'Resort',
                'location' => 'Purok 7, Brgy Limao, Island Garden City of Samal, Davao del Norte', 'region' => 'Island Garden City of Samal',
                'latitude' => 7.0914704, 'longitude' => 125.6681576, 'distance_km' => 6.4,
                'price_tier' => 'Mid-range', 'rooms' => 23.0,
                'accno' => 'DOT-R11-RES-00017-2020', 'issued' => '2024-12-09', 'expiry' => '2026-10-31',
                'contact' => '0917 7958372', 'email' => 'costamarinabeachresort@yahoo.com',
            ],
            [
                'slug' => 'dahican-beach-resort-and-spa', 'name' => 'Dahican Beach Resort and Spa', 'type' => 'Resort',
                'location' => 'Sitio bangunay l, Dahican-Bobon RD., Brgy Bobon, Mati City, Davao Oriental', 'region' => 'Davao Oriental',
                'latitude' => 6.9521657, 'longitude' => 126.2166758, 'distance_km' => 68.0,
                'price_tier' => 'Mid-range', 'rooms' => 20.0,
                'accno' => 'DOT-R11-RES-01749-2026', 'issued' => '2026-04-05', 'expiry' => '2026-10-31',
                'contact' => '0949 8901880', 'email' => 'fumar.s@dahicanbeachresortandspa.com',
            ],
            [
                'slug' => 'dusit-thani-lubi-plantation-resort', 'name' => 'Dusit Thani Lubi Plantation Resort', 'type' => 'Resort',
                'location' => 'Kopiat Island, Brgy. Pindasan, Mabini, Davao De Oro', 'region' => 'Davao de Oro',
                'latitude' => 7.3084474, 'longitude' => 125.8534216, 'distance_km' => 37.3,
                'price_tier' => 'Premium', 'rooms' => 78.0,
                'accno' => 'DOT-R11-RES-01511-2025', 'issued' => '2025-05-13', 'expiry' => '2026-10-31',
                'contact' => '0906 5876506', 'email' => 'events.executive@dusit.com',
            ],
            [
                'slug' => 'd-leonor-inland-resort-adventure-park', 'name' => 'D\' Leonor Inland Resort & Adventure Park', 'type' => 'Resort',
                'location' => 'Purok 5 Brgy. Communal, Buhangin District, Davao City', 'region' => 'Davao City',
                'latitude' => 7.1474739, 'longitude' => 125.6240311, 'distance_km' => 8.4,
                'price_tier' => 'Mid-range', 'rooms' => 137.0,
                'accno' => 'DOT-R11-RES-01097-2024', 'issued' => '2025-10-06', 'expiry' => '2026-10-31',
                'contact' => '(082) 221 1160', 'email' => 'dleonorinlandresortadpark2023@gmail.com',
            ],
            [
                'slug' => 'holiday-oceanview-beach-resort', 'name' => 'Holiday Oceanview Beach Resort', 'type' => 'Resort',
                'location' => 'Purok 3 Brgy Camudmud, Island Garden City of Samal, Davao del Norte', 'region' => 'Island Garden City of Samal',
                'latitude' => 7.1777841, 'longitude' => 125.6964839, 'distance_km' => 14.9,
                'price_tier' => 'Mid-range', 'rooms' => 38.0,
                'accno' => 'DOT-R11-RES-01052-2024', 'issued' => '2024-02-10', 'expiry' => '2026-10-31',
                'contact' => '0956 6939450', 'email' => 'oceanviewhov@gmail.com',
            ],
            [
                'slug' => 'amaranta-suites', 'name' => 'Amaranta Suites', 'type' => 'Homestay',
                'location' => 'General Luna, A. Drive, Gahol St., Bajada, Davao City', 'region' => 'Davao City',
                'latitude' => 7.0912881, 'longitude' => 125.6098813, 'distance_km' => 2.0,
                'price_tier' => 'Budget-Friendly', 'rooms' => 30.0,
                'accno' => 'DOT-R11-MAB-00125-2021', 'issued' => '2024-08-10', 'expiry' => '2026-10-31',
                'contact' => '0917 7101233', 'email' => 'amarantasuites65@gmail.com',
            ],
            [
                'slug' => 'asrodel-hotel', 'name' => 'Asrodel Hotel', 'type' => 'Homestay',
                'location' => '78 Artiaga St., Davao City', 'region' => 'Davao City',
                'latitude' => 7.0677022, 'longitude' => 125.6135696, 'distance_km' => 0.6,
                'price_tier' => 'Budget-Friendly', 'rooms' => 50.0,
                'accno' => 'DOT-R11-MAB-00013-2020', 'issued' => '2024-11-12', 'expiry' => '2026-10-31',
                'contact' => '(082) 228 5746', 'email' => 'asrodelhotel@yahoo.com',
            ],
            [
                'slug' => 'casa-leticia-boutique-hotel', 'name' => 'Casa Leticia Boutique Hotel', 'type' => 'Homestay',
                'location' => 'J. Camus St., Barangay 4-A (Pob.), Davao City', 'region' => 'Davao City',
                'latitude' => 7.0700062, 'longitude' => 125.6075976, 'distance_km' => 0.7,
                'price_tier' => 'Budget-Friendly', 'rooms' => 42.0,
                'accno' => 'DOT-R11-MAB-01393-2024', 'issued' => '2026-10-02', 'expiry' => '2026-10-31',
                'contact' => '(082) 225 0101', 'email' => 'rvi.inc.dvo@gmail.com',
            ],
            [
                'slug' => 'bagobo-cultural-village', 'name' => 'Bagobo Cultural Village', 'type' => 'Homestay',
                'location' => 'Sitio Kidaran, Tibolo, Sta Cruz, Davao Del Sur', 'region' => 'Davao del Sur',
                'latitude' => 6.9375725, 'longitude' => 125.3593763, 'distance_km' => 31.8,
                'price_tier' => 'Budget-Friendly', 'rooms' => 3.0,
                'accno' => 'DOT-R11-MAB-01610-2025', 'issued' => '2025-02-10', 'expiry' => '2026-10-31',
                'contact' => '0951 1358346', 'email' => 'Tibolobagoboculturalvillage@gmail.com',
            ],
        ];

        foreach ($accommodations as $a) {
            $listing = Accommodation::create([
                'slug' => $a['slug'],
                'name' => $a['name'],
                'location' => $a['location'],
                'region_id' => $regions[$a['region']] ?? null,
                'type' => $a['type'],
                'dot_classification' => 'DOT-Accredited',
                'description' => "{$a['name']} is a DOT Region XI-accredited {$a['type']} (accreditation no. {$a['accno']}).",
                'is_accredited' => true,
                'rating' => 0,
                'review_count' => 0,
                'price_tier' => $a['price_tier'],
                'latitude' => $a['latitude'],
                'longitude' => $a['longitude'],
                'distance_km' => $a['distance_km'],
            ]);

            AccreditationRecord::create([
                'listing_kind' => 'accommodation',
                'listing_id' => $listing->id,
                'accreditation_number' => $a['accno'],
                'status' => 'Active',
                'issue_date' => $a['issued'],
                'expiration_date' => $a['expiry'],
            ]);
        }
    }

    private function seedRestaurants($regions): void
    {
        $restaurants = [
            [
                'slug' => 'jacks-ridge-resort-and-restaurant', 'name' => 'Jack\'s Ridge Resort and Restaurant', 'cuisine_type' => 'Filipino & International',
                'location' => 'BCG Drive Shrine Hills, Brgy. Matina Crossing, Davao City', 'region' => 'Davao City',
                'latitude' => 7.0587144, 'longitude' => 125.5688143, 'price_tier' => 'Mid-range',
                'accno' => 'DOT-R11-RST-00211-2021', 'issued' => '2026-04-01', 'expiry' => '2027-04-01',
                'contact' => '(082) 297 8830', 'email' => 'jacksridgedavao@yahoo.com.ph',
            ],
            [
                'slug' => 'garden-by-the-bay-corporation', 'name' => 'Garden By The Bay Corporation', 'cuisine_type' => 'Filipino & International',
                'location' => 'Maryknoll St., Lanang Brgy. Pampanga, Buhangin, Davao City', 'region' => 'Davao City',
                'latitude' => 7.1757358, 'longitude' => 125.5822933, 'price_tier' => 'Mid-range',
                'accno' => 'DOT-R11-RST-00204-2021', 'issued' => '2025-10-14', 'expiry' => '2026-10-14',
                'contact' => '(082) 234-8491', 'email' => 'gardenbay101@gmail.com',
            ],
            [
                'slug' => 'garden-oases-restaurant', 'name' => 'Garden Oases Restaurant', 'cuisine_type' => 'Filipino & International',
                'location' => 'Davao Wescon Bldg., Porras St., Bo. Obrero Brgy. 15-B Poblacion District Davao City', 'region' => 'Davao City',
                'latitude' => 7.0815902, 'longitude' => 125.6174669, 'price_tier' => 'Mid-range',
                'accno' => 'DOT-R11-RST-00597-2023', 'issued' => '2025-09-30', 'expiry' => '2026-09-30',
                'contact' => '0965 3870746', 'email' => 'davoexcel101@gmail.com',
            ],
            [
                'slug' => 'nonki-japanese-restaurant', 'name' => 'Nonki Japanese Restaurant', 'cuisine_type' => 'Japanese',
                'location' => 'Door A-1 Commercial bldg., Davao Autoville Ctr. Dev. Corp., F Torres St., Davao City', 'region' => 'Davao City',
                'latitude' => 7.0792630, 'longitude' => 125.6078174, 'price_tier' => 'Mid-range',
                'accno' => 'DOT-R11-RST-00171-2021', 'issued' => '2026-07-07', 'expiry' => '2027-07-07',
                'contact' => '(082) 226-3058', 'email' => 'nonkirestaurant@yahoo.com',
            ],
            [
                'slug' => 'rekado-filipino-cuisine', 'name' => 'Rekado Filipino Cuisine', 'cuisine_type' => 'Filipino',
                'location' => '1050 Jacinto Extension St, Brgy 11-B Poblacion, Davao City', 'region' => 'Davao City',
                'latitude' => 7.0805733, 'longitude' => 125.6195265, 'price_tier' => 'Mid-range',
                'accno' => 'DOT-R11-RST-01648-2025', 'issued' => '2025-12-13', 'expiry' => '2026-12-13',
                'contact' => '0916 3782865', 'email' => 'rekadofilipinocuisinedavao@gmail.com',
            ],
            [
                'slug' => 'the-swiss-deli-restaurant', 'name' => 'The Swiss Deli Restaurant', 'cuisine_type' => 'European & Deli',
                'location' => 'RMD Bldg., Mamay Road, Brgy. Alfonso Angliongto Sr., Davao City', 'region' => 'Davao City',
                'latitude' => 7.1100279, 'longitude' => 125.6353837, 'price_tier' => 'Mid-range',
                'accno' => 'DOT-R11-RST-00744-2023', 'issued' => '2026-01-13', 'expiry' => '2027-01-13',
                'contact' => '0917-701-3742', 'email' => 'dbdb@swissdelidavao.com',
            ],
            [
                'slug' => 'tsuru', 'name' => 'Tsuru', 'cuisine_type' => 'Japanese',
                'location' => 'Juna Ave., Matina Crossing, Davao City', 'region' => 'Davao City',
                'latitude' => 7.0603478, 'longitude' => 125.5936177, 'price_tier' => 'Mid-range',
                'accno' => 'DOT-R11-RST-00104-2021', 'issued' => '2026-10-02', 'expiry' => '2027-10-02',
                'contact' => '(082) 224 3716', 'email' => 'tsuru.inc.juna@gmail.com',
            ],
            [
                'slug' => 'tsuru-japanese-restaurant-sushi-bar', 'name' => 'Tsuru Japanese Restaurant & Sushi Bar', 'cuisine_type' => 'Japanese',
                'location' => 'Unit 201 2nd Level SM Lanang, JP Laurel Ave., Brgy San Antonio, Agdao District, Davao City', 'region' => 'Davao City',
                'latitude' => 7.0836817, 'longitude' => 125.6242789, 'price_tier' => 'Mid-range',
                'accno' => 'DOT-R11-RST-00748-2023', 'issued' => '2026-10-02', 'expiry' => '2027-10-02',
                'contact' => '(082) 297-6947', 'email' => 'tsuru.inc.smlanang@gmail.com',
            ],
            [
                'slug' => 'kapehan-sa-tambacan', 'name' => 'Kapehan sa Tambacan', 'cuisine_type' => 'Cafe & Filipino',
                'location' => 'Purok Tambacan, Brgy., Lizada, Toril, Davao City', 'region' => 'Davao City',
                'latitude' => 6.9991665, 'longitude' => 125.4978724, 'price_tier' => 'Budget-Friendly',
                'accno' => 'DOT-R11-RST-01713-2026', 'issued' => '2026-03-19', 'expiry' => '2027-03-19',
                'contact' => '0995 9020844', 'email' => 'dakongbalay.tambacan@gmail.com',
            ],
            [
                'slug' => 'balik-bukid-farm-and-kitchen', 'name' => 'Balik Bukid Farm and Kitchen', 'cuisine_type' => 'Farm-to-Table',
                'location' => 'Sandawa Plaza, Quimpo Blvd.,Ecoland, Brgy. Bucana, Davao City', 'region' => 'Davao City',
                'latitude' => 7.0539426, 'longitude' => 125.5989103, 'price_tier' => 'Mid-range',
                'accno' => 'DOT-R11-RST-01562-2025', 'issued' => '2025-08-07', 'expiry' => '2026-08-07',
                'contact' => '0915 0511123', 'email' => 'balikbukid@yahoo.com',
            ],
            [
                'slug' => 'hillside-88-bar-and-restaurant', 'name' => 'Hillside 88 Bar and Restaurant', 'cuisine_type' => 'Bar & Grill',
                'location' => 'Panaghiusa-2, Capitol Hill Brgy. Central, City of Mati, Davao Oriental', 'region' => 'Davao Oriental',
                'latitude' => 6.9521657, 'longitude' => 126.2166758, 'price_tier' => 'Mid-range',
                'accno' => 'DOT-R11-RST-01604-2025', 'issued' => '2025-09-25', 'expiry' => '2026-09-23',
                'contact' => '0951 4883489', 'email' => 'michaelbryan.ortega@gmail.com',
            ],
            [
                'slug' => 'bigfat-tummys-steak-and-seafood-restaurant', 'name' => 'BigFat Tummy\'s Steak and Seafood Restaurant', 'cuisine_type' => 'Steak & Seafood',
                'location' => 'Purok Narra, Brgy. Visayan Village, Tagum City, Davao del Norte', 'region' => 'Davao del Norte',
                'latitude' => 7.4302967, 'longitude' => 125.7987007, 'price_tier' => 'Budget-Friendly',
                'accno' => 'DOT-R11-RST-00675-2023', 'issued' => '2026-06-04', 'expiry' => '2027-06-04',
                'contact' => '0917 3175356', 'email' => 'bigfattummytagum.ph@gmail.com',
            ],
            [
                'slug' => 'kusina-kabacan', 'name' => 'Kusina Kabacan', 'cuisine_type' => 'Filipino',
                'location' => 'Patulangon Zone 1, Santa Cruz, Davao Del Sur', 'region' => 'Davao del Sur',
                'latitude' => 6.8653021, 'longitude' => 125.4314882, 'price_tier' => 'Budget-Friendly',
                'accno' => 'DOT-R11-RST-01337-2024', 'issued' => '2025-10-12', 'expiry' => '2026-10-12',
                'contact' => '0946 6302557', 'email' => 'kusinakabacanpatulangon@gmail.com',
            ],
            [
                'slug' => 'skyway21-nature-park', 'name' => 'Skyway21 Nature Park', 'cuisine_type' => 'Filipino',
                'location' => 'Purok Rambutan, New Bataan-Maragusan Highway, Brgy. Tupaz, Maragusan, Davao de Oro', 'region' => 'Davao de Oro',
                'latitude' => 7.3176458, 'longitude' => 126.1226535, 'price_tier' => 'Mid-range',
                'accno' => 'DOT-R11-RST-00707-2023', 'issued' => '2026-07-20', 'expiry' => '2027-07-20',
                'contact' => '0975 3572748', 'email' => 'alanbaino@gmail.com',
            ],
            [
                'slug' => 'arvies-cafe', 'name' => 'Arvie\'s Cafe', 'cuisine_type' => 'Cafe',
                'location' => 'Purok 4, Echavez St., Brgy. Pob., Nabunturan, Davao de Oro', 'region' => 'Davao de Oro',
                'latitude' => 7.6021804, 'longitude' => 125.9687792, 'price_tier' => 'Budget-Friendly',
                'accno' => 'DOT-R11-RST-01778-2026', 'issued' => '2026-01-06', 'expiry' => '2027-01-06',
                'contact' => '0951 8251502', 'email' => 'badianaarvie41@gmail.com',
            ],
        ];

        foreach ($restaurants as $r) {
            $listing = Restaurant::create([
                'slug' => $r['slug'],
                'name' => $r['name'],
                'location' => $r['location'],
                'region_id' => $regions[$r['region']] ?? null,
                'cuisine_type' => $r['cuisine_type'],
                'description' => "{$r['name']} is a DOT Region XI-accredited restaurant (accreditation no. {$r['accno']}).",
                'is_accredited' => true,
                'rating' => 0,
                'review_count' => 0,
                'price_tier' => $r['price_tier'],
                'contact_number' => $r['contact'],
                'latitude' => $r['latitude'],
                'longitude' => $r['longitude'],
            ]);

            AccreditationRecord::create([
                'listing_kind' => 'restaurant',
                'listing_id' => $listing->id,
                'accreditation_number' => $r['accno'],
                'status' => 'Active',
                'issue_date' => $r['issued'],
                'expiration_date' => $r['expiry'],
            ]);
        }
    }

    private function seedTourOperators($regions): void
    {
        $operators = [
            [
                'slug' => '168-pacific-tours-incorporated', 'name' => '168 Pacific Tours Incorporated', 'specialization' => 'General Travel & Tours',
                'location' => 'Building 101 5th Floor, Room 402 km5 Damaso-Quiñones Road, Buhangin Poblacion, Davao City', 'region' => 'Davao City',
                'latitude' => 7.0834755, 'longitude' => 125.6122187, 'price_tier' => 'Mid-range',
                'accno' => 'DOT-R11-TTA-00682-2023', 'issued' => '2026-07-07', 'expiry' => '2028-06-30',
                'contact' => '09171267365', 'email' => '168pacifictoursinc@gmail.com',
            ],
            [
                'slug' => 'acs-travel-and-tours', 'name' => 'ACS Travel and Tours', 'specialization' => 'General Travel & Tours',
                'location' => 'Door 3 Citrine Court Bldg. Buhangin Cabantian Road, Brgy. Buhangin, Davao City', 'region' => 'Davao City',
                'latitude' => 7.1087013, 'longitude' => 125.6134978, 'price_tier' => 'Mid-range',
                'accno' => 'DOT-R11-TTA-01265-2024', 'issued' => '2026-07-07', 'expiry' => '2028-06-30',
                'contact' => '0922 8912011', 'email' => 'acstravel@yahoo.com.ph',
            ],
            [
                'slug' => 'airtrips-travel-and-tours', 'name' => 'Airtrips Travel and Tours', 'specialization' => 'General Travel & Tours',
                'location' => 'Dr 7 Grand Menseng Hotel Magallanes St., Brgy. 1-A (Pob.), Davao City', 'region' => 'Davao City',
                'latitude' => 7.2355709, 'longitude' => 125.6432082, 'price_tier' => 'Mid-range',
                'accno' => 'DOT-R11-TTA-01143-2024', 'issued' => '2026-07-07', 'expiry' => '2028-06-30',
                'contact' => '082 3082761', 'email' => 'airtripstravel@gmail.com',
            ],
            [
                'slug' => 'discovery-tour-inc', 'name' => 'Discovery Tour, Inc.', 'specialization' => 'General Travel & Tours',
                'location' => 'G/F Door 109-110 Quimpo Boulevard Ecoland, Davao City', 'region' => 'Davao City',
                'latitude' => 7.0523770, 'longitude' => 125.5926361, 'price_tier' => 'Mid-range',
                'accno' => 'DOT-R11-TRA-00463-2022', 'issued' => '2026-02-07', 'expiry' => '2028-06-30',
                'contact' => '0917-111-8472', 'email' => 'acctgdvo@discoverytour.ph',
            ],
            [
                'slug' => 'etours-davao-inc', 'name' => 'Etours Davao Inc.', 'specialization' => 'General Travel & Tours',
                'location' => '#174-5th B Street Phase 1, Ecoland, Brgy Bucana, Davao City', 'region' => 'Davao City',
                'latitude' => 7.0539426, 'longitude' => 125.5989103, 'price_tier' => 'Mid-range',
                'accno' => 'DOT-R11-TTA-00646-2023', 'issued' => '2026-08-06', 'expiry' => '2028-06-30',
                'contact' => '0977 8344716', 'email' => 'etours.ph@gmail.com',
            ],
            [
                'slug' => 'gmc-travelmakers-gmc-tm-travel-agency', 'name' => 'GMC Travelmakers (GMC TM TRAVEL AGENCY)', 'specialization' => 'General Travel & Tours',
                'location' => 'Dr 5 Ana Dorina Bldg Belen Road Lanang, Brgy Vicente Hizon Sr., Davao City', 'region' => 'Davao City',
                'latitude' => 7.1110812, 'longitude' => 125.6496202, 'price_tier' => 'Mid-range',
                'accno' => 'DOT-R11-TRA-00245-2021', 'issued' => '2026-02-07', 'expiry' => '2028-06-30',
                'contact' => '082 3227673', 'email' => 'gmctm1@yahoo.com',
            ],
            [
                'slug' => 'greta-lakwatsera-travel-tours', 'name' => 'Greta Lakwatsera Travel & Tours', 'specialization' => 'General Travel & Tours',
                'location' => 'Jose Abad Santos St., Purok Sunflower, Brgy Magugpo Poblacion, Tagum City, Davao del Note', 'region' => 'Davao City',
                'latitude' => 7.4472333, 'longitude' => 125.8043897, 'price_tier' => 'Mid-range',
                'accno' => 'DOT-R11-TRA-00719-2023', 'issued' => '2026-07-07', 'expiry' => '2028-06-30',
                'contact' => '0917 8192361', 'email' => 'gretchen_ticketingoffice@yahoo.com',
            ],
            [
                'slug' => 'john-gold-travel-and-tour-services-corp', 'name' => 'John Gold Travel and Tour Services Corp.', 'specialization' => 'General Travel & Tours',
                'location' => '053 SM Lanang Premier, JP Laurel Ave., Lanang, Brgy. San Jose, Davao City', 'region' => 'Davao City',
                'latitude' => 7.1301521, 'longitude' => 125.6450236, 'price_tier' => 'Mid-range',
                'accno' => 'DOT-R11-TRA-01358-2024', 'issued' => '2025-07-18', 'expiry' => '2027-06-30',
                'contact' => '09177020364', 'email' => 'johngoldtravel08@gmail.com',
            ],
            [
                'slug' => 'card-mri-hijos-tours-inc', 'name' => 'Card MRI Hijos Tours Inc.', 'specialization' => 'General Travel & Tours',
                'location' => 'Anda, JP Rizal St., corner Anda St., Brgy. 3-A, Davao City', 'region' => 'Davao City',
                'latitude' => 7.0681600, 'longitude' => 125.6065232, 'price_tier' => 'Mid-range',
                'accno' => 'DOT-R11-TOP-00388-2022', 'issued' => '2026-05-26', 'expiry' => '2028-06-30',
                'contact' => '0998 7235719', 'email' => 'card.hijostours@gmail.com',
            ],
            [
                'slug' => 'karst-geo-travel-and-tours', 'name' => 'Karst Geo Travel and Tours', 'specialization' => 'General Travel & Tours',
                'location' => 'Door 8 Paseo de Legaspi, 115 Pelayo St., Brgy 3-A, Davao City', 'region' => 'Davao City',
                'latitude' => 7.0681600, 'longitude' => 125.6065232, 'price_tier' => 'Mid-range',
                'accno' => 'DOT-R11-TTA-00291-2021', 'issued' => '2026-05-25', 'expiry' => '2028-06-30',
                'contact' => '0917 1588309', 'email' => 'karstgeotravel@gmail.com',
            ],
            [
                'slug' => 'chrisma-travel-and-tours', 'name' => 'Chrisma Travel and Tours', 'specialization' => 'General Travel & Tours',
                'location' => 'KM 8 Purok 8 Ferc Fuels Bldg., Brgy Catalunan Pequeño, Davao City', 'region' => 'Davao City',
                'latitude' => 7.0754348, 'longitude' => 125.5202540, 'price_tier' => 'Mid-range',
                'accno' => 'DOT-R11-TTA-00410-2022', 'issued' => '2026-10-06', 'expiry' => '2028-06-30',
                'contact' => '0917 1376041', 'email' => 'chrismatravel01@gmail.com',
            ],
            [
                'slug' => 'angel-garnet-travel-and-tours', 'name' => 'Angel Garnet Travel and Tours', 'specialization' => 'General Travel & Tours',
                'location' => 'Door 1, 2nd Floor Mckenzie Bldg. Quimpo Blvd. Ecoland, Davao City', 'region' => 'Davao City',
                'latitude' => 7.0523770, 'longitude' => 125.5926361, 'price_tier' => 'Mid-range',
                'accno' => 'DOT-R11-TTA-00116-2021', 'issued' => '2026-06-25', 'expiry' => '2028-06-30',
                'contact' => '(082) 297 5974', 'email' => 'travel.angelgarnet@gmail.com',
            ],
        ];

        foreach ($operators as $t) {
            $listing = TourOperator::create([
                'slug' => $t['slug'],
                'name' => $t['name'],
                'location' => $t['location'],
                'region_id' => $regions[$t['region']] ?? null,
                'specialization' => $t['specialization'],
                'description' => "{$t['name']} is a DOT Region XI-accredited travel and tour agency (accreditation no. {$t['accno']}).",
                'is_accredited' => true,
                'rating' => 0,
                'review_count' => 0,
                'price_tier' => $t['price_tier'],
                'contact_number' => $t['contact'],
                'latitude' => $t['latitude'],
                'longitude' => $t['longitude'],
            ]);

            AccreditationRecord::create([
                'listing_kind' => 'tour_operator',
                'listing_id' => $listing->id,
                'accreditation_number' => $t['accno'],
                'status' => 'Active',
                'issue_date' => $t['issued'],
                'expiration_date' => $t['expiry'],
            ]);
        }
    }

    private function seedSouvenirCenters($regions): void
    {
        $souvenirCenters = [
            [
                'slug' => 'apo-ni-lola-durian-delicacies', 'name' => 'Apo Ni Lola Durian Delicacies',
                'location' => '#28 San Miguel Village, Brgy Matina Crossing, Davao City', 'region' => 'Davao City',
                'latitude' => 7.0587144, 'longitude' => 125.5688143,
                'accno' => 'DOT-R11-TSP-00578-2023', 'issued' => '2026-04-13', 'expiry' => '2027-04-13',
                'contact' => '(082) 298 5099', 'email' => 'aponilola_durian@yahoo.com',
            ],
            [
                'slug' => 'godel-agriventures-inc', 'name' => 'Godel Agriventures Inc',
                'location' => 'Purok 1, Brgy. Malagos, Baguio District, Davao City', 'region' => 'Davao City',
                'latitude' => 7.1840413, 'longitude' => 125.4213810,
                'accno' => 'DOT-R11-TSP-01731-2026', 'issued' => '2026-04-06', 'expiry' => '2027-04-06',
                'contact' => '(082) 222 9706', 'email' => 'mjanepalapos@gmail.com',
            ],
        ];

        foreach ($souvenirCenters as $s) {
            $listing = SouvenirCenter::create([
                'slug' => $s['slug'],
                'name' => $s['name'],
                'location' => $s['location'],
                'region_id' => $regions[$s['region']] ?? null,
                'description' => "{$s['name']} is a DOT Region XI-accredited tourist shop (accreditation no. {$s['accno']}).",
                'is_accredited' => true,
                'rating' => 0,
                'review_count' => 0,
                'latitude' => $s['latitude'],
                'longitude' => $s['longitude'],
            ]);

            AccreditationRecord::create([
                'listing_kind' => 'souvenir_center',
                'listing_id' => $listing->id,
                'accreditation_number' => $s['accno'],
                'status' => 'Active',
                'issue_date' => $s['issued'],
                'expiration_date' => $s['expiry'],
            ]);
        }
    }
}
