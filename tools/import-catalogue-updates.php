<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$base=__DIR__.'/../output/database-audit-20261030';
$read=fn($p)=>json_decode(file_get_contents($base.'/'.$p),true,512,JSON_THROW_ON_ERROR);
$plan=[];
foreach ($read('changes.json') as $c) $plan[]=['table'=>$c['table'],'id'=>$c['id'],'name'=>$c['name'],'field'=>$c['field'],'before'=>$c['before'],'after'=>$c['after']];
foreach ($read('estimated/estimates.json') as $c) $plan[]=['table'=>'destinations','id'=>$c['id'],'name'=>$c['name'],'field'=>$c['field'],'before'=>$c['before'],'after'=>$c['value']];
foreach ($read('descriptions/descriptions.json') as $c) $plan[]=['table'=>$c['table'],'id'=>$c['id'],'name'=>$c['name'],'field'=>'description','before'=>$c['before'],'after'=>$c['after']];
$apply=in_array('--apply',$argv,true);
$same=fn($a,$b)=>$a===null||$b===null?$a===$b:(is_numeric($a)&&is_numeric($b)?abs((float)$a-(float)$b)<1e-9:(string)$a===(string)$b);
$normalize=fn($s)=>mb_strtolower(trim(preg_replace('/\s*\[SAMPLE\]\s*$/i','',$s)));
$allowed=['destinations'=>['itinerary_role','visit_duration','hours','entry_fee_min','entry_fee_max','price_tier','description'],'accommodations'=>['description'],'restaurants'=>['description'],'souvenir_centers'=>['description'],'tour_operators'=>['description']];
foreach ($plan as $c) if (!in_array($c['field'],$allowed[$c['table']]??[],true)) throw new RuntimeException('Unexpected field');
try {
    $connection=DB::connection();
    echo json_encode(['connection'=>$connection->getDriverName(),'database'=>$connection->getDatabaseName(),'app_url'=>config('app.url')])."\n";
    DB::transaction(function () use ($plan,$apply,$same,$normalize,$read,$base) {
        $pending=[]; $already=0; $conflicts=[]; $snapshots=[];
        foreach ($plan as $c) {
            $key=$c['table'].':'.$c['id'];
            if (!array_key_exists($key,$snapshots)) $snapshots[$key]=DB::table($c['table'])->where('id',$c['id'])->lockForUpdate()->first();
            $row=$snapshots[$key];
            if (!$row || $normalize($row->name)!==$normalize($c['name'])) { $conflicts[]=['table'=>$c['table'],'id'=>$c['id'],'field'=>$c['field'],'reason'=>'missing row or different name']; continue; }
            if ($same($row->{$c['field']},$c['after'])) { $already++; continue; }
            if (!$same($row->{$c['field']},$c['before'])) { $conflicts[]=['table'=>$c['table'],'id'=>$c['id'],'field'=>$c['field'],'reason'=>'current value differs from source','current'=>$row->{$c['field']},'expected'=>$c['before']]; continue; }
            $c['name']=$row->name;
            $c['before']=$row->{$c['field']};
            $pending[]=$c;
        }
        $tags=[];
        foreach ($read('added-tags.json') as $c) {
            $row=DB::table('destinations')->where('id',$c['id'])->first();
            if (!$row || $normalize($row->name)!==$normalize($c['name'])) { $conflicts[]=['table'=>'destination_tags','id'=>$c['id'],'reason'=>'destination identity mismatch']; continue; }
            if (!DB::table('destination_tags')->where('destination_id',$c['id'])->where('kind','category')->exists()) $tags[]=['destination_id'=>$c['id'],'kind'=>'category','value'=>$c['value']];
        }
        $report=['mode'=>$apply?'apply':'dry-run','pending_cells'=>count($pending),'already_matching'=>$already,'new_tags'=>count($tags),'conflicts'=>$conflicts,'tables'=>array_count_values(array_column($pending,'table'))];
        if (!is_dir($base.'/live-import')) mkdir($base.'/live-import',0777,true);
        file_put_contents($base.'/live-import/preflight.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
        if ($conflicts) throw new RuntimeException('Conflicts found; nothing changed. See preflight report.');
        if (!$apply) return;
        $stamp=gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
        $backup=$base.'/live-import/before-'.$stamp.'.json';
        $backupData=['created_utc'=>gmdate('c'),'changes'=>$pending,'rows'=>$snapshots,'planned_tags'=>$tags];
        $encoded=json_encode($backupData,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if (file_put_contents($backup,$encoded)!==strlen($encoded)) throw new RuntimeException('Backup could not be saved');
        $inserted=[];
        foreach ($pending as $c) {
            $q=DB::table($c['table'])->where('id',$c['id'])->where('name',$c['name']);
            $c['before']===null?$q->whereNull($c['field']):$q->where($c['field'],$c['before']);
            if ($q->update([$c['field']=>$c['after']])!==1) throw new RuntimeException('Concurrent edit detected');
        }
        foreach ($tags as $tag) $inserted[]=$tag+['id'=>DB::table('destination_tags')->insertGetId($tag)];
        foreach ($plan as $c) if (!$same(DB::table($c['table'])->where('id',$c['id'])->value($c['field']),$c['after'])) throw new RuntimeException('Read-back verification failed');
        $result=['backup'=>basename($backup),'updated_cells'=>count($pending),'inserted_tags'=>$inserted,'all_planned_values_verified'=>true];
        // Written inside transaction; final commit success is printed separately below.
        file_put_contents($base.'/live-import/result-'.$stamp.'.json',json_encode($result,JSON_PRETTY_PRINT));
        echo json_encode($result)."\n";
    });
    echo $apply?"COMMIT SUCCEEDED\n":"DRY RUN COMPLETE; NO CHANGES\n";
} catch (Throwable $e) {
    // Avoid printing connection credentials embedded in framework exception messages.
    if ($e instanceof RuntimeException && !$e instanceof Illuminate\Database\QueryException) fwrite(STDERR,$e->getMessage()."\n");
    else fwrite(STDERR,'Database/bootstrap operation failed ('.get_class($e).'). No credentials printed.'."\n");
    exit(1);
}
