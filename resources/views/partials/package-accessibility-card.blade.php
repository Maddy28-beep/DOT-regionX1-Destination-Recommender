{{-- Difficulty, who a package suits, what is on the trip and a note for travellers with health needs. $package --}}
@php
    $features = app(\App\Services\Amenities\AmenityStore::class)->keys('package', $package);
    $who = array_values(array_filter($features, fn ($k) => \App\Support\Amenities::group($k) === \App\Support\Amenities::WHO));
    $trip = array_values(array_filter($features, fn ($k) => \App\Support\Amenities::group($k) === \App\Support\Amenities::TRIP));
    $difficulty = $package->difficulty ? \App\Support\Amenities::DIFFICULTY[$package->difficulty] ?? null : null;
@endphp
@if ($difficulty || $features !== [] || $package->accessibility_note)
    <div class="side-card about-card">
        <h3 class="mt-0">Accessibility &amp; who it suits</h3>
        @if ($difficulty)
            <div class="about-card__label">How demanding</div>
            <p class="about-card__text" style="margin-bottom:12px;"><strong>{{ $difficulty[0] }}.</strong> {{ $difficulty[1] }}</p>
        @endif
        @if ($who !== [])
            <div class="about-card__label">Suitable for</div>
            <div class="good-for good-for--spaced">@foreach ($who as $k)<span class="good-for__chip">{{ \App\Support\Amenities::label($k) }}</span>@endforeach</div>
        @endif
        @if ($trip !== [])
            <div class="about-card__label" style="margin-top:12px;">On the trip</div>
            <div class="good-for good-for--spaced">@foreach ($trip as $k)<span class="good-for__chip">{{ \App\Support\Amenities::label($k) }}</span>@endforeach</div>
        @endif
        @if ($package->accessibility_note)
            <div class="about-card__label" style="margin-top:12px;">Good to know</div>
            <p class="about-card__text" style="margin:0;">{{ $package->accessibility_note }}</p>
        @endif
    </div>
@endif
