<?php
// Isolated current-code app, temporary upload storage and one rollback transaction.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->instance('request', Illuminate\Http\Request::create('https://www.exploredavao.com/'));
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
config(['session.driver' => 'array', 'cache.default' => 'array']);
$tag = 'qa-photo-'.bin2hex(random_bytes(5));
$qaRoot = sys_get_temp_dir().'/exploredvo-photo-qa-'.$tag;
mkdir($qaRoot, 0700);
config(['filesystems.disks.public.root' => $qaRoot.'/public']);
Illuminate\Support\Facades\Storage::forgetDisk('public');
$session = $app['session']->driver(); $session->start();
$checks = 0;
$check = function ($ok, $label) use (&$checks) { if (!$ok) throw new RuntimeException($label); echo "PASS: $label\n"; $checks++; };
$accountFor = function ($kind, $listing) {
    $account = new App\Models\EstablishmentAccount(['business_name' => 'QA owner', 'listing_kind' => $kind, 'matched_listing_id' => $listing->id, 'status' => 'approved']);
    $account->setRelation('matchedListing', $listing); return $account;
};
$requestFor = function ($account, $data = [], $file = false) use ($app, $session, $qaRoot) {
    $files = [];
    if ($file) {
        $temp = $qaRoot.'/input-'.bin2hex(random_bytes(4)).'.png';
        file_put_contents($temp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aMeUAAAAASUVORK5CYII='));
        $files['photos'] = [new Illuminate\Http\UploadedFile($temp, 'qa.png', 'image/png', null, true)];
    }
    $request = Illuminate\Http\Request::create('https://www.exploredavao.com/portal/establishment/photos', 'POST', $data, [], $files, ['HTTP_ACCEPT' => 'application/json']);
    $request->setLaravelSession($session); $app->instance('request', $request); $request->setUserResolver(fn () => $account); return $request;
};
$gallery = function ($listing) {
    return view('partials.gallery-hero', ['photos' => $listing->fresh()->photos, 'title' => $listing->name, 'subtitle' => 'QA', 'isAccredited' => true, 'provenance' => 'sample', 'rating' => '0', 'reviewCount' => 0, 'fallbackGradient' => '#163b30'])->render();
};
Illuminate\Support\Facades\DB::beginTransaction();
try {
    $region = App\Models\Region::firstOrFail();
    $makePlace = fn ($suffix) => App\Models\Destination::create(['slug' => "$tag-$suffix", 'name' => "$tag $suffix", 'location' => 'Davao City', 'region_id' => $region->id, 'type' => 'Nature', 'is_accredited' => true, 'price_tier' => 'Mid-range', 'rating' => 0, 'review_count' => 0]);
    $place = $makePlace('with-fallback'); $owner = $accountFor('destination', $place);
    $fallback = $place->photos()->create(['path' => 'qa/fallback.png', 'category' => 'Sample image', 'sort_order' => 0, 'is_primary' => false]);
    $controller = app(App\Http\Controllers\Establishment\EstablishmentPhotoController::class);
    $controller->store($requestFor($owner, ['category' => 'Amenities', 'amenity' => 'restroom', 'own' => 1], true));
    $restroom = $place->photos()->where('amenity', 'restroom')->firstOrFail();
    $check(!$restroom->is_primary && $restroom->isAmenityPhoto(), 'Restroom upload is classified as an amenity and is not promoted to cover');
    $check($place->fresh()->coverPhoto()?->id === $fallback->id && $place->fresh()->load('photos')->coverPhoto()?->id === $fallback->id, 'Lazy and eager cover selection keep the eligible fallback instead of the restroom');
    $html = $gallery($place);
    $check(str_contains($html, 'fallback.png') && !str_contains($html, basename($restroom->path)), 'Main gallery excludes restroom before suppressing fallback imagery');
    $amenities = view('partials.amenities-on-site', ['listing' => $place->fresh()->load('photos'), 'kind' => 'destination'])->render();
    $check(str_contains($amenities, basename($restroom->path)) && in_array('restroom', app(App\Services\Amenities\AmenityStore::class)->keys('destination', $place), true), 'Restroom image remains linked to the actual Restroom amenity viewer');
    $blocked = false;
    try { $controller->setPrimary($requestFor($owner), $restroom); } catch (Illuminate\Validation\ValidationException $e) { $blocked = true; }
    $check($blocked && $place->fresh()->coverPhoto()?->id === $fallback->id, 'Direct Make cover request rejects amenity images without clearing the valid cover');
    $controller->store($requestFor($owner, ['category' => 'Exterior', 'own' => 1], true));
    $exterior = $place->photos()->where('category', 'Exterior')->firstOrFail();
    $check($place->fresh()->load('photos')->coverPhoto()?->id === $exterior->id, 'A real exterior photograph takes priority over the placeholder');
    $controller->setPrimary($requestFor($owner), $exterior);
    $check($exterior->fresh()->is_primary, 'Eligible listing photograph can still be explicitly selected as cover');
    $controller->destroy($requestFor($owner), $exterior->fresh());
    $check($fallback->fresh()->is_primary && !$restroom->fresh()->is_primary, 'Deleting a cover promotes only an eligible fallback, never the restroom');
    $check(Illuminate\Support\Facades\Storage::disk('public')->exists($restroom->path), 'Restroom upload is preserved when another photo is removed');
    $fallback->delete();
    $check($place->fresh()->coverPhoto() === null && $place->fresh()->load('photos')->coverPhoto() === null, 'Only-amenity listings return no cover in both loading modes');
    $html = $gallery($place);
    $check(str_contains($html, '<h1>') && !str_contains($html, basename($restroom->path)), 'Only-amenity listing keeps its title and safe fallback hero');
    $card = view('partials.listing-poster-card', ['listing' => $place->fresh()->load('photos')])->render();
    $check(!str_contains($card, basename($restroom->path)), 'Search/list cards do not use the restroom as their cover');
    $empty = $makePlace('first-amenity'); $emptyOwner = $accountFor('destination', $empty);
    $controller->store($requestFor($emptyOwner, ['category' => 'Amenities', 'amenity' => 'restroom', 'own' => 1], true));
    $check(!$empty->photos()->first()->is_primary && $empty->fresh()->coverPhoto() === null, 'First-ever amenity upload cannot become an automatic cover');
    $controller->store($requestFor($emptyOwner, ['category' => 'General', 'own' => 1], true));
    $check($empty->photos()->where('category', 'General')->first()->is_primary && !$empty->fresh()->coverPhoto()->isAmenityPhoto(), 'General upload after amenity-only photos becomes the proper cover');
    foreach ([['category' => 'Accessibility', 'amenity' => null], ['category' => ' amenities ', 'amenity' => null], ['category' => 'General', 'amenity' => 'restroom']] as $index => $attributes) {
        $legacy = $empty->photos()->create($attributes + ['path' => "qa/legacy-$index.png", 'is_primary' => true, 'sort_order' => -10]);
        $check($legacy->isAmenityPhoto() && !$empty->photos()->coverEligible()->whereKey($legacy->id)->exists() && $empty->fresh()->load('photos')->coverPhoto()?->id !== $legacy->id, 'Legacy/category/tag variant '.($index + 1).' cannot override the cover');
    }
    $operator = App\Models\TourOperator::create(['slug' => "$tag-operator", 'name' => "$tag operator", 'location' => 'Davao', 'region_id' => $region->id, 'is_accredited' => false, 'rating' => 0, 'review_count' => 0]);
    $package = App\Models\Package::create(['slug' => "$tag-package", 'name' => "$tag package", 'tour_operator_id' => $operator->id, 'region_id' => $region->id, 'is_accredited' => false, 'status' => 'draft']);
    $packageOwner = $accountFor('tour_operator', $operator);
    $packageController = app(App\Http\Controllers\Establishment\EstablishmentPackageController::class);
    $feature = App\Support\Amenities::FOR_KIND['package'][0];
    $packageController->photoStore($requestFor($packageOwner, ['category' => 'Amenities', 'amenity' => $feature, 'own' => 1], true), $package);
    $packageAmenity = $package->photos()->firstOrFail();
    $check(!$packageAmenity->is_primary && $package->fresh()->coverPhoto() === null, 'Package amenity upload also stays out of automatic cover selection');
    $packageController->photoStore($requestFor($packageOwner, ['category' => 'General', 'own' => 1], true), $package);
    $packageCover = $package->photos()->where('category', 'General')->firstOrFail();
    $packageController->photoDestroy($requestFor($packageOwner), $package, $packageCover);
    $check(!$packageAmenity->fresh()->is_primary && $package->fresh()->coverPhoto() === null, 'Deleting a package cover does not promote an amenity');

    // Read-only check of the exact listing from the report. Do not change its photos or flags.
    $isla = App\Models\Destination::where('slug', 'isla-agri-ventures-inc')->with('photos')->firstOrFail();
    $islaRestroom = $isla->photos->firstWhere('amenity', 'restroom');
    $check($islaRestroom && !$isla->coverPhoto()?->isAmenityPhoto(), 'Isla-Agri cover selection no longer uses its restroom photo');
    $islaHero = $gallery($isla);
    $check(!str_contains($islaHero, basename($islaRestroom->path)), 'Isla-Agri desktop/mobile hero payload excludes the uploaded restroom');
    $islaAmenities = view('partials.amenities-on-site', ['listing' => $isla, 'kind' => 'destination'])->render();
    $check(str_contains($islaAmenities, basename($islaRestroom->path)), 'Isla-Agri restroom image remains available under Amenities on site');
    $islaOwner = App\Models\EstablishmentAccount::where('listing_kind', 'destination')->where('matched_listing_id', $isla->id)->firstOrFail();
    $request = Illuminate\Http\Request::create('https://www.exploredavao.com/portal/establishment/photos');
    $app->instance('request', $request); auth('establishment')->setUser($islaOwner);
    $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    $check($response->getStatusCode() === 200, 'Isla-Agri partner photo manager renders with the new placement guidance');
    $dom = new DOMDocument(); @$dom->loadHTML($response->getContent()); $xpath = new DOMXPath($dom);
    $check($xpath->query('//div[@data-id="'.$islaRestroom->id.'"]//button[@title="Make cover photo"]')->length === 0, 'Restroom row no longer offers the misleading Make cover action');
} finally {
    Illuminate\Support\Facades\DB::rollBack();
    if (str_starts_with($qaRoot, sys_get_temp_dir().'/exploredvo-photo-qa-')) Illuminate\Support\Facades\File::deleteDirectory($qaRoot);
}
echo "$checks amenity-photo checks passed; QA metadata rolled back and private fixture files removed.\n";
