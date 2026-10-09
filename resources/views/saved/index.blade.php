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
            <x-davo-empty-state
                variant="saved"
                title="Your next adventure starts here"
                message="No saved places yet. Tap the heart on a place you like, and you will find it here when you are ready to plan."
                :action-url="route('destinations.index')"
                action-label="Explore destinations"
                :secondary-url="route('plan.edit')"
                secondary-label="Plan My Trip"
            />
        @endforelse
    </div>
</div>
@endsection
