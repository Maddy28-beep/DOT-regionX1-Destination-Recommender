@extends('layouts.establishment')

@section('title', 'My Listing — Partner Dashboard')
@section('page-title', 'My Listing')
@section('page-sub', 'The information travellers see on ExploreDVO')

@section('page-actions')
    <a href="{{ route('establishment.listing.form', 'details') }}" class="btn btn-primary" data-modal-form data-title="Edit details" data-sub="What travellers read about you and where they can find you online"><x-icon name="pencil" /> Edit details</a>
@endsection

@section('content')

@php
    $kind = $establishment->listing_kind;
    $price = match ($kind) {
        'accommodation' => $listing->price_per_night ? '₱'.number_format($listing->price_per_night).' per night' : null,
        'package' => $listing->price_per_pax ? '₱'.number_format($listing->price_per_pax).' per person' : null,
        default => null,
    };
    $tracksStatus = method_exists($listing, 'operatingNotice');
    $closedNotice = $tracksStatus ? $listing->operatingNotice() : null;
    $hasPoint = $listing->latitude !== null && $listing->longitude !== null;
    $edit = fn (string $section, string $title, string $sub, ?string $size = null) => 'href="'.route('establishment.listing.form', $section).'" data-modal-form data-title="'.e($title).'" data-sub="'.e($sub).'"'.($size ? ' data-size="'.$size.'"' : '');
@endphp

