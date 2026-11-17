<?php
// Run from the application root after installing the manifest, assets and seeder.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require_once getcwd().'/database/seeders/DiningIllustrationSeeder.php';
use App\Models\ListingPhoto;
use App\Models\Restaurant;
use Database\Seeders\DiningIllustrationSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$entries=json_decode(file_get_contents(public_path(DiningIllustrationSeeder::DIRECTORY.'/manifest.json')),true,512,JSON_THROW_ON_ERROR);
$restaurants=Restaurant::publiclyVisible()->with('photos')->orderBy('id')->get();
if (count($entries)!==57 || $restaurants->count()!==64) throw new RuntimeException('Public dining inventory changed; investigate before publishing.');
$originalPhotos=ListingPhoto::where('listing_kind','restaurant')->whereIn('listing_id',$restaurants->modelKeys())->orderBy('id')->get()->mapWithKeys(fn ($p)=>[$p->id=>$p->getRawOriginal()])->all();
$fieldHashes=$beforeCovers=[];
foreach ($restaurants as $r) {
    $fieldHashes[$r->id]=hash('sha256',json_encode($r->getRawOriginal(),JSON_THROW_ON_ERROR));
    $cover=$r->coverPhoto();
    $beforeCovers[$r->id]=$cover?->only(['id','path','category']);
}
if (!in_array('--apply',$argv,true)) {
    echo json_encode(['mode'=>'dry-run','public_listings'=>$restaurants->count(),'artwork_entries'=>count($entries),'existing_photos'=>count($originalPhotos)],JSON_PRETTY_PRINT)."\n";
    exit;
}

$backup='/var/backups/exploredvo/dining-illustrations-'.gmdate('Ymd\THis\Z');
if (!mkdir($backup,0700,true)) throw new RuntimeException('Could not create backup.');
file_put_contents($backup.'/before.json',json_encode(['covers'=>$beforeCovers,'photos'=>$originalPhotos,'restaurant_field_hashes'=>$fieldHashes],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
DB::beginTransaction();
try {
    (new DiningIllustrationSeeder())->run();
    $after=Restaurant::publiclyVisible()->with('photos')->orderBy('id')->get();
    $hashes=[];$illustrations=0;$real=0;
    foreach ($after as $r) {
        if (hash('sha256',json_encode($r->getRawOriginal(),JSON_THROW_ON_ERROR))!==$fieldHashes[$r->id]) throw new RuntimeException('Restaurant fields changed: '.$r->id);
        $cover=$r->coverPhoto();
        if (!$cover || !Storage::disk('public')->exists($cover->path)) throw new RuntimeException('Missing displayed image: '.$r->id);
        $hash=hash('sha256',Storage::disk('public')->get($cover->path));
        if (isset($hashes[$hash])) throw new RuntimeException('Duplicate displayed image: '.$r->id.' / '.$hashes[$hash]);
        $hashes[$hash]=$r->id;
        if ($cover->isIllustration()) {
            if ($cover->path!==DiningIllustrationSeeder::STORAGE_PREFIX.'restaurant-'.$r->id.'.webp' || $cover->source_name!==DiningIllustrationSeeder::SOURCE) throw new RuntimeException('Unexpected illustration: '.$r->id);
            $illustrations++;
        } else {
            if ($cover->id!==$beforeCovers[$r->id]['id'] || $cover->path!==$beforeCovers[$r->id]['path']) throw new RuntimeException('Genuine cover changed: '.$r->id);
            $real++;
        }
    }
    foreach ($originalPhotos as $id=>$attributes) if (ListingPhoto::findOrFail($id)->getRawOriginal()!==$attributes) throw new RuntimeException('Original photo metadata changed: '.$id);
    $newIds=ListingPhoto::where('listing_kind','restaurant')->where('source_name',DiningIllustrationSeeder::SOURCE)->whereNotIn('id',array_keys($originalPhotos))->pluck('id')->all();
    $result=['public_listings'=>$after->count(),'distinct_cover_hashes'=>count($hashes),'illustrations'=>$illustrations,'genuine_photos_kept'=>$real,'new_photo_ids'=>$newIds,'backup'=>$backup];
    if ($illustrations!==57 || $real!==7) throw new RuntimeException('Unexpected post-import totals.');
    file_put_contents($backup.'/published.json',json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    DB::commit();
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    DB::rollBack(); throw $e;
}
