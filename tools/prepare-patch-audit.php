<?php
// Prepare a scratch runner that uses the patched PostgreSQL catalogue, not local SQLite.
$root=dirname(__DIR__);
$out=$root.'/output/live-publish-20261101';
if (!is_dir($out)) mkdir($out,0777,true);
$fixtures=__DIR__.'/fixtures/planner-audit';
$text=file_get_contents($fixtures.'/run-template.php');
$text=str_replace("'DB_CONNECTION'=>'sqlite','DB_DATABASE'=>__DIR__.\"/\$variant.sqlite\"", "'DB_CONNECTION'=>'pgsql','DB_HOST'=>'127.0.0.1','DB_PORT'=>'55440','DB_USERNAME'=>'planner_rehearsal','DB_PASSWORD'=>'','DB_DATABASE'=>'planner_patch_rehearsal'",$text,$n);
if ($n!==1) throw new RuntimeException('Audit runner template changed');
$insert= <<<'PHP'
if (Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'planner_patch_rehearsal') throw new RuntimeException('Wrong audit database');
if (Illuminate\Support\Facades\Artisan::call('migrate',['--path'=>['database/migrations/2026_11_01_180000_expand_itinerary_item_notes.php'],'--force'=>true])) throw new RuntimeException('Note expansion migration failed');
require $root.'/tools/audit-itinerary-dump.php';
$catalogue=readDump($argv[2]);
if (!Illuminate\Support\Facades\DB::table('accreditation_records')->exists()) foreach ($catalogue['accreditation_records'] as $r) {
    $r['verified_by']=null;
    Illuminate\Support\Facades\DB::table('accreditation_records')->insert($r);
}
PHP;
$text=str_replace("require __DIR__.'/CommonAudit.php';",$insert."\nrequire __DIR__.'/CommonAudit.php';",$text);
file_put_contents($out.'/run.php',$text);
copy($fixtures.'/CommonAudit.php',$out.'/CommonAudit.php');
copy($fixtures.'/scenarios.json',$out.'/scenarios.json');
echo "Prepared five-profile patched-catalogue audit\n";
