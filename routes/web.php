<?php

use App\Http\Controllers\AccommodationController;
use App\Http\Controllers\AddressSuggestionController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminListingController;
use App\Http\Controllers\Auth\EstablishmentRegistrationController;
use App\Http\Controllers\Auth\PortalAuthController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\CheckInController;
use App\Http\Controllers\DestinationController;
use App\Http\Controllers\Establishment\EstablishmentDashboardController;
use App\Http\Controllers\Establishment\EstablishmentPhotoController;
use App\Http\Controllers\ExitSurveyController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\QrCodeController;
use App\Http\Controllers\RestaurantController;
use App\Http\Controllers\SavedListingController;
use App\Http\Controllers\SouvenirCenterController;
use App\Http\Controllers\TourOperatorController;
use App\Http\Controllers\TripPlannerController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// Legal pages linked from the footer. Static content, no controller needed;
// the Privacy Policy also serves as the RA 10173 (Data Privacy Act) notice
// rather than duplicating the same facts across two documents that could
// drift apart.
Route::view('/privacy-policy', 'legal.privacy-policy')->name('legal.privacy');
Route::view('/terms-of-service', 'legal.terms-of-service')->name('legal.terms');
Route::view('/accessibility', 'legal.accessibility')->name('legal.accessibility');

/*
 * QR-code check-in (records a visit on scan; see CheckInController). Open to
 * everyone: the visitor scans the establishment's code and is counted, with
 * no account and nothing personal stored. Throttled because the URL is public
 * and printed on a poster.
 */
Route::get('/check-in/{type}/{id}', [CheckInController::class, 'checkIn'])
    ->middleware('throttle:20,1')
    ->name('check-in');

// Public destination catalog (2.2.1.3, Figure 12)
Route::get('/destinations', [DestinationController::class, 'index'])->name('destinations.index');
Route::get('/destinations/{destination:slug}', [DestinationController::class, 'show'])->name('destinations.show');

// Public accommodations catalog
Route::get('/accommodations', [AccommodationController::class, 'index'])->name('accommodations.index');
Route::get('/accommodations/{accommodation:slug}', [AccommodationController::class, 'show'])->name('accommodations.show');

// Public tour packages catalog
Route::get('/packages', [PackageController::class, 'index'])->name('packages.index');
Route::get('/packages/{package:slug}', [PackageController::class, 'show'])->name('packages.show');

// Public restaurants catalog (2.2.1.3)
Route::get('/restaurants', [RestaurantController::class, 'index'])->name('restaurants.index');
Route::get('/restaurants/{restaurant:slug}', [RestaurantController::class, 'show'])->name('restaurants.show');

// Public souvenir centers catalog (2.2.1.3)
Route::get('/souvenir-centers', [SouvenirCenterController::class, 'index'])->name('souvenir-centers.index');
Route::get('/souvenir-centers/{souvenirCenter:slug}', [SouvenirCenterController::class, 'show'])->name('souvenir-centers.show');

// Public tour operators catalog (RD meeting notes: "Include the travel and tour operators")
Route::get('/tour-operators', [TourOperatorController::class, 'index'])->name('tour-operators.index');
Route::get('/tour-operators/{tourOperator:slug}', [TourOperatorController::class, 'show'])->name('tour-operators.show');

/*
 * Trip planner: the travel-preference survey and AI itinerary (2.2.1.4,
 * Sec. 2.3.4). Open to everyone -- there are no traveler accounts at all, so
 * a visitor fills in the survey, including the optional health and
 * accessibility questions the itinerary takes into account, and gets a plan.
 * The plan lives in the session; see TripPlannerController.
 */
Route::get('/plan', [TripPlannerController::class, 'edit'])->name('plan.edit');

/*
 * Address type-ahead for the starting-point field. Throttled because it is an
 * unauthenticated endpoint that costs an upstream geocoder request whenever it
 * misses the cache -- see AddressSuggestionService.
 */
Route::get('/plan/address-suggest', AddressSuggestionController::class)
    ->middleware('throttle:40,1')
    ->name('plan.address-suggest');
Route::post('/plan', [TripPlannerController::class, 'update'])->name('plan.update');
Route::get('/plan/itinerary', [TripPlannerController::class, 'itinerary'])->name('plan.itinerary');
Route::post('/plan/itinerary/regenerate', [TripPlannerController::class, 'regenerate'])->name('plan.regenerate');

