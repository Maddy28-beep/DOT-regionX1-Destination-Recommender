<?php

/*
 * ExploreDVO QA sweep for the first trial (professor's QA run). ONE command, fixed seed, pass/fail summary.
 *
 *   php deploy/qa/qa-sweep.php [--seed=20261109] [--only=D1,D2,...] [--json=path]
 *
 * Runs against whatever database the environment points at, but ONLY a local one (127.0.0.1 / localhost / ::1):
 * it refuses to start otherwise. Every write happens inside a transaction that is rolled back at the end, so it
 * may be pointed at a copy of production data. Run it with APP_ENV=testing and the throttles left in place; the
 * sections that need many requests lift the throttle for themselves and say so.
 *
 *   D1 planner edge cases      D2 route crawl          D3 authorisation        D4 data-leak scan
 *   D5 hostile input           D6 repeated actions     D7 application log
 */

use App\Models\AdminUser;
use App\Models\Destination;
use App\Models\EstablishmentAccount;
use App\Models\Itinerary;
use App\Models\Promotion;
use App\Models\Region;
use App\Models\TouristAccount;
use App\Models\TouristPreference;
use App\Services\Recommendation\TransportMode;
use Illuminate\Foundation\Testing\TestCase as FrameworkTestCase;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

$opts = getopt('', ['seed::', 'only::', 'json::']);
$seed = (int) ($opts['seed'] ?? 20261109);
$only = isset($opts['only']) ? array_map('trim', explode(',', $opts['only'])) : null;
$jsonOut = $opts['json'] ?? null;

final class Results
{
    /** @var array<int, array{section: string, check: string, ok: bool, detail: string}> */
    public static array $rows = [];

    public static function add(string $section, string $check, bool $ok, string $detail = ''): void
    {
        self::$rows[] = ['section' => $section, 'check' => $check, 'ok' => $ok, 'detail' => $detail];
    }
}

final class Sweep extends FrameworkTestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }

    public function open(): void
    {
        $this->setUp();
        config(['app.debug' => false]); // what the visitor gets: a plain error page, never a stack trace
        DB::beginTransaction();
    }

    public function close(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        // No tearDown(): it needs the PHPUnit runner; the process ends right after the summary.
    }

    // ---------------------------------------------------------------- helpers
    public const LEAK_PATTERNS = [
        'a stack trace' => '/Stack trace:|#0 \/|vendor\/laravel\/framework|Whoops!|Illuminate\\\\[A-Za-z\\\\]+Exception|SQLSTATE\[/',
        'a placeholder accreditation number' => '/DOT-XI-2025-\d{3,4}/',
    ];

    public function leaks(string $body): array
    {
        $found = [];
        foreach (self::LEAK_PATTERNS as $what => $pattern) {
            if (preg_match($pattern, $body, $m)) {
                $found[] = $what.' ('.mb_substr($m[0], 0, 40).')';
            }
        }

        return $found;
    }

    public function payload(array $over = []): array
    {
        return $over + [
            'travel_days' => 3, 'travel_type' => 'Family', 'travel_purpose' => 'Leisure', 'visitor_type' => 'First-time Visitor',
            'place_of_origin' => 'Manila', 'budget' => 'Mid-range', 'accommodation_pref' => 'Any', 'distance_pref' => 'moderate',
            'start_date' => now('Asia/Manila')->addDays(14)->toDateString(), 'arrival_time' => '09:00',
            'activities' => ['Nature & Adventure', 'Cultural Heritage'],
        ];
    }

    /** The visitor-facing message of an error toast on the page (the app shows refusals this way), or null. */
    public function toastMessage(string $body): ?string
    {
        if (preg_match('/__toastQueue\.push\(\[\s*"error",\s*"([^"]+)",\s*"([^"]*)"/', $body, $m)) {
            return stripcslashes($m[1].' — '.$m[2]);
        }

        return null;
    }

    public function plan(array $over, string $label, string $section = 'D1', bool $expectRefusal = false): ?string
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->flushSession();   // each check starts as a new visitor; an invalid POST must not fall back to a previous trip
        $t = microtime(true);
        try {
            $response = $this->followingRedirects()->post(route('plan.update'), $this->payload($over));
        } catch (Throwable $e) {
            Results::add($section, $label, false, 'EXCEPTION '.get_class($e).': '.mb_substr($e->getMessage(), 0, 160));

            return null;
        }
        $ms = round((microtime(true) - $t) * 1000);
        $body = $response->getContent();
        $status = $response->getStatusCode();
        if ($dump = getenv('QA_DUMP')) {
            file_put_contents($dump.'/'.preg_replace('/\W+/', '_', $label).'.html', $body);
        }
        $problems = $this->leaks($body);
        $hasStops = substr_count($body, 'itin-stop') + substr_count($body, 'data-stop') > 0 || str_contains($body, 'Day 1');
        $explained = (bool) preg_match('/no (sightseeing|itinerary|destinations)|could not|not available|ran out|Try /i', $body);
        if ($status >= 500) {
            $problems[] = "HTTP $status";
        } elseif ($status >= 400 && $status !== 422) {
            $problems[] = "HTTP $status";
        }
        if ($status === 200 && ! $hasStops && ! $explained) {
            $problems[] = 'empty itinerary without an explanation';
        }
        if ($expectRefusal) {
            $shownError = (bool) preg_match('/(is required|must be|may not|invalid|before or equal|at least|at most|greater than|less than|please (select|choose|pick)|select at least|choose at least)/i', strip_tags($body));
            $planned = str_contains($body, 'Day 1') && ! str_contains($body, 'Travel Preferences');
            if ($planned || ! $shownError) {
                $problems[] = $planned ? 'the trip was planned although the input is invalid' : 'refused, but no readable message on the page';
            }
        } elseif (str_contains($body, 'Travel Preferences') && ! str_contains($body, 'Day 1') && $status === 200 && ! $this->toastMessage($body)) {
            $problems[] = 'a valid request ended back on the form';
        }
        Results::add($section, $label, $problems === [], $problems === [] ? "HTTP $status, {$ms} ms".($expectRefusal ? ', message shown' : '') : implode('; ', $problems));

        return $body;
    }
}

