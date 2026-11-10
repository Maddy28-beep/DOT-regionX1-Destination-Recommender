<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rows that point (listing_kind + listing_id, no database foreign key) at a listing that was deleted from its own table.
 * They show up as "Removed listing" in the admin overview. Reports by default; removes only with --delete.
 */
class ListingOrphans extends Command
{
    protected $signature = 'listings:orphans {--delete : Remove the orphaned rows (default: only report them)}';

    protected $description = 'Count (and optionally remove) rows that point at a listing that no longer exists.';

    /** listing_kind value => table that holds the listing. */
    public const KINDS = [
        'destination' => 'destinations', 'accommodation' => 'accommodations', 'restaurant' => 'restaurants',
        'package' => 'packages', 'souvenir_center' => 'souvenir_centers', 'tour_operator' => 'tour_operators',
    ];

    /** Tables that carry listing_kind + listing_id. Reviews and accreditation records are history of the listing itself. */
    public const DEPENDENTS = [
        'tourist_visits', 'saved_listings', 'reviews', 'listing_photos', 'promotions', 'advisories',
        'accreditation_records', 'exit_survey_visits', 'tourist_saved_destinations', 'listing_amenities',
    ];

    public function handle(): int
    {
        $rows = [];
        $total = 0;
        foreach (self::DEPENDENTS as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $orphans = $this->orphans($table);
            $count = (clone $orphans)->count();
            $rows[] = [$table, DB::table($table)->count(), $count];
            $total += $count;
            if ($count > 0 && $this->option('delete')) {
                $orphans->delete();
            }
        }
        $this->table(['Table', 'Rows', $this->option('delete') ? 'Orphans removed' : 'Orphans found'], $rows);
        $this->info($total === 0 ? 'No orphans.' : ($this->option('delete') ? "$total removed." : "$total found; nothing removed (use --delete)."));

        return self::SUCCESS;
    }

    private function orphans(string $table)
    {
        $q = DB::table($table)->whereNotNull('listing_kind')->where(function ($w) use ($table) {
            foreach (self::KINDS as $kind => $listings) {
                $w->orWhere(fn ($k) => $k->where("$table.listing_kind", $kind)
                    ->whereNotExists(fn ($e) => $e->select(DB::raw(1))->from($listings)->whereColumn("$listings.id", "$table.listing_id")));
            }
            // A kind this application does not know can never resolve to a listing either.
            $w->orWhereNotIn("$table.listing_kind", array_keys(self::KINDS));
        });

        return $q;
    }
}
