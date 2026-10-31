<?php
declare(strict_types=1);

// The supplied dump is parsed as text, never executed. Import only listing data.
require __DIR__.'/audit-itinerary-dump.php';
$dump=$argv[1]??throw new RuntimeException('Supply the PostgreSQL dump path.');
$options=[];foreach(array_slice($argv,2) as $arg){$parts=explode('=',$arg,2);$options[$parts[0]]=$parts[1]??true;}
$root=dirname(__DIR__);
$out=$options['--out']??$root.'/output/live-sync-20261031';
if(!is_dir($out))mkdir($out,0777,true);
if(isset($options['--database']))foreach(['DB_CONNECTION'=>'sqlite','DB_DATABASE'=>$options['--database'],'DB_URL'=>'','CACHE_STORE'=>'array'] as $key=>$value){putenv($key.'='.$value);$_ENV[$key]=$_SERVER[$key]=$value;}
require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$connection=DB::connection();
if($connection->getDriverName()!=='sqlite')throw new RuntimeException('This tool only writes local SQLite databases.');
$live=readDump($dump);
$dataset=json_decode(file_get_contents($root.'/database/data/dot-dataset-2026-09-23.json'),true,512,JSON_THROW_ON_ERROR);
$sourceCertificates=[];foreach($live['accreditation_records'] as $cert)$sourceCertificates[$cert['listing_kind']][$cert['listing_id']][]=$cert;
$regions=array_column($live['regions'],'name','id');
$localRegions=DB::table('regions')->pluck('id','name')->all();
$tables=['destination'=>'destinations','accommodation'=>'accommodations','restaurant'=>'restaurants','tour_operator'=>'tour_operators','souvenir_center'=>'souvenir_centers'];
$optional=['description','website_url','facebook_url','instagram_url','tiktok_url','contact_number','dot_classification','resort_settings'];
$blocked=['hours','opening_hours','visit_duration','price_tier','price_per_night','entry_fee_min','entry_fee_max','check_in','check_out','distance_km'];
$blank=fn($v)=>$v===null||trim((string)$v)==='';
$normal=fn($s)=>preg_replace('/[^\pL\pN]+/u','',mb_strtolower((string)$s));
$realCert=fn($c)=>($c['is_placeholder']??'f')!=='t'&&!preg_match('/^(?:SAMPLE|DOT-XI-)/i',$c['accreditation_number'])&&preg_match('/^DOT-R11-/',$c['accreditation_number']);
$plan=['dump_sha256'=>hash_file('sha256',$dump),'policy'=>'Blank-only listing enrichment; verified local fields and certificates remain unchanged. No sample, assumed operational data, accounts, surveys, embeddings or generated trips imported.','actions'=>[],'excluded'=>[],'withheld'=>[]];
$mapping=[];
foreach($tables as $kind=>$table){
 $local=DB::table($table)->get()->keyBy('id');
 $localCertificates=DB::table('accreditation_records')->where('listing_kind',$kind)->get();
 $cols=array_column($connection->getPdo()->query('PRAGMA table_info("'.$table.'")')->fetchAll(PDO::FETCH_ASSOC),'name');
 foreach($live[$table] as $row){
  $identity=['table'=>$table,'source_id'=>$row['id'],'name'=>$row['name']];
  $certs=array_values(array_filter($sourceCertificates[$kind][$row['id']]??[],$realCert));
  if(preg_match('/\bsample\b/i',$row['name'])||!$certs){$plan['excluded'][]=$identity+['reason'=>'sample name, placeholder-only certification or no real Region XI certificate'];continue;}
  if($table==='souvenir_centers'&&str_contains(strtolower($row['name']),'divers')){$plan['excluded'][]=$identity+['reason'=>'dive provider misclassified as souvenir shop; needs a provider-category review'];continue;}
  $region=$localRegions[$regions[$row['region_id']]??'']??null;
  if(!$region){$plan['excluded'][]=$identity+['reason'=>'unmapped region identity'];continue;}
  // Match exact slug first and cross-check certificate ownership; never reuse dump IDs.
  $slugMatches=$local->filter(fn($d)=>$d->slug===$row['slug']);
  $certMatches=$localCertificates->filter(fn($c)=>in_array($c->accreditation_number,array_column($certs,'accreditation_number'),true))->pluck('listing_id')->unique();
  $matches=$slugMatches->isNotEmpty()?$slugMatches:$local->filter(fn($d)=>$certMatches->contains($d->id));
  if($matches->count()>1){
   $matches=$matches->filter(fn($d)=>$normal($d->name)===$normal($row['name'])&&$normal($d->location)===$normal($row['location']));
   if($matches->count()!==1){$plan['excluded'][]=$identity+['reason'=>'ambiguous branch/certificate ownership'];continue;}
  }
  $existing=$matches->first();
  if($existing&&$slugMatches->isNotEmpty()&&$certMatches->isNotEmpty()&&!$certMatches->contains($existing->id))throw new RuntimeException('Slug and certificate identity conflict: '.$row['name']);
  if($existing&&$normal($existing->name)!==$normal($row['name'])){$plan['excluded'][]=$identity+['reason'=>'listing name conflicts with certificate/slug match'];continue;}
  if(!$existing){
   $names=$local->filter(fn($d)=>$normal($d->name)===$normal($row['name'])&&$normal($d->location)===$normal($row['location']));
   if($names->count()>1){$plan['excluded'][]=$identity+['reason'=>'ambiguous duplicate identity'];continue;}
   $existing=$names->first();
  }
  if($existing&&$existing->archived_at!==null){$plan['excluded'][]=$identity+['reason'=>'local archived listing preserved'];continue;}
  $fields=[];$before=[];
  if(!$existing){
   $fields=['slug'=>$row['slug'],'name'=>$row['name'],'location'=>$row['location'],'region_id'=>$region,'is_accredited'=>in_array($row['is_accredited'],['t','1',1,true],true)];
   foreach(['type','cuisine_type','specialization','operating_status','closure_reason','reopens_on'] as $field)if(in_array($field,$cols,true)&&!$blank($row[$field]??null))$fields[$field]=$row[$field];
   // No imported popularity scores, ratings, pictures, accounts or verification claims.
  }
  foreach($optional as $field){
   if(!in_array($field,$cols,true)||$blank($row[$field]??null)||($existing&&!$blank($existing->$field)))continue;
   if(str_ends_with($field,'_url')&&!filter_var($row[$field],FILTER_VALIDATE_URL))continue;
   $fields[$field]=$row[$field];$before[$field]=$existing?->$field;
  }
  // Import a coordinate pair only when the independent supplied list identifies
  // the exact establishment/building; locality and nearby-landmark pins stay out.
  $coordinateEvidence=array_values(array_filter($dataset['rows'],function($s)use($certs,$row){
   return in_array($s['accno'],array_column($certs,'accreditation_number'),true)
    &&in_array($s['check'],['Verified','Corrected','Matched building'],true)
    &&!preg_match('/city centre|barangay|district|locality|nearby landmark|unverified/i',(string)($s['precision']??''))
    &&$s['lat']!==null&&$s['lng']!==null&&$row['latitude']!==null&&$row['longitude']!==null
    &&abs((float)$s['lat']-(float)$row['latitude'])<0.000001&&abs((float)$s['lng']-(float)$row['longitude'])<0.000001;
  }));
  $coordsBlank=!$existing||($existing->latitude===null&&$existing->longitude===null);
  $duplicateVenue=$table==='destinations'&&preg_match('/crocodile park/i',$row['name'])&&DB::table('destinations')->whereNotNull('latitude')->where('id','!=',$existing?->id??0)->where('name','like','%Crocodile Park%')->exists();
  if($coordsBlank&&$row['latitude']!==null&&$row['longitude']!==null){
   if($coordinateEvidence&&!$duplicateVenue&&(float)$row['latitude']>=5.5&&(float)$row['latitude']<=8.3&&(float)$row['longitude']>=125&&(float)$row['longitude']<=126.8){
    $fields['latitude']=$row['latitude'];$fields['longitude']=$row['longitude'];$before['latitude']=$existing?->latitude;$before['longitude']=$existing?->longitude;
   }else $plan['withheld'][]=$identity+['field'=>'coordinates','reason'=>$duplicateVenue?'duplicate attraction would create repeat visits':'no establishment-specific supplied coordinate evidence'];
  }
  foreach($blocked as $field)if(in_array($field,$cols,true)&&!$blank($row[$field]??null)&&(!$existing||$blank($existing->$field)))$plan['withheld'][]=$identity+['field'=>$field,'reason'=>'operational/price value lacks field-specific source evidence; may be an inherited default or planning assumption'];
  $newCerts=[];
  foreach($certs as $cert){
   $current=$localCertificates->filter(fn($c)=>$c->accreditation_number===$cert['accreditation_number']);
   if($existing)$current=$current->where('listing_id',$existing->id);
   if($current->isNotEmpty())continue;
   if(!$existing||$certMatches->isEmpty()){
    $evidence=array_values(array_filter($dataset['rows'],fn($s)=>$s['accno']===$cert['accreditation_number']&&$s['expiry']===$cert['expiration_date']));
    if(!$evidence){$plan['withheld'][]=$identity+['field'=>'certificate','reason'=>'number/expiry not corroborated by supplied September DOT list'];continue;}
    $newCerts[]=array_intersect_key($cert,array_flip(['accreditation_number','status','issue_date','expiration_date']));
   }
  }
  if(!$existing&&!$newCerts){$plan['excluded'][]=$identity+['reason'=>'new listing has no corroborated real certificate'];continue;}
  if($fields||$newCerts)$plan['actions'][]=$identity+['kind'=>$kind,'local_id'=>$existing?->id,'slug'=>$row['slug'],'fields'=>$fields,'before'=>$before,'certificates'=>$newCerts,'coordinate_evidence'=>$coordinateEvidence];
  $mapping[$kind][$row['id']]=$existing?->id;
 }
}
$tagActions=[];
foreach($live['destination_tags'] as $tag){
 $localId=$mapping['destination'][$tag['destination_id']]??null;
 if(!$localId||$tag['kind']!=='category')continue;
 if(DB::table('destination_tags')->where('destination_id',$localId)->where('kind','category')->exists())continue;
 $tagActions[]=['destination_id'=>$localId,'kind'=>'category','value'=>$tag['value']];
}
$plan['tags']=$tagActions;
$counts=['listing_actions'=>count($plan['actions']),'new_listings'=>count(array_filter($plan['actions'],fn($a)=>$a['local_id']===null)),'existing_enrichments'=>count(array_filter($plan['actions'],fn($a)=>$a['local_id']!==null)),'new_certificates'=>array_sum(array_map(fn($a)=>count($a['certificates']),$plan['actions'])),'excluded_records'=>count($plan['excluded']),'withheld_fields'=>count($plan['withheld']),'tags'=>count($tagActions)];
file_put_contents($out.'/plan.json',json_encode($plan,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
echo json_encode($counts,JSON_PRETTY_PRINT),PHP_EOL;
if(!isset($options['--apply'])){echo 'Dry run: no database changes.',PHP_EOL;exit;}
$backup=$out.'/before.sqlite';
if(file_exists($backup))throw new RuntimeException('Backup already exists; use a separate output directory for each application.');
$from=new SQLite3($connection->getDatabaseName(),SQLITE3_OPEN_READONLY);$to=new SQLite3($backup);if(!$from->backup($to))throw new RuntimeException('Backup failed');$to->close();$from->close();
$applied=[];
DB::transaction(function()use($plan,&$applied){
 foreach($plan['actions'] as $action){
  $fields=$action['fields'];$id=$action['local_id'];
  if($id!==null){
   $current=DB::table($action['table'])->where('id',$id)->first();
   foreach($fields as $field=>$value)if($current->$field!==null&&trim((string)$current->$field)!=='')throw new RuntimeException('Refusing to overwrite populated local field.');
   if($fields)DB::table($action['table'])->where('id',$id)->update($fields);
  }else{
   if(DB::table($action['table'])->where('slug',$action['slug'])->exists())throw new RuntimeException('Concurrent listing creation; rerun dry run.');
   $id=DB::table($action['table'])->insertGetId($fields+['created_at'=>now()->toDateTimeString()]);
  }
  foreach($action['certificates'] as $cert)DB::table('accreditation_records')->insert($cert+['listing_kind'=>$action['kind'],'listing_id'=>$id,'is_placeholder'=>false,'verified_by'=>null,'verified_at'=>null,'created_at'=>now()->toDateTimeString()]);
  $applied[]=$action+['applied_local_id'=>$id];
 }
 foreach($plan['tags'] as $tag)if(!DB::table('destination_tags')->where($tag)->exists())DB::table('destination_tags')->insert($tag);
});
file_put_contents($out.'/applied.json',json_encode(['source_sha256'=>$plan['dump_sha256'],'counts'=>$counts,'actions'=>$applied,'tags'=>$plan['tags']],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
echo 'Applied blank-only listing data; original database backed up. No source IDs or sample evidence reused.',PHP_EOL;
