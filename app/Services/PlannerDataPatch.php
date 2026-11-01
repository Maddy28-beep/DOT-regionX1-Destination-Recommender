<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Apply explicit sourced field changes with optimistic guards; never replace whole tables. */
final class PlannerDataPatch
{
    private const FIELDS = [
        'destinations' => ['slug', 'name', 'region_id', 'location', 'type', 'itinerary_role', 'description', 'latitude', 'longitude', 'hours', 'visit_duration', 'price_tier', 'entry_fee_min', 'entry_fee_max', 'is_accredited', 'planner_verified_at', 'planner_verification_source', 'planning_notes'],
        'restaurants' => ['slug', 'name', 'region_id', 'location', 'description', 'latitude', 'longitude', 'opening_hours', 'website_url', 'is_accredited', 'planner_verified_at', 'planner_verification_source', 'host_destination_id', 'host_accommodation_id'],
        'accommodations' => ['latitude', 'longitude', 'resort_settings'],
    ];

    public function run(array $manifest, bool $apply = false): array
    {
        if (($manifest['format'] ?? null) !== 1 || ! is_array($manifest['records'] ?? null)) {
            throw new RuntimeException('Unsupported planner patch format.');
        }
        $missing = [];
        foreach ($manifest['required_columns'] ?? [] as $table => $columns) {
            if (! isset(self::FIELDS[$table])) {
                throw new RuntimeException('Unexpected prerequisite table.');
            }
            foreach ($columns as $column) {
                if (! in_array($column, self::FIELDS[$table], true) || ! Schema::hasColumn($table, $column)) {
                    $missing[] = $table.'.'.$column;
                }
            }
        }
        if ($apply && $missing) {
            throw new RuntimeException('Run the two bundled verification migrations before applying: '.implode(', ', $missing));
        }

        return DB::transaction(function () use ($manifest, $apply, $missing) {
            $report = ['mode' => $apply ? 'apply' : 'preview', 'missing_columns' => $missing, 'inserted' => 0, 'updated' => 0, 'tags_added' => 0, 'unchanged' => 0, 'records' => []];
            foreach ($manifest['records'] as $record) {
                $table = $record['table'] ?? '';
                if (! isset(self::FIELDS[$table]) || empty($record['slug']) || empty($record['name']) || empty($record['sources'])) {
                    throw new RuntimeException('Invalid or unsourced patch record.');
                }
                foreach (array_keys($record['fields'] ?? []) as $field) {
                    if (! in_array($field, self::FIELDS[$table], true)) {
                        throw new RuntimeException('Disallowed field: '.$table.'.'.$field);
                    }
                }
                if ($table !== 'destinations' && ! empty($record['tags'])) {
                    throw new RuntimeException('Only destination category tags are supported.');
                }
                $matches = DB::table($table)->where('slug', $record['slug'])->when($apply, fn ($q) => $q->lockForUpdate())->get();
                if ($matches->count() > 1) {
                    throw new RuntimeException('Ambiguous slug: '.$record['slug']);
                }
                $existing = $matches->first();
                if ($existing && $existing->name !== $record['name']) {
                    throw new RuntimeException('Identity changed: '.$record['slug']);
                }
                if (! $existing && ! ($record['insert_if_missing'] ?? false)) {
                    throw new RuntimeException('Expected existing listing missing: '.$record['slug']);
                }
                if (! $existing && DB::table($table)->where('name', $record['name'])->exists()) {
                    throw new RuntimeException('Same-name listing needs review: '.$record['name']);
                }

                if (! $existing) {
                    if ($table === 'accommodations') {
                        throw new RuntimeException('This patch cannot create hotels.');
                    }
                    if (($record['fields']['is_accredited'] ?? null) !== false) {
                        throw new RuntimeException('New visitor venues must not claim accreditation.');
                    }
                    $report['inserted']++;
                    if ($apply) {
                        $fields = $this->resolve($record['fields']);
                        if (($fields['slug'] ?? '') !== $record['slug'] || ($fields['name'] ?? '') !== $record['name']) {
                            throw new RuntimeException('Insert identity does not match.');
                        }
                        $fields['created_at'] = now();
                        $id = DB::table($table)->insertGetId($fields);
                    } else {
                        $id = null;
                    }
                    $action = 'insert';
                } else {
                    $id = $existing->id;
                    $fields = $this->resolve($record['fields']);
                    $updates = [];
                    foreach ($fields as $field => $value) {
                        $current = $existing->$field ?? null;
                        if ($this->same($field, $current, $value)) {
                            continue;
                        }
                        if (in_array($field, ['slug', 'name', 'is_accredited'], true)) {
                            throw new RuntimeException('Existing identity and accreditation are protected: '.$record['slug']);
                        }
                        // A repeated patch must never undo a subsequent editor's correction.
                        if (! array_key_exists($field, $record['expected'] ?? []) || ! $this->same($field, $current, $record['expected'][$field])) {
                            throw new RuntimeException('Concurrent or manual correction needs review: '.$table.'.'.$record['slug'].'.'.$field);
                        }
                        $updates[$field] = $value;
                    }
                    if ($updates) {
                        if ($apply) {
                            DB::table($table)->where('id', $id)->update($updates);
                        }
                        $report['updated']++;
                        $action = 'update';
                    } else {
                        $report['unchanged']++;
                        $action = 'unchanged';
                    }
                }
                foreach ($record['tags'] ?? [] as $tag) {
                    if (! is_string($tag) || trim($tag) === '') {
                        throw new RuntimeException('Invalid category tag.');
                    }
                    if ($id !== null && DB::table('destination_tags')->where(['destination_id' => $id, 'kind' => 'category', 'value' => $tag])->exists()) {
                        continue;
                    }
                    $report['tags_added']++;
                    if ($apply) {
                        DB::table('destination_tags')->insert(['destination_id' => $id, 'kind' => 'category', 'value' => $tag]);
                    }
                }
                $report['records'][] = ['table' => $table, 'slug' => $record['slug'], 'action' => $action];
            }

            return $report;
        });
    }

