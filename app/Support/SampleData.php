<?php

namespace App\Support;

use App\Models\AccreditationRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which listings are sample data.
 *
 * A listing is sample data when it has accreditation records and every one of them is a placeholder (the number is
 * ours, not DOT's). The moment a listing gets a real record it stops being sample data, whatever else it still holds.
 * Sample data is only ever labelled: it keeps taking part in the planner, swaps, recommendations and Apriori.
 *
 * One small query per request (a few dozen rows at most), then every badge and tag on the page reads from memory.
 * AccreditationRecord clears it when a record is saved or deleted.
 */
class SampleData
{
    /** @var array<string, string>|null  "kind:id" => lower-cased listing name */
    private ?array $sample = null;

    public function flush(): void
    {
        $this->sample = null;
    }

    public function isSample(Model $listing): bool
    {
        return $this->isSampleKey($listing->getMorphClass(), (int) $listing->getKey());
    }

    public function isSampleKey(string $kind, int $id): bool
    {
        return array_key_exists($kind.':'.$id, $this->load());
    }

    /** True when a listing with this name is sample data. For rows that only store the name of the place a rule started from. */
    public function isSampleName(?string $name): bool
    {
        return $name !== null && $name !== '' && in_array(mb_strtolower(trim($name)), $this->load(), true);
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
