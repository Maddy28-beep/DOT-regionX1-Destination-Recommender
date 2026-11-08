<?php

namespace App\Console\Commands;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\EstablishmentAccount;
use App\Models\Package;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use App\Support\SampleData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Creates one partner (establishment) account that is already approved and already linked to its listing, so a person
 * with access to the server can skip the registration form and the admin's approve-and-link steps.
 *
 *   php artisan partners:create accommodation paradise-island-park-beach-resort owner@example.com   # look only
 *   php artisan partners:create accommodation paradise-island-park-beach-resort owner@example.com --apply
 *
 * The password is never an argument or an option (it would sit in the shell history and the process list): it is typed at
 * a hidden prompt, read from an environment variable the caller names with --password-env (so one hidden entry can serve
 * several accounts: `read -s PW; export PW`, then --password-env=PW), or made up by --generate and shown once on the
 * screen. Without --apply nothing is written and no password is asked for.
 *
 * Safe by design:
 *  - a partner account can edit the listing it is linked to, so a listing that is real DOT data (not sample data) is
 *    refused unless --allow-real is given, which should only follow a request from that business;
 *  - one e-mail address is one account (any letter case), and a listing already linked to an account is refused;
 *  - it only adds a row. It never changes or removes an account, a listing or anything else, and it writes the password
 *    only as the same hash the registration form stores.
 */
class CreatePartnerAccount extends Command
{
    protected $signature = 'partners:create
        {kind : destination, accommodation, restaurant, package, souvenir_center or tour_operator}
        {listing : the listing\'s slug (the last part of its web address)}
        {email : the sign-in e-mail address}
        {--name= : business name to show (default: the listing\'s own name)}
        {--person=Test Contact : contact person}
        {--phone=09000000000 : contact number}
        {--generate : make up a strong password and show it once, instead of asking for one}
        {--password-env= : the NAME of an environment variable that holds the password (never the password itself)}
        {--allow-real : allow a listing that is real DOT data, not sample data}
        {--apply : create the account (default is a report only)}';

    protected $description = 'Create an approved partner account linked to a listing, from the server (dry run by default; the password is never an argument).';

    private const KINDS = [
        'destination' => Destination::class,
        'accommodation' => Accommodation::class,
        'restaurant' => Restaurant::class,
        'package' => Package::class,
        'souvenir_center' => SouvenirCenter::class,
        'tour_operator' => TourOperator::class,
    ];

    public function handle(SampleData $sampleData): int
    {
        $kind = (string) $this->argument('kind');
        $slug = (string) $this->argument('listing');
        $email = trim((string) $this->argument('email'));

        $class = self::KINDS[$kind] ?? null;
        if ($class === null) {
            $this->error("kind must be one of: ".implode(', ', array_keys(self::KINDS)));

            return self::FAILURE;
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) {
            $this->error('Give a valid e-mail address (up to 100 characters).');

            return self::FAILURE;
        }
        if (EstablishmentAccount::whereRaw('lower(email) = ?', [mb_strtolower($email)])->exists()) {
            $this->error('That e-mail address is already registered. Nothing changed.');

            return self::FAILURE;
        }

        $listing = $class::where('slug', $slug)->first();
        if (! $listing) {
            $this->error("No {$kind} with the slug '{$slug}'. Nothing changed.");

            return self::FAILURE;
        }

        $already = EstablishmentAccount::where('listing_kind', $kind)->where('matched_listing_id', $listing->id)->first();
        if ($already) {
            $this->error("{$listing->name} is already linked to the account {$already->email}. Nothing changed.");

            return self::FAILURE;
        }

        $isSample = $sampleData->status($listing) === SampleData::SAMPLE;
        if (! $isSample && ! $this->option('allow-real')) {
            $this->error("{$listing->name} is real DOT data, not sample data. A partner account can edit its listing, so this is refused. "
                .'Add --allow-real only if that business asked for access.');

            return self::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?: $listing->name));
        $this->table(['what', 'value'], [
            ['listing', "{$kind} #{$listing->id}: {$listing->name}".($isSample ? ' (sample data)' : ' (REAL DOT data)')],
            ['business name', $name],
            ['sign-in e-mail', $email],
            ['contact', $this->option('person').', '.$this->option('phone')],
            ['status', 'approved and linked'],
            ['password', $this->option('generate') ? 'will be generated and shown once' : ($this->option('password-env') ? 'read from the environment variable '.$this->option('password-env') : 'will be asked for, hidden')],
        ]);

        if (! $this->option('apply')) {
            $this->info('Dry run: nothing written. Add --apply to create this account.');

            return self::SUCCESS;
        }

        $shown = null;
        if ($this->option('password-env')) {
            $name = (string) $this->option('password-env');
            $password = (string) (getenv($name) ?: '');
            if (mb_strlen($password) < 8) {
                $this->error("The environment variable {$name} is empty or shorter than 8 characters. Nothing changed.");

                return self::FAILURE;
            }
        } elseif ($this->option('generate')) {
            $password = Str::password(16, symbols: false);
            $shown = $password;
        } else {
            $password = (string) $this->secret('Password (at least 8 characters, typed hidden)');
            if (mb_strlen($password) < 8) {
                $this->error('The password must be at least 8 characters. Nothing changed.');

                return self::FAILURE;
            }
            if ($password !== (string) $this->secret('Type it again')) {
                $this->error('The two passwords do not match. Nothing changed.');

                return self::FAILURE;
            }
        }

        $account = EstablishmentAccount::create([
            'business_name' => mb_substr($name, 0, 150),
            'listing_kind' => $kind,
            'matched_listing_id' => $listing->id,
            'portal_key' => (string) Str::uuid(),
            'email' => $email,
            'password_hash' => Hash::make($password),
            'contact_person' => mb_substr((string) $this->option('person'), 0, 100),
            'contact_number' => mb_substr((string) $this->option('phone'), 0, 20),
            'status' => 'approved',
            'submitted_at' => now(),
            'reviewed_at' => now(),
            'review_note' => 'Created and approved from the server (partners:create).',
        ]);

        // The e-mail and the listing, never the password.
        Log::info('partners:create', ['account_id' => $account->id, 'listing_kind' => $kind, 'listing_id' => $listing->id, 'email' => $email, 'sample' => $isSample]);

        $this->info("Created and approved: {$email} for {$listing->name}. Sign in at /portal/login (establishment).");
        if ($shown !== null) {
            $this->warn("Password (shown only now, copy it): {$shown}");
        }

        return self::SUCCESS;
    }
}
