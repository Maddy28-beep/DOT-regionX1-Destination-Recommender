{{-- Keep the filtered province's niche here; the regional slogan belongs to the homepage and footer. --}}
@php
    $chosen = request('region_id') && isset($regions) ? $regions->firstWhere('id', (int) request('region_id')) : null;
    $niche = \App\Support\ProvinceBrand::for($chosen?->name);
@endphp
@if ($niche)
    <p class="province-niche"><strong>{{ $chosen->name }}</strong> &middot; {{ $niche }}</p>
@endif
