<?php
declare(strict_types=1);
require __DIR__.'/audit-itinerary-dump.php';
$source = $argv[1];
$out = $argv[2] ?? 'output/database-audit-20261030';
$tables = readDump($source);
if (!is_dir($out)) mkdir($out,0777,true);
$changes = [];
// Restore the explicit role policy already defined by the application's migration.
foreach ($tables['destinations'] as $row) {
    $role = match ($row['type']) {
        'Wellness & Spa'=>'optional',
        'Events & Conventions', 'Sports & Recreation'=>'excluded',
        default=>null,
    };
    if ($role !== null && $row['itinerary_role'] === 'sightseeing') {
        $changes[] = ['table'=>'destinations','id'=>$row['id'],'name'=>$row['name'],'field'=>'itinerary_role','before'=>$row['itinerary_role'],'after'=>$role,'source'=>'database/migrations/2026_10_07_000100_add_itinerary_role_to_destinations.php'];
    }
}
$researchFile=__DIR__.'/../database/data/destination-research-2026-10-30.json';
$researchChanges=[];
foreach (json_decode(file_get_contents($researchFile),true,512,JSON_THROW_ON_ERROR) as $addition) {
    if (!in_array($addition['field'],['visit_duration','entry_fee_min','entry_fee_max','price_tier'],true)
        || preg_match('/[\t\r\n\\\\]/',$addition['value'])) throw new RuntimeException('Invalid research field/value');
    $matches=array_values(array_filter($tables['destinations'],fn($r)=>$r['id']===$addition['id'] && $r['name']===$addition['name']));
    if (count($matches)!==1) throw new RuntimeException('Research destination identity mismatch');
    $current=$matches[0][$addition['field']];
    if ($current!==null && $current!=='') {
        if ($current===$addition['value']) continue;
        throw new RuntimeException('Research refuses to overwrite an existing value: '.$addition['id'].' '.$addition['field']);
    }
    $change=['table'=>'destinations','id'=>$addition['id'],'name'=>$addition['name'],'field'=>$addition['field'],'before'=>$current,'after'=>$addition['value'],
        'source'=>$addition['source'],'checked_on'=>$addition['checked_on'],'evidence_class'=>$addition['evidence_class'],'notes'=>$addition['notes']];
    $researchChanges[]=$change;
    $changes[]=$change;
}
$quote = fn($v) => $v === null ? 'NULL' : "'".str_replace("'", "''", $v)."'";
$forward = ["-- Target: ".basename($source), '-- Guarded updates; mismatched or already changed rows are skipped.', 'BEGIN;', 'SET LOCAL standard_conforming_strings = on;'];
$rollback = ['BEGIN;', 'SET LOCAL standard_conforming_strings = on;'];
foreach ($changes as $c) {
    $where = "id = ".(int)$c['id']." AND name = ".$quote($c['name']);
    $forward[] = "UPDATE public.destinations SET {$c['field']} = ".$quote($c['after'])." WHERE $where AND {$c['field']} IS NOT DISTINCT FROM ".$quote($c['before']).';';
    $rollback[] = "UPDATE public.destinations SET {$c['field']} = ".$quote($c['before'])." WHERE $where AND {$c['field']} IS NOT DISTINCT FROM ".$quote($c['after']).';';
}
$forward[] = 'COMMIT;'; $rollback[] = 'COMMIT;';
$researchSql=['-- Fill only researched blank fields; preserves existing nonblank values.', 'BEGIN;', 'SET LOCAL standard_conforming_strings = on;'];
$csv=fopen($out.'/research-additions.csv','wb');
fputcsv($csv,['id','name','field','value','source'],',','"','');
foreach ($researchChanges as $c) {
    $researchSql[]="UPDATE public.destinations SET {$c['field']} = ".$quote($c['after'])." WHERE id = ".(int)$c['id']." AND name = ".$quote($c['name'])." AND {$c['field']} IS NOT DISTINCT FROM ".$quote($c['before']).';';
    fputcsv($csv,[$c['id'],$c['name'],$c['field'],$c['after'],$c['source'].' | checked '.$c['checked_on'].' | '.$c['evidence_class'].' | '.$c['notes']],',','"','');
}
fclose($csv);
$researchSql[]='COMMIT;';
file_put_contents($out.'/research-additions.sql',implode("\n",$researchSql)."\n");
file_put_contents($out.'/research-additions.json',json_encode($researchChanges,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
file_put_contents($out.'/repairs.sql',implode("\n",$forward)."\n");
file_put_contents($out.'/rollback.sql',implode("\n",$rollback)."\n");
file_put_contents($out.'/changes.json',json_encode($changes,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
// Preserve all original SQL and every unrelated COPY value byte-for-byte.
$input = fopen($source,'rb'); $output = fopen($out.'/daily-20261030-repaired.sql','wb');
$columns = null; $changed = 0;
while (($line = fgets($input)) !== false) {
    if (preg_match('/^COPY public\.destinations \(([^)]+)\) FROM stdin;/', $line,$m)) $columns=explode(', ',$m[1]);
    elseif ($columns !== null && rtrim($line,"\r\n") === '\\.') $columns=null;
    elseif ($columns !== null) {
        $end = str_ends_with($line,"\r\n") ? "\r\n" : "\n";
        $cells = explode("\t",rtrim($line,"\r\n"));
        foreach ($changes as $c) {
            if ($cells[array_search('id',$columns,true)] === $c['id']) {
                $cells[array_search($c['field'],$columns,true)]=$c['after']; $changed++;
                $line=implode("\t",$cells).$end;
            }
        }
    }
    fwrite($output,$line);
}
fclose($input); fclose($output);
$repaired = readDump($out.'/daily-20261030-repaired.sql');
$expected = $tables;
foreach ($expected['destinations'] as &$row) foreach ($changes as $c) if ($row['id'] === $c['id']) $row[$c['field']]=$c['after'];
unset($row);
if ($expected !== $repaired || $changed !== count($changes)) throw new RuntimeException('Repair verification failed');
$tagCandidates = [
    ['id'=>'28','name'=>'Oak Island [SAMPLE]','value'=>'Island Hopping','source'=>'https://islanddiariesph.com/product/mati-joiner-tour/ | operator lists Oak Island as an island-tour stop; checked 2026-10-30'],
    ['id'=>'39','name'=>'Panas Eco-cultural Park [SAMPLE]','value'=>'Nature','source'=>'Existing dump type Nature & Adventure; category derived from that classification, not independent verification'],
];
$tagChanges=[];
$nextTagId=max(array_map('intval',array_column($tables['destination_tags'],'id')))+1;
foreach ($tagCandidates as $candidate) {
    $matches=array_filter($tables['destinations'],fn($r)=>$r['id']===$candidate['id'] && $r['name']===$candidate['name']);
    $existing=array_filter($tables['destination_tags'],fn($r)=>$r['destination_id']===$candidate['id'] && $r['kind']==='category');
    if (!$matches || $existing) continue;
    $new=['id'=>(string)$nextTagId++, 'destination_id'=>$candidate['id'], 'kind'=>'category','value'=>$candidate['value']];
    $expected['destination_tags'][]=$new;
    $tagChanges[]=$candidate+['inserted_row'=>$new];
    $guard='d.id = '.(int)$candidate['id'].' AND d.name = '.$quote($candidate['name']);
    $insert="INSERT INTO public.destination_tags (destination_id, kind, value) SELECT d.id, 'category', ".$quote($candidate['value'])." FROM public.destinations d WHERE $guard AND NOT EXISTS (SELECT 1 FROM public.destination_tags t WHERE t.destination_id=d.id AND t.kind='category');";
    array_splice($forward,-1,0,[$insert]);
    // An added tag can subsequently be adopted/edited by an administrator; inspect the change log before rollback.
    $delete="DELETE FROM public.destination_tags t USING public.destinations d WHERE t.destination_id=d.id AND $guard AND t.kind='category' AND t.value=".$quote($candidate['value']).';';
    array_splice($rollback,-1,0,[$delete]);
}
$dump=file_get_contents($out.'/daily-20261030-repaired.sql');
if ($tagChanges) {
    $lines=implode("\n",array_map(fn($c)=>implode("\t",array_values($c['inserted_row'])),$tagChanges))."\n";
    $dump=preg_replace_callback('/(COPY public\.destination_tags \([^\n]+\) FROM stdin;\r?\n)(.*?)(\\\\\.\r?\n)/s',fn($m)=>$m[1].$m[2].$lines.$m[3],$dump,1,$count);
    if ($count!==1) throw new RuntimeException('Cannot locate tag COPY block');
    $dump=preg_replace_callback("/(SELECT pg_catalog.setval\('public.destination_tags_id_seq', )(\d+)(, (?:true|false)\);)/",fn($m)=>$m[1].max((int)$m[2],$nextTagId-1).', true);',$dump,1,$count);
    if ($count!==1) throw new RuntimeException('Cannot locate tag sequence');
    file_put_contents($out.'/daily-20261030-repaired.sql',$dump);
}
if (readDump($out.'/daily-20261030-repaired.sql') !== $expected) throw new RuntimeException('Tag repair verification failed');
file_put_contents($out.'/repairs.sql',implode("\n",$forward)."\n");
file_put_contents($out.'/rollback.sql',implode("\n",$rollback)."\n");
file_put_contents($out.'/added-tags.json',json_encode($tagChanges,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
file_put_contents($out.'/verification.json',json_encode(['source_sha256'=>hash_file('sha256',$source),'repaired_sha256'=>hash_file('sha256',$out.'/daily-20261030-repaired.sql'),'changed_cells'=>$changed,'added_category_tags'=>count($tagChanges),'all_other_parsed_data_identical'=>true,'table_counts'=>array_map('count',$expected)],JSON_PRETTY_PRINT));
echo "Verified $changed repaired cells and ".count($tagChanges)." new category tags; other parsed data preserved.\n";