// Exit survey (2.2.1.7, Figures 13-15) — anonymous by design, no login required
Route::get('/exit-survey', [ExitSurveyController::class, 'create'])->name('exit-survey.create');
Route::post('/exit-survey', [ExitSurveyController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('exit-survey.store');

// Chatbot Assistance Module (2.2.1.13, Sec. 2.2.3.1.10)
Route::post('/chatbot/message', [ChatbotController::class, 'respond'])
    ->middleware('throttle:30,1')
    ->name('chatbot.respond');

/*
 * Saved places — the heart control on every listing card and detail page.
 *
 * There is no traveler account (removed for Data Privacy Act compliance), so
 * the list is kept against an opaque random browser token rather than a
 * person; see EnsureVisitorToken and SavedListingController.
 */
Route::get('/saved', [SavedListingController::class, 'index'])->name('saved.index');
Route::post('/saved/{type}/{id}', [SavedListingController::class, 'toggle'])->name('saved.toggle');

// Partner Portal (2.3.2 Tourism Administrator + DOT-Accredited Establishment, Figure 7)
Route::prefix('portal')->name('portal.')->group(function () {
    Route::get('/login', [PortalAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [PortalAuthController::class, 'login']);
    Route::post('/logout', [PortalAuthController::class, 'logout'])->name('logout');

    Route::get('/register', [EstablishmentRegistrationController::class, 'showRegister'])->name('establishment.register');
    Route::post('/register', [EstablishmentRegistrationController::class, 'register']);
});

// DOT Admin console (2.3.2 Tourism Administrator, Figures 16-21)
Route::prefix('portal/admin')->name('admin.')->middleware('auth:admin')->group(function () {
    Route::get('/', [AdminDashboardController::class, 'overview'])->name('overview');

    Route::get('/establishments', [AdminDashboardController::class, 'establishments'])->name('establishments');
    Route::post('/establishments/{establishment}/approve', [AdminDashboardController::class, 'approveEstablishment'])->name('establishments.approve');
    Route::post('/establishments/{establishment}/reject', [AdminDashboardController::class, 'rejectEstablishment'])->name('establishments.reject');
    Route::post('/establishments/{establishment}/match', [AdminDashboardController::class, 'matchEstablishmentListing'])->name('establishments.match');

    Route::get('/accreditation', [AdminDashboardController::class, 'accreditation'])->name('accreditation');
    Route::post('/accreditation/{accreditation}/renew', [AdminDashboardController::class, 'renewAccreditation'])->name('accreditation.renew');
    Route::post('/accreditation/bulk-renew', [AdminDashboardController::class, 'bulkRenewAccreditation'])->name('accreditation.bulk-renew');
    Route::get('/exit-surveys', [AdminDashboardController::class, 'exitSurveys'])->name('exit-surveys');
    Route::get('/association-rules', [AdminDashboardController::class, 'associationRules'])->name('association-rules');
    Route::get('/reports', [AdminDashboardController::class, 'reports'])->name('reports');
    Route::get('/reports/export.csv', [AdminDashboardController::class, 'exportCsv'])->name('reports.export-csv');
    Route::get('/reports/print', [AdminDashboardController::class, 'printReport'])->name('reports.print');

    // Tourism Information Management (2.2.3.1.2)
    Route::prefix('listings/{type}')->name('listings.')->group(function () {
        Route::get('/', [AdminListingController::class, 'index'])->name('index');
        Route::get('/create', [AdminListingController::class, 'create'])->name('create');
        Route::post('/', [AdminListingController::class, 'store'])->name('store');
        Route::get('/{id}/edit', [AdminListingController::class, 'edit'])->name('edit');
        Route::put('/{id}', [AdminListingController::class, 'update'])->name('update');
        Route::post('/bulk', [AdminListingController::class, 'bulk'])->name('bulk');
        Route::post('/{id}/archive', [AdminListingController::class, 'archive'])->name('archive');
        Route::post('/{id}/unarchive', [AdminListingController::class, 'unarchive'])->name('unarchive');
        Route::get('/{id}/qr-code', [QrCodeController::class, 'admin'])->name('qr-code');
    });
});

// Establishment partner console (2.2.1.11)
Route::prefix('portal/establishment')->name('establishment.')->middleware('auth:establishment')->group(function () {
    Route::get('/', [EstablishmentDashboardController::class, 'overview'])->name('overview');
    Route::get('/listing', [EstablishmentDashboardController::class, 'editListing'])->name('listing.edit');
    Route::put('/listing', [EstablishmentDashboardController::class, 'updateListing'])->name('listing.update');
    Route::get('/listing/qr-code', [QrCodeController::class, 'establishment'])->name('listing.qr-code');
    Route::get('/reviews', [EstablishmentDashboardController::class, 'reviews'])->name('reviews');
    Route::post('/reviews/{review}/reply', [EstablishmentDashboardController::class, 'replyToReview'])->name('reviews.reply');
    Route::get('/notifications', [EstablishmentDashboardController::class, 'notifications'])->name('notifications');
    Route::post('/notifications/read-all', [EstablishmentDashboardController::class, 'markNotificationsRead'])->name('notifications.read-all');

    Route::get('/photos', [EstablishmentPhotoController::class, 'index'])->name('photos');
    Route::post('/photos', [EstablishmentPhotoController::class, 'store'])->name('photos.store');
    Route::post('/photos/{photo}/primary', [EstablishmentPhotoController::class, 'setPrimary'])->name('photos.primary');
    Route::delete('/photos/{photo}', [EstablishmentPhotoController::class, 'destroy'])->name('photos.destroy');
    Route::post('/photos/{photo}/up', [EstablishmentPhotoController::class, 'moveUp'])->name('photos.up');
    Route::post('/photos/{photo}/down', [EstablishmentPhotoController::class, 'moveDown'])->name('photos.down');
});
