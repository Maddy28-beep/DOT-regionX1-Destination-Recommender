<?php

namespace Database\Seeders;

use App\Models\Accommodation;
use App\Models\AdminUser;
use App\Models\EstablishmentAccount;
use App\Models\Package;
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
        /*
         * Was BlueJaz Beach Resort, which has since closed and been archived --
         * an approved partner account pointing at an archived listing is a real
         * state the portal has to handle, but it is a poor default for a demo
         * because the dashboard opens on "your listing is hidden from public
         * search". Pearl Farm is the same kind of listing (Samal beach resort)
         * and is live, so the console shows its normal state. The archived-
         * listing path is still covered, by PortalAccessAuditTest.
         */
        $pearlFarm = Accommodation::where('slug', 'pearl-farm-beach-resort')->first();

        // Approved & matched to a real catalog listing — demonstrates the establishment dashboard fully.
        EstablishmentAccount::create([
            'business_name' => 'Pearl Farm Beach Resort',
            'listing_kind' => 'accommodation',
            // The resort's actual DOT record, so approving this account can be
            // checked against the accreditation table instead of a made-up
            // number that matches nothing.
            'claimed_accreditation_number' => 'DOT-R11-RES-00268-2021',
            'matched_listing_id' => $pearlFarm?->id,
            'portal_key' => (string) Str::uuid(),
            'email' => 'partner@pearlfarm.example.com',
            'password_hash' => Hash::make('password'),
            // Deliberately not a person's name. The business is real; inventing
            // a named employee for it would put a fabricated person on a real
            // establishment's record.
            'contact_person' => 'Demo contact (seeded)',
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
        // way the Pearl Farm account exercises the accommodation branch.
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

        /*
         * Package partner, approved and matched.
         *
         * Added because nothing in the catalogue demonstrated the package
         * branch: five packages existed, all created by the seeder, and no
         * account owned any of them -- so the operator journey (register as a
         * Tour Package Provider, get matched to a package, edit it) could not
         * be shown end to end.
         *
         * Mount Apo rather than one of the other four because it is the only
         * package carrying photos, so the console's photo management has
         * something real in it.
         */
        $apoTrek = Package::where('slug', 'mount-apo-3-day-summit-trek')->first();

        EstablishmentAccount::create([
            'business_name' => 'Apo Summit Guides',
            'listing_kind' => 'package',
            // Matches the accreditation record already attached to this
            // package, so approving the account can be checked against it.
            'claimed_accreditation_number' => 'DOT-XI-2025-0019',
            'matched_listing_id' => $apoTrek?->id,
            'portal_key' => (string) Str::uuid(),
            'email' => 'partner@aposummitguides.example.com',
            'password_hash' => Hash::make('password'),
            // Not a person's name: see the note on the Pearl Farm account.
            'contact_person' => 'Demo contact (seeded)',
            'contact_number' => '09172345678',
            'status' => 'approved',
            'submitted_at' => now()->subMonths(2),
            'reviewed_by' => $admin?->id,
            'reviewed_at' => now()->subMonths(2)->addDays(4),
            'review_note' => 'Tour package accreditation verified against DOT records.',
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
