<?php
// Build a public catalogue-only, guarded patch. Never execute the supplied SQL.
declare(strict_types=1);
require __DIR__.'/audit-itinerary-dump.php';
$root = dirname(__DIR__);
$dump = $argv[1] ?? throw new RuntimeException('Supply the downloaded live dump path.');
$tables = readDump($dump);
foreach ($tables as &$rows) foreach ($rows as &$row) {
    $row = array_combine(array_map(fn($k) => trim($k, '"'), array_keys($row)), array_values($row));
}
unset($rows, $row);
$pdo = new PDO('sqlite:'.$root.'/database/database.sqlite');
$local = [];
foreach (['destinations','restaurants','accommodations','regions'] as $t) $local[$t] = $pdo->query('SELECT * FROM '.$t)->fetchAll(PDO::FETCH_ASSOC);
$json = fn($f) => json_decode(file_get_contents($root.'/database/data/'.$f), true, 512, JSON_THROW_ON_ERROR);
$records = []; $withheld = [];
function findListing(array $rows, array $identity): ?array {
    if (!empty($identity['slug'])) {
        $exact = array_values(array_filter($rows, fn($r) => $r['slug'] === $identity['slug']));
        if (count($exact) === 1) return $exact[0];
    }
    $found = array_values(array_filter($rows, fn($r) => (!empty($identity['slug']) && $r['slug'] === $identity['slug']) || $r['name'] === $identity['name']));
    if (count($found) > 1) throw new RuntimeException('Ambiguous listing '.$identity['name']);
    return $found[0] ?? null;
}
function collect(string $table, array $identity, array $fields, string|array $source, array $tags = [], bool $allowInsert = false): void {
    global $records, $tables, $local, $withheld;
    $l = findListing($local[$table], $identity);
    if (!$l) throw new RuntimeException('Missing reviewed local listing '.$identity['name']);
    $old = findListing($tables[$table], ['slug'=>$l['slug'], 'name'=>$l['name']]);
    if (!$old && $table === 'destinations' && $l['slug'] === 'davao-crocodile-park') $old = findListing($tables[$table], ['name'=>'Davao Crocodile Park Inc']);
    if (!$old && !$allowInsert && !isset($records[$table.':'.$l['slug']])) { $withheld[] = $table.': '.$l['name'].' — absent from live; no insertion approved'; return; }
    $key = $table.':'.($old['slug'] ?? $l['slug']);
    $record = $records[$key] ?? ['table'=>$table,'slug'=>$old['slug'] ?? $l['slug'],'name'=>$old['name'] ?? $l['name'],'insert_if_missing'=>!$old,'fields'=>[],'expected'=>[],'sources'=>[],'tags'=>[]];
    foreach ($fields as $field => $value) {
        $record['fields'][$field] = $value;
        if ($old) $record['expected'][$field] = $old[$field] ?? null;
    }
    $record['sources'] = array_values(array_unique(array_merge($record['sources'], (array)$source)));
    $record['tags'] = array_values(array_unique(array_merge($record['tags'], $tags)));
    $records[$key] = $record;
}
function reviewed(string $table, array $row, array $fields, string $source, array $tags = []): void {
    global $local;
    $l = findListing($local[$table], $row);
    if (!$l) throw new RuntimeException('Missing local source listing '.$row['name']);
    $values = array_intersect_key($l, array_flip($fields));
    if (isset($values['visit_duration']) && strlen($values['visit_duration']) > 50) $values['visit_duration'] = 'Variable; confirm selected activity';
    if ($table !== 'accommodations') {
        $values['planner_verified_at'] = $l['planner_verified_at'];
        $values['planner_verification_source'] = $l['planner_verification_source'];
    }
    collect($table, $row, $values, $source, $tags);
}

// Quarantine test fixtures without deleting history or accreditation records.
foreach ($tables['destinations'] as $r) if (str_contains(strtoupper($r['name']), 'SAMPLE') || $r['name'] === 'Samal Island') {
    $key = 'destinations:'.$r['slug'];
    $records[$key] = ['table'=>'destinations','slug'=>$r['slug'],'name'=>$r['name'],'insert_if_missing'=>false,
        'fields'=>['itinerary_role'=>'excluded'],'expected'=>['itinerary_role'=>$r['itinerary_role']],
        'sources'=>['Catalogue audit: sample fixture or non-routable island-wide locality; exclude from itinerary selection without deleting the record.'],'tags'=>[]];
}
$h = fopen($root.'/database/data/planner-integration-verified-2026-10-31.csv','rb');
$header = fgetcsv($h,0,',','"','');
while (($cells = fgetcsv($h,0,',','"','')) !== false) {
    $r = array_combine($header,$cells);
    if (!in_array($r['field'], ['latitude','longitude','hours','type','visit_duration','entry_fee_min','entry_fee_max'], true)) continue;
    $identity = ['name'=>$r['name']];
    if ($r['name'] === 'Elysia Wellness Spa') $identity['slug'] = 'elysia-wellness-spa-2';
    if ($r['name'] === 'Davao Crocodile Park Inc') $identity = ['name'=>'Davao Crocodile Park'];
    if (!findListing($local['destinations'],$identity)) continue;
    reviewed('destinations',$identity,[$r['field']],$r['source']);
}
fclose($h);
foreach ($json('planner-hotel-coordinate-corrections-2026-10-31.json')['hotels'] as $r) reviewed('accommodations',$r,['latitude','longitude'],$r['source']);
$completion = $json('planner-completion-2026-10-31.json');
foreach ($completion['renewals'] as $r) if (!empty($r['location_source']) && $r['slug'] !== 'funventure-talicud-beach-resort') reviewed('accommodations',$r,['latitude','longitude'],$r['location_source']);
foreach ($completion['destinations'] as $r) reviewed('destinations',$r,['latitude','longitude'],$r['source']);

