<?php

namespace Database\Seeders;

/**
 * Imports the 2026-11 Wikimedia Commons batch (database/data/listing-photos/commons-2026-11): pictures that carry an open
 * licence (CC BY or CC BY-SA), each shown with its photographer's credit and licence.
 *
 * It works exactly like DriveBatchPhotoSeeder (listings found by exact name, never by guess; a name that matches nothing
 * or more than one listing is skipped and reported; an existing real photo stays primary; safe to run repeatedly), with
 * one difference in how a name is looked up. The development data and the live site do not always spell a name the same
 * way (some carry a trailing " [SAMPLE]"), so a name is tried as written first, then with and without that suffix.
 */
class CommonsBatchPhotoSeeder extends DriveBatchPhotoSeeder
{
    protected function defaultDirectory(): string
    {
        return database_path('data/listing-photos/commons-2026-11');
    }

    protected function storageDirectory(): string
    {
        return 'listings/commons-2026-11';
    }

    protected function namesFor(array $entry): array
    {
        $name = (string) $entry['name'];
        $plain = trim((string) preg_replace('/\s*\[SAMPLE\]\s*$/i', '', $name));

        return array_values(array_unique([$name, $plain, $plain.' [SAMPLE]']));
    }
}