    private function resolve(array $fields): array
    {
        foreach ($fields as $field => &$value) {
            if (! is_array($value)) {
                continue;
            }
            $table = $value['table'] ?? '';
            if (! in_array($table, ['regions', 'destinations', 'accommodations'], true)) {
                throw new RuntimeException('Unsupported relation.');
            }
            if (($table === 'regions' && $field !== 'region_id') || ($table === 'destinations' && $field !== 'host_destination_id') || ($table === 'accommodations' && $field !== 'host_accommodation_id')) {
                throw new RuntimeException('Relation field mismatch.');
            }
            $query = DB::table($table)->where('name', $value['name']);
            if ($table !== 'regions') {
                $query->where('slug', $value['slug']);
            }
            $rows = $query->get();
            if ($rows->count() !== 1) {
                throw new RuntimeException('Missing or ambiguous relation for '.$field);
            }
            $value = $rows->first()->id;
        }
        unset($value);

        return $fields;
    }

    private function same(string $field, mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        if (in_array($field, ['latitude', 'longitude', 'entry_fee_min', 'entry_fee_max', 'region_id', 'host_destination_id', 'host_accommodation_id'], true)) {
            return is_numeric($a) && is_numeric($b) && abs((float) $a - (float) $b) < 0.00000001;
        }
        if ($field === 'is_accredited') {
            return in_array($a, [true, 1, '1', 't', 'true'], true) === in_array($b, [true, 1, '1', 't', 'true'], true);
        }
        if ($field === 'resort_settings') {
            $x = array_values(array_filter(explode('|', (string) $a)));
            $y = array_values(array_filter(explode('|', (string) $b)));
            sort($x);
            sort($y);

            return $x === $y;
        }

        return (string) $a === (string) $b;
    }
}