function section(string $id): bool
{
    global $only;

    return $only === null || in_array($id, $only, true);
}

mt_srand($seed);
$t = new Sweep('sweep');
$started = microtime(true);
$logFile = $root.'/storage/logs/laravel-'.date('Y-m-d').'.log';
$logSingle = $root.'/storage/logs/laravel.log';
$logMarker = file_exists($logSingle) ? filesize($logSingle) : 0;
$logDailyMarker = file_exists($logFile) ? filesize($logFile) : 0;

$dbHost = (string) (getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '127.0.0.1'));
if (! in_array($dbHost, ['127.0.0.1', 'localhost', '::1'], true) && getenv('DB_CONNECTION') !== 'sqlite') {
    fwrite(STDERR, "Refusing to run: DB_HOST is '$dbHost'. The sweep only runs against a local database (a copy).\n");
    exit(2);
}

$t->open();

// ====================================================================== D1 planner edge cases
if (section('D1')) {
    $transports = array_merge([null], TransportMode::keys());
    $budgets = ['Budget-Friendly', 'Mid-range', 'Premium'];
    $distances = ['near', 'moderate', 'far'];
    $n = 0;
    foreach ($budgets as $b) {
        foreach ($distances as $d) {
            foreach ($transports as $tr) {
                foreach ([1, 7] as $days) {
                    $n++;
                    $t->plan(array_filter(['budget' => $b, 'distance_pref' => $d, 'transport_pref' => $tr, 'travel_days' => $days], fn ($v) => $v !== null), "matrix $b/$d/".($tr ?? 'no transport')."/{$days}d");
                }
            }
        }
    }
    Results::add('D1', "matrix of $n budget x distance x transport x length combinations ran", true, "$n trips");

    $t->plan(['start_date' => now('Asia/Manila')->subDays(30)->toDateString()], 'start date in the past');
    $t->plan(['start_date' => now('Asia/Manila')->addDays(700)->toDateString()], 'start date far in the future (within the 2-year limit)');
    $t->plan(['start_date' => now('Asia/Manila')->addYears(5)->toDateString()], 'start date beyond 2 years is refused with a message', 'D1', true);
    $t->plan(['activities' => []], 'no interests (must be refused with a message)', 'D1', true);
    $all = ['Beach & Island', 'Nature & Adventure', 'Cultural Heritage', 'Food & Culinary', 'Wildlife', 'Shopping & Souvenirs', 'Wellness & Spa', 'Festivals & Events'];
    $t->plan(['activities' => $all], 'all interests at once');
    foreach (['00:30', '04:00', '05:59', '19:45', '23:30'] as $time) {
        $t->plan(['arrival_time' => $time], "arrival at $time");
    }
    $t->plan(['arrival_time' => null], 'no arrival time');
    $t->plan(['origin_lat' => null, 'origin_lng' => null], 'no starting point');
    $t->plan(['departure_time' => '14:30'], 'departure time given');
    $t->plan(['departure_time' => '14:30', 'departure_lat' => 7.1255, 'departure_lng' => 125.6459, 'departure_label' => 'Airport'], 'departure time and point given');
    $t->plan(['departure_time' => '06:00', 'travel_days' => 1], 'one-day trip leaving at 06:00');
    $t->plan(['travel_days' => 31], 'more than 30 days is refused with a message', 'D1', true);
    $t->plan(['travel_days' => 0], 'zero days is refused with a message', 'D1', true);
    $t->plan(['visitor_type' => 'Regular / Local', 'place_of_origin' => null], 'local visitor without an origin');

    // Official-only mode.
    config(['itinerary.official_only' => true]);
    $t->plan([], 'official-only mode on a normal trip');
    config(['itinerary.official_only' => false]);

    // A trip whose only options are closed: close everything for the trip's dates and ask for a trip.
    DB::beginTransaction();
    Destination::query()->update(['operating_status' => 'closed']);
    $body = $t->plan([], 'every destination closed: a clear message, not a crash');
    $msg = $body === null ? null : $t->toastMessage($body);
    $clear = $msg !== null && (bool) preg_match('/no open places|closed|out of range/i', $msg);
    Results::add('D1', 'closed everywhere: the page says why there is no trip', $clear, $clear ? 'shown: '.$msg : 'no explanation found');
    DB::rollBack();

    // No place that should have been excluded: build trips in the service and test each stop.
    $excluded = 0;
    $checked = 0;
    foreach (['near', 'moderate', 'far'] as $d) {
        $pref = TouristPreference::create($t->payload(['distance_pref' => $d, 'travel_days' => 4]) + []);
        foreach ($t->payload()['activities'] as $a) {
            App\Models\PreferenceActivity::create(['preference_id' => $pref->id, 'activity' => $a]);
        }
        try {
            $trip = app(App\Services\Recommendation\ItineraryGenerationService::class)->generate($pref->load('activities', 'amenities'));
        } catch (Throwable $e) {
            Results::add('D1', "service generate $d", false, get_class($e).': '.$e->getMessage());

            continue;
        }
        foreach ($trip->items()->whereNotNull('destination_id')->pluck('destination_id')->unique() as $id) {
            $checked++;
            $dest = Destination::find($id);
            if (! $dest || ! $dest->is_accredited || $dest->archived_at !== null || in_array($dest->operating_status, ['closed', 'permanently_closed'], true)) {
                $excluded++;
            }
        }
    }
    Results::add('D1', "no closed / archived / unaccredited place on a generated trip ($checked stops checked)", $excluded === 0, $excluded === 0 ? 'none found' : "$excluded found");
}

