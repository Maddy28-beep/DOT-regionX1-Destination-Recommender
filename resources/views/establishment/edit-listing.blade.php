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

    // One line per day, "Title | Description", matching what the form
    // posts back -- so re-editing shows exactly what was last saved.
    $itineraryText = $establishment->listing_kind === 'package'
        ? $listing->itineraryDays->map(fn ($day) => $day->description ? "{$day->title} | {$day->description}" : $day->title)->implode("\n")
        : '';
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

            @include('partials.location-picker', ['listing' => $listing])

            @if ($establishment->listing_kind === 'package')
                <div class="field" style="margin-top:20px;">
                    <label for="itinerary">Day-by-Day Itinerary</label>
                    <textarea id="itinerary" name="itinerary" rows="6" placeholder="Day 1 title | Day 1 details (optional)&#10;Day 2 title | Day 2 details (optional)">{{ old('itinerary', $itineraryText) }}</textarea>
                    <p class="field-hint">One day per line: a short title, then optionally a "|" and more detail. The day number comes from the line's order.</p>
                </div>
            @endif

            <div class="field-group" style="margin-top:24px;">
                <h3 style="margin-bottom:4px;">Online Presence</h3>
                <p class="field-hint" style="margin-top:0;">Optional. Add links so travelers can find you online &mdash; they'll appear on your public listing page automatically.</p>

                <div class="field">
                    <label for="website_url">Official Website</label>
                    <input type="url" id="website_url" name="website_url" placeholder="https://www.yourbusiness.com" value="{{ old('website_url', $listing->website_url) }}">
                </div>

                <div class="field">
                    <label for="facebook_url">Facebook Page</label>
                    <input type="url" id="facebook_url" name="facebook_url" placeholder="https://facebook.com/yourbusiness" value="{{ old('facebook_url', $listing->facebook_url) }}">
                </div>

                <div class="field">
                    <label for="instagram_url">Instagram (optional)</label>
                    <input type="url" id="instagram_url" name="instagram_url" placeholder="https://instagram.com/yourbusiness" value="{{ old('instagram_url', $listing->instagram_url) }}">
                </div>

                <div class="field">
                    <label for="tiktok_url">TikTok (optional)</label>
                    <input type="url" id="tiktok_url" name="tiktok_url" placeholder="https://tiktok.com/@yourbusiness" value="{{ old('tiktok_url', $listing->tiktok_url) }}">
                </div>
            </div>

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
