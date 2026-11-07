<?php

namespace App\Support;

use App\Models\AccreditationRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a listing's accreditation comes from: an official DOT record, sample data, or nothing we can confirm.
 *
 * A listing is sample data when it has accreditation records and every one of them is a placeholder (the number is
 * ours, not DOT's). The moment a listing gets a real record it stops being sample data, whatever else it still holds.
 * A listing with no accreditation record at all, or only records that carry no accreditation number, is never read as
 * official: it is "unverified". Only a record that is not a placeholder and has a number makes a listing official.
 * This describes provenance only. Sample and unverified listings are only ever labelled: they keep taking part in the
 * planner, swaps, recommendations and Apriori (eligibility is the is_accredited flag, which this class never touches).
 *
 * One small query per request (a few dozen rows at most), then every badge and tag on the page reads from memory.
 * AccreditationRecord clears it when a record is saved or deleted.
 */
class SampleData
{
    public const OFFICIAL = 'official';

    public const SAMPLE = 'sample';

    public const UNVERIFIED = 'unverified';

    /** @var array<string, string>|null  "kind:id" => lower-cased listing name */
    private ?array $sample = null;

    /** @var array<string, string>|null  "kind:id" => official | sample | unverified, for listings that have a record */
    private ?array $statuses = null;

    public function flush(): void
    {
        $this->sample = null;
        $this->statuses = null;
    }

    /** official, sample or unverified. */
    public function status(Model $listing): string
    {
        return $this->statusOf($listing->getMorphClass(), (int) $listing->getKey());
    }

    public function statusOf(string $kind, int $id): string
    {
        // No record at all: nothing supports an accreditation, so it is never read as official.
        return $this->loadStatuses()[$kind.':'.$id] ?? self::UNVERIFIED;
    }

    /**
     * A listing's name as a visitor reads it: without the trailing " [SAMPLE]" the import adds. The stored name keeps
     * the suffix (it is one of the three sample markers, and the planner, imports and chatbot match on it); the
     * "Sample Data" badge, which comes from the accreditation records, is what tells a visitor it is sample data.
     */
    public static function displayName(?string $name): string
    {
        return trim((string) preg_replace('/\s*\[SAMPLE\]\s*$/i', '', (string) $name));
    }

    /** The badge text for a status. */
    public static function label(string $status): string
    {
        return match ($status) {
            self::OFFICIAL => 'DOT Accredited',
            self::SAMPLE => 'Sample Data',
            default => 'Not Yet Verified',
        };
    }

    /**
     * The marker added to a name on plain-text surfaces (map popups, calendar files, CSV, pickers) where an HTML badge
     * cannot go. Empty for an official record. The stored name is never changed: the 7 original sample places (Samal
     * Island, Eden Nature Park, ...) carry no "[SAMPLE]" in their name, and this is what marks them everywhere else.
     */
    public static function suffix(string $status): string
    {
        return match ($status) {
            self::SAMPLE => ' [SAMPLE]',
            self::UNVERIFIED => ' [NOT YET VERIFIED]',
            default => '',
        };
    }

    /** A name with its provenance marker, unless it already carries one. */
    public static function labelName(string $name, string $status): string
    {
        $suffix = self::suffix($status);

        if ($suffix === '' || str_contains(mb_strtolower($name), trim(mb_strtolower($suffix)))) {
            return $name;
        }

        return $name.$suffix;
    }

    /** The listing's name (or $text, when a different wording is wanted) with its provenance marker. */
    public function labelledName(Model $listing, ?string $text = null): string
    {
        return self::labelName($text ?? (string) ($listing->name ?? ''), $this->status($listing));
    }

    /** Same, for a listing known only by kind and id (a report row). */
    public function labelledNameOf(string $kind, int $id, string $name): string
    {
        return self::labelName($name, $this->statusOf($kind, $id));
    }

    /** The sentence behind a badge (its tooltip). */
    public static function title(string $status): string
    {
        return match ($status) {
            self::OFFICIAL => 'Supported by an official DOT accreditation record.',
            self::SAMPLE => 'Sample data, not DOT-verified: a temporary demonstration record whose accreditation number is a placeholder, not an official DOT number.',
            default => 'Not yet verified: there is no DOT accreditation record on file for this listing, so its accreditation cannot be confirmed.',
        };
    }

    public function isSample(Model $listing): bool
    {
        return $this->isSampleKey($listing->getMorphClass(), (int) $listing->getKey());
    }

    public function isSampleKey(string $kind, int $id): bool
    {
        return array_key_exists($kind.':'.$id, $this->load());
    }

    /**
     * How many of the listings a query returns are sample data, so a count that says "accredited" can be split
     * instead of hard-coded.
     *
     * @return array{total: int, sample: int, real: int, unverified: int}  real = official DOT records; unverified = no accreditation on file
     */
    public function split(\Illuminate\Database\Eloquent\Builder $query): array
    {
        $kind = $query->getModel()->getMorphClass();
        $ids = $query->pluck($query->getModel()->getQualifiedKeyName());
        $counts = $ids->countBy(fn ($id) => $this->statusOf($kind, (int) $id));

        return ['total' => $ids->count(), 'sample' => $counts[self::SAMPLE] ?? 0, 'real' => $counts[self::OFFICIAL] ?? 0, 'unverified' => $counts[self::UNVERIFIED] ?? 0];
    }

    /** True when a listing with this name is sample data. For rows that only store the name of the place a rule started from. */
    public function isSampleName(?string $name): bool
    {
        return $name !== null && $name !== '' && in_array(mb_strtolower(trim($name)), $this->load(), true);
    }

    /**
     * @return array<string, string>
     */
    private function loadStatuses(): array
    {
        if ($this->statuses !== null) {
            return $this->statuses;
        }

        $this->statuses = [];
        $placeholders = Schema::hasColumn('accreditation_records', 'is_placeholder');

        $rows = DB::table('accreditation_records')
            ->selectRaw('listing_kind, listing_id, count(*) as total, '
                .($placeholders ? 'sum(case when is_placeholder then 1 else 0 end)' : '0').' as placeholders, '
                .'sum(case when '.($placeholders ? 'not is_placeholder and ' : '')."trim(coalesce(accreditation_number, '')) <> '' then 1 else 0 end) as genuine")
            ->groupBy('listing_kind', 'listing_id')
            ->get();

        foreach ($rows as $row) {
            $this->statuses[$row->listing_kind.':'.$row->listing_id] = (int) $row->genuine > 0
                ? self::OFFICIAL
                : ((int) $row->placeholders === (int) $row->total ? self::SAMPLE : self::UNVERIFIED);
        }

        return $this->statuses;
    }

    /**
     * @return array<string, string>
     */
    private function load(): array
    {
        if ($this->sample !== null) {
            return $this->sample;
        }

        $this->sample = [];

        // The column arrives with a migration; until it has run (a fresh checkout mid-deploy) nothing is sample data.
        if (! Schema::hasColumn('accreditation_records', 'is_placeholder')) {
            return $this->sample;
        }

        $rows = DB::table('accreditation_records')
            ->selectRaw('listing_kind, listing_id, sum(case when is_placeholder then 1 else 0 end) as placeholders, count(*) as total')
            ->groupBy('listing_kind', 'listing_id')
            ->get()
            ->filter(fn ($r) => (int) $r->placeholders > 0 && (int) $r->placeholders === (int) $r->total);

        foreach ($rows as $row) {
            $model = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($row->listing_kind);
            $name = $model ? $model::query()->whereKey($row->listing_id)->value('name') : null;
            $this->sample[$row->listing_kind.':'.$row->listing_id] = mb_strtolower((string) $name);
        }

        return $this->sample;
    }
}
