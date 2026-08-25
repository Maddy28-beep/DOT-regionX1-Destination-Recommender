<?php

namespace Database\Seeders;

use App\Models\Accommodation;
use App\Models\AccreditationRecord;
use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\Package;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class AccreditationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Skips any listing that already has an AccreditationRecord, so it never
     * overwrites the real DOT accreditation numbers RealAccreditedEstablishmentSeeder
     * creates for real, imported establishments — this seeder only backfills
     * synthetic numbers for the original hand-authored demo listings.
     */
    public function run(): void
    {
        $admin = AdminUser::first();

        $sets = [
            'destination' => Destination::where('is_accredited', true)->whereDoesntHave('accreditationRecords')->get(),
            'accommodation' => Accommodation::where('is_accredited', true)->whereDoesntHave('accreditationRecords')->get(),
            'restaurant' => Restaurant::where('is_accredited', true)->whereDoesntHave('accreditationRecords')->get(),
            'souvenir_center' => SouvenirCenter::where('is_accredited', true)->whereDoesntHave('accreditationRecords')->get(),
            'package' => Package::where('is_accredited', true)->whereDoesntHave('accreditationRecords')->get(),
            'tour_operator' => TourOperator::where('is_accredited', true)->whereDoesntHave('accreditationRecords')->get(),
        ];

        $counter = 1;

        foreach ($sets as $kind => $listings) {
            foreach ($listings as $index => $listing) {
                $cycle = $index % 6;
                [$status, $issued, $expires] = match (true) {
                    $cycle === 0 => ['Expired', Carbon::now()->subYears(2), Carbon::now()->subDays(15)],
                    $cycle === 1 => ['Expiring Soon', Carbon::now()->subYear(), Carbon::now()->addDays(30)],
                    default => ['Active', Carbon::now()->subMonths(6), Carbon::now()->addYear()],
                };

                AccreditationRecord::create([
                    'listing_kind' => $kind,
                    'listing_id' => $listing->id,
                    'accreditation_number' => sprintf('DOT-XI-2025-%04d', $counter++),
                    'status' => $status,
                    'issue_date' => $issued->toDateString(),
                    'expiration_date' => $expires->toDateString(),
                    'verified_by' => $admin?->id,
                    'verified_at' => $issued,
                ]);
            }
        }
    }
}
