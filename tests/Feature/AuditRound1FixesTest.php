<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Models\AccreditationRecord;
use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\EstablishmentAccount;
use App\Models\ExitSurvey;
use App\Models\ExitSurveyVisit;
use App\Models\Region;
use App\Models\TouristAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Fixes from the first code audit: spreadsheet formulas, survey flooding, report dates, accreditation flags, logins. */
class AuditRound1FixesTest extends TestCase
{
    use RefreshDatabase;

    private function destination(string $slug = 'audit-park'): Destination
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        return Destination::create([
            'slug' => $slug, 'name' => 'Audit Park', 'location' => 'Davao City', 'region_id' => $region->id,
            'type' => 'Nature & Leisure', 'is_accredited' => true, 'rating' => 0, 'review_count' => 0, 'price_tier' => 'Mid-range',
        ]);
    }

    private function admin(): AdminUser
    {
        return AdminUser::create(['email' => 'a@dot.test', 'password_hash' => Hash::make('x'), 'full_name' => 'Admin', 'role' => 'super_admin']);
    }

    // ---- CSV formulas
    public function test_csv_cells_that_start_like_a_formula_become_plain_text(): void
    {
        foreach (['=HYPERLINK("http://evil")', '+1+1', '-2+3', '@SUM(A1)'] as $text) {
            $this->assertSame("'".$text, AdminDashboardController::csvSafe($text));
        }

        $this->assertSame('Great trip', AdminDashboardController::csvSafe('Great trip'));
        $this->assertSame(5, AdminDashboardController::csvSafe(5));
        $this->assertSame('—', AdminDashboardController::csvSafe('—'));
    }

    // ---- report dates
    public function test_reports_with_nonsense_dates_still_open_and_fall_back_to_the_default_range(): void
    {
        $admin = $this->admin();

        foreach (['Exit Survey Responses', 'Trip Plans Created', 'Accreditation Status', 'Destination Visits', 'Verified Visits'] as $type) {
            $this->actingAs($admin, 'admin')
                ->get('/portal/admin/reports?report_type='.urlencode($type).'&from=abc&to=2026-13-45')
                ->assertOk();
        }

        $this->actingAs($admin, 'admin')->get('/portal/admin/reports/export.csv?from=x&to=y')->assertOk();
    }

    public function test_a_report_range_follows_philippine_days(): void
    {
        $admin = $this->admin();
        $survey = ExitSurvey::create(['overall_rating' => 4, 'would_recommend' => 'Yes', 'data_source' => 'real', 'submitted_at' => '2026-10-15 19:30:00']);   // 3:30 AM on the 16th in Davao

        $on16 = $this->actingAs($admin, 'admin')->get('/portal/admin/reports?report_type=Exit+Survey+Responses&from=2026-10-16&to=2026-10-16');
        $on15 = $this->actingAs($admin, 'admin')->get('/portal/admin/reports?report_type=Exit+Survey+Responses&from=2026-10-15&to=2026-10-15');

        $on16->assertSee('1 exit survey response');
        $on15->assertSee('0 exit survey responses');
        $this->assertNotNull($survey->id);
    }

    // ---- survey flooding
    private function payload(array $extra = []): array
    {
        return array_merge(['overall_rating' => 5, 'would_recommend' => 'Yes', 'travel_purpose' => 'Leisure', 'activities' => ['Beach & Island']], $extra);
    }

    public function test_one_browser_cannot_flood_the_exit_survey(): void
    {
        $token = (string) Str::uuid();

        $this->withCookie('visitor_token', $token)->post(route('exit-survey.store'), $this->payload())->assertRedirect(route('exit-survey.recap'));
        $this->withCookie('visitor_token', $token)->post(route('exit-survey.store'), $this->payload())->assertSessionHasErrors('overall_rating');

        $this->assertSame(1, ExitSurvey::count());
    }

    public function test_trial_mode_surveys_are_not_limited_and_another_browser_is_not_affected(): void
    {
        $token = (string) Str::uuid();

        $this->withCookie('visitor_token', $token)->post(route('exit-survey.store'), $this->payload(['test' => 1]));
        $this->withCookie('visitor_token', $token)->post(route('exit-survey.store'), $this->payload(['test' => 1]));
        $this->withCookie('visitor_token', (string) Str::uuid())->post(route('exit-survey.store'), $this->payload());

        $this->assertSame(3, ExitSurvey::withoutGlobalScopes()->count());
    }

    public function test_a_survey_only_records_places_that_exist_and_each_once(): void
    {
        $d = $this->destination();

        $this->post(route('exit-survey.store'), $this->payload(['places_visited' => ["destination:{$d->id}", "destination:{$d->id}", 'destination:99999', 'restaurant:99999']]));

        $this->assertSame(1, ExitSurveyVisit::count());
        $this->assertSame($d->id, (int) ExitSurveyVisit::first()->listing_id);
    }

    // ---- accreditation flags
    private function record(Destination $d, string $number, string $expires): void
    {
        AccreditationRecord::create([
            'listing_kind' => 'destination', 'listing_id' => $d->id, 'accreditation_number' => $number, 'status' => 'Active',
            'issue_date' => now()->subYears(3), 'expiration_date' => $expires,
        ]);
    }

    public function test_a_listing_with_an_old_expired_record_and_a_valid_one_stays_accredited(): void
    {
        $d = $this->destination();
        $this->record($d, 'OLD-1', now()->subYear()->toDateString());
        $this->record($d, 'NEW-1', now()->addYear()->toDateString());

        Artisan::call('accreditation:sync-status');

        $this->assertTrue((bool) $d->fresh()->is_accredited);
    }

    public function test_a_listing_whose_records_have_all_expired_is_hidden(): void
    {
        $d = $this->destination();
        $this->record($d, 'OLD-1', now()->subYear()->toDateString());
        $this->record($d, 'OLD-2', now()->subMonth()->toDateString());

        Artisan::call('accreditation:sync-status');

        $this->assertFalse((bool) $d->fresh()->is_accredited);
    }

    // ---- portal login
    private function partner(string $status): EstablishmentAccount
    {
        return EstablishmentAccount::create([
            'business_name' => 'Audit Resort', 'listing_kind' => 'accommodation', 'portal_key' => (string) Str::uuid(),
            'email' => 'partner@test.example.com', 'password_hash' => Hash::make('right-password-1'), 'contact_person' => 'P',
            'contact_number' => '0917', 'status' => $status, 'submitted_at' => now(),
        ]);
    }

    public function test_a_stranger_cannot_learn_that_an_account_is_pending(): void
    {
        $this->partner('pending');

        $wrong = $this->post('/portal/login', ['portal' => 'establishment', 'identifier' => 'partner@test.example.com', 'password' => 'wrong-guess-123']);
        $missing = $this->post('/portal/login', ['portal' => 'establishment', 'identifier' => 'nobody@test.example.com', 'password' => 'wrong-guess-123']);

        $wrong->assertSessionHasErrors(['identifier' => 'Those credentials do not match our records.']);
        $missing->assertSessionHasErrors(['identifier' => 'Those credentials do not match our records.']);
    }

    public function test_the_owner_with_the_right_password_is_told_the_account_is_pending(): void
    {
        $this->partner('pending');

        $this->post('/portal/login', ['portal' => 'establishment', 'identifier' => 'partner@test.example.com', 'password' => 'right-password-1'])
            ->assertSessionHasErrors('identifier');

        $this->assertGuest('establishment');
    }

    public function test_an_approved_partner_can_still_sign_in(): void
    {
        $this->partner('approved');

        $this->post('/portal/login', ['portal' => 'establishment', 'identifier' => 'partner@test.example.com', 'password' => 'right-password-1'])
            ->assertRedirect(route('establishment.overview'));

        $this->assertAuthenticated('establishment');
    }

    // ---- lookalike accounts
    public function test_an_email_or_alias_differing_only_in_case_is_refused(): void
    {
        $this->partner('approved');
        TouristAccount::create(['alias' => 'Maddy', 'password_hash' => Hash::make('x')]);

        $this->post('/portal/register', [
            'business_name' => 'Copy', 'listing_kind' => 'accommodation', 'email' => 'PARTNER@test.example.com', 'password' => 'password123',
            'password_confirmation' => 'password123', 'contact_person' => 'X', 'contact_number' => '0917', 'terms_accepted' => 1,
        ])->assertSessionHasErrors('email');

        $this->post('/account/register', ['alias' => 'maddy', 'password' => 'password123', 'password_confirmation' => 'password123'])
            ->assertSessionHasErrors('alias');
    }
}
