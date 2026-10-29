<?php

namespace Tests\Feature;

use App\Models\AccreditationRecord;
use App\Models\AdminUser;
use App\Models\Advisory;
use App\Models\Destination;
use App\Models\EstablishmentAccount;
use App\Models\Event;
use App\Models\Region;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The DOT Admin top bar: the environment tag, the + New menu, the bell, the account menu and the search.
 */
class AdminTopBarTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $extra = []): AdminUser
    {
        return AdminUser::create($extra + ['email' => 'a@x.test', 'password_hash' => Hash::make('x'), 'full_name' => 'Dot Region Admin', 'role' => 'super_admin']);
    }

    private function destination(string $name, array $extra = []): Destination
    {
        $region = Region::firstOrCreate(['name' => 'Davao City']);

        return Destination::create($extra + ['slug' => str($name)->slug()->toString(), 'name' => $name, 'location' => 'Davao City', 'region_id' => $region->id, 'type' => 'Nature', 'latitude' => 7.07, 'longitude' => 125.6]);
    }

    private ?AdminUser $signedIn = null;

    private function page(): string
    {
        $this->signedIn ??= $this->admin();

        return $this->actingAs($this->signedIn, 'admin')->get('/portal/admin')->assertOk()->getContent();
    }

    private function establishment(string $name, string $key): EstablishmentAccount
    {
        return EstablishmentAccount::create([
            'business_name' => $name, 'contact_person' => 'Ana', 'contact_number' => '0917', 'email' => $key.'@x.test', 'password_hash' => 'x', 'portal_key' => $key,
            'listing_kind' => 'restaurant', 'status' => 'pending', 'submitted_at' => now(),
        ]);
    }

    // ---- the bar

    public function test_the_bar_has_the_search_new_menu_bell_and_account_menu(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('id="admSearchInput"', $html);
        $this->assertStringContainsString('data-url="'.route('admin.search').'"', $html);
        $this->assertStringContainsString('aria-label="Add something new"', $html);
        $this->assertStringContainsString('aria-label="Needs attention"', $html);
        $this->assertStringContainsString('aria-label="Your account"', $html);
        $this->assertStringContainsString('admin-topbar.js', $html);
    }

    public function test_the_environment_is_named_and_the_live_site_says_so(): void
    {
        $this->assertStringContainsString('adm-env ', $this->page());
        $this->assertStringContainsString('>Testing<', str_replace(["\n", '  '], '', $this->page()), 'Anything that is not production is named, not hidden.');

        $this->app['env'] = 'production';
        $html = $this->page();
        $this->assertStringContainsString('adm-env is-live', $html);
        $this->assertStringContainsString('Live', $html);
        $this->assertStringContainsString('seen by travellers', $html);
    }

    public function test_the_new_menu_opens_each_add_form_in_the_modal_and_links_to_it(): void
    {
        $html = $this->page();

        foreach (['destinations', 'accommodations', 'restaurants', 'packages', 'souvenir-centers', 'tour-operators'] as $type) {
            $this->assertStringContainsString('href="'.route('admin.listings.create', $type).'" data-modal-form', $html);
        }
        foreach ([route('admin.advisories.create'), route('admin.events.create'), route('admin.postcard-slides.create')] as $url) {
            $this->assertStringContainsString('href="'.$url.'" data-modal-form', $html);
        }
    }

    public function test_the_account_menu_shows_who_and_what_role_and_logs_out_with_a_post(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('Dot Region Admin', $html);
        $this->assertStringContainsString('a@x.test', $html);
        $this->assertStringContainsString('Super admin', $html);
        $this->assertStringContainsString('>DR<', str_replace([' ', "\n"], '', $html), 'Initials of the name.');
        $this->assertMatchesRegularExpression('/<form method="POST" action="[^"]*logout[^"]*">.*?Log out/s', $html);
        $this->assertStringContainsString('target="_blank" rel="noopener"', $html);
    }

    // ---- the bell

    public function test_the_bell_says_when_nothing_needs_attention(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('aria-label="Nothing needs attention"', $html);
        $this->assertStringContainsString('Nothing needs attention.', $html);
        $this->assertStringNotContainsString('adm-badge', $html);
    }

    public function test_the_bell_counts_what_needs_doing_and_links_to_it(): void
    {
        $d = $this->destination('Lapsed Falls', ['latitude' => null, 'longitude' => null]);
        AccreditationRecord::create(['listing_kind' => 'destination', 'listing_id' => $d->id, 'accreditation_number' => 'N1', 'status' => 'Expired', 'issue_date' => now()->subYears(2), 'expiration_date' => now()->subDay()]);
        $this->establishment('Waiting Cafe', 'waiting');

        $html = $this->page();

        $this->assertStringContainsString('aria-label="Needs attention: 3 things"', $html);
        $this->assertStringContainsString('1 accreditation expired', $html);
        $this->assertStringContainsString('1 establishment awaiting approval', $html);
        $this->assertStringContainsString('Waiting Cafe', $html);
        $this->assertStringContainsString('1 destination has no map position', $html);
        $this->assertStringContainsString(e(route('admin.data-completeness', ['type' => 'destinations', 'check' => 'coords'])), $html);
        $this->assertStringContainsString(route('admin.accreditation', ['status' => 'Expired']), $html);
    }

    // ---- the search

    public function test_the_search_needs_an_admin(): void
    {
        $this->getJson('/portal/admin/search?q=falls')->assertUnauthorized();
    }

    public function test_a_search_shorter_than_two_letters_finds_nothing(): void
    {
        $this->destination('Eagle Falls');

        $this->actingAs($this->admin(), 'admin')->getJson('/portal/admin/search?q=e')->assertOk()->assertExactJson(['results' => []]);
        $this->actingAs($this->admin(['email' => 'b@x.test']), 'admin')->getJson('/portal/admin/search')->assertOk()->assertExactJson(['results' => []]);
    }

    public function test_it_finds_a_listing_and_says_what_opening_it_does(): void
    {
        $d = $this->destination('Eagle Falls');

        $result = $this->actingAs($this->admin(), 'admin')->getJson('/portal/admin/search?q=eagle')->assertOk()->json('results.0');

        $this->assertSame('Listings', $result['group']);
        $this->assertSame('Eagle Falls', $result['title']);
        $this->assertSame('Destination · Davao City', $result['sub']);
        $this->assertSame('Edit', $result['action']);
        $this->assertSame(route('admin.listings.edit', ['destinations', $d->id]), $result['url']);
        $this->assertSame('Edit Destination', $result['modal']);
    }

    public function test_it_finds_establishments_advisories_events_and_pages(): void
    {
        $admin = $this->admin();
        $this->establishment('Reef Cafe', 'reef');
        Advisory::create(['title' => 'Reef closed', 'message' => 'm', 'severity' => 'warning', 'admin_id' => $admin->id]);
        Event::create(['slug' => 'reef-day', 'title' => 'Reef Day', 'category' => array_key_first(Event::CATEGORIES), 'starts_on' => '2026-11-01', 'location' => 'Samal']);

        $groups = collect($this->actingAs($admin, 'admin')->getJson('/portal/admin/search?q=reef')->json('results'))->pluck('group')->all();
        $this->assertEqualsCanonicalizing(['Establishments', 'Advisories', 'Events'], $groups);

        $pages = $this->actingAs($admin, 'admin')->getJson('/portal/admin/search?q=audit')->json('results.0');
        $this->assertSame('Pages', $pages['group']);
        $this->assertSame(route('admin.audit-log'), $pages['url']);
        $this->assertNull($pages['modal'], 'A page is a plain link.');
    }

    public function test_a_name_that_starts_with_the_search_comes_before_one_that_only_contains_it(): void
    {
        $this->destination('Old Eagle Park');
        $this->destination('Eagle Falls');

        $titles = collect($this->actingAs($this->admin(), 'admin')->getJson('/portal/admin/search?q=eagle')->json('results'))->pluck('title')->all();

        $this->assertSame(['Eagle Falls', 'Old Eagle Park'], $titles);
    }

    public function test_a_percent_or_underscore_is_searched_for_literally(): void
    {
        $this->destination('Plain Falls');
        $this->destination('100% Pure Farm');

        $admin = $this->admin();
        $this->assertCount(0, $this->actingAs($admin, 'admin')->getJson('/portal/admin/search?q=%25%25')->json('results'), 'A bare percent sign is not a wildcard.');
        $this->assertSame(['100% Pure Farm'], collect($this->actingAs($admin, 'admin')->getJson('/portal/admin/search?q=100%25')->json('results'))->pluck('title')->all());
        $this->assertCount(0, $this->actingAs($admin, 'admin')->getJson('/portal/admin/search?q=__')->json('results'));
    }

    public function test_an_archived_listing_is_found_and_marked(): void
    {
        $this->destination('Hidden Falls', ['archived_at' => now()]);

        $result = $this->actingAs($this->admin(), 'admin')->getJson('/portal/admin/search?q=hidden')->json('results.0');

        $this->assertStringContainsString('archived', $result['sub']);
    }

    public function test_the_results_are_capped_and_span_the_listing_kinds(): void
    {
        foreach (range(1, 6) as $i) {
            $this->destination("Reef Spot {$i}");
        }
        $region = Region::first();
        Restaurant::create(['slug' => 'r', 'name' => 'Reef Grill', 'location' => 'Samal', 'region_id' => $region->id]);

        $results = $this->actingAs($this->admin(), 'admin')->getJson('/portal/admin/search?q=reef')->json('results');

        $this->assertLessThanOrEqual(8, count($results));
        $this->assertContains('Reef Grill', array_column($results, 'title'));
    }

    public function test_searching_is_not_written_to_the_audit_log(): void
    {
        $this->destination('Eagle Falls');
        $this->actingAs($this->admin(), 'admin')->getJson('/portal/admin/search?q=eagle')->assertOk();

        $this->assertDatabaseMissing('system_audit_logs', ['action' => 'admin.search']);
    }
}
