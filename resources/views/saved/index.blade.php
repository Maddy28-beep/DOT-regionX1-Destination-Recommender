@extends('layouts.app')

@section('title', 'Saved Places — ExploreDVO')

@section('content')
<div class="page-head">
    <div class="container">
        <span class="poster-kicker">your shortlist</span>
        <h1 class="poster-title">Saved Places</h1>
        <p>
            Everything you have hearted while browsing. This list lives in this browser only &mdash;
            there is no account behind it and nothing here identifies you.
        </p>
    </div>
</div>

<div class="section-tight">
    <div class="container">
        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

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
