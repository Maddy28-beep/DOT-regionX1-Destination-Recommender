<?php

namespace Database\Seeders;

use App\Models\Accommodation;
use App\Models\AdminUser;
use App\Models\EstablishmentAccount;
use App\Models\TourOperator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EstablishmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = AdminUser::first();
        $blueJaz = Accommodation::where('slug', 'bluejaz-beach-resort')->first();

        // Approved & matched to a real catalog listing — demonstrates the establishment dashboard fully.
        EstablishmentAccount::create([
            'business_name' => 'BlueJaz Beach Resort',
            'listing_kind' => 'accommodation',
            'claimed_accreditation_number' => 'DOT-XI-2025-0006',
            'matched_listing_id' => $blueJaz?->id,
            'portal_key' => (string) Str::uuid(),
            'email' => 'partner@bluejaz.example.com',
            'password_hash' => Hash::make('password'),
            'contact_person' => 'Grace Villamor',
            'contact_number' => '09171234567',
            'status' => 'approved',
            'submitted_at' => now()->subMonths(4),
            'reviewed_by' => $admin?->id,
            'reviewed_at' => now()->subMonths(4)->addDays(2),
            'review_note' => 'Accreditation verified against DOT records.',
        ]);

        // Tour-operator partner, approved and matched — the establishment console
        // is shared across listing kinds, so this exercises the tour_operator
        // branch of it (QR check-in, review replies, photo management) the same
        // way the BlueJaz account exercises the accommodation branch.
        $islandExplorers = TourOperator::where('slug', 'davao-island-explorers')->first();

        EstablishmentAccount::create([
            'business_name' => 'Davao Island Explorers',
            'listing_kind' => 'tour_operator',
            'claimed_accreditation_number' => 'DOT-XI-2025-0142',
            'matched_listing_id' => $islandExplorers?->id,
            'portal_key' => (string) Str::uuid(),
            'email' => 'partner@davaoislandexplorers.example.com',
            'password_hash' => Hash::make('password'),
            'contact_person' => 'Melchor Aquino',
            'contact_number' => '09173456789',
            'status' => 'approved',
            'submitted_at' => now()->subMonths(3),
            'reviewed_by' => $admin?->id,
            'reviewed_at' => now()->subMonths(3)->addDays(3),
            'review_note' => 'Tour operator accreditation verified against DOT records.',
        ]);

        // A third account left pending — demonstrates the review queue on the admin side.
        EstablishmentAccount::create([
            'business_name' => 'Malagos Chocolate House',
            'listing_kind' => 'restaurant',
            'claimed_accreditation_number' => null,
            'portal_key' => (string) Str::uuid(),
            'email' => 'owner@malagoschocolate.example.com',
            'password_hash' => Hash::make('password'),
            'contact_person' => 'Ramon Dizon',
            'contact_number' => '09189876543',
            'status' => 'pending',
            'submitted_at' => now()->subDays(3),
        ]);
    }
}
