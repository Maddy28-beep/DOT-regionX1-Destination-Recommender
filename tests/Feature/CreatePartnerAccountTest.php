<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccreditationRecord;
use App\Models\EstablishmentAccount;
use App\Models\Region;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * partners:create makes an approved partner account linked to a listing, from the server. A partner account can edit the
 * listing it is linked to, so it must refuse real DOT data by default, never take the password as an argument, never write
 * anything on a dry run, and never create a second account for the same e-mail address or listing.
 */
class CreatePartnerAccountTest extends TestCase
{
    use RefreshDatabase;

    private function listing(string $class, string $kind, string $name, bool $sample): object
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);
        $listing = $class::create([
            'slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $region->id,
            'is_accredited' => true, 'rating' => 0, 'review_count' => 0,
        ] + ($class === Accommodation::class ? ['type' => 'Hotel'] : []));
        AccreditationRecord::create([
            'listing_kind' => $kind, 'listing_id' => $listing->id, 'status' => 'Active', 'is_placeholder' => $sample,
            'accreditation_number' => ($sample ? 'SAMPLE-' : 'DOT-R11-REAL-').$kind.'-'.$listing->id,
        ]);

        return $listing;
    }

    public function test_a_dry_run_writes_nothing_and_asks_for_no_password(): void
    {
        $this->listing(Accommodation::class, 'accommodation', 'Sample Inn', true);

        $this->artisan('partners:create', ['kind' => 'accommodation', 'listing' => 'sample-inn', 'email' => 'inn@example.com'])
            ->expectsOutputToContain('Dry run')->assertSuccessful();

        $this->assertSame(0, EstablishmentAccount::count());
    }

    public function test_it_creates_an_approved_linked_account_that_can_really_sign_in(): void
    {
        $inn = $this->listing(Accommodation::class, 'accommodation', 'Sample Inn', true);

        $this->artisan('partners:create', ['kind' => 'accommodation', 'listing' => 'sample-inn', 'email' => 'Inn@Example.com', '--apply' => true])
            ->expectsQuestion('Password (at least 8 characters, typed hidden)', 'a-long-test-password')
            ->expectsQuestion('Type it again', 'a-long-test-password')
            ->expectsOutputToContain('Created and approved')->assertSuccessful();

        $account = EstablishmentAccount::firstOrFail();
        $this->assertSame('approved', $account->status);
        $this->assertSame($inn->id, (int) $account->matched_listing_id);
        $this->assertSame('accommodation', $account->listing_kind);
        $this->assertSame('Sample Inn', $account->business_name);
        $this->assertNotSame('a-long-test-password', $account->password_hash, 'stored hashed');
        $this->assertTrue(Hash::check('a-long-test-password', $account->password_hash));

        // The same form the partner would use: an approved account gets in.
        $this->post(route('portal.login'), ['portal' => 'establishment', 'identifier' => 'Inn@Example.com', 'password' => 'a-long-test-password'])
            ->assertSessionHasNoErrors();
        $this->assertAuthenticated('establishment');
    }

    public function test_generate_shows_a_password_once_that_works(): void
    {
        $this->listing(TourOperator::class, 'tour_operator', 'Sample Tours', true);

        $this->artisan('partners:create', ['kind' => 'tour_operator', 'listing' => 'sample-tours', 'email' => 'tours@example.com', '--generate' => true, '--apply' => true])
            ->expectsOutputToContain('Password (shown only now, copy it):')->assertSuccessful();

        $this->assertSame(1, EstablishmentAccount::count());
        $this->assertSame('approved', EstablishmentAccount::first()->status);
    }

    public function test_the_password_can_come_from_a_named_environment_variable_and_must_be_long_enough(): void
    {
        $this->listing(Accommodation::class, 'accommodation', 'Sample Inn', true);
        $this->listing(TourOperator::class, 'tour_operator', 'Sample Tours', true);

        putenv('PARTNER_TEST_PW=short');
        $this->artisan('partners:create', ['kind' => 'accommodation', 'listing' => 'sample-inn', 'email' => 'inn@example.com', '--password-env' => 'PARTNER_TEST_PW', '--apply' => true])
            ->expectsOutputToContain('shorter than 8')->assertFailed();
        $this->assertSame(0, EstablishmentAccount::count());

        putenv('PARTNER_TEST_PW=a-shared-test-pass');
        foreach ([['accommodation', 'sample-inn', 'inn@example.com'], ['tour_operator', 'sample-tours', 'tours@example.com']] as [$kind, $slug, $email]) {
            $this->artisan('partners:create', ['kind' => $kind, 'listing' => $slug, 'email' => $email, '--password-env' => 'PARTNER_TEST_PW', '--apply' => true])->assertSuccessful();
        }
        putenv('PARTNER_TEST_PW');

        $this->assertSame(2, EstablishmentAccount::count());
        foreach (EstablishmentAccount::all() as $account) {
            $this->assertTrue(Hash::check('a-shared-test-pass', $account->password_hash));
        }
    }

    public function test_a_password_that_is_short_or_mistyped_creates_nothing(): void
    {
        $this->listing(SouvenirCenter::class, 'souvenir_center', 'Sample Shop', true);

        $this->artisan('partners:create', ['kind' => 'souvenir_center', 'listing' => 'sample-shop', 'email' => 'shop@example.com', '--apply' => true])
            ->expectsQuestion('Password (at least 8 characters, typed hidden)', 'short')
            ->expectsOutputToContain('at least 8 characters')->assertFailed();

        $this->artisan('partners:create', ['kind' => 'souvenir_center', 'listing' => 'sample-shop', 'email' => 'shop@example.com', '--apply' => true])
            ->expectsQuestion('Password (at least 8 characters, typed hidden)', 'long-enough-1')
            ->expectsQuestion('Type it again', 'long-enough-2')
            ->expectsOutputToContain('do not match')->assertFailed();

        $this->assertSame(0, EstablishmentAccount::count());
    }

    public function test_real_dot_data_is_refused_unless_allow_real_is_given(): void
    {
        $this->listing(Accommodation::class, 'accommodation', 'Real Hotel', false);

        $this->artisan('partners:create', ['kind' => 'accommodation', 'listing' => 'real-hotel', 'email' => 'real@example.com', '--generate' => true, '--apply' => true])
            ->expectsOutputToContain('real DOT data')->assertFailed();
        $this->assertSame(0, EstablishmentAccount::count());

        $this->artisan('partners:create', ['kind' => 'accommodation', 'listing' => 'real-hotel', 'email' => 'real@example.com', '--generate' => true, '--apply' => true, '--allow-real' => true])
            ->assertSuccessful();
        $this->assertSame(1, EstablishmentAccount::count());
    }

    public function test_a_second_account_for_the_same_email_or_the_same_listing_is_refused(): void
    {
        $this->listing(Accommodation::class, 'accommodation', 'Sample Inn', true);
        $this->listing(Accommodation::class, 'accommodation', 'Other Inn', true);
        $this->artisan('partners:create', ['kind' => 'accommodation', 'listing' => 'sample-inn', 'email' => 'inn@example.com', '--generate' => true, '--apply' => true])->assertSuccessful();

        $this->artisan('partners:create', ['kind' => 'accommodation', 'listing' => 'other-inn', 'email' => 'INN@example.com', '--generate' => true, '--apply' => true])
            ->expectsOutputToContain('already registered')->assertFailed();
        $this->artisan('partners:create', ['kind' => 'accommodation', 'listing' => 'sample-inn', 'email' => 'second@example.com', '--generate' => true, '--apply' => true])
            ->expectsOutputToContain('already linked')->assertFailed();

        $this->assertSame(1, EstablishmentAccount::count());
    }

    public function test_an_unknown_kind_slug_or_email_is_refused(): void
    {
        $this->listing(Accommodation::class, 'accommodation', 'Sample Inn', true);

        $this->artisan('partners:create', ['kind' => 'castle', 'listing' => 'sample-inn', 'email' => 'a@example.com'])->expectsOutputToContain('kind must be')->assertFailed();
        $this->artisan('partners:create', ['kind' => 'accommodation', 'listing' => 'nope', 'email' => 'a@example.com'])->expectsOutputToContain('No accommodation')->assertFailed();
        $this->artisan('partners:create', ['kind' => 'accommodation', 'listing' => 'sample-inn', 'email' => 'not-an-email'])->expectsOutputToContain('valid e-mail')->assertFailed();
    }

    public function test_the_password_is_never_an_argument_or_an_option(): void
    {
        $source = file_get_contents(app_path('Console/Commands/CreatePartnerAccount.php'));

        // --password-env names a variable; the password itself is never an option.
        $this->assertDoesNotMatchRegularExpression('/\{--?password(?!-env)/i', $source);
        $this->assertDoesNotMatchRegularExpression('/\{password/i', $source);
        $this->assertStringContainsString("\$this->secret(", $source);
        // The log line names the account, not the password.
        $this->assertStringNotContainsString("'password' =>", substr($source, strpos($source, 'Log::info')));
    }
}
