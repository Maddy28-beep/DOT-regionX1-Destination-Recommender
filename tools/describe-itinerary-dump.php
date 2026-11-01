<?php
declare(strict_types=1);
require __DIR__.'/audit-itinerary-dump.php';
$source=$argv[1] ?? 'output/database-audit-20261030/estimated/daily-20261030-with-estimates.sql';
$out=$argv[2] ?? 'output/database-audit-20261030/descriptions';
$target=$out.'/daily-20261030-with-descriptions.sql';
if (realpath($source)===realpath($target)) throw new RuntimeException('Use a separate output directory');
$tables=readDump($source); $expected=$tables; $changes=[];
$regions=array_column($tables['regions'],'name','id');
// Editorial summaries of the supplied catalogue, not new independently verified facts.
$destinationCopy=[
1=>'Samal Island is a beach destination with coastal scenery and island attractions. It offers options for a day trip or a longer island stay.',
2=>'Eden Nature Park is a nature destination in Toril, Davao City, with gardens and mountain scenery. It is a place to spend time outdoors away from the city center.',
3=>'The Philippine Eagle Center in Malagos focuses on Philippine eagle conservation. A visit offers a chance to learn about the eagle and other native wildlife.',
4=>"People's Park is a public park in central Davao City with landscaped spaces and sculptures inspired by Mindanao culture. It is a place for a walk and a break during a city visit.",
5=>'Malagos Garden Resort is a garden destination in Davao City associated with cacao and chocolate. It combines nature and leisure in one stop.',
6=>'Mount Apo Natural Park is a mountain destination with forest trails and volcanic landscapes. Summit trips require advance planning and arrangements for a multi-day trek.',
7=>'Dahican Beach is a coastal destination in Mati with a long sandy shore. It is known for surfing and beach activities.',
8=>'SMX Convention Center Davao is an events venue at SM Lanang Premier. It hosts gatherings such as meetings, conventions and exhibitions.',
9=>'Belviz Farm is a farm tourism destination in Calinan, Davao City. It adds an agriculture-focused stop to a local trip.',
10=>'Bemwa Farm Fresh Inc. is a farm tourism destination in Marilog, Davao City. It is an option for visitors interested in agriculture and a highland setting.',
11=>'Bukid Amara Tourism Agriventures Corporation is a farm tourism listing in Davao City. Contact the business to confirm the visitor site and available activities.',
12=>'Agriya Naturetainment is a farm tourism destination in Panabo City. It offers an agriculture-focused option for a local outing.',
13=>'Isla- Agri Ventures Inc is a farm tourism listing in Samal. Contact the business for information about visits and available activities.',
14=>'Lao Integrated Farms is a farm tourism destination in Bansalan. It is a stop for visitors interested in agriculture and farm-based experiences.',
15=>'Davao Crocodile Park is a wildlife destination in Davao City. It is a place to learn about crocodiles and other animals.',
16=>'JKM Mini Zoo is a wildlife destination in Kapalong. It offers an animal-focused stop for a local visit.',
17=>'Apo Golf and Country Club is a golf and recreation venue in Davao City. Contact the club about visitor access and playing arrangements.',
18=>'Rancho Palos Verdes Golf & Sports Club is a golf and recreation venue in Davao City. Contact the club about visitor access and playing arrangements.',
19=>'Rancho Palos Verdes Golf & Sports Club is a golf and recreation listing in Davao City. Confirm the playing venue and visitor arrangements with the club.',
20=>'South Pacific Davao Country Club is a sports and recreation venue in Davao City. Contact the club for access details and available activities.',
21=>'IMIN The Philippine-Japan Historical Museum in Calinan explores the historical ties between the Philippines and Japan. It adds a cultural stop to a Davao City visit.',
22=>'Elysia Wellness Spa is a wellness stop in Davao City for a break focused on relaxation. Contact this branch for available treatments and appointments.',
23=>'Elysia Wellness Spa is a wellness stop in Davao City for a break focused on relaxation. Contact this branch for available treatments and appointments.',
24=>'Elysia Wellness Spa is a wellness stop in Davao City for a break focused on relaxation. Contact this branch for available treatments and appointments.',
25=>'Aliwagwag Falls is a multi-tiered waterfall in Cateel, surrounded by a protected landscape. Confirm current access before planning a visit.',
26=>'Mount Hamiguitan Range Wildlife Sanctuary is a protected mountain landscape in Davao Oriental with distinctive plants and wildlife. Trekking visits require advance arrangements.',
27=>'Pujada Bay and Pujada Island are coastal destinations in Mati with beaches and marine scenery. Island visits require separate boat arrangements.',
28=>'Oak Island is a small sandbar near Mati that appears at low tide. Visits depend on tide levels and boat conditions.',
29=>'Guang-guang Mangrove Forest is a coastal nature area in Mati associated with mangrove and sea turtle conservation. Confirm visitor access before going.',
30=>'Sleeping Dinosaur is a natural landform near Mati that resembles a resting dinosaur. It is a scenic landmark along the approach to the city.',
31=>"Subangan Museum in Mati introduces the heritage and natural history of Davao Oriental. Its exhibits include the skeleton of a sperm whale named Davor.",
32=>'Campawan Curtain Falls is a wide waterfall in Baganga surrounded by forest. Visiting involves a walk to the falls and may include a river crossing.',
33=>'Lon Wa Buddhist Temple is a religious and cultural landmark in Davao City, also known as Longhua Temple. Visitors should respect worship activities and temple rules.',
34=>'Davao Chinatown is a district known for Chinese-style arches, shops and restaurants. It offers a city stop focused on food, shopping and local heritage.',
35=>'The National Museum of the Philippines Davao presents exhibits on Mindanao heritage and culture. It is located in the People\'s Park compound in Davao City.',
36=>'Monfort Bat Cave in Samal is home to a large fruit bat colony. Visitors observe the bats from designated viewing areas outside the cave.',
37=>'Hagimit Falls in Samal is a series of small waterfalls and natural pools. Check its current operating status before including it in a trip.',
38=>'Tagbibinta Falls is a waterfall destination near Maragusan at the foothills of Mount Candalaga. It adds a nature stop to a visit to the area.',
39=>'Panas Eco-cultural Park in New Corella is a nature destination with pools and waterfalls. It is an option for an outdoor visit in Davao del Norte.',
40=>'Linow tu Gabon, also called the Ocean of Fogs, is an upland viewpoint in Kapalong. Morning fog is part of its appeal, although views depend on the weather.',
41=>"Uraya's Peak is a hilltop destination in Talaingod with views of the surrounding landscape. Confirm access and visit arrangements before going.",
42=>'Tudaya Falls is a waterfall within the Mount Apo area in Davao del Sur. Plan for a nature visit and confirm local access conditions in advance.',
];
$quote=fn($v)=>$v===null?'NULL':"'".str_replace("'","''",$v)."'";
$sql=['-- Plain-language descriptions based on existing catalogue data; not new venue verification.', 'BEGIN;', 'SET LOCAL standard_conforming_strings = on;'];
$rollback=['BEGIN;', 'SET LOCAL standard_conforming_strings = on;'];
foreach (['destinations','accommodations','restaurants','souvenir_centers','tour_operators'] as $table) {
    foreach ($expected[$table] as &$row) {
        $name=trim(str_replace(' [SAMPLE]','',$row['name']));
        $area=$regions[$row['region_id']] ?? $row['location'];
        $type=strtolower(trim((string)($row['type'] ?? '')));
        $description=match($table) {
            'destinations'=>$destinationCopy[(int)$row['id']] ?? throw new RuntimeException('Missing destination copy'),
            'accommodations'=>"$name is ".(in_array($type,['hotel','resort','hostel','inn','homestay','pension house','guesthouse','guest house','apartelle'],true)?(in_array($type,['inn','apartelle'],true)?'an ':'a ').$type:'a place to stay')." in $area. Contact the property for room options and booking details.",
            'restaurants'=>"$name is a dining stop in $area. ".(empty($row['cuisine_type'])?'Contact the restaurant for its menu and dining details.':'Its listed food category is '.$row['cuisine_type'].'. Contact the restaurant for the current menu.'),
            'souvenir_centers'=>"$name is a souvenir shop in $area. Browse for keepsakes or gifts, and contact the shop for its current selection.",
            'tour_operators'=>"$name is a travel and tour provider in $area. Contact the team to discuss trip options and booking arrangements.",
        };
        if ($description===$row['description']) continue;
        if (str_contains($description,"\n") || str_contains($description,"\t")) throw new RuntimeException('Unexpected control character');
        $where='id = '.(int)$row['id'].' AND name = '.$quote($row['name']);
        $sql[]="UPDATE public.$table SET description = ".$quote($description)." WHERE $where AND description IS NOT DISTINCT FROM ".$quote($row['description']).';';
        $rollback[]="UPDATE public.$table SET description = ".$quote($row['description'])." WHERE $where AND description IS NOT DISTINCT FROM ".$quote($description).';';
        $changes[]=['table'=>$table,'id'=>$row['id'],'name'=>$row['name'],'before'=>$row['description'],'after'=>$description,'basis'=>'editorial_summary_of_existing_catalogue'];
        $row['description']=$description;
    }
    unset($row);
}
if (!is_dir($out)) mkdir($out,0777,true);
$lookup=[];
foreach ($changes as $c) $lookup[$c['table']][$c['id']]=$c['after'];
$input=fopen($source,'rb'); $output=fopen($target,'wb'); $table=null; $columns=[];
while (($line=fgets($input))!==false) {
    if (preg_match('/^COPY public\.(\w+) \(([^)]+)\) FROM stdin;/', $line,$m)) { $table=$m[1]; $columns=explode(', ',$m[2]); }
    elseif ($table!==null && rtrim($line,"\r\n")==='\\.') $table=null;
    elseif ($table!==null && isset($lookup[$table])) {
        $end=str_ends_with($line,"\r\n")?"\r\n":"\n";
        $cells=explode("\t",rtrim($line,"\r\n")); $id=$cells[array_search('id',$columns,true)];
        if (isset($lookup[$table][$id])) { $cells[array_search('description',$columns,true)]=str_replace('\\','\\\\',$lookup[$table][$id]); $line=implode("\t",$cells).$end; }
    }
    fwrite($output,$line);
}
fclose($input); fclose($output);
if (readDump($target)!==$expected) throw new RuntimeException('Unexpected data change');
$sql[]='COMMIT;'; $rollback[]='COMMIT;';
file_put_contents($out.'/descriptions.sql',implode("\n",$sql)."\n");
file_put_contents($out.'/rollback-descriptions.sql',implode("\n",$rollback)."\n");
file_put_contents($out.'/descriptions.json',json_encode($changes,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
$preview="# Simple listing descriptions\n\nBased on existing catalogue information; not newly verified venue details.\n";
foreach ($changes as $c) $preview.="\n## {$c['name']} ({$c['table']} #{$c['id']})\n\n{$c['after']}\n";
file_put_contents($out.'/PREVIEW.md',$preview);
$counts=array_count_values(array_column($changes,'table'));
file_put_contents($out.'/verification.json',json_encode(['changed_descriptions'=>$counts,'all_other_parsed_data_preserved'=>true,'source_sha256'=>hash_file('sha256',$source),'output_sha256'=>hash_file('sha256',$target)],JSON_PRETTY_PRINT));
echo json_encode($counts)."\nAll other parsed data preserved.\n";
