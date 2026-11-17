<?php
// Run from an isolated application copy. Database writes roll back; files use private QA storage.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->instance('request', Illuminate\Http\Request::create('https://www.exploredavao.com/'));
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
require_once getcwd().'/database/seeders/DiningIllustrationSeeder.php';
config(['session.driver'=>'array', 'cache.default'=>'array']);
$root = sys_get_temp_dir().'/exploredvo-dining-qa-'.bin2hex(random_bytes(6));
mkdir($root, 0700); mkdir($root.'/assets', 0700);
config(['filesystems.disks.public.root'=>$root.'/storage']);
Illuminate\Support\Facades\Storage::forgetDisk('public');
$disk = Illuminate\Support\Facades\Storage::disk('public');
$checks = 0;
$check = function ($ok, $label) use (&$checks) { if (!$ok) throw new RuntimeException($label); echo 'PASS: '.$label."\n"; $checks++; };
Illuminate\Support\Facades\DB::beginTransaction();
try {
    $region = App\Models\Region::firstOrFail();
    $restaurants = []; $entries = [];
    foreach (['grilled-tuna','cafe','bibingka','japanese'] as $index=>$asset) {
        $r = App\Models\Restaurant::create(['name'=>basename($root).'-'.$index, 'slug'=>basename($root).'-'.$index,
            'location'=>'Davao City', 'region_id'=>$region->id, 'is_accredited'=>true, 'price_tier'=>'Mid-range', 'rating'=>0, 'review_count'=>0]);
        $restaurants[] = $r;
        $file = 'restaurant-'.$r->id.'.webp';
        copy(public_path('images/dining-illustrations-v1/'.$asset.'.webp'), $root.'/assets/'.$file);
        $entries[] = ['id'=>$r->id, 'name'=>$r->name, 'slug'=>$r->slug, 'file'=>$file, 'sha256'=>hash_file('sha256',$root.'/assets/'.$file)];
    }
    $old = $restaurants[0]->photos()->create(['path'=>'qa/old-sample.webp', 'category'=>'Sample image','sort_order'=>0,'is_primary'=>false]);
    $real = $restaurants[1]->photos()->create(['path'=>'qa/real.webp','category'=>'General','sort_order'=>0,'is_primary'=>true]);
    $amenity = $restaurants[3]->photos()->create(['path'=>'qa/restroom.webp','category'=>'Amenities','amenity'=>'restroom','is_primary'=>true]);
    $snapshots = [$old->id=>$old->fresh()->getRawOriginal(),$real->id=>$real->fresh()->getRawOriginal(),$amenity->id=>$amenity->fresh()->getRawOriginal()];
    $listingSnapshots = array_map(fn ($r)=>$r->fresh()->getRawOriginal(),$restaurants);
    $write = fn ($rows)=>file_put_contents($root.'/assets/manifest.json',json_encode($rows,JSON_THROW_ON_ERROR));
    $write($entries);
    $seed = new Database\Seeders\DiningIllustrationSeeder(); $seed->directory=$root.'/assets'; $seed->run();
    $prefix = Database\Seeders\DiningIllustrationSeeder::STORAGE_PREFIX;
    $check(App\Models\ListingPhoto::where('path','like',$prefix.'%')->whereIn('listing_id',array_column($entries,'id'))->count()===3,'Only listings without genuine covers receive illustration records');
    foreach ([0,2,3] as $index) {
        $r=$restaurants[$index]->fresh()->load('photos'); $cover=$r->coverPhoto();
        $check($cover && $cover->path===$prefix.'restaurant-'.$r->id.'.webp' && $cover->isIllustration() && !$cover->is_primary,'Listing '.$index.' uses its own labeled illustration without a primary flag');
        $check($disk->exists($cover->path) && hash('sha256',$disk->get($cover->path))===$entries[$index]['sha256'],'Listing '.$index.' image bytes match the manifest');
    }
    $check($restaurants[1]->fresh()->coverPhoto()->id===$real->id,'Genuine cover photograph retains precedence');
    foreach ($snapshots as $id=>$original) $check(App\Models\ListingPhoto::findOrFail($id)->getRawOriginal()===$original,'Original photo '.$id.' remains unchanged');
    foreach ($restaurants as $index=>$r) $check($r->fresh()->getRawOriginal()===$listingSnapshots[$index],'Restaurant '.$index.' business fields remain unchanged');
    $before=App\Models\ListingPhoto::count(); $seed->run();
    $check(App\Models\ListingPhoto::count()===$before,'Repeated import does not duplicate photo records');
    $card=view('partials.listing-poster-card',['listing'=>$restaurants[0]->fresh()->load('photos')])->render();
    $check(str_contains($card,'restaurant-'.$restaurants[0]->id.'.webp') && str_contains($card,'Illustration'),'Public dining card shows the new asset and disclosure');
    $gallery=view('partials.gallery-hero',['photos'=>$restaurants[0]->fresh()->photos,'title'=>'QA dining','subtitle'=>'QA','isAccredited'=>true,'provenance'=>'sample','rating'=>0,'reviewCount'=>0,'fallbackGradient'=>'#075846'])->render();
    $check(str_contains($gallery,'restaurant-'.$restaurants[0]->id.'.webp') && str_contains($gallery,'Illustration, not a photo'),'Detail gallery retains the illustration disclosure');
    $newReal=$restaurants[0]->photos()->create(['path'=>'qa/new-owner.webp','category'=>'Exterior','is_primary'=>true]);
    $check($restaurants[0]->fresh()->load('photos')->coverPhoto()->id===$newReal->id,'A later owner photograph automatically replaces the illustration as cover');
    $seed->run();
    $check($restaurants[0]->fresh()->coverPhoto()->id===$newReal->id,'Reimport preserves a later owner photograph');
    $invalid=$entries; $invalid[1]['file']='../escape.webp'; $write($invalid); $blocked=false;
    try { $seed->run(); } catch (RuntimeException $e) { $blocked=true; }
    $check($blocked,'Import rejects path traversal before writing');
    $invalid=$entries; $invalid[1]['sha256']=$entries[0]['sha256']; $write($invalid); $blocked=false;
    try { $seed->run(); } catch (RuntimeException $e) { $blocked=true; }
    $check($blocked,'Import rejects mismatched content hashes');
    $invalid=$entries; $invalid[1]['slug']='wrong-listing'; $write($invalid); $blocked=false;
    try { $seed->run(); } catch (RuntimeException $e) { $blocked=true; }
    $check($blocked,'Import refuses a listing whose identity differs from the manifest');
} finally {
    Illuminate\Support\Facades\DB::rollBack();
    if (str_starts_with($root,sys_get_temp_dir().'/exploredvo-dining-qa-')) Illuminate\Support\Facades\File::deleteDirectory($root);
}
echo "$checks dining-artwork checks passed; database writes rolled back and private files removed.\n";