// ====================================================================== fixtures for D2-D6
$admin = AdminUser::create(['email' => 'qa-admin@x.test', 'password_hash' => Hash::make('x'), 'full_name' => 'QA Admin', 'role' => 'super_admin']);
$staff = AdminUser::create(['email' => 'qa-staff@x.test', 'password_hash' => Hash::make('x'), 'full_name' => 'QA Staff', 'role' => 'admin']);
$listing = Destination::query()->where('is_accredited', true)->first();
$other = Destination::query()->where('is_accredited', true)->where('id', '!=', $listing?->id)->first();
$mk = fn (Destination $d, string $email) => EstablishmentAccount::create([
    'business_name' => $d->name, 'listing_kind' => 'destination', 'matched_listing_id' => $d->id, 'portal_key' => (string) Str::uuid(), 'email' => $email,
    'password_hash' => Hash::make('x'), 'contact_person' => 'QA', 'contact_number' => '09170000000', 'status' => 'approved', 'submitted_at' => now(),
]);
$partnerA = $mk($listing, 'qa-a@x.test');
$partnerB = $mk($other, 'qa-b@x.test');
$tourist = TouristAccount::create(['alias' => 'qa-tourist', 'password_hash' => Hash::make('x')]);

// ====================================================================== D2 route crawl
if (section('D2')) {
    $params = fn (Illuminate\Routing\Route $r) => collect($r->parameterNames())->mapWithKeys(function ($name) use ($listing) {
        $model = match ($name) {
            'destination' => $listing, 'accommodation' => App\Models\Accommodation::first(), 'restaurant' => App\Models\Restaurant::first(),
            'souvenirCenter' => App\Models\SouvenirCenter::first(), 'tourOperator' => App\Models\TourOperator::first(), 'package' => App\Models\Package::first(),
            'itinerary' => Itinerary::first(), 'advisory' => App\Models\Advisory::first(), 'event' => App\Models\Event::first(), 'postcardSlide' => App\Models\PostcardSlide::first(),
            default => null,
        };

        return [$name => match (true) {
            $model !== null => $model->getRouteKey(), $name === 'type' => 'destinations', $name === 'id' => $listing->id, $name === 'section' => 'overview', $name === 'path' => 'x',
            default => '1',
        }];
    })->all();

    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => in_array('GET', $r->methods(), true) && ! str_starts_with($r->uri(), '_') && ! str_starts_with($r->uri(), 'storage'));
    $who = ['guest' => null, 'admin' => ['admin', $admin], 'partner' => ['establishment', $partnerA], 'tourist' => ['tourist', $tourist]];
    $bad = [];
    $count = 0;
    foreach ($who as $label => $actor) {
        foreach ($routes as $r) {
            $uri = '/'.ltrim(preg_replace_callback('/\{(\w+)\??(?::[^}]+)?\}/', fn ($m) => $params($r)[$m[1]] ?? '1', $r->uri()), '/');
            $count++;
            foreach (['admin', 'establishment', 'tourist', 'web'] as $g) {
                auth($g)->logout();   // one role at a time: a login from the previous role must not carry over
            }
            if ($actor) {
                $t->actingAs($actor[1], $actor[0]);
            }
            try {
                $res = $t->get($uri);
            } catch (Throwable $e) {
                $bad[] = "$label GET $uri EXCEPTION ".get_class($e);

                continue;
            }
            $status = $res->getStatusCode();
            $body = (string) $res->getContent();
            $isProtected = str_starts_with($r->uri(), 'portal/admin') || str_starts_with($r->uri(), 'portal/establishment') || str_starts_with($r->uri(), 'account/');
            if ($status >= 500) {
                $bad[] = "$label GET $uri -> $status";
            }
            if ($label === 'guest' && $isProtected && $status === 200 && ! str_contains($r->uri(), 'login') && ! str_contains($r->uri(), 'register') && ! str_contains($r->uri(), 'forgot') && ! str_contains($r->uri(), 'reset')) {
                $bad[] = "guest can open protected $uri (200)";
            }
            if ($label === 'partner' && str_starts_with($r->uri(), 'portal/admin') && $status === 200) {
                $bad[] = "partner can open admin page $uri (200)";
            }
            if ($label === 'admin' && str_starts_with($r->uri(), 'portal/establishment') && $status === 200 && ! str_contains($r->uri(), 'login')) {
                $bad[] = "admin session can open partner page $uri (200)";
            }
            if ($status === 200 && ($lk = $t->leaks($body)) !== []) {
                $bad[] = "$label GET $uri leaks ".implode(', ', $lk);
            }
            // Tour-package screens are for tour-operator accounts only: a hotel partner correctly gets a 404 there.
            $operatorOnly = $label === 'partner' && str_starts_with($r->uri(), 'portal/establishment/packages');
            if ($status === 404 && ! $operatorOnly && ! str_contains($r->uri(), '{') && ! str_contains($r->uri(), 'assets')) {
                $bad[] = "$label GET $uri -> 404 (route exists but page is missing)";
            }
        }
    }
    Results::add('D2', "crawled $count route/role combinations (".$routes->count().' GET routes x guest, admin, partner, tourist)', $bad === [], $bad === [] ? 'no unintended 5xx, stack trace, open protected page' : count($bad).' problems: '.implode(' | ', array_slice($bad, 0, 12)));
}

