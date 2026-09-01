<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        AdminUser::create([
            'email' => 'dotadmin@dot.gov.ph',
            'password_hash' => Hash::make('password'),
            'full_name' => 'DOT Region XI Admin',
            'role' => 'super_admin',
        ]);

        $this->call([
            DestinationSeeder::class,
            PackageSeeder::class,
            TourOperatorSeeder::class,
            RealAccreditedEstablishmentSeeder::class,
            AccreditationSeeder::class,
            EstablishmentSeeder::class,
            ListingPhotoSeeder::class,
            RecommendationDataSeeder::class,
        ]);
    }
}
