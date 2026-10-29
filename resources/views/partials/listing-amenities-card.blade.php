{{-- Amenities and accessibility card for a stay, restaurant or souvenir center. $listing, $kind --}}
@if (app(\App\Services\Amenities\AmenityStore::class)->keys($kind, $listing) !== [])
    <div class="side-card about-card">
        @include('partials.amenities-on-site', ['listing' => $listing, 'kind' => $kind])
    </div>
@endif