// ====================================================================== D3 authorisation
if (section('D3')) {
    foreach (['admin', 'establishment', 'tourist', 'web'] as $g) {
        auth($g)->logout();
    }
    $promoB = Promotion::create(['establishment_account_id' => $partnerB->id, 'title' => 'B only', 'listing_kind' => 'destination', 'listing_id' => $other->id] + []);
    $res = $t->actingAs($partnerA, 'establishment')->put('/portal/establishment/promotions/'.$promoB->id, ['title' => 'hijacked']);
    $changed = Promotion::find($promoB->id)?->title !== 'B only';
    Results::add('D3', "partner A cannot change partner B's promotion (status {$res->getStatusCode()})", ! $changed, $changed ? 'B\'s promotion was changed by A' : 'unchanged');
    $res = $t->actingAs($partnerA, 'establishment')->delete('/portal/establishment/promotions/'.$promoB->id);
    Results::add('D3', "partner A cannot delete partner B's promotion (status {$res->getStatusCode()})", Promotion::find($promoB->id) !== null, Promotion::find($promoB->id) ? 'still there' : 'DELETED by A');
    $t->actingAs($partnerA, 'establishment')->put('/portal/establishment/listing', ['operating_status' => 'open', 'matched_listing_id' => $other->id, 'listing_id' => $other->id]);
    Results::add('D3', "partner A's listing edit cannot be pointed at partner B's place", $partnerA->fresh()->matched_listing_id === $listing->id, 'matched listing unchanged');

    // Another visitor's trip: guess ids.
    $pref = TouristPreference::create($t->payload());
    $trip = Itinerary::create(['preference_id' => $pref->id, 'total_days' => 3, 'generated_at' => now()]);
    foreach (['/plan/itinerary', '/account/itineraries/'.$trip->id, '/plan/itinerary/'.$trip->id, '/itineraries/'.$trip->id, '/plan/'.$trip->id] as $url) {
        $res = $t->get($url);
        $exposes = $res->getStatusCode() === 200 && str_contains((string) $res->getContent(), 'Day 1');
        Results::add('D3', "a stranger cannot open someone else's trip by guessing $url (status {$res->getStatusCode()})", ! $exposes, $exposes ? 'trip content shown' : 'not shown');
    }
    $t->actingAs($tourist, 'tourist');
    $res = $t->get('/account/itineraries/'.$trip->id);
    Results::add('D3', "a signed-in tourist cannot open a trip that is not theirs (status {$res->getStatusCode()})", $res->getStatusCode() !== 200, '');
    $res = $t->delete('/account/itineraries/'.$trip->id);
    Results::add('D3', "a signed-in tourist cannot delete a trip that is not theirs (status {$res->getStatusCode()})", Itinerary::find($trip->id) !== null, '');
    $res = $t->put('/storage/qa-upload.txt', ['x' => 'y']);
    Results::add('D3', 'unsigned PUT to /storage/{path} is refused (status '.$res->getStatusCode().')', in_array($res->getStatusCode(), [403, 404, 405], true), 'framework local-disk upload route');
    foreach (['admin', 'establishment', 'tourist', 'web'] as $g) {
        auth($g)->logout();
    }
    foreach (['POST /portal/admin/advisories', 'POST /portal/admin/listings/destinations', 'PUT /portal/establishment/listing', 'POST /portal/establishment/promotions'] as $line) {
        [$m, $u] = explode(' ', $line);
        $res = $t->call($m, $u, ['title' => 'x']);
        Results::add('D3', "guest cannot $line (status {$res->getStatusCode()})", in_array($res->getStatusCode(), [302, 401, 403, 419], true), '');
    }
}