<div class="adm-cols ptr-cols2">
    <div class="adm-stack">
        @include('establishment.partials.readiness', ['readiness' => $readiness])

        <div class="adm-card">
            <div class="adm-card__head">
                <div><h2>{{ $listing->name }}</h2><p>{{ $listing->location }}</p></div>
            </div>
            <div class="adm-card__body">
                <p class="ptr-note">Your listing <strong>name</strong> and <strong>accreditation details</strong> are managed by DOT Region XI and can't be edited here. Contact DOT Region XI if either needs correcting.</p>
                <dl class="ptr-dl">
                    <dt>Description</dt><dd>{{ $listing->description ?: '—' }}</dd>
                    <dt>Budget tier</dt><dd>{{ $listing->price_tier ?: '—' }}</dd>
                    @if ($price)<dt>Price</dt><dd>{{ $price }}</dd>@endif
                    @if ($hoursColumn)
                        <dt>Opening hours</dt>
                        <dd>@if ($hours !== '' && $hours !== null){{ $hours }} <a class="adm-link" {!! $edit('hours', 'Opening hours', 'Shown to travellers and used when they plan a day', 'narrow') !!}>Edit</a>@else<span class="ptr-missing">Not set</span> <a class="adm-link" {!! $edit('hours', 'Opening hours', 'Shown to travellers and used when they plan a day', 'narrow') !!}>Add hours</a>@endif</dd>
                    @endif
                    <dt>Map position</dt>
                    <dd>@if ($hasPoint){{ number_format((float) $listing->latitude, 5) }}, {{ number_format((float) $listing->longitude, 5) }} <a class="adm-link" {!! $edit('position', 'Pin your place on the map', 'Travellers use this to find you and to plan the route between places', 'narrow') !!}>Move</a>@else<span class="ptr-missing">Not set</span> <a class="adm-link" {!! $edit('position', 'Pin your place on the map', 'Travellers use this to find you and to plan the route between places', 'narrow') !!}>Pin it</a>@endif</dd>
                    <dt>Website</dt><dd>{{ $listing->website_url ?: '—' }}</dd>
                    <dt>Facebook</dt><dd>{{ $listing->facebook_url ?: '—' }}</dd>
                    <dt>Instagram</dt><dd>{{ $listing->instagram_url ?: '—' }}</dd>
                    <dt>TikTok</dt><dd>{{ $listing->tiktok_url ?: '—' }}</dd>
                    @if ($kind === 'package')
                        <dt>Itinerary</dt><dd>{{ $listing->itineraryDays->count() }} {{ \Illuminate\Support\Str::plural('day', $listing->itineraryDays->count()) }}</dd>
                    @endif
                </dl>
            </div>
        </div>
    </div>

    <div class="adm-stack">
        @if ($tracksStatus)
            <div id="operating-status" class="adm-card ptr-status {{ $closedNotice ? 'is-closed' : '' }}">
                <span class="ptr-status__dot" aria-hidden="true"></span>
                <div>
                    <h2>Operating status</h2>
                    <p>{{ $closedNotice ?? 'Open for visitors' }}</p>
                </div>
                <a href="{{ route('establishment.listing.form', 'status') }}" class="btn btn-outline btn-sm" data-modal-form data-title="Operating status" data-sub="Tell travellers whether you are open" data-size="narrow">Change</a>
            </div>
        @endif

        @if ($amenityKeys !== null)
            @php
                $facilities = array_values(array_filter($amenityKeys, fn ($k) => \App\Support\Amenities::group($k) === \App\Support\Amenities::FACILITY));
                $access = array_values(array_filter($amenityKeys, fn ($k) => \App\Support\Amenities::group($k) === \App\Support\Amenities::ACCESS));
                $unphotographed = array_values(array_diff($amenityKeys, $amenityPhotos));
            @endphp
            <div class="adm-card" id="amenities">
                <div class="adm-card__head">
                    <div><h2>Amenities</h2><p>{{ count($amenityKeys) }} ticked. Travellers and the trip planner use these.</p></div>
                    <a href="{{ route('establishment.listing.form', 'amenities') }}" class="btn btn-outline btn-sm" data-modal-form data-title="Amenities" data-sub="Tick what is really there" data-size="narrow"><x-icon name="pencil" /> Edit</a>
                </div>
                <div class="adm-card__body ptr-pad">
                    <span class="ptr-eyebrow">Facilities</span>
                    <div>@include('partials.amenity-chips', ['keys' => $facilities, 'evidence' => $amenityPhotos, 'empty' => 'None ticked yet'])</div>
                    <span class="ptr-eyebrow" style="margin-top:10px;">Accessibility</span>
                    <div>@include('partials.amenity-chips', ['keys' => $access, 'evidence' => $amenityPhotos, 'empty' => 'None ticked yet'])</div>
                    @if ($unphotographed !== [])
                        <p class="ptr-callout" style="margin:12px 0 0;"><b>{{ count($unphotographed) }} ticked without a photo:</b> {{ collect($unphotographed)->map(fn ($k) => \App\Support\Amenities::label($k))->implode(', ') }}. Optional, but travellers trust what they can see. <a class="adm-link" href="{{ route('establishment.photos') }}">Add photos</a></p>
                    @endif
                </div>
            </div>
        @endif

        <div class="adm-card">
            <div class="adm-card__head">
                <div><h2>Your QR code</h2><p>Guests scan it to check in</p></div>
            </div>
            <div class="adm-card__body">
                @if (app(\App\Support\SampleData::class)->isSample($listing))
                    <p class="ptr-note">This listing is sample data, not DOT-verified, so it has no QR code.</p>
                @elseif ($listing->is_accredited && ! $listing->archived_at)
                    <div class="ptr-qr">
                        <div class="ptr-qr__img"><img src="{{ route('establishment.listing.qr-code') }}" alt="QR code linking to {{ $listing->name }}"></div>
                        <div>
                            <p>Print it for your front desk or entrance. Scanning records a check-in for signed-in travellers before sending them to your page &mdash; that is what powers your visit counts.</p>
                            <p class="ptr-url"><a href="{{ $qrTargetUrl }}" target="_blank" rel="noopener">Where this code goes<span class="sr-only"> (opens in a new tab)</span></a></p>
                            <div class="ptr-qr__acts">
                                <a href="{{ route('establishment.listing.qr-code') }}" download="{{ \Illuminate\Support\Str::slug($listing->name) }}-qr-code.svg" class="btn btn-primary btn-sm">Download</a>
                                <a href="{{ route('establishment.listing.qr-code') }}" target="_blank" rel="noopener" class="btn btn-outline btn-sm">Open for printing<span class="sr-only"> (opens in a new tab)</span></a>
                            </div>
                        </div>
                    </div>
                @else
                    <p class="ptr-note">A QR code becomes available once your listing is DOT-accredited and active.</p>
                @endif
            </div>
        </div>
    </div>
</div>

@endsection
