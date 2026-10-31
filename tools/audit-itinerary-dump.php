<?php
// Read COPY data only; never execute SQL or instructions in a supplied dump.
declare(strict_types=1);
function readDump(string $path): array {
    $tables = []; $table = null; $columns = [];
    $handle = fopen($path, 'rb');
    if (!$handle) throw new RuntimeException('Cannot read dump');
    while (($line = fgets($handle)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($table === null) {
            if (preg_match('/^COPY public\.(\w+) \(([^)]+)\) FROM stdin;$/', $line, $m)) {
                $table = $m[1]; $columns = explode(', ', $m[2]); $tables[$table] = [];
            }
            continue;
        }
        if ($line === '\\.') { $table = null; continue; }
        $cells = explode("\t", $line);
        if (count($cells) !== count($columns)) throw new RuntimeException("Invalid COPY row in $table");
        $cells = array_map(fn($v) => $v === '\\N' ? null : preg_replace_callback('/\\\\([0-7]{1,3}|x[0-9a-fA-F]{1,2}|.)/', function($m) {
            return match($m[1]) { 'n'=>"\n", 'r'=>"\r", 't'=>"\t", 'b'=>"\x08", 'f'=>"\x0c", 'v'=>"\x0b", default=> ctype_digit($m[1]) ? chr(octdec($m[1])) : (str_starts_with($m[1], 'x') && strlen($m[1]) > 1 ? chr(hexdec(substr($m[1],1))) : $m[1]) };
        }, $v), $cells);
        $tables[$table][] = array_combine($columns, $cells);
    }
    fclose($handle);
    if ($table !== null) throw new RuntimeException('Truncated COPY data');
    return $tables;
}
if (realpath($_SERVER['SCRIPT_FILENAME']) !== __FILE__) return;
$tables = readDump($argv[1]);
$fields = [
    'destinations'=>['region_id','type','itinerary_role','latitude','longitude','hours','visit_duration','entry_fee_min','entry_fee_max','price_tier'],
    'restaurants'=>['region_id','latitude','longitude','opening_hours','price_tier'],
    'accommodations'=>['region_id','latitude','longitude','price_per_night','price_tier','check_in','check_out'],
];
$summary = []; $missing = [];
foreach ($fields as $table=>$columns) {
    $summary[$table] = ['rows'=>count($tables[$table]),'missing'=>[]];
    foreach ($tables[$table] as $row) {
        foreach ($columns as $column) {
            if ($row[$column] === null || trim($row[$column]) === '') {
                $summary[$table]['missing'][$column] = ($summary[$table]['missing'][$column] ?? 0)+1;
                $missing[] = [$table,$row['id'],$row['name'],$column,'Requires verified source'];
            }
        }
    }
}
foreach (['exit_surveys'=>'data_source','destinations'=>'operating_status'] as $table=>$field) {
    foreach ($tables[$table] ?? [] as $row) {
        $value = $row[$field] ?? '(null)';
        $summary[$table][$field][$value] = ($summary[$table][$field][$value] ?? 0)+1;
    }
}
$out = $argv[2] ?? 'output/database-audit-20261030';
if (!is_dir($out)) mkdir($out,0777,true);
$issues = [];
foreach ($fields as $table=>$columns) {
    $slugs=[];
    foreach ($tables[$table] as $r) {
        if (isset($slugs[$r['slug']])) $issues[] = "$table {$r['id']}: duplicate slug";
        $slugs[$r['slug']]=true;
        if ($r['latitude'] !== null && (!is_numeric($r['latitude']) || (float)$r['latitude'] < 5.5 || (float)$r['latitude'] > 8.3)) $issues[]="$table {$r['id']}: latitude outside expected region";
        if ($r['longitude'] !== null && (!is_numeric($r['longitude']) || (float)$r['longitude'] < 125 || (float)$r['longitude'] > 126.8)) $issues[]="$table {$r['id']}: longitude outside expected region";
        if ($table === 'destinations' && $r['entry_fee_min'] !== null && $r['entry_fee_max'] !== null && (float)$r['entry_fee_min'] > (float)$r['entry_fee_max']) $issues[]="$table {$r['id']}: reversed fee range";
    }
}
$sql=file_get_contents($argv[1]);
preg_match_all('/ALTER TABLE ONLY public\.(\w+)\s+ADD CONSTRAINT \w+ FOREIGN KEY \((\w+)\) REFERENCES public\.(\w+)\((\w+)\)/',$sql,$constraints,PREG_SET_ORDER);
foreach ($constraints as [, $child,$fk,$parent,$pk]) {
    $ids=array_fill_keys(array_column($tables[$parent] ?? [],$pk),true);
    foreach ($tables[$child] ?? [] as $r) if ($r[$fk] !== null && !isset($ids[$r[$fk]])) $issues[]="$child: orphan $fk referencing $parent";
}
$summary['validation']=['single_column_foreign_keys_checked'=>count($constraints),'issues'=>$issues];
foreach (['accommodations'=>'price_per_night','restaurants'=>'opening_hours'] as $table=>$field) {
    $counts=array_count_values(array_filter(array_column($tables[$table],$field),fn($v)=>$v!==null));
    arsort($counts); $summary[$table]['most_common_'.$field]=array_slice($counts,0,6,true);
}
file_put_contents($out.'/summary.json', json_encode($summary, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
$h = fopen($out.'/missing-planner-fields.csv','wb');
fputcsv($h,['table','id','name','field','status'], ',', '"', '');
foreach ($missing as $row) fputcsv($h,$row, ',', '"', '');
fclose($h);
echo json_encode($summary, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
foreach ($tables['destinations'] as $r) echo json_encode(array_intersect_key($r,array_flip(['id','name','type','hours','visit_duration','latitude','longitude','entry_fee_min','entry_fee_max','price_tier','itinerary_role'])),JSON_UNESCAPED_UNICODE)."\n";