// New public venues: actual accreditation stays false; unknown facts stay null.
foreach ($json('planner-public-places-2026-10-31.json')['places'] as $r) {
    $l = findListing($local['destinations'],$r);
    $region = array_values(array_filter($local['regions'],fn($x)=>$x['id']==$l['region_id']))[0];
    $fields = array_intersect_key($l,array_flip(['slug','name','location','type','itinerary_role','description','latitude','longitude','hours','visit_duration','price_tier','entry_fee_min','entry_fee_max','planner_verified_at','planner_verification_source','planning_notes']));
    $fields['region_id'] = ['table'=>'regions','name'=>$region['name']];
    $old = findListing($tables['destinations'],['slug'=>$l['slug'],'name'=>$l['name']]);
    if (!$old) $fields['is_accredited'] = false;
    else unset($fields['slug'],$fields['name'],$fields['region_id']);
    collect('destinations',$r,$fields,$r['source'],$r['tags'],true);
}
foreach (['planner-verified-experiences-2026-10-31.json','planner-evidence-updates-2026-11-01.json'] as $file) {
    $data = $json($file);
    foreach ($data['destinations'] ?? [] as $r) reviewed('destinations',$r,array_keys($r['fields']),$r['source'],$r['tags'] ?? []);
    foreach ($data['restaurant_updates'] ?? [] as $r) reviewed('restaurants',$r,array_keys($r['fields']),$r['source']);
    foreach ($data['restaurants'] ?? [] as $r) {
        $l = findListing($local['restaurants'],$r);
        $fields = array_intersect_key($l,array_flip(['slug','name','location','description','latitude','longitude','opening_hours','website_url','planner_verified_at','planner_verification_source']));
        $region = array_values(array_filter($local['regions'],fn($x)=>$x['id']==$l['region_id']))[0];
        $fields['region_id'] = ['table'=>'regions','name'=>$region['name']];
        $hostMissing = false;
        foreach (['destination'=>'destinations','accommodation'=>'accommodations'] as $kind=>$table) {
            $field = 'host_'.$kind.'_id';
            if (empty($l[$field])) continue;
            $host = array_values(array_filter($local[$table],fn($x)=>$x['id']==$l[$field]))[0];
            $liveHost = findListing($tables[$table],$host);
            if (!$liveHost && !isset($records[$table.':'.$host['slug']])) { $hostMissing = true; break; }
            $fields[$field] = ['table'=>$table,'slug'=>$liveHost['slug'] ?? $host['slug'],'name'=>$liveHost['name'] ?? $host['name']];
        }
        if ($hostMissing) { $withheld[] = 'Meal venue '.$r['name'].' — verified host absent from live'; continue; }
        $old = findListing($tables['restaurants'],$r);
        if (!$old) $fields['is_accredited'] = false;
        else unset($fields['slug'],$fields['name']);
        collect('restaurants',$r,$fields,$r['source'],[],true);
    }
}
foreach ($json('resort-setting-corrections-2026-11-01.json')['corrections'] as $r) collect('accommodations',$r,['resort_settings'=>'|'.implode('|',$r['settings']).'|'],$r['sources']);
// Ensure referenced destinations are inserted before their meal venues.
$ordered = [];
foreach (['destinations','accommodations','restaurants'] as $table) foreach ($records as $r) if ($r['table'] === $table) $ordered[] = $r;
$patch = ['format'=>1,'prepared_at'=>'2026-11-01','baseline_sha256'=>hash_file('sha256',$dump),
    'policy'=>'Selective sourced public catalogue updates. Preserve live closures, certificates, room rates, hotel check-in/out and private operational records. Abort on changed edited fields; no deletion.',
    'required_columns'=>['destinations'=>['planner_verified_at','planner_verification_source','planning_notes'],'restaurants'=>['planner_verified_at','planner_verification_source','host_destination_id','host_accommodation_id']],
    'withheld'=>array_merge($withheld,['Accreditation renewals/new hotel claims: require current certificate confirmation.','Magongawon trail: mixed access evidence; hold pending operator confirmation.','No invented near-5km beach or hiking venue. Existing three interest gaps remain disclosed.']),
    'records'=>$ordered];
$target = $root.'/database/data/live-planner-patch-2026-11-01.json';
file_put_contents($target,json_encode($patch,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
echo json_encode(['file'=>$target,'records'=>count($ordered),'by_table'=>array_count_values(array_column($ordered,'table')),'withheld'=>$patch['withheld']],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
