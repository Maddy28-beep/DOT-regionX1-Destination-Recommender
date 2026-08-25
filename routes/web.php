<?php

use App\Http\Controllers\AccommodationController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminListingController;
use App\Http\Controllers\Auth\EstablishmentRegistrationController;
use App\Http\Controllers\Auth\PortalAuthController;
use App\Http\Controllers\Auth\TouristAuthController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\CheckInController;
use App\Http\Controllers\DestinationController;
use App\Http\Controllers\Establishment\EstablishmentDashboardController;
use App\Http\Controllers\Establishment\EstablishmentPhotoController;
use App\Http\Controllers\ExitSurveyController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ItineraryController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\QrCodeController;
use App\Http\Controllers\RestaurantController;
use App\Http\Controllers\SouvenirCenterController;
use App\Http\Controllers\TouristDashboardController;
use App\Http\Controllers\TourOperatorController;
use App\Http\Controllers\TouristHealthProfileController;
use App\Http\Controllers\TouristPreferenceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

// QR-code check-in (records a real TouristVisit on scan; see CheckInController)
Route::get('/check-in/{type}/{id}', [CheckInController::class, 'checkIn'])
    ->middleware('auth:tourist')
    ->name('check-in');

// Public destination catalog (2.2.1.3, Figure 12)
Route::get('/destinations', [DestinationController::class, 'index'])->name('destinations.index');
Route::get('/destinations/{destination:slug}', [DestinationController::class, 'show'])->name('destinations.show');
Route::post('/destinations/{destination:slug}/save', [DestinationController::class, 'toggleSave'])
    ->middleware('auth:tourist')
    ->name('destinations.save');

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

// Exit survey (2.2.1.7, Figures 13-15) — anonymous by design, no login required
Route::get('/exit-survey', [ExitSurveyController::class, 'create'])->name('exit-survey.create');
Route::post('/exit-survey', [ExitSurveyController::class, 'store'])->name('exit-survey.store');

// Chatbot Assistance Module (2.2.1.13, Sec. 2.2.3.1.10) — available to guests and logged-in tourists
Route::post('/chatbot/message', [ChatbotController::class, 'respond'])
    ->middleware('throttle:30,1')
    ->name('chatbot.respond');

// Tourist-facing auth (2.2.1.1 / 2.2.1.2, Figure 8)
Route::middleware('guest:tourist')->group(function () {
    Route::get('/register', [TouristAuthController::class, 'showRegister'])->name('tourist.register');
    Route::post('/register', [TouristAuthController::class, 'register']);
    Route::get('/login', [TouristAuthController::class, 'showLogin'])->name('tourist.login');
    Route::post('/login', [TouristAuthController::class, 'login']);
});
Route::post('/logout', [TouristAuthController::class, 'logout'])
    ->middleware('auth:tourist')
    ->name('tourist.logout');

Route::middleware('auth:tourist')->group(function () {
    Route::get('/dashboard', [TouristDashboardController::class, 'index'])->name('tourist.dashboard');
    Route::put('/dashboard/profile', [TouristDashboardController::class, 'updateProfile'])->name('tourist.profile.update');

    // Travel preference survey (2.2.1.4, Figure 9) — collects inputs for the recommendation engine
    Route::get('/dashboard/preferences', [TouristPreferenceController::class, 'edit'])->name('tourist.preferences.edit');
    Route::put('/dashboard/preferences', [TouristPreferenceController::class, 'update'])->name('tourist.preferences.update');

    // AI-driven itinerary (Content-Based Recommendation + Apriori Algorithm, Sec. 2.3.3-2.3.4)
    Route::get('/dashboard/itinerary', [ItineraryController::class, 'show'])->name('tourist.itinerary.show');
    Route::post('/dashboard/itinerary/regenerate', [ItineraryController::class, 'regenerate'])->name('tourist.itinerary.regenerate');

    // Health/accessibility profile (2.2.1.14, Table 38-39) — optional, consent-gated, editable/deletable anytime
    Route::get('/dashboard/health-profile', [TouristHealthProfileController::class, 'edit'])->name('tourist.health-profile.edit');
    Route::put('/dashboard/health-profile', [TouristHealthProfileController::class, 'update'])->name('tourist.health-profile.update');
    Route::delete('/dashboard/health-profile', [TouristHealthProfileController::class, 'destroy'])->name('tourist.health-profile.destroy');
});

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
    Route::get('/tourists', [AdminDashboardController::class, 'tourists'])->name('tourists');

    Route::get('/establishments', [AdminDashboardController::class, 'establishments'])->name('establishments');
    Route::post('/establishments/{establishment}/approve', [AdminDashboardController::class, 'approveEstablishment'])->name('establishments.approve');
    Route::post('/establishments/{establishment}/reject', [AdminDashboardController::class, 'rejectEstablishment'])->name('establishments.reject');
    Route::post('/establishments/{establishment}/match', [AdminDashboardController::class, 'matchEstablishmentListing'])->name('establishments.match');

    Route::get('/accreditation', [AdminDashboardController::class, 'accreditation'])->name('accreditation');
    Route::post('/accreditation/{accreditation}/renew', [AdminDashboardController::class, 'renewAccreditation'])->name('accreditation.renew');
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

    Route::get('/photos', [EstablishmentPhotoController::class, 'index'])->name('photos');
    Route::post('/photos', [EstablishmentPhotoController::class, 'store'])->name('photos.store');
    Route::post('/photos/{photo}/primary', [EstablishmentPhotoController::class, 'setPrimary'])->name('photos.primary');
    Route::delete('/photos/{photo}', [EstablishmentPhotoController::class, 'destroy'])->name('photos.destroy');
    Route::post('/photos/{photo}/up', [EstablishmentPhotoController::class, 'moveUp'])->name('photos.up');
    Route::post('/photos/{photo}/down', [EstablishmentPhotoController::class, 'moveDown'])->name('photos.down');
});
