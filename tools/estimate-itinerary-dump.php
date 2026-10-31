<?php
declare(strict_types=1);
require __DIR__.'/audit-itinerary-dump.php';
require __DIR__.'/../app/Support/OpeningHours.php';
require __DIR__.'/../app/Support/VisitDuration.php';

// User-authorized planning assumptions. None of these values is a verified venue fact.
$source=$argv[1] ?? 'output/database-audit-20261030/daily-20261030-repaired.sql';
$out=$argv[2] ?? 'output/database-audit-20261030/estimated';
$target=$out.'/daily-20261030-with-estimates.sql';
if (realpath($source) === realpath($target)) throw new RuntimeException('Use a separate output directory');
$tables=readDump($source); $expected=$tables; $changes=[];
// ID => [name, assumed daily planning window, visit minutes, admission allowance PHP min/max].
// Durations exclude inter-destination travel. Fees exclude transport, meals, guides and packages.
$plans=[
  25=>['Aliwagwag Falls','8:00 AM-4:00 PM',180,50,200],
  26=>['Mount Hamiguitan Range Wildlife Sanctuary','6:00 AM-4:00 PM',1440,500,1500],
  27=>['Pujada Bay and Pujada Island','8:00 AM-4:00 PM',240,50,200],
  28=>['Oak Island','8:00 AM-4:00 PM',60,0,100],
  29=>['Guang-guang Mangrove Forest','8:00 AM-4:00 PM',60,0,100],
  30=>['Sleeping Dinosaur','8:00 AM-4:00 PM',30,0,50],
  31=>['Subangan Museum','9:00 AM-4:00 PM',60,10,150],
  32=>['Campawan Curtain Falls','8:00 AM-4:00 PM',180,20,150],
  33=>['Lon Wa Buddhist Temple','9:00 AM-4:00 PM',30,0,0],
  34=>['Davao Chinatown','9:00 AM-5:00 PM',90,0,0],
  35=>['National Museum of the Philippines Davao','9:00 AM-4:00 PM',120,0,0],
  37=>['Hagimit Falls','8:00 AM-4:00 PM',180,50,200],
  38=>['Tagbibinta Falls','8:00 AM-4:00 PM',120,20,150],
  39=>['Panas Eco-cultural Park','8:00 AM-4:00 PM',180,20,150],
  40=>['Linow tu Gabon (Ocean of Fogs)','6:00 AM-3:00 PM',120,50,300],
  41=>["Uraya's Peak",'7:00 AM-4:00 PM',120,50,300],
  42=>['Tudaya Falls','8:00 AM-3:00 PM',240,20,200],
];
$quote=fn($v)=>$v===null?'NULL':"'".str_replace("'","''",$v)."'";
$sql=['-- UNVERIFIED USER-AUTHORIZED PLANNING ESTIMATES. See README.md and estimates.json.', 'BEGIN;', 'SET LOCAL standard_conforming_strings = on;'];
$rollback=['BEGIN;', 'SET LOCAL standard_conforming_strings = on;'];
foreach ($expected['destinations'] as &$row) {
    if (!isset($plans[(int)$row['id']])) continue;
    [$name,$hours,$minutes,$min,$max]=$plans[(int)$row['id']];
    if ($row['name'] !== $name.' [SAMPLE]') throw new RuntimeException('Destination identity mismatch');
    $values=['hours'=>$hours,'visit_duration'=>"$minutes minutes",'entry_fee_min'=>number_format($min,2,'.',''),'entry_fee_max'=>number_format($max,2,'.',''),'price_tier'=>$max<=500?'Budget-Friendly':'Mid-range'];
    foreach ($values as $field=>$value) {
        if ($row[$field]!==null && $row[$field]!=='') continue;
        if ($field==='hours' && App\Support\OpeningHours::windows($value)===null) throw new RuntimeException('Unreadable hours');
        if ($field==='visit_duration' && App\Support\VisitDuration::minutes($value)!==$minutes) throw new RuntimeException('Unreadable duration');
        $before=$row[$field];
        $where='id = '.(int)$row['id'].' AND name = '.$quote($row['name']);
        $sql[]="UPDATE public.destinations SET $field = ".$quote($value)." WHERE $where AND $field IS NOT DISTINCT FROM ".$quote($before).';';
        $rollback[]="UPDATE public.destinations SET $field = ".$quote($before)." WHERE $where AND $field IS NOT DISTINCT FROM ".$quote($value).';';
        $changes[]=['id'=>$row['id'],'name'=>$row['name'],'field'=>$field,'before'=>$before,'value'=>$value,'evidence_class'=>'unverified_planning_assumption','authorized_on'=>'2026-10-30'];
        $row[$field]=$value;
    }
}
unset($row);
if (!is_dir($out)) mkdir($out,0777,true);
$input=fopen($source,'rb'); $output=fopen($target,'wb'); $columns=null;
while (($line=fgets($input))!==false) {
    if (preg_match('/^COPY public\.destinations \(([^)]+)\) FROM stdin;/', $line,$m)) $columns=explode(', ',$m[1]);
    elseif ($columns!==null && rtrim($line,"\r\n")==='\\.') $columns=null;
    elseif ($columns!==null) {
        $end=str_ends_with($line,"\r\n")?"\r\n":"\n";
        $cells=explode("\t",rtrim($line,"\r\n"));
        foreach ($changes as $c) if ($cells[array_search('id',$columns,true)]===$c['id']) $cells[array_search($c['field'],$columns,true)]=$c['value'];
        $line=implode("\t",$cells).$end;
    }
    fwrite($output,$line);
}
fclose($input); fclose($output);
if (readDump($target)!==$expected) throw new RuntimeException('Unexpected data change');
$sql[]='COMMIT;'; $rollback[]='COMMIT;';
file_put_contents($out.'/estimates.sql',implode("\n",$sql)."\n");
file_put_contents($out.'/rollback-estimates.sql',implode("\n",$rollback)."\n");
file_put_contents($out.'/estimates.json',json_encode($changes,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
file_put_contents($out.'/verification.json',json_encode(['estimated_cells'=>count($changes),'other_parsed_data_preserved'=>true,'source_sha256'=>hash_file('sha256',$source),'output_sha256'=>hash_file('sha256',$target)],JSON_PRETTY_PRINT));
echo 'Verified '.count($changes)." estimated cells; all other parsed data preserved.\n";
