<?php

namespace App\Console\Commands;

use App\Models\ListingPhoto;
use App\Support\PhotoCardCopy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Makes smaller WebP "card" copies of the listing photographs that are much bigger than a list card needs, so the listing
 * pages load faster on slow connections.
 *
 * Safety: a dry run is the default and shows the real before and after sizes without writing anything. With --apply, only
 * NEW files named "<original>.card.webp" are written next to the originals. An original is never opened for writing, renamed,
 * resized or deleted, and an existing copy is left alone unless --force is given. A copy is only kept when it is smaller than
 * the original. Delete the "*.card.webp" files at any time and the site goes back to using the originals.
 */
class MakePhotoCardCopies extends Command
{
    protected $signature = 'photos:make-card-copies
        {--apply : Write the copies (default is a report only)}
        {--min-kb=300 : Only consider photos larger than this many KB}
        {--width=800 : Widest a copy may be, in pixels}
        {--quality=80 : WebP quality, 1 to 100}
        {--force : Rebuild a copy that already exists}';

    protected $description = 'Make smaller card copies of large listing photos next to the originals (dry run by default).';

    public function handle(): int
    {
        if (! PhotoCardCopy::supported()) {
            $this->error('This PHP cannot write WebP pictures (the GD extension with WebP support is needed).');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $minBytes = max(0, (int) $this->option('min-kb')) * 1000;
        $width = max(100, (int) $this->option('width'));
        $quality = (int) $this->option('quality');
        $force = (bool) $this->option('force');

        $disk = Storage::disk('public');
        $paths = ListingPhoto::query()->whereNotNull('path')->distinct()->pluck('path')->sort()->values();

        $rows = [];
        $made = 0;
        $before = 0;
        $after = 0;
        $skipped = ['small' => 0, 'not a picture we can copy' => 0, 'missing file' => 0, 'copy exists' => 0, 'no saving' => 0, 'unreadable' => 0];

        foreach ($paths as $path) {
            $path = (string) $path;
            $lower = strtolower($path);

            if (PhotoCardCopy::isVariant($path) || ! preg_match('/\.(jpe?g|png)$/', $lower)) {
                $skipped['not a picture we can copy']++;

                continue;
            }
            if (! $disk->exists($path)) {
                $skipped['missing file']++;

                continue;
            }

            $size = $disk->size($path);
            if ($size <= $minBytes) {
                $skipped['small']++;

                continue;
            }

            $variant = PhotoCardCopy::variantPath($path);
            if ($disk->exists($variant) && ! $force) {
                $skipped['copy exists']++;

                continue;
            }

            $bytes = $disk->get($path);
            $webp = PhotoCardCopy::encode($bytes, $width, $quality);
            if ($webp === null) {
                $skipped['unreadable']++;

                continue;
            }

            $copySize = strlen($webp);
            if ($copySize >= $size) {
                $skipped['no saving']++;

                continue;
            }

            $dims = PhotoCardCopy::displaySize($bytes);
            $newDims = @getimagesizefromstring($webp);
            $rows[] = [
                $path,
                $dims ? $dims[0].'x'.$dims[1] : '?',
                $this->kb($size),
                $newDims ? $newDims[0].'x'.$newDims[1] : '?',
                $this->kb($copySize),
                round((1 - $copySize / $size) * 100).'%',
            ];
            $before += $size;
            $after += $copySize;

            if ($apply) {
                $disk->put($variant, $webp);
                $check = @getimagesizefromstring((string) $disk->get($variant));
                if (! $check) {
                    $disk->delete($variant); // never leave a broken copy behind: the card would fall back to the original
                    $this->error("Could not verify the copy of {$path}; it was removed.");

                    return self::FAILURE;
                }
                Log::info('photos:make-card-copies wrote a card copy', ['original' => $path, 'copy' => $variant, 'from' => $size, 'to' => $copySize]);
            }
            $made++;
        }

        $this->table(['Photo', 'Size now', 'Original', 'Copy size', 'Copy', 'Saved'], $rows);
        $this->line('');
        $this->line(($apply ? 'Wrote ' : 'Would write ').$made.' card copies. Originals: '.$this->kb($before).', copies: '.$this->kb($after).($before ? ', saving '.round((1 - $after / max(1, $before)) * 100).'%.' : '.'));
        $this->line('Skipped: '.collect($skipped)->filter()->map(fn ($n, $why) => "{$n} {$why}")->implode(', ').(array_sum($skipped) ? '.' : 'none.'));

        if (! $apply) {
            $this->line('');
            $this->info('Dry run only: nothing was written. Run again with --apply to write the copies.');
        } else {
            $this->info('Done. The originals were not touched.');
        }

        return self::SUCCESS;
    }

    private function kb(int $bytes): string
    {
        return number_format($bytes / 1000, 0).' KB';
    }
}