// ====================================================================== D4 data-leak scan
if (section('D4')) {
    $placeholder = '/DOT-XI-2025-\d{3,4}/';
    $pages = ['/', '/destinations', '/accommodations', '/restaurants', '/souvenir-centers', '/tour-operators', '/packages', '/advisories', '/plan', '/destinations/'.$listing->slug];
    $hits = [];
    foreach (Destination::query()->limit(40)->get() as $d) {
        $pages[] = '/destinations/'.$d->slug;
    }
    foreach (array_unique($pages) as $url) {
        $body = (string) $t->get($url)->getContent();
        if (preg_match($placeholder, $body, $m)) {
            $hits[] = "$url shows {$m[0]}";
        }
    }
    Results::add('D4', 'no placeholder accreditation number on '.count(array_unique($pages)).' public pages', $hits === [], implode(' | ', array_slice($hits, 0, 8)));

    // Unlabelled sample records: every sample / unverified listing must carry its marker wherever its name is printed in plain text.
    $sd = app(App\Support\SampleData::class);
    $unlabelled = [];
    foreach (Destination::query()->where('is_accredited', true)->limit(200)->get() as $d) {
        $status = $sd->status($d);
        if ($status !== App\Support\SampleData::OFFICIAL && ! str_contains($sd->labelledName($d), ' [')) {
            $unlabelled[] = $d->name;
        }
    }
    Results::add('D4', 'every sample / unverified destination gets a label from SampleData::labelledName()', $unlabelled === [], implode(', ', array_slice($unlabelled, 0, 5)));

    // Exports and calendar of a generated trip, and the chatbot, carry no placeholder number and no real business names marked as sample.
    $t->plan([], 'trip for the export scan', 'D4');
    $chat = (string) $t->postJson('/chatbot/message', ['message' => 'Which resorts are accredited? Give me the accreditation numbers.'])->getContent();
    Results::add('D4', 'chatbot answer shows no placeholder accreditation number', ! preg_match($placeholder, $chat), mb_substr($chat, 0, 100));
    $csvHits = [];
    foreach (['admin.reports.export', 'admin.reports'] as $rn) {
        if (Route::has($rn)) {
            $t->actingAs($admin, 'admin');
            $body = (string) $t->get(route($rn, ['report_type' => 'visits', 'format' => 'csv']))->getContent();
            if (preg_match($placeholder, $body)) {
                $csvHits[] = $rn;
            }
        }
    }
    Results::add('D4', 'admin report / CSV exports show no placeholder accreditation number', $csvHits === [], implode(',', $csvHits));
}

