<?php

namespace App\Providers;

use App\Models\Accommodation;
use App\Models\AdminUser;
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
    }
}
