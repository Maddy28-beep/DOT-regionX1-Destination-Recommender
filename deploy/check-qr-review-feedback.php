<?php
// Actual HTTP routes, isolated code copy, temporary QA place and one rollback transaction.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->instance('request', Illuminate\Http\Request::create('https://www.exploredavao.com/'));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class); $kernel->bootstrap();
config(['session.driver' => 'array', 'cache.default' => 'array']); $app->instance('env', 'testing');
$checks = 0;
$check = function ($ok, $label) use (&$checks) { if (!$ok) throw new RuntimeException($label); echo "PASS: $label\n"; $checks++; };
$send = function ($method, $url, $data, array &$cookies, $json = false) use ($app, $kernel) {
    $request = Illuminate\Http\Request::create(str_starts_with($url, 'http') ? $url : 'https://www.exploredavao.com'.$url, $method, $data, $cookies, [], ['HTTP_ACCEPT' => $json ? 'application/json' : 'text/html']);
    $app->instance('request', $request); $response = $kernel->handle($request);
    foreach ($response->headers->getCookies() as $cookie) $cookies[$cookie->getName()] = $cookie->getValue();
    return $response;
};
Illuminate\Support\Facades\DB::beginTransaction();
try {
    $tag = 'QA feedback '.bin2hex(random_bytes(5));
    $region = App\Models\Region::firstOrFail();
    $place = App\Models\Destination::create(['slug' => Illuminate\Support\Str::slug($tag), 'name' => $tag, 'location' => 'Davao City', 'region_id' => $region->id, 'type' => 'Nature', 'is_accredited' => true, 'price_tier' => 'Mid-range', 'rating' => 0, 'review_count' => 0]);
    $owner = []; $outsider = [];
    $detailUrl = route('destinations.show', $place);
    $scanUrl = Illuminate\Support\Facades\URL::signedRoute('check-in', ['type' => 'destinations', 'id' => $place->id]);
    $scan = $send('GET', $scanUrl, [], $owner);
    $check($scan->getStatusCode() === 302 && App\Models\TouristVisit::where('listing_kind', 'destination')->where('listing_id', $place->id)->count() === 1, 'Signed QR scan records the temporary visitor');
    $page = $send('GET', $detailUrl, [], $owner);
    $check($page->getStatusCode() === 200, 'Checked-in traveler can open the listing');
    $html = $page->getContent();
    file_put_contents(getcwd().'/qr-review-initial-preview.html', $html);
    $dom = new DOMDocument(); @$dom->loadHTML($html); $path = new DOMXPath($dom);
    $check($path->query('//form[@data-review-form]//textarea[@name="comment" and not(ancestor::details)]')->length === 1, 'Comment field is visible without opening Add details');
    $check($path->query('//form[@data-review-form]//input[@name="service_rating" and not(ancestor::details)]')->length === 5 && $path->query('//form[@data-review-form]//input[@name="amenities_rating" and not(ancestor::details)]')->length === 5, 'Service and amenities each expose five optional stars');
    $store = '/reviews/destinations/'.$place->id;
    $complete = $store.'/feedback';
    $saved = $send('POST', $store, ['rating' => 4], $owner, true);
    $check($saved->getStatusCode() === 302, 'Traveler can still post only the required overall rating');
    $review = App\Models\Review::where('listing_kind', 'destination')->where('listing_id', $place->id)->firstOrFail();
    $originalId = $review->id;
    $check($review->rating === 4 && $review->service_rating === null && $review->amenities_rating === null && blank($review->comment), 'Star-only review is saved without invented optional answers');
    $page = $send('GET', $detailUrl, [], $owner);
    $check($page->getStatusCode() === 200 && str_contains($page->getContent(), 'data-review-followup'), 'Previously rated traveler can return to add missing feedback');
    file_put_contents(getcwd().'/qr-review-followup-preview.html', $page->getContent());
    @$dom->loadHTML($page->getContent()); $path = new DOMXPath($dom);
    $check($path->query('//form[@data-review-followup]//input[@name="rating"]')->length === 0 && $path->query('//form[@data-review-followup]//textarea[@name="comment"]')->length === 1, 'Follow-up form adds comments without requesting another overall rating');
    foreach ([[], ['service_rating' => 6], ['amenities_rating' => 0], ['comment' => str_repeat('a', 501)]] as $index => $invalid) {
        $response = $send('POST', $complete, $invalid, $owner, true);
        $check($response->getStatusCode() === 422, 'Invalid follow-up case '.($index + 1).' is rejected');
    }
    $response = $send('POST', $complete, ['comment' => 'Must not change another visitor'], $outsider, true);
    $check($response->getStatusCode() === 403, 'Unscanned browser cannot add feedback to another visitor review');
    $send('GET', $scanUrl, [], $outsider);
    $response = $send('POST', $complete, ['comment' => 'Still not this browser review'], $outsider, true);
    $check($response->getStatusCode() === 403, 'A checked-in browser without its own rating cannot complete another review');
    $comment = "$tag: clearer signs please.\n<script>qrFeedback()</script>";
    $response = $send('POST', $complete, ['service_rating' => 5, 'amenities_rating' => 3, 'comment' => $comment, 'rating' => 1], $owner, true);
    $check($response->getStatusCode() === 302 && str_ends_with($response->headers->get('Location'), '#traveler-reviews'), 'Completing feedback returns traveler to the review section');
    $review->refresh();
    $check($review->id === $originalId && $review->rating === 4 && $review->service_rating === 5 && $review->amenities_rating === 3 && $review->comment === $comment, 'Missing ratings and comment are added to the original review, with overall rating unchanged');
    $check(App\Models\Review::where('listing_kind', 'destination')->where('listing_id', $place->id)->count() === 1 && $place->fresh()->review_count === 1 && (float)$place->fresh()->rating === 4.0, 'Follow-up does not duplicate review counts or alter the overall average');
    $send('POST', $complete, ['service_rating' => 1, 'amenities_rating' => 1, 'comment' => 'Replace original'], $owner, true);
    $review->refresh();
    $check($review->service_rating === 5 && $review->amenities_rating === 3 && $review->comment === $comment, 'Repeat follow-up cannot overwrite previously answered feedback');
    $page = $send('GET', $detailUrl, [], $owner);
    $check(str_contains($page->getContent(), htmlspecialchars($comment, ENT_QUOTES, 'UTF-8')) && !str_contains($page->getContent(), '<script>qrFeedback()</script>'), 'Saved comment appears as safe plain text on the public review');
    $check(!str_contains($page->getContent(), 'data-review-followup'), 'Completed review no longer asks for missing feedback');
    $send('POST', $store, ['rating' => 5, 'comment' => 'Duplicate'], $owner, true);
    $check(App\Models\Review::where('listing_kind', 'destination')->where('listing_id', $place->id)->count() === 1, 'Original one-review-per-browser rule remains enforced');

    $request = Illuminate\Http\Request::create('https://www.exploredavao.com/portal/admin/feedback-analysis', 'GET', ['establishment' => 'destination:'.$place->id]);
    $app->instance('request', $request); auth('admin')->setUser(App\Models\AdminUser::firstOrFail());
    $controller = app(App\Http\Controllers\Admin\AdminFeedbackAnalysisController::class);
    $view = $controller->index($request, app(App\Services\Feedback\ReviewStatistics::class));
    $data = $view->getData(); $html = $view->render();
    $check(str_contains($html, htmlspecialchars($comment, ENT_QUOTES, 'UTF-8')) && str_contains($html, 'Written reviews'), 'DOT admin sees the traveler written review, not only stars');
    $check(str_contains($html, 'Service: 5/5') && str_contains($html, 'Amenities: 3/5'), 'DOT admin sees the specific optional ratings with the comment');
    $check($data['stats']['aspects']['service']['n'] === 1 && (float)$data['stats']['aspects']['service']['mean'] === 5.0 && $data['stats']['aspects']['amenities']['n'] === 1 && (float)$data['stats']['aspects']['amenities']['mean'] === 3.0, 'Service and amenities statistics use the newly completed answers');
    $check(!str_contains($html, $review->visitor_token), 'Visitor browser token stays out of admin comment output');
    $response = $send('POST', $store, ['rating' => 3, 'service_rating' => 4, 'amenities_rating' => 2, 'comment' => "$tag first-post suggestion"], $outsider, true);
    $check($response->getStatusCode() === 302, 'Another checked-in traveler can submit all categories and a comment together');
    $otherReview = App\Models\Review::where('listing_kind', 'destination')->where('listing_id', $place->id)->where('id', '!=', $originalId)->firstOrFail();
    $check($otherReview->rating === 3 && $otherReview->service_rating === 4 && $otherReview->amenities_rating === 2 && $otherReview->comment === "$tag first-post suggestion", 'Initial form saves overall, service, amenities and comment on the same review');
} finally { Illuminate\Support\Facades\DB::rollBack(); }
echo "$checks QR-review feedback checks passed; all QA visits/reviews/listing changes rolled back.\n";
