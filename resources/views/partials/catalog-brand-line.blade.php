{{-- Under a listing page's heading: the regional line, and, once a province or city is chosen in the filter, the niche DOT
     Region XI gave it (App\Support\ProvinceBrand). --}}
@php
    $chosen = request('region_id') && isset($regions) ? $regions->firstWhere('id', (int) request('region_id')) : null;
    $niche = \App\Support\ProvinceBrand::for($chosen?->name);
@endphp
<p class="brand-line">{{ \App\Support\ProvinceBrand::REGION_LINE }}</p>
@if ($niche)
    <p class="province-niche"><strong>{{ $chosen->name }}</strong> &middot; {{ $niche }}</p>
@endif
