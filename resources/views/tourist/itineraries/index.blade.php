@extends('layouts.app')

@section('title', 'My Itineraries — ExploreDVO')

@section('content')
<div class="dash-shell">
    <div class="dash-header">
        <div class="container">
            <div>
                <span class="poster-kicker" style="font-size:1.05rem;">your account</span>
                <h1 class="page-title" style="font-size:1.9rem; margin:0;">My Itineraries</h1>
                <div class="sub">Saved to your account &mdash; open one to keep viewing or customizing it.</div>
            </div>
            <a href="{{ route('plan.edit') }}" class="btn btn-outline">Plan a New Trip</a>
        </div>
    </div>

    <div class="dash-body">
        <div class="container">
            @if ($itineraries->isEmpty())
                <div class="panel">
                    <div class="empty-panel">
                        <div class="icon"><x-icon name="compass" /></div>
                        <h3>No saved itineraries yet</h3>
                        <p>Generate a trip plan, then choose "Save Itinerary" to keep it here.</p>
                    </div>
                </div>
            @else
                @foreach ($itineraries as $itinerary)
                    @php
                        $destinationCount = $itinerary->items->pluck('destination_id')->filter()->unique()->count();
                    @endphp
                    <div class="panel">
                        <div class="panel-head">
                            <div>
                                <h2>{{ $itinerary->title }}</h2>
                                <p>
                                    {{ $itinerary->total_days }} Day{{ $itinerary->total_days === 1 ? '' : 's' }}
                                    &middot; {{ $destinationCount }} Destination{{ $destinationCount === 1 ? '' : 's' }}
                                    @if ($itinerary->package)
                                        &middot; from the {{ $itinerary->package->name }} package
                                    @endif
                                </p>
                            </div>
                        </div>
                        <div class="panel-body" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
                            <p class="sub" style="margin:0;">
                                Created {{ $itinerary->generated_at->format('F j, Y') }}
                                @if ($itinerary->updated_at && ! $itinerary->updated_at->eq($itinerary->generated_at))
                                    &middot; Last updated {{ $itinerary->updated_at->format('F j, Y') }}
                                @endif
                            </p>
                            <div class="util-row">
                                <a href="{{ route('account.itineraries.show', $itinerary) }}" class="btn btn-primary btn-xs">View Itinerary</a>
                                <a href="{{ route('account.itineraries.edit', $itinerary) }}" class="btn btn-outline btn-xs">Edit</a>
                                <form method="POST" action="{{ route('account.itineraries.destroy', $itinerary) }}" onsubmit="return confirm('Delete this itinerary? This cannot be undone.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-outline btn-xs">Delete</button>
                                </form>
                            </div>
                        </div>
                    </div>
                @endforeach
            @endif
        </div>
    </div>
</div>
@endsection
