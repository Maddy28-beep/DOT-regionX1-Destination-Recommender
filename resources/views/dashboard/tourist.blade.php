@extends('layouts.app')

@section('title', 'My Trip — ExploreDVO')

@section('content')
<div class="dash-shell">
    <div class="dash-header">
        <div class="container">
            <div>
                <h1>Welcome, {{ $tourist->full_name }}</h1>
                <div class="sub">Member since {{ $tourist->created_at->format('F Y') }}</div>
            </div>
            <form method="POST" action="{{ route('tourist.logout') }}">
                @csrf
                <button type="submit" class="btn btn-outline">Log out</button>
            </form>
        </div>
    </div>

    <div class="dash-body">
        <div class="container">
            @if (session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif

            <div class="stat-cards" style="grid-template-columns: repeat(3, 1fr);">
                <div class="stat-card">
                    <div class="stat-card-val">{{ $savedDestinations->count() }}</div>
                    <div class="stat-card-label">Saved Destinations</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-val" style="font-size:1.1rem;">{{ $tourist->nationality }}</div>
                    <div class="stat-card-label">Nationality</div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-val" style="font-size:1.1rem;">{{ $tourist->preferred_language }}</div>
                    <div class="stat-card-label">Preferred Language</div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>My Saved Destinations</h2>
                        <p>Places you've bookmarked while browsing.</p>
                    </div>
                    <a href="{{ route('destinations.index') }}" class="btn btn-outline">Browse more</a>
                </div>
                <div class="panel-body">
                    @if ($savedDestinations->isEmpty())
                        <div class="empty-panel">
                            <div class="icon"><x-icon name="heart" /></div>
                            <h3>No saved destinations yet</h3>
                            <p>Tap the heart on any destination page to save it here for later.</p>
                            <a href="{{ route('destinations.index') }}" class="btn btn-primary" style="margin-top:12px;">Browse Destinations</a>
                        </div>
                    @else
                        <div class="card-grid">
                            @foreach ($savedDestinations as $destination)
                                @include('partials.dest-card', ['destination' => $destination])
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>My Account</h2>
                        <p>Keep your travel profile up to date.</p>
                    </div>
                </div>
                <div class="panel-body">
                    <form method="POST" action="{{ route('tourist.profile.update') }}">
                        @csrf
                        @method('PUT')

                        <div class="filter-inline" style="align-items:start;">
                            <div class="field" style="flex:1; min-width:200px;">
                                <label for="full_name">Full name</label>
                                <input type="text" id="full_name" name="full_name" value="{{ old('full_name', $tourist->full_name) }}" required>
                            </div>
                            <div class="field" style="flex:1; min-width:200px;">
                                <label for="email">Email address</label>
                                <input type="email" id="email" value="{{ $tourist->email }}" disabled style="background:var(--bg); color:var(--muted);">
                            </div>
                        </div>

                        <div class="filter-inline" style="align-items:start; margin-top:14px;">
                            <div class="field" style="flex:1; min-width:160px;">
                                <label for="nationality">Nationality</label>
                                <input type="text" id="nationality" name="nationality" value="{{ old('nationality', $tourist->nationality) }}" required>
                            </div>
                            <div class="field" style="flex:1; min-width:160px;">
                                <label for="age_range">Age range</label>
                                <select id="age_range" name="age_range" required>
                                    @foreach (['Under 18', '18-24', '25-34', '35-44', '45-59', '60+'] as $range)
                                        <option value="{{ $range }}" @selected(old('age_range', $tourist->age_range) === $range)>{{ $range }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field" style="flex:1; min-width:160px;">
                                <label for="gender">Gender (optional)</label>
                                <input type="text" id="gender" name="gender" value="{{ old('gender', $tourist->gender) }}">
                            </div>
                        </div>

                        <div class="filter-inline" style="align-items:start; margin-top:14px;">
                            <div class="field" style="flex:1; min-width:160px;">
                                <label for="contact_number">Contact number (optional)</label>
                                <input type="text" id="contact_number" name="contact_number" value="{{ old('contact_number', $tourist->contact_number) }}">
                            </div>
                            <div class="field" style="flex:1; min-width:160px;">
                                <label for="preferred_language">Preferred language</label>
                                <input type="text" id="preferred_language" name="preferred_language" value="{{ old('preferred_language', $tourist->preferred_language) }}">
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary" style="margin-top:18px;">Save Changes</button>
                    </form>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Health &amp; Accessibility Profile</h2>
                        <p>
                            @if ($healthProfile && $healthProfile->consent)
                                {{ $healthProfile->conditions->count() }} condition{{ $healthProfile->conditions->count() === 1 ? '' : 's' }} on file. Optional, consent-based, and deletable anytime.
                            @else
                                Optionally share accessibility needs so we can flag suitable destinations for you. Consent-based, editable and deletable anytime.
                            @endif
                        </p>
                    </div>
                    <a href="{{ route('tourist.health-profile.edit') }}" class="btn btn-outline">
                        {{ $healthProfile && $healthProfile->consent ? 'Update' : 'Set Up' }}
                    </a>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Plan Your Next Trip</h2>
                        <p>
                            @if ($tourist->preferences()->exists())
                                Personalized destination recommendations and an AI-driven itinerary are ready, based on your travel preferences.
                            @else
                                Set your travel preferences and we'll generate a personalized, ranked itinerary for you automatically.
                            @endif
                        </p>
                    </div>
                    @if ($tourist->preferences()->exists())
                        <a href="{{ route('tourist.itinerary.show') }}" class="btn btn-primary">View My Itinerary</a>
                    @else
                        <a href="{{ route('tourist.preferences.edit') }}" class="btn btn-primary">Set Travel Preferences</a>
                    @endif
                </div>
                <div class="panel-body">
                    @if ($tourist->preferences()->exists())
                        <div class="util-row" style="margin-bottom:12px;">
                            <a href="{{ route('tourist.preferences.edit') }}" class="btn btn-outline">Update Travel Preferences</a>
                        </div>
                    @endif
                    <div class="util-row">
                        <a href="{{ route('destinations.index') }}" class="btn btn-outline">Browse Destinations</a>
                        <a href="{{ route('accommodations.index') }}" class="btn btn-outline">Browse Accommodations</a>
                        <a href="{{ route('restaurants.index') }}" class="btn btn-outline">Browse Restaurants</a>
                        <a href="{{ route('packages.index') }}" class="btn btn-outline">Browse Tour Packages</a>
                        <a href="{{ route('souvenir-centers.index') }}" class="btn btn-outline">Browse Souvenir Centers</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
