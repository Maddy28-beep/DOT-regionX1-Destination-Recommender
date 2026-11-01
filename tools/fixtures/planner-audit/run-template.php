<?php
$root = dirname(__DIR__, 2);
$variant = $argv[1];
$base = $variant === 'github' ? __DIR__.'/github' : $root;
$loader = require $root.'/vendor/autoload.php';
if ($variant === 'github') {
    $classes = [];
    foreach (['App' => 'app', 'Database\\Factories' => 'database/factories', 'Database\\Seeders' => 'database/seeders'] as $ns => $dir) {
        if (!is_dir($base.'/'.$dir)) continue;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base.'/'.$dir)) as $file) {
            if ($file->getExtension() !== 'php') continue;
            $relative = substr($file->getPathname(), strlen($base.'/'.$dir)+1, -4);
            $classes[$ns.'\\'.str_replace(['/', '\\'], '\\', $relative)] = $file->getPathname();
        }
        $loader->addPsr4($ns.'\\', $base.'/'.$dir, true);
    }
    $loader->addClassMap($classes);
}
foreach (['DB_CONNECTION'=>'sqlite','DB_DATABASE'=>__DIR__."/$variant.sqlite",'DB_URL'=>'','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync','COMPOSER_VENDOR_DIR'=>$root.'/vendor','APP_KEY'=>'base64:'.base64_encode(str_repeat('c',32))] as $key=>$value) {
    putenv("$key=$value"); $_ENV[$key] = $_SERVER[$key] = $value;
}
putenv('APP_BASE_PATH='.$base);
$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::parse('2026-11-01 12:00:00','Asia/Manila'));
require __DIR__.'/CommonAudit.php';
$shared = new Comparison\ItineraryAudit;
$native = app(App\Services\Recommendation\ItineraryAudit::class);
$results = [];
$scenarios = json_decode(file_get_contents(__DIR__.'/scenarios.json'), true);
foreach ($scenarios as $scenario) {
    if (!empty($scenario['departure_time']) && !Illuminate\Support\Facades\Schema::hasColumn('tourist_preferences','departure_time')) continue;
    Illuminate\Support\Facades\DB::beginTransaction();
    try {
        $origin = $scenario['origin'] ?? (!empty($scenario['airport']) ? ['lat'=>7.130,'lng'=>125.645,'label'=>'Davao International Airport'] : []);
        $p = App\Models\TouristPreference::create([
            'travel_days'=>$scenario['travel_days'],'travel_type'=>$scenario['travel_type'],'travel_purpose'=>$scenario['travel_purpose'],
            'visitor_type'=>$scenario['visitor_type'],'budget'=>$scenario['budget'],'accommodation_pref'=>$scenario['accommodation_pref'],
            'distance_pref'=>$scenario['distance_pref'],'transport_pref'=>$scenario['transport_pref'],
            'start_date'=>now('Asia/Manila')->next($scenario['start']??'Saturday')->toDateString(),'arrival_time'=>$scenario['arrival_time'],
            'origin_lat'=>$origin['lat']??null,'origin_lng'=>$origin['lng']??null,'origin_label'=>$origin['label']??null,
        ]);
        if (!empty($scenario['departure_time'])) $p->forceFill(['departure_time'=>$scenario['departure_time'],'departure_lat'=>7.130,'departure_lng'=>125.645,'departure_label'=>'Davao International Airport'])->save();
        foreach ($scenario['activities'] as $interest) $p->activities()->create(['activity'=>$interest]);
        foreach (['Restaurant','Parking Area'] as $amenity) $p->amenities()->create(['amenity'=>$amenity]);
        $p->load('activities','amenities');
        $trip = app(App\Services\Recommendation\ItineraryGenerationService::class)->generate($p);
        $checks = $shared->audit($trip, $p);
        $nativeChecks = $native->audit($trip, $p);
        $items = $trip->items()->with(['destination','accommodation','restaurant'])->orderBy('day_number')->orderBy('sort_order')->get()->toArray();
        $results[] = ['name'=>$scenario['name'],'request'=>$scenario,'range_tier_used'=>$trip->range_tier_used,'date'=>$p->start_date,'shared'=>$checks,'native'=>$nativeChecks,'items'=>$items];
    } catch (Throwable $e) {
        $checks = [['key'=>'built','label'=>'Build completed','status'=>'fail','detail'=>get_class($e).': '.$e->getMessage()]];
        $results[] = ['name'=>$scenario['name'],'shared'=>$checks,'native'=>$checks,'error_trace'=>array_slice(explode("\n",$e->getTraceAsString()),0,4)];
    } finally {
        Illuminate\Support\Facades\DB::rollBack();
    }
    echo $variant,' ',count($results),' ', $scenario['name'],PHP_EOL;
}
$summary = ['variant'=>$variant,'generator_file'=>(new ReflectionClass(App\Services\Recommendation\ItineraryGenerationService::class))->getFileName(),'total'=>count($results)];
foreach (['shared','native'] as $judge) {
    $summary[$judge] = ['clean'=>0,'fully_verified'=>0,'checks'=>[]];
    foreach ($results as $r) {
        $statuses = array_column($r[$judge], 'status');
        $clean = !in_array('fail',$statuses,true);
        $summary[$judge]['clean'] += (int)$clean;
        $summary[$judge]['fully_verified'] += (int)($clean&&!in_array('unknown',$statuses,true));
        foreach($r[$judge] as $check) $summary[$judge]['checks'][$check['key']][$check['status']] = ($summary[$judge]['checks'][$check['key']][$check['status']]??0)+1;
    }
}
file_put_contents(__DIR__."/$variant-results.json",json_encode(['summary'=>$summary,'trips'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode($summary, JSON_PRETTY_PRINT),PHP_EOL;
