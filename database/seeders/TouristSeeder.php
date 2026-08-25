<?php

namespace Database\Seeders;

use App\Models\Tourist;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TouristSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tourists = [
            [
                'full_name' => 'John Karlo Santos', 'email' => 'johnkarlo.santos@example.com',
                'nationality' => 'Filipino', 'age_range' => '25-34', 'gender' => 'Male',
                'preference' => [
                    'travel_days' => 3, 'travel_type' => 'Solo', 'budget' => 'Mid-range',
                    'accommodation_pref' => 'Beach Resort', 'distance_pref' => 'moderate',
                    'activities' => ['Adventure', 'Cultural Heritage', 'Shopping'],
                ],
            ],
            [
                'full_name' => 'Sarah Mendoza', 'email' => 'sarah.mendoza@example.com',
                'nationality' => 'Filipino', 'age_range' => '35-44', 'gender' => 'Female',
                'preference' => [
                    'travel_days' => 4, 'travel_type' => 'Family/Group', 'budget' => 'High',
                    'accommodation_pref' => 'Beach Resort', 'distance_pref' => 'near',
                    'activities' => ['Beach', 'Family Friendly', 'Nature'],
                ],
            ],
            [
                'full_name' => 'Liam Carter', 'email' => 'liam.carter@example.com',
                'nationality' => 'Australian', 'age_range' => '18-24', 'gender' => 'Male',
                'preference' => [
                    'travel_days' => 2, 'travel_type' => 'Friends/Group', 'budget' => 'Low',
                    'accommodation_pref' => 'Hostel', 'distance_pref' => 'far',
                    'activities' => ['Adventure', 'Wildlife', 'Hiking'],
                ],
            ],
        ];

        foreach ($tourists as $t) {
            $tourist = Tourist::create([
                'full_name' => $t['full_name'],
                'email' => $t['email'],
                'password_hash' => Hash::make('password'),
                'nationality' => $t['nationality'],
                'age_range' => $t['age_range'],
                'gender' => $t['gender'],
                'privacy_consent' => true,
                'privacy_consent_at' => now(),
            ]);

            $preference = $tourist->preferences()->create([
                'travel_days' => $t['preference']['travel_days'],
                'travel_type' => $t['preference']['travel_type'],
                'budget' => $t['preference']['budget'],
                'accommodation_pref' => $t['preference']['accommodation_pref'],
                'distance_pref' => $t['preference']['distance_pref'],
            ]);

            foreach ($t['preference']['activities'] as $activity) {
                $preference->activities()->create(['activity' => $activity]);
            }
        }
    }
}
