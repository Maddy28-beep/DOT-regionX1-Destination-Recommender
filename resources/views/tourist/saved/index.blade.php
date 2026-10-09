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

@include('partials.account-nav')

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
