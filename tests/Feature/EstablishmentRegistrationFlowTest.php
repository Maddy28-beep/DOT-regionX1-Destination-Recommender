<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AdminUser;
use App\Models\EstablishmentAccount;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * "List your establishment" end to end.
 *
 * This is how an establishment gets into the system at all: it registers with
 * the accreditation number it claims to hold, waits for DOT Region XI to check
 * that claim against the real records, and only then can sign in. The gate is
 * the point -- an unreviewed or rejected business must not be able to reach a
 * portal that lets it edit a public listing and reply to travellers' reviews.
 *
 * Every step below was previously untested: the account tests only ever
 * started from an already-approved partner.
 */
class EstablishmentRegistrationFlowTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function application(array $overrides = []): array
    {
        return $overrides + [
            'business_name' => 'Seaside Inn Davao',
            'listing_kind' => 'accommodation',
            'claimed_accreditation_number' => 'DOT-R11-MAB-01724-2026',
            'email' => 'owner@seasideinn.example.com',
            'password' => 'a-real-password',
            'password_confirmation' => 'a-real-password',
            'contact_person' => 'Maria Santos',
            'contact_number' => '09170000001',
            'terms_accepted' => '1',
        ];
    }

    /**
     * Consent to the Terms and Privacy Policy is a required, explicit act --
     * this form takes account credentials and business records, so agreement
     * cannot be inferred from the fact that someone pressed Submit.
     *
     * The checkbox carries HTML `required`, but an unchecked box is simply not
     * posted at all, so only a server-side rule actually stops it. Passing the
     * whole application minus that one key is exactly what a browser sends
     * when the box is left unticked.
     */
    public function test_an_application_without_consent_is_refused(): void
    {
        $payload = $this->application();
        unset($payload['terms_accepted']);

        $this->post('/portal/register', $payload)
            ->assertSessionHasErrors('terms_accepted');

        $this->assertDatabaseCount('establishment_accounts', 0);
    }

    /** An explicitly unticked box is refused the same way a missing one is. */
    public function test_consent_must_be_ticked_not_merely_present(): void
    {
        $this->post('/portal/register', $this->application(['terms_accepted' => '0']))
            ->assertSessionHasErrors('terms_accepted');

        $this->assertDatabaseCount('establishment_accounts', 0);
    }

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'email' => 'reviewer@dot.gov.ph',
            'password_hash' => Hash::make('irrelevant-actingAs-is-used'),
            'full_name' => 'DOT Reviewer',
            'role' => 'super_admin',
        ]);
    }

    private function listing(): Accommodation
    {
        return Accommodation::create([
            'slug' => 'seaside-inn-davao', 'name' => 'Seaside Inn Davao', 'location' => 'Davao City',
            'region_id' => Region::firstOrCreate(['name' => 'Davao City'])->id,
            'type' => 'Hotel', 'is_accredited' => true,
            'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
        ]);
    }

    public function test_the_registration_form_is_reachable_without_signing_in(): void
    {
        $this->get('/portal/register')->assertOk()->assertSee('accreditation', false);
    }

    public function test_an_application_is_recorded_as_pending_and_never_self_approves(): void
    {
        $this->post('/portal/register', $this->application())
            ->assertRedirect(route('portal.login'));

        $account = EstablishmentAccount::where('email', 'owner@seasideinn.example.com')->sole();

        $this->assertSame('pending', $account->status,
            'A business must never be able to grant itself access.');
        $this->assertNull($account->matched_listing_id,
            'Nothing may be linked to a public listing before DOT has checked the claim.');
        $this->assertSame('DOT-R11-MAB-01724-2026', $account->claimed_accreditation_number,
            'The claimed number is what DOT verifies against, so it has to be kept.');
        $this->assertNull($account->reviewed_by);
    }

    /** The password must be hashed, never stored as typed. */
    public function test_the_password_is_not_stored_in_the_clear(): void
    {
        $this->post('/portal/register', $this->application());

        $account = EstablishmentAccount::sole();

        $this->assertNotSame('a-real-password', $account->password_hash);
        $this->assertTrue(Hash::check('a-real-password', $account->password_hash));
    }

    public function test_one_business_cannot_register_the_same_email_twice(): void
    {
        $this->post('/portal/register', $this->application());
        $this->post('/portal/register', $this->application())
            ->assertSessionHasErrors('email');

        $this->assertSame(1, EstablishmentAccount::count());
    }

    /** @return array<string, string> the real shape the sign-in form posts */
    private function credentials(): array
    {
        return [
            'portal' => 'establishment',
            'identifier' => 'owner@seasideinn.example.com',
            'password' => 'a-real-password',
        ];
    }

    public function test_an_application_awaiting_review_cannot_sign_in(): void
    {
        $this->post('/portal/register', $this->application());

        $this->post('/portal/login', $this->credentials())
            ->assertSessionHasErrors('identifier');

        // Asserting the reason, not merely that something failed: with the
        // wrong field names this test passed on a validation error while the
        // status gate went completely unexercised.
        $this->assertStringContainsString('pending',
            session('errors')->first('identifier'));

        $this->assertGuest('establishment');
    }

    public function test_a_rejected_application_cannot_sign_in(): void
    {
        $this->post('/portal/register', $this->application());
        $account = EstablishmentAccount::sole();

        $this->actingAs($this->admin(), 'admin')
            ->post("/portal/admin/establishments/{$account->id}/reject")
            ->assertRedirect();

        $this->assertSame('rejected', $account->fresh()->status);

        $this->post('/portal/login', $this->credentials())
            ->assertSessionHasErrors('identifier');

        $this->assertStringContainsString('rejected',
            session('errors')->first('identifier'));

        $this->assertGuest('establishment');
    }

    /** The whole point of the flow: verified, matched, then and only then let in. */
    public function test_once_dot_verifies_and_matches_it_the_business_can_sign_in_to_its_own_listing(): void
    {
        $listing = $this->listing();
        $this->post('/portal/register', $this->application());
        $account = EstablishmentAccount::sole();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post("/portal/admin/establishments/{$account->id}/match", ['matched_listing_id' => $listing->id])
            ->assertRedirect();
        $this->actingAs($admin, 'admin')
            ->post("/portal/admin/establishments/{$account->id}/approve")
            ->assertRedirect();

        $account->refresh();
        $this->assertSame('approved', $account->status);
        $this->assertSame($listing->id, $account->matched_listing_id);
        $this->assertSame($admin->id, $account->reviewed_by);

        $this->post('/portal/login', $this->credentials())
            ->assertSessionHasNoErrors();

        $this->assertAuthenticated('establishment');

        $this->get('/portal/establishment/listing')->assertOk()->assertSee('Seaside Inn Davao');
    }

    /**
     * A match is scoped to the account's own listing_kind, so a restaurant
     * cannot be pointed at somebody's hotel and inherit its page.
     */
    public function test_an_account_cannot_be_matched_to_a_listing_of_another_kind(): void
    {
        $hotel = $this->listing();
        $this->post('/portal/register', $this->application(['listing_kind' => 'restaurant']));
        $account = EstablishmentAccount::sole();

        $this->actingAs($this->admin(), 'admin')
            ->post("/portal/admin/establishments/{$account->id}/match", ['matched_listing_id' => $hotel->id])
            ->assertSessionHasErrors('matched_listing_id');

        $this->assertNull($account->fresh()->matched_listing_id);
    }

    /** Only DOT staff may pass judgement on an application. */
    public function test_a_business_cannot_approve_itself_or_anyone_else(): void
    {
        $this->post('/portal/register', $this->application());
        $account = EstablishmentAccount::sole();

        foreach (['approve', 'reject'] as $verdict) {
            $this->post("/portal/admin/establishments/{$account->id}/{$verdict}")
                ->assertRedirect('/portal/login');
        }

        $this->assertSame('pending', $account->fresh()->status);
    }
}
