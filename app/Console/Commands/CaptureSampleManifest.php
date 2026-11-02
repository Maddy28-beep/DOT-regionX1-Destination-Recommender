<?php

namespace App\Console\Commands;

use App\Support\SampleManifest;
use Illuminate\Console\Command;

/**
 * Captures (or verifies) the durable rollback manifest for one imported sample batch.
 *
 * The manifest is a file, not a database row: the nightly backup covers the database and uploaded
 * files, and this file is neither, so durability is an explicit step, never an assumption. Capture
 * writes the file, verifies it against the database, and -- with --copy-to=<dir> -- copies it to a
 * second location (an off-server folder chosen by the operator) and proves the copy's hash.
 *
 *   php artisan sample:manifest --from-number=SAMPLE-0019 --to-number=SAMPLE-0148 --write=storage/app/import-manifests/sample-130.json --copy-to=D:\Backups\ExploreDVO
 *   php artisan sample:manifest --verify=storage/app/import-manifests/sample-130.json
 */
class CaptureSampleManifest extends Command
{
    protected $signature = 'sample:manifest
        {--from-number= : First SAMPLE number of the batch (capture)}
        {--to-number= : Last SAMPLE number of the batch (capture)}
        {--write= : Path to write the manifest to (capture)}
        {--copy-to= : Directory to copy the verified manifest to (off-server durability)}
        {--verify= : Path of a manifest file to verify (integrity + database agreement)}';

    protected $description = 'Capture or verify the durable rollback manifest for an imported sample batch.';

    public function handle(SampleManifest $manifest): int
    {
        if ($path = $this->option('verify')) {
            $check = $manifest->verifyAgainstDatabase((string) $path);
            foreach ($check['errors'] as $error) {
                $this->error($error);
            }
            if (! $check['ok']) {
                return self::FAILURE;
            }
            $this->info("Manifest OK: {$check['count']} records, sha256 {$check['sha256']}");

            return self::SUCCESS;
        }

        $from = (string) $this->option('from-number');
        $to = (string) $this->option('to-number');
        $write = (string) $this->option('write');
        if (SampleManifest::numberValue($from) === null || SampleManifest::numberValue($to) === null || $write === '') {
            $this->error('Capture needs --from-number=SAMPLE-####, --to-number=SAMPLE-#### and --write=<path>.');

            return self::FAILURE;
        }

        $entries = $manifest->entriesForRange($from, $to);
        if ($entries === []) {
            $this->error("No placeholder sample records found in {$from}..{$to}. Nothing to capture.");

            return self::FAILURE;
        }

        $dir = dirname($write);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $hash = $manifest->write($write, $entries);
        $check = $manifest->verifyAgainstDatabase($write);
        if (! $check['ok']) {
            foreach ($check['errors'] as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $this->info('Captured '.count($entries)." records ({$from}..{$to}) to {$write}, sha256 {$hash}");

        if ($copyTo = $this->option('copy-to')) {
            if (! is_dir((string) $copyTo)) {
                mkdir((string) $copyTo, 0775, true);
            }
            $target = rtrim((string) $copyTo, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.basename($write);
            $copyHash = $manifest->copyVerified($write, $target);
            $this->info("Copied to {$target} and verified, sha256 {$copyHash}");
            $this->warn('The manifest is a file: the nightly database/uploads backup does NOT include it. Keep the copied file with the backup set.');
        } else {
            $this->warn('No --copy-to given: the manifest exists only at the path above and is NOT covered by the nightly backup. Copy it off-server before relying on it for rollback.');
        }

        return self::SUCCESS;
    }
}
