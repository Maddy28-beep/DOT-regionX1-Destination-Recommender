<?php

namespace App\Providers;

use App\Models\Accommodation;
use App\Models\AdminUser;
use App\Models\Advisory;
use App\Models\Destination;
use App\Models\EstablishmentAccount;
use App\Models\Package;
use App\Models\Restaurant;
use App\Models\SouvenirCenter;
use App\Models\TourOperator;
use Illuminate\Database\Eloquent\Relations\Relation;
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

            $view->with('topAdvisory', $listingAdvisory ?? Advisory::active()->general()->urgentFirst()->first());
        });
    }
}
