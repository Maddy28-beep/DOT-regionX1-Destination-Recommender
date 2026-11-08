<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\EstablishmentAccount;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A destination (a park, a falls, a resort grounds) can have its own partner account, like a stay or a tour operator:
 * the owner registers, DOT links the request to the destination and approves it, and the owner then keeps that one
 * destination's amenities, opening hours and operating status up to date.
 */
class DestinationPartnerAccountTest extends TestCase
{
    use RefreshDatabase;

    private function destination(string $name, array $extra = []): Destination
    {
        return Destination::create($extra + [
            'slug' => Str::slug($name), 'name' => $name, 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Nature & Adventure', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0,
            'hours' => '8:00 AM–5:00 PM',
        ]);
    }

    private function partner(Destination $destination): EstablishmentAccount
    {
        return EstablishmentAccount::create([
            'business_name' => $destination->name, 'listing_kind' => 'destination', 'matched_listing_id' => $destination->id,
            'portal_key' => (string) Str::uuid(), 'email' => Str::slug($destination->name).'@example.com',
            'password_hash' => Hash::make('irrelevant-actingAs-is-used'), 'contact_person' => 'Owner', 'contact_number' => '09170000000',
            'status' => 'approved', 'submitted_at' => now(),
        ]);
    }

    private function admin(): AdminUser
    {
        return AdminUser::create(['email' => 'dest-admin@x.test', 'password_hash' => Hash::make('x'), 'full_name' => 'Admin', 'role' => 'super_admin']);
    }

    public function test_the_registration_form_offers_destination_and_accepts_it(): void
    {
        $this->get(route('portal.establishment.register'))->assertOk()->assertSee('value="destination"', false)->assertSee('Destination / Attraction');

        $this->post('/portal/register', [
            'business_name' => 'Hagimit Falls', 'listing_kind' => 'destination', 'email' => 'falls@example.com',
            'password' => 'a-long-test-password', 'password_confirmation' => 'a-long-test-password',
            'contact_person' => 'Owner', 'contact_number' => '09170000000', 'terms_accepted' => '1',
        ])->assertSessionHasNoErrors();

        $account = EstablishmentAccount::firstOrFail();
        $this->assertSame('destination', $account->listing_kind);
        $this->assertSame('pending', $account->status, 'a new request still waits for DOT');
    }

    public function test_dot_links_the_request_to_a_destination_and_approves_it(): void
    {
        $falls = $this->destination('Hagimit Falls');
        $account = EstablishmentAccount::create([
            'business_name' => 'Hagimit Falls', 'listing_kind' => 'destination', 'portal_key' => (string) Str::uuid(),
            'email' => 'falls@example.com', 'password_hash' => Hash::make('x'), 'contact_person' => 'Owner',
            'contact_number' => '09170000000', 'status' => 'pending', 'submitted_at' => now(),
        ]);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get(route('admin.establishments'))->assertOk()->assertSee('Hagimit Falls');

        $this->actingAs($admin, 'admin')->post(route('admin.establishments.approve', $account));
        $this->assertSame('pending', $account->fresh()->status, 'cannot approve before it is linked to a destination');

        $this->actingAs($admin, 'admin')->post(route('admin.establishments.match', $account), ['matched_listing_id' => $falls->id])->assertSessionHasNoErrors();
        $this->actingAs($admin, 'admin')->post(route('admin.establishments.approve', $account));

        $this->assertSame('approved', $account->fresh()->status);
        $this->assertSame($falls->id, (int) $account->fresh()->matched_listing_id);
    }

    public function test_a_destination_owner_can_open_the_portal_and_edit_amenities_hours_and_status_of_their_own_destination_only(): void
    {
        $mine = $this->destination('Hagimit Falls');
        $other = $this->destination('Eden Nature Park');
        $partner = $this->partner($mine);

        $this->actingAs($partner, 'establishment')->get(route('establishment.overview'))->assertOk();
        $this->actingAs($partner, 'establishment')->get(route('establishment.listing.edit'))->assertOk();

        $this->actingAs($partner, 'establishment')->put(route('establishment.listing.update'), [
            'section' => 'amenities', 'amenities' => ['parking', 'restroom'],
        ])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(['Parking Area', 'Restroom'], $mine->tags()->where('kind', 'amenity')->pluck('value')->all());
        $this->assertSame(0, $other->tags()->where('kind', 'amenity')->count(), 'another destination is untouched');

        $hours = array_fill(0, 7, ['on' => '1', 'from' => '09:00', 'to' => '16:00']);
        $this->actingAs($partner, 'establishment')->put(route('establishment.listing.update'), ['section' => 'hours', 'hours' => $hours])->assertSessionHasNoErrors();
        $this->assertNotSame('8:00 AM–5:00 PM', $mine->fresh()->hours);
        $this->assertSame('8:00 AM–5:00 PM', $other->fresh()->hours);

        $this->actingAs($partner, 'establishment')->put(route('establishment.listing.update'), [
            'section' => 'status', 'operating_status' => Destination::STATUS_TEMPORARILY_CLOSED, 'closure_reason' => 'Repairs', 'reopens_on' => now()->addMonth()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->assertSame(Destination::STATUS_TEMPORARILY_CLOSED, $mine->fresh()->operating_status);
        $this->assertSame(Destination::STATUS_OPEN, $other->fresh()->operating_status);
    }

    public function test_partners_create_makes_a_destination_account_that_can_sign_in(): void
    {
        $this->destination('Sample Falls [SAMPLE]');
        \App\Models\AccreditationRecord::create(['listing_kind' => 'destination', 'listing_id' => Destination::first()->id, 'accreditation_number' => 'SAMPLE-D1', 'status' => 'Active', 'is_placeholder' => true]);

        $this->artisan('partners:create', ['kind' => 'destination', 'listing' => 'sample-falls-sample', 'email' => 'falls@exploredavao.com', '--generate' => true, '--apply' => true])
            ->expectsOutputToContain('Created and approved')->assertSuccessful();

        $account = EstablishmentAccount::firstOrFail();
        $this->assertSame('destination', $account->listing_kind);
        $this->assertSame('approved', $account->status);
        $this->assertSame(Destination::first()->id, (int) $account->matched_listing_id);
    }
}