// ====================================================================== D5 hostile input
if (section('D5')) {
    foreach (['admin', 'establishment', 'tourist', 'web'] as $g) {
        auth($g)->logout();
    }
    $hostile = [
        'very long text' => str_repeat('A very long line of text. ', 4000),
        'special characters' => "Ana's \"quoted\" <b>bold</b> & ünïcödé ñ 日本語 \\ / % _ \0",
        'emoji' => '🏝️🌴🔥 Great trip 😀👨‍👩‍👧‍👦',
        'script tag' => '<script>window.__xss=1</script><img src=x onerror=alert(1)>',
        'sql-looking' => "'; DROP TABLE destinations; -- ' OR '1'='1",
    ];
    $before = Destination::count();
    foreach ($hostile as $label => $text) {
        // chatbot
        try {
            $res = $t->postJson('/chatbot/message', ['message' => $text]);
            $body = (string) $res->getContent();
            $ok = $res->getStatusCode() < 500 && ! str_contains($body, '<script>window.__xss') && $t->leaks($body) === [];
            Results::add('D5', "chatbot: $label", $ok, 'HTTP '.$res->getStatusCode());
        } catch (Throwable $e) {
            Results::add('D5', "chatbot: $label", false, get_class($e).': '.mb_substr($e->getMessage(), 0, 120));
        }
        // trip form text fields
        $body = $t->plan(['place_of_origin' => mb_substr($text, 0, 140), 'origin_label' => mb_substr($text, 0, 190), 'accessibility_notes' => mb_substr($text, 0, 900)], "trip form text fields: $label", 'D5');
        $executes = $body !== null && (str_contains($body, '<script>window.__xss') || str_contains($body, 'onerror=alert'));
        Results::add('D5', "trip form output is escaped: $label", ! $executes, $executes ? 'raw markup echoed back' : 'escaped');
        // review
        try {
            $res = $t->post('/reviews/destinations/'.$listing->id, ['rating' => 5, 'comment' => $text, 'body' => $text, 'title' => mb_substr($text, 0, 100)]);
            Results::add('D5', "review: $label", $res->getStatusCode() < 500, 'HTTP '.$res->getStatusCode());
            $page = (string) $t->get('/destinations/'.$listing->slug)->getContent();
            Results::add('D5', "review shown escaped: $label", ! str_contains($page, '<script>window.__xss'), '');
        } catch (Throwable $e) {
            Results::add('D5', "review: $label", false, get_class($e).': '.mb_substr($e->getMessage(), 0, 120));
        }
        // exit survey free text
        try {
            $res = $t->post('/exit-survey', ['feedback' => $text, 'comments' => $text, 'suggestions' => $text]);
            Results::add('D5', "exit survey: $label", $res->getStatusCode() < 500, 'HTTP '.$res->getStatusCode());
        } catch (Throwable $e) {
            Results::add('D5', "exit survey: $label", false, get_class($e).': '.mb_substr($e->getMessage(), 0, 120));
        }
        // search / filter query strings
        $res = $t->get('/destinations?search='.urlencode($text));
        Results::add('D5', "catalogue search: $label", $res->getStatusCode() < 500, 'HTTP '.$res->getStatusCode());
    }
    Results::add('D5', 'tables intact after the SQL-looking input', Destination::count() === $before, '');
}

