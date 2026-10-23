<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Reads and checks database/data/listing-photos/manifest.json: one entry per picture, saying which listing it is
 * for, where it came from, on what basis it may be shown, and how far it has been checked.
 *
 * Only an entry whose verification_status is `approved` is ever imported, and an approved entry must carry its
 * evidence: who gave permission and on what basis, the reference to that evidence, the date, the credit to show,
 * and who checked that the picture really shows that place. Everything else may be listed as work in progress.
 *
 * Nothing here decides that a picture is allowed; it only refuses to treat an entry as approved while the record
 * of why is incomplete.
 */
class ListingPhotoManifest
{
    public function __construct(private readonly string $directory) {}

    public static function default(): self
    {
        return new self(database_path('data/listing-photos'));
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /** @return list<array<string, mixed>> */
    public function entries(): array
    {
        $file = $this->directory.'/manifest.json';

        return is_file($file) ? json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) : [];
    }

    /** @return list<array<string, mixed>> the entries that may be imported, with no check of their files */
    public function approved(): array
    {
        return array_values(array_filter($this->entries(), fn (array $e) => ($e['verification_status'] ?? null) === 'approved'));
    }

    /**
     * Every problem found, as readable lines (an empty list means the manifest is sound).
     *
     * @return list<string>
     */
    public function problems(?array $entries = null): array
    {
        $entries ??= $this->entries();
        $problems = [];
        $files = [];

        foreach ($entries as $i => $e) {
            $problems = array_merge($problems, $this->problemsWith($e, $i + 1));

            $file = $e['file'] ?? null;
            if ($file !== null) {
                if (isset($files[$file])) {
                    $problems[] = $this->label($e, $i + 1)." uses a file that entry {$files[$file]} already uses ({$file})";
                }
                $files[$file] = $i + 1;
            }
        }

        return $problems;
    }

    /**
     * Problems with one entry. An approved entry is checked far more strictly than one still being worked on.
     *
     * @param  array<string, mixed>  $e
     * @return list<string>
     */
    public function problemsWith(array $e, int $position = 0): array
    {
        $label = $this->label($e, $position);
        $problems = [];
        $status = $e['verification_status'] ?? null;

        if (! in_array($e['kind'] ?? null, config('listing_photos.listing_kinds'), true)) {
            $problems[] = "$label: kind must be one of ".implode(', ', config('listing_photos.listing_kinds'));
        }
        if (! isset($e['id']) || ! is_numeric($e['id'])) {
            $problems[] = "$label: id is missing";
        }
        if (blank($e['name'] ?? null)) {
            $problems[] = "$label: name is missing";
        }
        if (! in_array($status, config('listing_photos.statuses'), true)) {
            $problems[] = "$label: verification_status must be one of ".implode(', ', config('listing_photos.statuses'));
        }
        if (! in_array($e['source_type'] ?? null, config('listing_photos.source_types'), true)) {
            $problems[] = "$label: source_type must be one of ".implode(', ', config('listing_photos.source_types'));
        }

        $path = blank($e['file'] ?? null) ? null : $this->directory.'/'.$e['file'];
        if ($path === null) {
            $problems[] = "$label: file is missing";
        } elseif (! is_file($path)) {
            $problems[] = "$label: file {$e['file']} is not in the folder";
        } elseif (isset($e['sha256']) && hash_file('sha256', $path) !== $e['sha256']) {
            $problems[] = "$label: the file {$e['file']} has changed since its sha256 was recorded";
        }

        if ($status !== 'approved') {
            return $problems;
        }

        if (! in_array($e['permission_basis'] ?? null, config('listing_photos.permission_bases'), true)) {
            $problems[] = "$label: an approved picture needs permission_basis (".implode(', ', config('listing_photos.permission_bases')).')';
        }
        foreach (['permission_ref' => 'a reference to the permission evidence', 'credit_text' => 'the credit to show', 'verified_by' => 'who checked that it shows this place'] as $key => $why) {
            if (blank($e[$key] ?? null)) {
                $problems[] = "$label: an approved picture needs $key ($why)";
            }
        }
        if (! $this->isDate($e['permission_date'] ?? null)) {
            $problems[] = "$label: an approved picture needs permission_date as YYYY-MM-DD";
        }
        if (! $this->isDate($e['verified_at'] ?? null)) {
            $problems[] = "$label: an approved picture needs verified_at as YYYY-MM-DD";
        }
        if (blank($e['source_url'] ?? null) && ! in_array($e['source_type'] ?? null, ['own_photo', 'establishment_supplied'], true)) {
            $problems[] = "$label: an approved picture from another source needs source_url";
        }

        return $problems;
    }

    private function label(array $e, int $position): string
    {
        return 'Entry '.($position ?: '?').' ('.($e['kind'] ?? '?').' '.($e['id'] ?? '?').' '.($e['name'] ?? '').')';
    }

    private function isDate(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->format('Y-m-d') === $value;
        } catch (\Throwable) {
            return false;
        }
    }
}
