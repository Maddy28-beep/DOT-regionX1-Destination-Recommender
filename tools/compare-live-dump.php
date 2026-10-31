<?php
// Read-only comparison: COPY text is parsed as data, never executed as SQL.
require __DIR__.'/audit-itinerary-dump.php';
$dump=$argv[1]??throw new RuntimeException('Supply the PostgreSQL dump path');
$out=$argv[2]??dirname(__DIR__).'/output/live-local-comparison-20261031';
if(!is_dir($out))mkdir($out,0777,true);
$live=readDump($dump);
foreach($live as &$tableRows)foreach($tableRows as &$row)foreach(array_keys($row) as $key)if(str_starts_with($key,'"')){$row[trim($key,'"')]=$row[$key];unset($row[$key]);}
unset($tableRows,$row);
$sql=file_get_contents($dump);
preg_match_all('/CREATE TABLE public\.(\w+) \(\R(.*?)\R\);/s',$sql,$definitions,PREG_SET_ORDER);
$types=[];
foreach($definitions as $table)foreach(explode("\n",$table[2]) as $line)if(preg_match('/^\s+"?(\w+)"?\s+(.+?)(?:,)?\s*$/',$line,$m))$types[$table[1]][$m[1]]=trim($m[2]," ,\r");
preg_match_all('/COPY public\.(\w+) \(([^)]+)\) FROM stdin;/',$sql,$copies,PREG_SET_ORDER);
$columns=[];foreach($copies as $copy)$columns[$copy[1]]=array_map(fn($c)=>trim($c,'"'),explode(', ',$copy[2]));
$root=dirname(__DIR__);require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection=Illuminate\Support\Facades\DB::connection();
if($connection->getDriverName()!=='sqlite')throw new RuntimeException('Expected local SQLite connection; refusing a different target');
$pdo=$connection->getPdo();$pdo->beginTransaction();
$localTables=$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$normalize=function($value,string $type){
 if($value===null)return null;
 if(str_contains($type,'boolean'))return in_array($value,[true,1,'1','t','true'],true)?'true':'false';
 if(preg_match('/^(?:bigint|integer|smallint|numeric|double precision|real|decimal)/',$type)&&is_numeric($value))return rtrim(rtrim(sprintf('%.10F',(float)$value),'0'),'.');
 if(str_contains($type,'timestamp')){try{return (new DateTimeImmutable((string)$value,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');}catch(Throwable){}}
 if(preg_match('/^date(?:\s|$)/',$type)){try{return (new DateTimeImmutable((string)$value))->format('Y-m-d');}catch(Throwable){}}
 if(str_contains($type,'json')){try{return json_encode(json_decode((string)$value,true,512,JSON_THROW_ON_ERROR),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}catch(Throwable){}}
 return (string)$value;
};
$public=['destinations','destination_tags','accommodations','accreditation_records','accommodation_room_types','restaurants','regions','listing_amenities','tour_operators','souvenir_centers'];
$safe=['name','slug','type','location','region_id','latitude','longitude','hours','opening_hours','visit_duration','price_tier','entry_fee_min','entry_fee_max','price_per_night','check_in','check_out','is_accredited','itinerary_role','accreditation_number','expiration_date','issue_date','status','listing_id','listing_kind','kind','value','planner_verified_at','planner_verification_source'];
$summary=['dump'=>basename($dump),'dump_sha256'=>hash_file('sha256',$dump),'local_driver'=>'sqlite','local_database'=>basename($connection->getDatabaseName()),'live_only_tables'=>array_values(array_diff(array_keys($columns),$localTables)),'local_only_tables'=>array_values(array_diff($localTables,array_keys($columns))),'tables'=>[]];
$details=[];$migrations=[];
foreach($columns as $table=>$liveCols){
 if(!in_array($table,$localTables,true))continue;
 $localCols=$pdo->query('PRAGMA table_info("'.$table.'")')->fetchAll(PDO::FETCH_ASSOC);
 $localColNames=array_column($localCols,'name');$common=array_values(array_intersect($liveCols,$localColNames));
 $pk=array_column(array_filter($localCols,fn($c)=>$c['pk']>0),'name');
 // Independent imports can assign different IDs to the same business.
 if(in_array('slug',$common,true))$pk=['slug'];
 if($table==='migrations')$pk=['migration'];
 if($table==='accreditation_records')$pk=['accreditation_number','listing_kind'];
 $key=fn($row)=>json_encode(array_map(fn($c)=>(string)($row[$c]??''),$pk?:$common),JSON_UNESCAPED_UNICODE);
 $l=[];foreach($live[$table]??[] as $row)$l[$key($row)]=$row;
 $r=[];foreach($pdo->query('SELECT * FROM "'.$table.'"')->fetchAll(PDO::FETCH_ASSOC) as $row)$r[$key($row)]=$row;
 if(count($l)!==count($live[$table]??[]) || count($r)!==(int)$pdo->query('SELECT COUNT(*) FROM "'.$table.'"')->fetchColumn()){
  $pk=array_column(array_filter($localCols,fn($c)=>$c['pk']>0),'name');
  if($table==='accreditation_records')$pk=['accreditation_number','listing_kind','listing_id'];
  $key=fn($row)=>json_encode(array_map(fn($c)=>(string)($row[$c]??''),$pk?:$common),JSON_UNESCAPED_UNICODE);
  $l=[];foreach($live[$table]??[] as $row)$l[$key($row)]=$row;
  $r=[];foreach($pdo->query('SELECT * FROM "'.$table.'"')->fetchAll(PDO::FETCH_ASSOC) as $row)$r[$key($row)]=$row;
 }
 $liveOnly=array_diff_key($l,$r);$localOnly=array_diff_key($r,$l);$same=0;$changed=0;$fields=[];
 foreach(array_intersect_key($l,$r) as $id=>$before){
  $after=$r[$id];$delta=[];
  foreach($common as $col)if($normalize($before[$col],$types[$table][$col]??'')!==$normalize($after[$col],$types[$table][$col]??'')){
   $fields[$col]=($fields[$col]??0)+1;
   if(in_array($table,$public,true)&&in_array($col,$safe,true))$delta[$col]=['live'=>$before[$col],'local'=>$after[$col]];
  }
  $different=false;foreach($common as $col)if($normalize($before[$col],$types[$table][$col]??'')!==$normalize($after[$col],$types[$table][$col]??'')){$different=true;break;}
  if($different){$changed++;if($delta)$details[]=['table'=>$table,'id'=>$before['id']??null,'name'=>$after['name']??$before['name']??null,'changes'=>$delta];}else $same++;
 }
 $summary['tables'][$table]=['matched_by'=>$pk,'live_rows'=>count($l),'local_rows'=>count($r),'live_only_rows'=>count($liveOnly),'local_only_rows'=>count($localOnly),'equal_common_rows'=>$same,'changed_common_rows'=>$changed,'changed_columns'=>$fields,'live_only_columns'=>array_values(array_diff($liveCols,$localColNames)),'local_only_columns'=>array_values(array_diff($localColNames,$liveCols))];
 if(in_array($table,$public,true))foreach(['live_only'=>$liveOnly,'local_only'=>$localOnly] as $side=>$rows)foreach($rows as $row)$details[]=['table'=>$table,'side'=>$side,'record'=>array_intersect_key($row,array_flip(array_merge(['id'],$safe)))];
 if($table==='migrations')$migrations=['live_only'=>array_column($liveOnly,'migration'),'local_only'=>array_column($localOnly,'migration')];
}
$pdo->rollBack();
file_put_contents($out.'/summary.json',json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
file_put_contents($out.'/planner-differences.json',json_encode($details,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
file_put_contents($out.'/migration-differences.json',json_encode($migrations,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode(['live_only_tables'=>$summary['live_only_tables'],'local_only_tables'=>$summary['local_only_tables'],'tables'=>$summary['tables'],'migrations'=>$migrations],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
