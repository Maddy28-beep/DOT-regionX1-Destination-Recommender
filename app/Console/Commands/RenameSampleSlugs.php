<?php

namespace App\Console\Commands;

use App\Models\Destination;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Drops the "-sample" ending from the address of real places ("oak-island-sample" becomes "oak-island"). The old address keeps
 * working: it is remembered in destination_slug_redirects and sent on to the new one with a permanent redirect.
 *
 * Only real places are renamed. The made-up "Sample ..." destinations keep their address, so nothing invented ever looks like a
 * real place. A new address that is already used by another destination is left alone and reported. A dry run is the default.
 *
 * Run the photo batches (stays:apply-photos, destinations:apply-photos) first or after: both find a destination by its old or
 * its new address. The planner, imports and chatbot match on the stored name, which this does not change.
 */
class RenameSampleSlugs extends Command
{
    protected $signature = 'destinations:rename-sample-slugs {--apply : Write the changes (default is a report only)} {--undo : Put the old addresses back}';

    protected $description = 'Drop the "-sample" ending from the address of real destinations, keeping the old address as a redirect.';

    public function handle(): int
    {
        return $this->option('undo') ? $this->undo() : $this->rename();
    }

    private function rename(): int
    {
        $taken = array_flip(Destination::pluck('slug')->all());
        $taken += array_flip(DB::table('destination_slug_redirects')->pluck('old_slug')->all());

        $plan = [];
        $rows = [];
        foreach (Destination::where('slug', 'like', '%-sample')->where('name', 'not like', 'Sample %')->orderBy('id')->get() as $destination) {
            $new = (string) preg_replace('/-sample$/', '', $destination->slug);
            if ($new === '' || isset($taken[$new])) {
                $rows[] = [$destination->id, $destination->slug, '', 'kept: the new address is empty or already used'];

                continue;
            }
            $taken[$new] = true;
            $plan[] = ['destination' => $destination, 'old' => $destination->slug, 'new' => $new];
            $rows[] = [$destination->id, $destination->slug, $new, 'will rename'];
        }

        $this->table(['id', 'address now', 'new address', 'result'], $rows);
        $this->line(count($plan).' to rename, '.(count($rows) - count($plan)).' kept as they are.');

        if (! $this->option('apply')) {
            $this->info('Dry run: nothing written. Add --apply to make these changes.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan) {
            foreach ($plan as $p) {
                DB::table('destinations')->where('id', $p['destination']->id)->update(['slug' => $p['new']]);
                DB::table('destination_slug_redirects')->updateOrInsert(
                    ['old_slug' => $p['old']],
                    ['destination_id' => $p['destination']->id, 'created_at' => now(), 'updated_at' => now()]
                );
                Log::info('destinations:rename-sample-slugs', ['id' => $p['destination']->id, 'from' => $p['old'], 'to' => $p['new']]);
            }
        });

        $this->info('Renamed '.count($plan).' addresses. The old ones redirect to the new ones.');

        return self::SUCCESS;
    }

    private function undo(): int
    {
        $redirects = DB::table('destination_slug_redirects')->get();
        $back = 0;
        foreach ($redirects as $redirect) {
            $clash = Destination::where('slug', $redirect->old_slug)->where('id', '!=', $redirect->destination_id)->exists();
            $this->line("#{$redirect->destination_id}: {$redirect->old_slug}".($clash ? ' (taken by another destination, skipped)' : ''));
            if ($clash || ! $this->option('apply')) {
                continue;
            }
            DB::transaction(function () use ($redirect) {
                DB::table('destinations')->where('id', $redirect->destination_id)->update(['slug' => $redirect->old_slug]);
                DB::table('destination_slug_redirects')->where('id', $redirect->id)->delete();
            });
            $back++;
        }
        $this->info($this->option('apply') ? "Put {$back} old addresses back." : 'Dry run: nothing written. Add --apply to put them back.');

        return self::SUCCESS;
    }
}