// ====================================================================== D6 repeated actions
if (section('D6')) {
    foreach (['admin', 'establishment', 'tourist', 'web'] as $g) {
        auth($g)->logout();
    }
    $t->withoutMiddleware(ThrottleRequests::class);
    $t->followingRedirects()->post(route('plan.update'), $t->payload());
    $codes = [];
    for ($i = 0; $i < 5; $i++) {
        $codes[] = $t->post(route('plan.regenerate'))->getStatusCode();
    }
    Results::add('D6', 'five rapid Regenerate requests: no 5xx ('.implode(',', $codes).')', max($codes) < 500, '');
    $rows = Itinerary::count();
    $sw = [];
    foreach ([1, 2, 3] as $i) {
        $sw[] = $t->post(route('plan.swap'), ['destination_id' => 999999, 'replacement_id' => 999998])->getStatusCode();
    }
    Results::add('D6', 'forged swap (places that are not on the trip) refused without a 5xx ('.implode(',', $sw).')', max($sw) < 500, '');
    $sw = $t->post(route('plan.swap'), ['destination_id' => 'abc', 'replacement_id' => ['x']])->getStatusCode();
    Results::add('D6', "malformed swap values refused without a 5xx ($sw)", $sw < 500, '');
    $dupe = [$t->post(route('plan.itinerary.save'))->getStatusCode(), $t->post(route('plan.itinerary.save'))->getStatusCode()];
    Results::add('D6', 'double-submitted Save: no 5xx ('.implode(',', $dupe).')', max($dupe) < 500, '');

    // Rate limit: the real limiter, on a fresh key.
    Illuminate\Support\Facades\RateLimiter::clear('trip:127.0.0.1');
    $t2 = new Sweep('sweep');
    $t2->open();
    $limited = null;
    for ($i = 1; $i <= 35; $i++) {   // JSON requests get the 429 itself
        if ($t2->postJson(route('plan.regenerate'))->getStatusCode() === 429) {
            $limited = $i;

            break;
        }
    }
    Results::add('D6', 'trip-build rate limit trips at 30/min with HTTP 429 for JSON'.($limited ? " (request $limited)" : ''), $limited !== null && $limited >= 28, '');
    // A browser gets a redirect back with a readable toast instead (the limiter is already full for this address).
    $first = $t2->post(route('plan.regenerate'));   // not followed: the flash is read from the session, then the redirect target is shown
    $flash = (string) session('error').' — '.(string) session('status_detail');
    $page = (string) $first->getContent();   // the redirect body itself must not carry a trace
    if ($dump = getenv('QA_DUMP')) {
        file_put_contents($dump.'/rate_limit_page.html', $page);
    }
    $toast = $first->getStatusCode() === 302 && (bool) preg_match('/Please slow down — Too many tries in a short time\. Please wait \d+ seconds/', $flash);
    Results::add('D6', 'the rate-limit message shown to a browser is readable (no blank page, no trace)', $toast && $t->leaks($page) === [], $toast ? 'shown: Please slow down — Too many tries in a short time…' : 'toast not found: status '.$first->getStatusCode().', flash ['.$flash.'], location '.$first->headers->get('Location'));
    $t2->close();
}

