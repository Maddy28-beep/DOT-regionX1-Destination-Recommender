<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Official-only mode (config itinerary.official_only, env ITINERARY_OFFICIAL_ONLY, default off).
 *
 * Off, apply() returns the query untouched, so sample and unverified listings take part in planning, swaps, suggestions and
 * the chatbot exactly as before (the recommendation code never reads provenance: it is only a label). On, every candidate
 * query that passes through here is narrowed to listings holding a genuine DOT accreditation record: a record that is not
 * a placeholder and carries an accreditation number, the same rule SampleData uses to call a listing "official".
 *
 * The engines call only this one switch; the provenance rule lives here, not in them. Browsing pages are not affected.
 */
final class OfficialOnlyMode
{
    public static function enabled(): bool
    {
        return (bool) config('itinerary.official_only');
    }

    public static function apply(Builder $query): Builder
    {
        if (! self::enabled()) {
            return $query;
        }

        $model = $query->getModel();

        return $query->whereExists(fn ($sub) => $sub->selectRaw('1')->from('accreditation_records as ar')
            ->where('ar.listing_kind', $model->getMorphClass())
            ->whereColumn('ar.listing_id', $model->getQualifiedKeyName())
            ->where('ar.is_placeholder', false)
            ->whereRaw("trim(coalesce(ar.accreditation_number, '')) <> ''"));
    }
}
