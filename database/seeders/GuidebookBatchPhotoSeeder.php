<?php

namespace Database\Seeders;

/**
 * Imports the pictures of the DOT Region XI Davao MICE Guidebook 2024 (database/data/listing-photos/guidebook-2026-11):
 * one picture for each hotel and resort in its "Hotels, Resorts, and Venues" section, matched to the stay by the heading
 * printed under the picture.
 *
 * It works exactly like CommonsBatchPhotoSeeder: a stay is found by exact name (tried as written, then with and without
 * a trailing " [SAMPLE]"), a name that matches nothing or more than one stay is skipped and reported, an existing real
 * photo stays primary, and it is safe to run repeatedly.
 */
class GuidebookBatchPhotoSeeder extends CommonsBatchPhotoSeeder
{
    protected function defaultDirectory(): string
    {
        return database_path('data/listing-photos/guidebook-2026-11');
    }

    protected function storageDirectory(): string
    {
        return 'listings/guidebook-2026-11';
    }
}
