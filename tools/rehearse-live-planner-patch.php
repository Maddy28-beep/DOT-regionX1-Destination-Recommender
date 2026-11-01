<?php
declare(strict_types=1);
$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
require __DIR__.'/audit-itinerary-dump.php';
foreach (['DB_CONNECTION'=>'pgsql','DB_HOST'=>'127.0.0.1','DB_PORT'=>'55440','DB_DATABASE'=>'planner_patch_rehearsal','DB_USERNAME'=>'planner_rehearsal','DB_PASSWORD'=>'','DB_URL'=>'','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync','APP_CONFIG_CACHE'=>$root.'/output/live-publish-20261101/no-config-cache.php'] as $k=>$v) { putenv("$k=$v"); $_ENV[$k]=$_SERVER[$k]=$v; }
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'planner_patch_rehearsal' || config('database.connections.pgsql.port') != 55440) throw new RuntimeException('Scratch DB safety assertion failed');
$out=$root.'/output/live-publish-20261101';
$data=readDump($argv[1]);
foreach ($data as &$rows) foreach ($rows as &$r) $r=array_combine(array_map(fn($k)=>trim($k,'"'),array_keys($r)),array_values($r));
unset($rows,$r);
// Rehearse exactly the migration names in the downloaded production ledger.
if (!Schema::hasTable('migrations')) {
    $paths=[];
    foreach ($data['migrations'] as $m) $paths[]='database/migrations/'.$m['migration'].'.php';
    $exit=Artisan::call('migrate',['--path'=>$paths,'--force'=>true]);
    file_put_contents($out.'/baseline-migrations.log',Artisan::output());
    if ($exit) throw new RuntimeException('Baseline migrations failed');
}
// Copy public catalogue rows only; never stage accounts, sessions, surveys or trips.
$public=['regions','destinations','accommodations','restaurants','souvenir_centers','destination_tags','amenities','accommodation_amenities','destination_amenities','accreditation_records'];
foreach ($public as $table) {
    if (!Schema::hasTable($table) || DB::table($table)->exists()) continue;
    $columns=Schema::getColumnListing($table);
    foreach ($data[$table] ?? [] as $r) {
        $r=array_intersect_key($r,array_flip($columns));
        foreach (['created_by','updated_by','verified_by'] as $key) if (array_key_exists($key,$r)) $r[$key]=null;
        DB::table($table)->insert($r);
    }
    if (in_array('id',$columns,true) && DB::table($table)->exists()) DB::statement("SELECT setval(pg_get_serial_sequence(?, 'id'), ?, true)",[$table,DB::table($table)->max('id')]);
}
$manifest=json_decode(file_get_contents($root.'/database/data/live-planner-patch-2026-11-01.json'),true,512,JSON_THROW_ON_ERROR);
$patch=app(App\Services\PlannerDataPatch::class);
file_put_contents($out.'/before-migrations-preview.json',json_encode($patch->run($manifest),JSON_PRETTY_PRINT));
$exit=Artisan::call('migrate',['--path'=>['database/migrations/2026_10_31_200000_add_planner_verification_to_destinations.php','database/migrations/2026_10_31_210000_add_verified_meal_venues.php'],'--force'=>true]);
file_put_contents($out.'/verification-migrations.log',Artisan::output());
if ($exit) throw new RuntimeException('Verification migrations failed');
$before=[];
foreach (['destinations','accommodations','restaurants'] as $t) foreach (DB::table($t)->get() as $r) $before[$t][$r->id]=(array)$r;
$preview=$patch->run($manifest);
$applied=$patch->run($manifest,true);
$repeat=$patch->run($manifest,true);
if ($repeat['updated'] || $repeat['inserted'] || $repeat['tags_added']) throw new RuntimeException('Patch is not idempotent');
$selected=[];
foreach ($manifest['records'] as $r) $selected[$r['table']][$r['slug']]=array_keys($r['fields']);
$protected=0;
foreach ($before as $t=>$rows) foreach ($rows as $id=>$r) {
    $after=(array)DB::table($t)->where('id',$id)->first();
    foreach ($r as $field=>$value) if (!in_array($field,$selected[$t][$r['slug']] ?? [],true)) {
        if ($after[$field] !== $value) throw new RuntimeException('Unselected field changed: '.$t.'.'.$r['slug'].'.'.$field);
        $protected++;
    }
}
$summary=['engine'=>DB::selectOne('select version() as v')->v,'baseline_migrations'=>count($data['migrations']),'preview'=>$preview,'applied'=>$applied,'second_apply'=>$repeat,'protected_fields_unchanged'=>$protected,'private_production_rows_copied'=>0];
file_put_contents($out.'/rehearsal.json',json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode(['applied'=>array_diff_key($applied,['records'=>1]),'second_apply'=>array_diff_key($repeat,['records'=>1]),'protected_fields_unchanged'=>$protected],JSON_PRETTY_PRINT)."\n";
