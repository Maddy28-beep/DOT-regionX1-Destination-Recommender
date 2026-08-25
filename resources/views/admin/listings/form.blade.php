@extends('layouts.admin')

@section('title', ($listing->exists ? 'Edit' : 'Add').' '.$config['singular'].' — DOT Admin')
@section('page-title', ($listing->exists ? 'Edit' : 'Add New').' '.$config['singular'])
@section('page-sub', 'Tourism Information Management')

@section('content')

@if ($errors->any())
    <div class="alert alert-error">
        @foreach ($errors->all() as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
@endif

<div class="panel">
    <div class="panel-body">
        <form method="POST" action="{{ $listing->exists ? route('admin.listings.update', [$type, $listing->id]) : route('admin.listings.store', $type) }}">
            @csrf
            @if ($listing->exists)
                @method('PUT')
            @endif

            <div class="filter-inline" style="align-items:start;">
                <div class="field" style="flex:2; min-width:240px;">
                    <label for="name">Name</label>
                    <input type="text" id="name" name="name" value="{{ old('name', $listing->name) }}" required>
                </div>
                <div class="field" style="flex:1; min-width:200px;">
                    <label for="region_id">Region</label>
                    <select id="region_id" name="region_id">
                        <option value="">Select...</option>
                        @foreach ($regions as $region)
                            <option value="{{ $region->id }}" @selected(old('region_id', $listing->region_id) == $region->id)>{{ $region->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="field">
                <label for="location">{{ $type === 'packages' ? 'Location / Coverage Area' : 'Location' }}</label>
                <input type="text" id="location" name="location" value="{{ old('location', $listing->location) }}" @if($type === 'restaurants') required @endif>
            </div>

            <div class="field">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="4">{{ old('description', $listing->description) }}</textarea>
            </div>

            @switch($type)
                @case('destinations')
                    <div class="filter-inline" style="align-items:start; margin-top:14px;">
                        <div class="field" style="flex:1; min-width:160px;">
                            <label for="type">Category</label>
                            <input type="text" id="type" name="type" value="{{ old('type', $listing->type) }}" placeholder="e.g. Beach &amp; Leisure">
                        </div>
                        <div class="field" style="flex:1; min-width:160px;">
                            <label for="price_tier">Budget Tier</label>
                            <select id="price_tier" name="price_tier">
                                <option value="">Not specified</option>
                                @foreach (['Free', 'Budget-Friendly', 'Mid-range', 'Premium'] as $tier)
                                    <option value="{{ $tier }}" @selected(old('price_tier', $listing->price_tier) === $tier)>{{ $tier }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field" style="flex:1; min-width:140px;">
                            <label for="distance_km">Distance (km)</label>
                            <input type="number" step="0.1" id="distance_km" name="distance_km" value="{{ old('distance_km', $listing->distance_km) }}">
                        </div>
                    </div>
                    <div class="filter-inline" style="align-items:start; margin-top:14px;">
                        <div class="field" style="flex:1; min-width:140px;">
                            <label for="entry_fee_min">Entry Fee Min (&#8369;)</label>
                            <input type="number" step="0.01" id="entry_fee_min" name="entry_fee_min" value="{{ old('entry_fee_min', $listing->entry_fee_min) }}">
                        </div>
                        <div class="field" style="flex:1; min-width:140px;">
                            <label for="entry_fee_max">Entry Fee Max (&#8369;)</label>
                            <input type="number" step="0.01" id="entry_fee_max" name="entry_fee_max" value="{{ old('entry_fee_max', $listing->entry_fee_max) }}">
                        </div>
                        <div class="field" style="flex:1; min-width:140px;">
                            <label for="visit_duration">Visit Duration</label>
                            <input type="text" id="visit_duration" name="visit_duration" value="{{ old('visit_duration', $listing->visit_duration) }}" placeholder="e.g. Half day">
                        </div>
                    </div>
                    <div class="filter-inline" style="align-items:start; margin-top:14px;">
                        <div class="field" style="flex:1; min-width:140px;">
                            <label for="best_time">Best Time to Visit</label>
                            <input type="text" id="best_time" name="best_time" value="{{ old('best_time', $listing->best_time) }}">
                        </div>
                        <div class="field" style="flex:1; min-width:140px;">
                            <label for="hours">Operating Hours</label>
                            <input type="text" id="hours" name="hours" value="{{ old('hours', $listing->hours) }}">
                        </div>
                    </div>
                    <div class="filter-inline" style="align-items:start; margin-top:14px;">
                        <div class="field" style="flex:1; min-width:140px;">
                            <label for="latitude">Latitude</label>
                            <input type="number" step="0.0000001" id="latitude" name="latitude" value="{{ old('latitude', $listing->latitude) }}">
                        </div>
                        <div class="field" style="flex:1; min-width:140px;">
                            <label for="longitude">Longitude</label>
                            <input type="number" step="0.0000001" id="longitude" name="longitude" value="{{ old('longitude', $listing->longitude) }}">
                        </div>
                    </div>
                    <label class="field-check">
                        <input type="checkbox" name="featured" value="1" @checked(old('featured', $listing->featured))>
                        <span>Feature on homepage</span>
                    </label>
                    @break

                @case('accommodations')
                    <div class="filter-inline" style="align-items:start; margin-top:14px;">
                        <div class="field" style="flex:1; min-width:160px;">
                            <label for="type">Type</label>
                            <input type="text" id="type" name="type" value="{{ old('type', $listing->type) }}" placeholder="e.g. Beach Resort">
                        </div>
                        <div class="field" style="flex:1; min-width:160px;">
                            <label for="dot_classification">DOT Classification</label>
                            <input type="text" id="dot_classification" name="dot_classification" value="{{ old('dot_classification', $listing->dot_classification) }}">
                        </div>
                        <div class="field" style="flex:1; min-width:160px;">
                            <label for="price_tier">Budget Tier</label>
                            <select id="price_tier" name="price_tier">
                                <option value="">Not specified</option>
                                @foreach (['Budget-Friendly', 'Mid-range', 'Premium'] as $tier)
                                    <option value="{{ $tier }}" @selected(old('price_tier', $listing->price_tier) === $tier)>{{ $tier }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="filter-inline" style="align-items:start; margin-top:14px;">
                        <div class="field" style="flex:1; min-width:140px;">
                            <label for="price_per_night">Price / Night (&#8369;)</label>
                            <input type="number" step="0.01" id="price_per_night" name="price_per_night" value="{{ old('price_per_night', $listing->price_per_night) }}">
                        </div>
                        <div class="field" style="flex:1; min-width:120px;">
                            <label for="check_in">Check-in</label>
                            <input type="time" id="check_in" name="check_in" value="{{ old('check_in', $listing->check_in) }}">
                        </div>
                        <div class="field" style="flex:1; min-width:120px;">
                            <label for="check_out">Check-out</label>
                            <input type="time" id="check_out" name="check_out" value="{{ old('check_out', $listing->check_out) }}">
                        </div>
                        <div class="field" style="flex:1; min-width:140px;">
                            <label for="distance_km">Distance (km)</label>
                            <input type="number" step="0.1" id="distance_km" name="distance_km" value="{{ old('distance_km', $listing->distance_km) }}">
                        </div>
                    </div>
                    <div class="field">
                        <label for="room_types">Room Types (one per line: Name | Price Min | Price Max &mdash; either price may be left blank)</label>
                        <textarea id="room_types" name="room_types" rows="4" placeholder="Standard Room | 2000 | 3000&#10;Deluxe Suite | 4500 | 6000">{{ old('room_types', $listing->exists ? $listing->roomTypes->map(fn ($r) => $r->name.' | '.($r->price_min ?? '').' | '.($r->price_max ?? ''))->implode("\n") : '') }}</textarea>
                    </div>
                    <div class="filter-inline" style="align-items:start; margin-top:14px;">
                        <div class="field" style="flex:1; min-width:160px;">
                            <label for="latitude">Latitude</label>
                            <input type="number" step="0.0000001" id="latitude" name="latitude" value="{{ old('latitude', $listing->latitude) }}">
                        </div>
                        <div class="field" style="flex:1; min-width:160px;">
                            <label for="longitude">Longitude</label>
                            <input type="number" step="0.0000001" id="longitude" name="longitude" value="{{ old('longitude', $listing->longitude) }}">
                        </div>
                    </div>
                    <label class="field-check">
                        <input type="checkbox" name="featured" value="1" @checked(old('featured', $listing->featured))>
                        <span>Feature on homepage</span>
                    </label>
                    @break

                @case('restaurants')
                    <div class="filter-inline" style="align-items:start; margin-top:14px;">
                        <div class="field" style="flex:1; min-width:160px;">
                            <label for="cuisine_type">Cuisine Type</label>
                            <input type="text" id="cuisine_type" name="cuisine_type" value="{{ old('cuisine_type', $listing->cuisine_type) }}">
                        </div>
                        <div class="field" style="flex:1; min-width:160px;">
                            <label for="price_tier">Budget Tier</label>
                            <select id="price_tier" name="price_tier">
                                <option value="">Not specified</option>
                                @foreach (['Budget-Friendly', 'Mid-range', 'Premium'] as $tier)
                                    <option value="{{ $tier }}" @selected(old('price_tier', $listing->price_tier) === $tier)>{{ $tier }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field" style="flex:1; min-width:160px;">
                            <label for="opening_hours">Opening Hours</label>
                            <input type="text" id="opening_hours" name="opening_hours" value="{{ old('opening_hours', $listing->opening_hours) }}">
                        </div>
                    </div>
                    <div class="filter-inline" style="align-items:start; margin-top:14px;">
                        <div class="field" style="flex:1; min-width:160px;">
                            <label for="contact_number">Contact Number</label>
                            <input type="text" id="contact_number" name="contact_number" value="{{ old('contact_number', $listing->contact_number) }}">
                        </div>
                        <div class="field" style="flex:1; min-width:140px;">
                            <label for="latitude">Latitude</label>
                            <input type="number" step="0.0000001" id="latitude" name="latitude" value="{{ old('latitude', $listing->latitude) }}">
                        </div>
                        <div class="field" style="flex:1; min-width:140px;">
                            <label for="longitude">Longitude</label>
                            <input type="number" step="0.0000001" id="longitude" name="longitude" value="{{ old('longitude', $listing->longitude) }}">
                        </div>
                    </div>
                    @break

                @case('packages')
                    <div class="filter-inline" style="align-items:start; margin-top:14px;">
                        <div class="field" style="flex:1; min-width:160px;">
                            <label for="type">Category</label>
                            <input type="text" id="type" name="type" value="{{ old('type', $listing->type) }}" placeholder="e.g. Beach &amp; Island">
                        </div>
                        <div class="field" style="flex:1; min-width:160px;">
                            <label for="duration_label">Duration Label</label>
                            <input type="text" id="duration_label" name="duration_label" value="{{ old('duration_label', $listing->duration_label) }}" placeholder="e.g. 3 Days, 2 Nights">
                        </div>
                        <div class="field" style="flex:1; min-width:120px;">
                            <label for="duration_days">Duration (days)</label>
                            <input type="number" id="duration_days" name="duration_days" value="{{ old('duration_days', $listing->duration_days) }}">
                        </div>
                    </div>
                    <div class="filter-inline" style="align-items:start; margin-top:14px;">
                        <div class="field" style="flex:1; min-width:140px;">
                            <label for="price_per_pax">Price / Pax (&#8369;)</label>
                            <input type="number" step="0.01" id="price_per_pax" name="price_per_pax" value="{{ old('price_per_pax', $listing->price_per_pax) }}">
                        </div>
                        <div class="field" style="flex:1; min-width:160px;">
                            <label for="price_tier">Budget Tier</label>
                            <select id="price_tier" name="price_tier">
                                <option value="">Not specified</option>
                                @foreach (['Budget-Friendly', 'Mid-range', 'Premium'] as $tier)
                                    <option value="{{ $tier }}" @selected(old('price_tier', $listing->price_tier) === $tier)>{{ $tier }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field" style="flex:1; min-width:160px;">
                            <label for="provider_name">Provider Name (free text, if no registered operator)</label>
                            <input type="text" id="provider_name" name="provider_name" value="{{ old('provider_name', $listing->provider_name) }}">
                        </div>
                    </div>
                    <div class="field">
                        <label for="tour_operator_id">Registered Tour Operator</label>
                        <select id="tour_operator_id" name="tour_operator_id">
                            <option value="">None &mdash; use provider name above</option>
                            @foreach ($tourOperators as $operator)
                                <option value="{{ $operator->id }}" @selected(old('tour_operator_id', $listing->tour_operator_id) == $operator->id)>{{ $operator->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field">
                        <label for="inclusions">Inclusions (one per line)</label>
                        <textarea id="inclusions" name="inclusions" rows="5">{{ old('inclusions', $listing->exists ? $listing->inclusions->pluck('item')->implode("\n") : '') }}</textarea>
                    </div>
                    <div class="filter-inline" style="align-items:start; margin-top:14px;">
                        <div class="field" style="flex:1; min-width:160px;">
                            <label for="latitude">Latitude</label>
                            <input type="number" step="0.0000001" id="latitude" name="latitude" value="{{ old('latitude', $listing->latitude) }}">
                        </div>
                        <div class="field" style="flex:1; min-width:160px;">
                            <label for="longitude">Longitude</label>
                            <input type="number" step="0.0000001" id="longitude" name="longitude" value="{{ old('longitude', $listing->longitude) }}">
                        </div>
                    </div>
                    <label class="field-check">
                        <input type="checkbox" name="featured" value="1" @checked(old('featured', $listing->featured))>
                        <span>Feature on homepage</span>
                    </label>
                    @break

                @case('souvenir-centers')
                    <div class="filter-inline" style="align-items:start; margin-top:14px;">
                        <div class="field" style="flex:1; min-width:160px;">
                            <label for="latitude">Latitude</label>
                            <input type="number" step="0.0000001" id="latitude" name="latitude" value="{{ old('latitude', $listing->latitude) }}">
                        </div>
                        <div class="field" style="flex:1; min-width:160px;">
                            <label for="longitude">Longitude</label>
                            <input type="number" step="0.0000001" id="longitude" name="longitude" value="{{ old('longitude', $listing->longitude) }}">
                        </div>
                    </div>
                    @break

                @case('tour-operators')
                    <div class="filter-inline" style="align-items:start; margin-top:14px;">
                        <div class="field" style="flex:1; min-width:160px;">
                            <label for="specialization">Specialization</label>
                            <input type="text" id="specialization" name="specialization" value="{{ old('specialization', $listing->specialization) }}" placeholder="e.g. Mountain Trekking">
                        </div>
                        <div class="field" style="flex:1; min-width:160px;">
                            <label for="price_tier">Budget Tier</label>
                            <select id="price_tier" name="price_tier">
                                <option value="">Not specified</option>
                                @foreach (['Budget-Friendly', 'Mid-range', 'Premium'] as $tier)
                                    <option value="{{ $tier }}" @selected(old('price_tier', $listing->price_tier) === $tier)>{{ $tier }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field" style="flex:1; min-width:160px;">
                            <label for="contact_number">Contact Number</label>
                            <input type="text" id="contact_number" name="contact_number" value="{{ old('contact_number', $listing->contact_number) }}">
                        </div>
                    </div>
                    <div class="filter-inline" style="align-items:start; margin-top:14px;">
                        <div class="field" style="flex:1; min-width:160px;">
                            <label for="latitude">Latitude</label>
                            <input type="number" step="0.0000001" id="latitude" name="latitude" value="{{ old('latitude', $listing->latitude) }}">
                        </div>
                        <div class="field" style="flex:1; min-width:160px;">
                            <label for="longitude">Longitude</label>
                            <input type="number" step="0.0000001" id="longitude" name="longitude" value="{{ old('longitude', $listing->longitude) }}">
                        </div>
                    </div>
                    @break
            @endswitch

            <label class="field-check">
                <input type="checkbox" name="is_accredited" value="1" @checked(old('is_accredited', $listing->is_accredited))>
                <span>DOT Accredited</span>
            </label>

            <div class="util-row" style="margin-top:22px;">
                <button type="submit" class="btn btn-primary">{{ $listing->exists ? 'Save Changes' : 'Create '.$config['singular'] }}</button>
                <a href="{{ route('admin.listings.index', $type) }}" class="btn btn-ghost">Cancel</a>
                @if ($listing->exists && $listing->is_accredited && ! $listing->archived_at)
                    <a href="{{ route('admin.listings.qr-code', [$type, $listing->id]) }}" target="_blank" class="btn btn-outline">Download QR Code</a>
                @endif
            </div>
        </form>
    </div>
</div>

@endsection