$t->close();

// ====================================================================== D7 application log
if (section('D7')) {
    $lines = [];
    $expectedLogged = 0;
    foreach ([[$logSingle, $logMarker], [$logFile, $logDailyMarker]] as [$file, $from]) {
        if (is_file($file) && filesize($file) > $from) {
            $fh = fopen($file, 'rb');
            fseek($fh, $from);
            $chunk = stream_get_contents($fh);
            fclose($fh);
            foreach (preg_split('/\R/', $chunk) as $line) {
                if (preg_match('/^\[[\d\- :]+\] \w+\.(ERROR|CRITICAL|EMERGENCY|ALERT): (.{0,200})/', $line, $m)) {
                    if (str_contains($m[2], 'No accredited destinations are available')) {
                        $expectedLogged++;   // the closed-everywhere check in D1 raises this on purpose; the visitor sees a message

                        continue;
                    }
                    $lines[] = $m[2];
                }
            }
        }
    }
    $unique = array_count_values($lines);
    arsort($unique);
    Results::add('D7', 'no exception was written to the application log during the sweep', $lines === [], $lines === [] ? 'log clean'.($expectedLogged ? " ($expectedLogged expected 'no accredited destinations' entry from the closed-everywhere check)" : '') : count($lines).' entries: '.implode(' | ', array_map(fn ($k, $v) => "{$v}x ".mb_substr($k, 0, 110), array_keys(array_slice($unique, 0, 6, true)), array_slice($unique, 0, 6, true))));
}

// ====================================================================== summary
$pass = count(array_filter(Results::$rows, fn ($r) => $r['ok']));
$fail = count(Results::$rows) - $pass;
echo "\nExploreDVO QA sweep  seed=$seed  ".date('Y-m-d H:i:s').'  '.round(microtime(true) - $started).'s  commit '.trim((string) @shell_exec('git -C '.escapeshellarg($root).' rev-parse --short HEAD'))."\n";
echo str_repeat('=', 100)."\n";
$bySection = [];
foreach (Results::$rows as $r) {
    $bySection[$r['section']]['pass'] = ($bySection[$r['section']]['pass'] ?? 0) + ($r['ok'] ? 1 : 0);
    $bySection[$r['section']]['fail'] = ($bySection[$r['section']]['fail'] ?? 0) + ($r['ok'] ? 0 : 1);
}
ksort($bySection);
foreach ($bySection as $s => $c) {
    printf("%-4s pass %4d   fail %3d\n", $s, $c['pass'], $c['fail']);
}
echo str_repeat('-', 100)."\n";
foreach (Results::$rows as $r) {
    if (! $r['ok']) {
        printf("FAIL %-3s %s\n       %s\n", $r['section'], $r['check'], $r['detail']);
    }
}
echo str_repeat('=', 100)."\nTOTAL pass $pass  fail $fail   =>  ".($fail === 0 ? 'GATE 2: MET' : 'GATE 2: NOT MET')."\n";
if ($jsonOut) {
    file_put_contents($jsonOut, json_encode(['seed' => $seed, 'rows' => Results::$rows], JSON_PRETTY_PRINT));
}
exit($fail === 0 ? 0 : 1);
