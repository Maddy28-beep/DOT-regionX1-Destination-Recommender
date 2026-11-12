<?php

namespace App\Console\Commands;

use App\Support\Features;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Turn a feature switch on or off on the server without a deploy: php artisan features:set trip_location_v2 off */
class FeatureFlag extends Command
{
    protected $signature = 'features:set {name? : the switch} {state? : on, off or default (remove the override)}';

    protected $description = 'Show feature switches, or turn one on or off without a deploy';

    public function handle(): int
    {
        $name = $this->argument('name');

        if ($name === null) {
            foreach (Features::known() as $known) {
                $this->line(sprintf('%-22s %s', $known, Features::on($known) ? 'ON' : 'off'));
            }

            return self::SUCCESS;
        }

        if (! in_array($name, Features::known(), true)) {
            $this->error("Unknown switch '{$name}'. Known: ".implode(', ', Features::known()));

            return self::FAILURE;
        }

        $state = strtolower((string) $this->argument('state'));
        if (! in_array($state, ['on', 'off', 'default'], true)) {
            $this->error("Say 'on', 'off' or 'default'.");

            return self::FAILURE;
        }

        if ($state === 'default') {
            DB::table('feature_flags')->where('name', $name)->delete();
        } else {
            DB::table('feature_flags')->updateOrInsert(['name' => $name], ['enabled' => $state === 'on', 'updated_at' => now()]);
        }

        $this->info("{$name} is now ".(Features::on($name) ? 'ON' : 'off').'.');

        return self::SUCCESS;
    }
}
