@extends('layouts.establishment')

@section('title', 'Edit Listing — Partner Dashboard')
@section('page-title', 'My Listing')
@section('page-sub', 'Update the information travelers see on ExploreDVO')

@section('content')

@php
    $priceLabel = match ($establishment->listing_kind) {
        'accommodation' => 'Price per night (₱)',
        'package' => 'Price per person (₱)',
        default => null,
    };
    $priceValue = match ($establishment->listing_kind) {
        'accommodation' => $listing->price_per_night,
        'package' => $listing->price_per_pax,
        default => null,
    };
@endphp

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>{{ $listing->name }}</h2>
            <p>Some fields (name, accreditation) are managed by DOT Admin and can't be edited here.</p>
        </div>
    </div>
    <div class="panel-body">
        <form method="POST" action="{{ route('establishment.listing.update') }}">
            @csrf
            @method('PUT')

            <div class="field">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="5">{{ old('description', $listing->description) }}</textarea>
            </div>

            <div class="field">
                <label for="price_tier">Budget tier</label>
                <select id="price_tier" name="price_tier">
                    <option value="">Not specified</option>
                    <option value="Budget-Friendly" @selected(old('price_tier', $listing->price_tier) === 'Budget-Friendly')>Budget-Friendly</option>
                    <option value="Mid-range" @selected(old('price_tier', $listing->price_tier) === 'Mid-range')>Mid-range</option>
                    <option value="Premium" @selected(old('price_tier', $listing->price_tier) === 'Premium')>Premium</option>
                </select>
            </div>

            @if ($priceLabel)
                <div class="field">
                    <label for="price_amount">{{ $priceLabel }}</label>
                    <input type="number" id="price_amount" name="price_amount" step="0.01" min="0" value="{{ old('price_amount', $priceValue) }}">
                </div>
            @endif

            <button type="submit" class="btn btn-primary" style="margin-top:20px;">Save Changes</button>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Your QR Code</h2>
            <p>Scanning this opens your ExploreDVO listing page directly &mdash; print it for your counter, window, or brochures.</p>
        </div>
    </div>
    <div class="panel-body">
        @if ($listing->is_accredited && ! $listing->archived_at)
            <img src="{{ route('establishment.listing.qr-code') }}" alt="QR code linking to {{ $listing->name }}" width="180" height="180" style="border:1px solid var(--border); border-radius:8px; padding:8px;">
            <div style="margin-top:12px;">
                <a href="{{ route('establishment.listing.qr-code') }}" download="{{ \Illuminate\Support\Str::slug($listing->name) }}-qr-code.svg" class="btn btn-outline">Download QR Code</a>
            </div>
        @else
            <p style="color:var(--muted); font-size:.85rem;">A QR code becomes available once your listing is DOT-accredited and active.</p>
        @endif
    </div>
</div>

@endsection
