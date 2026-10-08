<?php

namespace App\Providers;

use App\Models\Accommodation;
use App\Models\AdminUser;
use App\Http\Middleware\EnsureVisitorToken;
use App\Models\Advisory;
use App\Models\Itinerary;
use App\Models\Destination;
use App\Models\EstablishmentAccount;
use App\Models\Package;
use App\Models\Restaurant;
use App\Models\SavedListing;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use App\Models\TouristSavedDestination;
use Illuminate\Database\Eloquent\Relations\Relation;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Event;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Failed;
use App\Support\Toast;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Audit trail for the DOT Admin console: who signed in, who tried and failed, who signed out.
        $audit = function (string $adminId, string $action) {
            try {
                app(AuditLogger::class)->record($adminId, $action, 'admin_users', $adminId, 'from '.request()->ip());
            } catch (\Throwable $e) {
                report($e); // never let the log stop a sign-in
            }
        };
        Event::listen(Login::class, fn (Login $e) => $e->guard === 'admin' ? $audit((string) $e->user->getAuthIdentifier(), 'login') : null);
        Event::listen(Logout::class, fn (Logout $e) => $e->guard === 'admin' && $e->user ? $audit((string) $e->user->getAuthIdentifier(), 'logout') : null);
        // A wrong password for a real admin account (nothing is recorded for an unknown email: there is no admin to attach it to).
        Event::listen(Failed::class, fn (Failed $e) => $e->guard === 'admin' && $e->user ? $audit((string) $e->user->getAuthIdentifier(), 'login_failed') : null);

        /*
         * Rate limits. Without them anyone could try thousands of passwords a minute
         * against the admin or partner login, or hammer the trip builder (each call
         * runs the whole recommendation pipeline) until a small server stops answering.
         * Limits are per visitor address and deliberately generous: a DOT event kiosk or
         * a school network can put many real tourists behind one address.
         *
         * Over the limit, a browser gets a plain "wait a moment" message on the page it
         * came from; a script gets a 429 with Retry-After.
         */
        $tooMany = function (Request $request, array $headers) {
            $seconds = (int) ($headers['Retry-After'] ?? 60);
            $detail = 'Too many tries in a short time. Please wait '.max(1, $seconds).' seconds and try again.';

            return $request->expectsJson()
                ? response()->json(['message' => $detail], 429, $headers)
                : back()->withInput($request->except(['password', 'password_confirmation']))
                    ->with(Toast::error('Please slow down', $detail));
        };

        // Sign-in: 5 tries a minute for one account from one address, 30 a minute from one address in total.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by('login:'.$request->ip().'|'.Str::lower((string) ($request->input('identifier') ?? $request->input('alias') ?? $request->input('email') ?? '')))->response($tooMany),
            Limit::perMinute(30)->by('login-ip:'.$request->ip())->response($tooMany),
        ]);

        // New accounts: 10 an hour from one address.
        RateLimiter::for('register', fn (Request $request) => Limit::perHour(10)->by('register:'.$request->ip())->response($tooMany));

        // Anything that runs the recommendation pipeline (build, regenerate, swap, save).
        RateLimiter::for('trip-build', fn (Request $request) => Limit::perMinute(30)->by('trip:'.$request->ip())->response($tooMany));

        // Heart / unheart buttons.
        RateLimiter::for('toggle', fn (Request $request) => Limit::perMinute(60)->by('toggle:'.$request->ip())->response($tooMany));

        // listing_kind values used by accreditation_records, reviews,
        // tourist_visits, establishment_accounts.matched_listing_id
        // user_type values used by notifications
        Relation::enforceMorphMap([
            'destination' => Destination::class,
            'accommodation' => Accommodation::class,
            'restaurant' => Restaurant::class,
            'souvenir_center' => SouvenirCenter::class,
            'package' => Package::class,
            'tour_operator' => TourOperator::class,
            'admin' => AdminUser::class,
            'establishment' => EstablishmentAccount::class,
        ]);

        View::composer('layouts.establishment', function ($view) {
            $establishment = Auth::guard('establishment')->user();
            $listing = $establishment?->matchedListing;

            $view->with('navNotifications', $establishment ? $establishment->notifications()->latest()->limit(5)->get() : collect());
            $view->with('navUnreadCount', $establishment ? $establishment->notifications()->where('is_read', false)->count() : 0);

            // Sidebar count badge — same "needs your attention" signal the
            // Overview panel shows, surfaced on every page so it isn't missed.
            $view->with('navUnrepliedCount', $listing ? $listing->reviews()->whereNull('owner_reply')->count() : 0);

            /*
             * Portal-wide alert bar. Accreditation lapsing affects every page,
             * not just Overview, so it is composed here rather than yielded per
             * view. Visibility comes from the same source of truth the Overview
             * cards use: scopePubliclyVisible() is is_accredited AND not
             * archived -- never the AccreditationRecord status string, which is
             * a separate human-maintained field and can disagree.
             */
            $view->with('navStatus', $establishment?->portalStatus());
        });

        /*
         * The single most urgent active advisory, shown as the site-wide
         * sticky ribbon above the header on every public page. Prefers an
         * advisory posted against whatever listing the current page happens
         * to be showing (found generically via route-model binding -- no
         * per-controller wiring needed) over a general, platform-wide one,
         * so a traveler looking at Mt. Apo sees Mt. Apo's own closure notice
         * first rather than an unrelated general notice burying it. Only
         * ONE advisory is ever surfaced here; the rest remain reachable on
         * the /advisories hub page.
         */
        View::composer('partials.header', function ($view) {
            $listingAdvisory = null;

            foreach (request()->route()?->parameters() ?? [] as $param) {
                if (! $param instanceof \Illuminate\Database\Eloquent\Model) {
                    continue;
                }

                $kind = array_search($param::class, Relation::morphMap(), true);
                if ($kind === false) {
                    continue;
                }

                $listingAdvisory = Advisory::active()->forListing($kind, $param->id)->urgentFirst()->first();
                if ($listingAdvisory) {
                    break;
                }
            }

            $general = Advisory::active()->general()->urgentFirst()->take(5)->get();
            $view->with('topAdvisory', $listingAdvisory ?? $general->first());

            // The strip lets a traveller step through what is active: this page's own notice first,
            // then the general ones, most urgent first. The nav dot carries the count of everything live.
            $view->with('ribbonAdvisories', collect([$listingAdvisory])->filter()->merge($general)->unique('id')->take(5)->values());
            $view->with('activeAdvisoryCount', Advisory::active()->count());

            // The number on the header's heart: the signed-in traveller's own list, otherwise this
            // browser's. Nothing is counted for a first-time visitor who has no token yet.
            $token = request()->cookie(EnsureVisitorToken::COOKIE);
            $view->with('accountItineraryCount', Auth::guard('tourist')->check()
                ? Itinerary::where('tourist_account_id', Auth::guard('tourist')->id())->count()
                : 0);
            $view->with('savedCount', Auth::guard('tourist')->check()
                ? TouristSavedDestination::where('tourist_account_id', Auth::guard('tourist')->id())->count()
                : (is_string($token) ? SavedListing::where('visitor_token', $token)->count() : 0));
        });
    }
}
