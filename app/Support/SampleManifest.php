<?php

namespace App\Support;

use App\Models\AccreditationRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * The durable record of one sample-import batch: exactly which listings an import added, so a
 * rollback can remove those and only those.
 *
 * A manifest is a JSON file (listing kind, id, slug, name and SAMPLE number per record) with a
 * SHA-256 over its canonical entries. It lives OUTSIDE the database on purpose: the nightly backup
 * backs up the database and uploaded files, and this file is neither, so nothing may assume the
 * backup contains it. Capture copies it to a caller-chosen second location (off-server) and
 * verifies that copy's hash before calling the manifest durable.
 */
final class SampleManifest
{
    public const KINDS = ['destination', 'accommodation', 'restaurant', 'souvenir_center'];

    /** @return array<int, array{kind: string, id: int, slug: string, name: string, number: string}> */
    public function entriesForRange(string $fromNumber, string $toNumber): array
    {
        $from = self::numberValue($fromNumber);
        $to = self::numberValue($toNumber);

        return AccreditationRecord::where('is_placeholder', true)
            ->where('accreditation_number', 'like', 'SAMPLE-%')
            ->get()
            ->filter(fn (AccreditationRecord $r) => ($n = self::numberValue($r->accreditation_number)) !== null && $n >= $from && $n <= $to)
            ->map(function (AccreditationRecord $r) {
                /** @var Model|null $listing */
                $listing = $r->listing;

                return $listing === null ? null : [
                    'kind' => $r->listing_kind,
                    'id' => $listing->getKey(),
                    'slug' => (string) $listing->slug,
                    'name' => (string) $listing->name,
                    'number' => $r->accreditation_number,
                ];
            })
            ->filter()
            ->sortBy(fn (array $e) => self::numberValue($e['number']))
            ->values()
            ->all();
    }

    /** Write the manifest file and return its SHA-256 (over the canonical entry list). */
    public function write(string $path, array $entries): string
    {
        $entries = self::canonical($entries);
        $hash = self::hash($entries);
        $payload = json_encode([
            'format' => 1,
            'generated_at' => now()->toIso8601String(),
            'count' => count($entries),
            'sha256' => $hash,
            'entries' => $entries,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($path, $payload) === false) {
            throw new \RuntimeException("Could not write manifest: {$path}");
        }

        return $hash;
    }

    /**
     * Copy a manifest to a second location and prove the copy is byte-identical in content hash.
     * This is the off-server durability step: the caller picks the destination (a synced folder,
     * a mounted backup drive); this class only guarantees the copy matches what was captured.
     */
    public function copyVerified(string $fromPath, string $toPath): string
    {
        $check = $this->verifyFile($fromPath);
        if (! $check['ok']) {
            throw new \RuntimeException('Source manifest does not verify: '.implode('; ', $check['errors']));
        }
        if (! copy($fromPath, $toPath)) {
            throw new \RuntimeException("Could not copy manifest to: {$toPath}");
        }
        $copyCheck = $this->verifyFile($toPath);
        if (! $copyCheck['ok'] || $copyCheck['sha256'] !== $check['sha256']) {
            throw new \RuntimeException('Copied manifest does not match the source hash.');
        }

        return $copyCheck['sha256'];
    }

    /** @return array{ok: bool, sha256: ?string, count: int, errors: array<int, string>} */
    public function verifyFile(string $path): array
    {
        if (! is_file($path)) {
            return ['ok' => false, 'sha256' => null, 'count' => 0, 'errors' => ["manifest file not found: {$path}"]];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || ! isset($decoded['entries'], $decoded['sha256'], $decoded['count'])) {
            return ['ok' => false, 'sha256' => null, 'count' => 0, 'errors' => ['manifest file is not a valid sample manifest']];
        }
        $entries = self::canonical($decoded['entries']);
        $errors = [];
        if (self::hash($entries) !== $decoded['sha256']) {
            $errors[] = 'SHA-256 mismatch: the file does not match its recorded hash (tampered or truncated)';
        }
        if (count($entries) !== (int) $decoded['count']) {
            $errors[] = 'entry count does not match the recorded count';
        }
        $numbers = array_map(fn (array $e) => self::numberValue($e['number']), $entries);
        if (count($numbers) !== count(array_unique($numbers))) {
            $errors[] = 'duplicate SAMPLE numbers in manifest';
        }
        sort($numbers);
        foreach (array_keys($numbers) as $i) {
            if ($i > 0 && $numbers[$i] !== $numbers[$i - 1] + 1) {
                $errors[] = 'SAMPLE numbers are not contiguous';
                break;
            }
        }

        return ['ok' => $errors === [], 'sha256' => $decoded['sha256'], 'count' => count($entries), 'errors' => $errors];
    }

    /**
     * File integrity plus database agreement: every entry must still resolve to the same listing
     * (kind, id, slug, name) holding the same placeholder SAMPLE number, and nothing in the range
     * may be missing from the file.
     *
     * @return array{ok: bool, sha256: ?string, count: int, errors: array<int, string>}
     */
    public function verifyAgainstDatabase(string $path): array
    {
        $check = $this->verifyFile($path);
        if (! $check['ok']) {
            return $check;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        $entries = self::canonical($decoded['entries']);
        $errors = $check['errors'];

        foreach ($entries as $e) {
            $record = AccreditationRecord::where('accreditation_number', $e['number'])->where('is_placeholder', true)->first();
            $listing = $record?->listing;
            if ($record === null || $listing === null || $record->listing_kind !== $e['kind'] || $listing->getKey() !== $e['id']
                || (string) $listing->slug !== $e['slug'] || (string) $listing->name !== $e['name']) {
                $errors[] = "entry {$e['number']} ({$e['kind']} #{$e['id']}) no longer matches the database";
            }
        }
        if ($entries !== []) {
            $live = $this->entriesForRange($entries[0]['number'], $entries[count($entries) - 1]['number']);
            if (count($live) !== count($entries)) {
                $errors[] = 'database holds '.count($live).' sample records in the manifest range, the file holds '.count($entries);
            }
        }

        return ['ok' => $errors === [], 'sha256' => $check['sha256'], 'count' => $check['count'], 'errors' => $errors];
    }

    public static function numberValue(?string $number): ?int
    {
        return $number !== null && preg_match('/^SAMPLE-(\d+)$/', $number, $m) ? (int) $m[1] : null;
    }

    /** @param array<int, array{kind: string, id: int, slug: string, name: string, number: string}> $entries */
    private static function canonical(array $entries): array
    {
        $entries = array_map(fn (array $e) => [
            'kind' => (string) $e['kind'], 'id' => (int) $e['id'], 'slug' => (string) $e['slug'],
            'name' => (string) $e['name'], 'number' => (string) $e['number'],
        ], $entries);
        usort($entries, fn (array $a, array $b) => self::numberValue($a['number']) <=> self::numberValue($b['number']));

        return $entries;
    }

    private static function hash(array $canonicalEntries): string
    {
        return hash('sha256', json_encode($canonicalEntries, JSON_UNESCAPED_SLASHES));
    }
}
