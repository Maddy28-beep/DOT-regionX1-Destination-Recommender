@extends('layouts.app')

@section('title', 'Saved Places — My Account — ExploreDVO')

@section('content')
<div class="page-head">
    <div class="container">
        <span class="poster-kicker">your account</span>
        <h1 class="poster-title">Saved Places</h1>
        <p>
            Kept against your account, so this list follows you across devices &mdash; separate from
            the browser-only Saved Places you may already have built up while browsing anonymously.
        </p>
    </div>
</div>

<div class="section-tight">
    <div class="container">
        @forelse ($groups as $segment => $group)
            <div class="section-head">
                <div>
                    <h2 class="poster-title" style="color:var(--ocean-teal-dark);">{{ $group['label'] }}</h2>
                    <p>{{ $group['items']->count() }} saved</p>
                </div>
            </div>
            <div class="dpost-grid" style="margin-bottom:34px;">
                @foreach ($group['items'] as $listing)
                    @include('partials.listing-poster-card', ['listing' => $listing])
                @endforeach
            </div>
        @empty
            <div class="empty-state">
                <h2 class="poster-title" style="color:var(--ocean-teal-dark);">Nothing saved yet</h2>
                <p>
                    Tap the heart on any destination, accommodation, restaurant or souvenir center
                    and it will show up here.
                </p>
                <div class="empty-state__actions">
                    <a href="{{ route('destinations.index') }}" class="btn btn-poster-primary">Browse Destinations</a>
                    <a href="{{ route('plan.edit') }}" class="btn btn-poster-ghost">Plan My Trip</a>
                </div>
            </div>
        @endforelse
    </div>
</div>
@endsection
