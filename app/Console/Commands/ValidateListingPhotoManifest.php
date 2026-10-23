<?php

namespace App\Console\Commands;

use App\Support\ListingPhotoManifest;
use Illuminate\Console\Command;

class ValidateListingPhotoManifest extends Command
{
    protected $signature = 'listing-photos:validate {--path= : A folder holding manifest.json and the images (default: database/data/listing-photos)}';

    protected $description = 'Check the listing photo manifest: every entry well formed, every file present, every approved picture carrying its evidence.';

    public function handle(): int
    {
        $manifest = $this->option('path') ? new ListingPhotoManifest((string) $this->option('path')) : ListingPhotoManifest::default();
        $entries = $manifest->entries();
        $problems = $manifest->problems($entries);

        $byStatus = collect($entries)->countBy(fn ($e) => $e['verification_status'] ?? 'missing')->sortKeys();
        $this->line(count($entries).' entries: '.($byStatus->map(fn ($n, $s) => "$s $n")->implode(', ') ?: 'none'));

        if ($problems === []) {
            $this->info('The manifest is sound.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }
        $this->error(count($problems).' problem(s) found.');

        return self::FAILURE;
    }
}
