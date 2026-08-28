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
            <p>Update the details travelers see on your listing page.</p>
        </div>
    </div>
    <div class="panel-body">
        <x-banner tone="info">
            Your listing <strong>name</strong> and <strong>accreditation details</strong> are managed by
            DOT Region XI Admin and can't be edited here. Contact DOT Region XI if either needs correcting.
        </x-banner>

        <form method="POST" action="{{ route('establishment.listing.update') }}">
            @csrf
            @method('PUT')

            <div class="field">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="5">{{ old('description', $listing->description) }}</textarea>
            </div>

            <div class="field">
                <label for="price_tier">Budget tier</label>
                <select id="price_tier" name="price_tier" class="form-select">
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
            <div class="qr-block">
                <div class="qr-frame">
                    <span class="qr-frame__tab">Scan me</span>
                    <img src="{{ route('establishment.listing.qr-code') }}" alt="QR code linking to {{ $listing->name }}">
                </div>

                <div class="qr-meta">
                    <div class="qr-meta__label">Where this code goes</div>
                    <a href="{{ $qrTargetUrl }}" class="qr-url" target="_blank" rel="noopener">{{ $qrTargetUrl }}</a>

                    <x-banner tone="info">
                        Scanning also records a check-in for signed-in travelers before forwarding
                        them to your listing page &mdash; that's what powers your visit counts.
                    </x-banner>

                    <div class="qr-actions">
                        <a href="{{ route('establishment.listing.qr-code') }}"
                           download="{{ \Illuminate\Support\Str::slug($listing->name) }}-qr-code.svg"
                           class="btn btn-primary">Download QR Code</a>
                        <a href="{{ route('establishment.listing.qr-code') }}" target="_blank" rel="noopener"
                           class="btn btn-outline">Open for printing</a>
                    </div>
                </div>
            </div>
        @else
            <x-banner tone="warn">
                A QR code becomes available once your listing is DOT-accredited and active.
            </x-banner>
        @endif
    </div>
</div>

@endsection
