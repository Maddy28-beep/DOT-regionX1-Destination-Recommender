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

        <form method="POST" action="{{ route('establishment.listing.update') }}" id="listing-form">
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
                <div class="field-group" style="margin-top:20px;">
                    <label>Day-by-Day Itinerary</label>
                    <p class="field-hint" style="margin-top:0;">
                        Add one card per day of the trip, in order. A short title is enough &mdash; the
                        detail underneath is optional.
                    </p>

                    {{--
                        A plain "Title | Description" textarea (the previous version of this field)
                        asked a non-technical tour operator to learn a delimiter convention with no
                        visual feedback for getting it wrong. This keeps the exact same wire format --
                        the hidden #itinerary textarea below still posts "Title | Description" one line
                        per day, so EstablishmentDashboardController::syncItineraryDays() needs no
                        changes at all -- it's purely a friendlier way to build that same string.
                    --}}
                    <div class="itinerary-editor" id="itinerary-editor"></div>

                    <template id="itinerary-day-template">
                        <div class="itinerary-day-card">
                            <div class="itinerary-day-card__head">
                                <span class="itinerary-day-card__label">Day <span data-day-number></span></span>
                                <div class="itinerary-day-card__actions">
                                    <button type="button" data-move-up aria-label="Move this day earlier" title="Move earlier">&#9650;</button>
                                    <button type="button" data-move-down aria-label="Move this day later" title="Move later">&#9660;</button>
                                    <button type="button" data-remove aria-label="Remove this day" title="Remove day">&#10005;</button>
                                </div>
                            </div>
                            <input type="text" data-day-title placeholder="What happens this day? e.g. Arrival &amp; Camp 1">
                            <textarea data-day-description rows="2" placeholder="More detail (optional)"></textarea>
                        </div>
                    </template>

                    <button type="button" class="btn btn-outline btn-sm" id="itinerary-add-day" style="margin-top:4px;">+ Add another day</button>

                    <textarea id="itinerary" name="itinerary" hidden>{{ old('itinerary', $itineraryText) }}</textarea>
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

@if ($establishment->listing_kind === 'package')
<script>
    /*
     * Day-by-day itinerary editor: builds the "Title | Description" text the
     * server has always expected (EstablishmentDashboardController::
     * syncItineraryDays()), but as individual day cards instead of a
     * delimiter format the operator would otherwise have to learn. Nothing
     * about the wire format or the backend changed -- #itinerary is still a
     * plain textarea, just hidden and filled in right before submit.
     */
    (function () {
        var editor = document.getElementById('itinerary-editor');
        var template = document.getElementById('itinerary-day-template');
        var addBtn = document.getElementById('itinerary-add-day');
        var hidden = document.getElementById('itinerary');
        var form = document.getElementById('listing-form');
        if (!editor || !template || !addBtn || !hidden || !form) return;

        function renumber() {
            var cards = editor.querySelectorAll('.itinerary-day-card');
            cards.forEach(function (card, index) {
                card.querySelector('[data-day-number]').textContent = index + 1;
                card.querySelector('[data-move-up]').disabled = index === 0;
                card.querySelector('[data-move-down]').disabled = index === cards.length - 1;
            });
        }

        function addCard(title, description) {
            var card = template.content.firstElementChild.cloneNode(true);
            card.querySelector('[data-day-title]').value = title || '';
            card.querySelector('[data-day-description]').value = description || '';

            card.querySelector('[data-remove]').addEventListener('click', function () {
                card.remove();
                renumber();
            });
            card.querySelector('[data-move-up]').addEventListener('click', function () {
                var prev = card.previousElementSibling;
                if (prev) editor.insertBefore(card, prev);
                renumber();
            });
            card.querySelector('[data-move-down]').addEventListener('click', function () {
                var next = card.nextElementSibling;
                if (next) editor.insertBefore(next, card);
                renumber();
            });

            editor.appendChild(card);
            return card;
        }

        // Seed from whatever the hidden textarea already holds -- the last
        // saved days, or old('itinerary') after a failed submit -- so
        // re-opening this page always shows the same days it last posted.
        var existingLines = hidden.value.split('\n').map(function (l) { return l.trim(); }).filter(Boolean);
        if (existingLines.length) {
            existingLines.forEach(function (line) {
                var bar = line.indexOf('|');
                if (bar === -1) {
                    addCard(line, '');
                } else {
                    addCard(line.slice(0, bar).trim(), line.slice(bar + 1).trim());
                }
            });
        } else {
            addCard('', '');
        }
        renumber();

        addBtn.addEventListener('click', function () {
            addCard('', '');
            renumber();
        });

        form.addEventListener('submit', function () {
            var lines = [];
            editor.querySelectorAll('.itinerary-day-card').forEach(function (card) {
                var title = card.querySelector('[data-day-title]').value.trim();
                var description = card.querySelector('[data-day-description]').value.trim();
                if (!title) return; // an empty card is skipped, not saved as a blank day
                lines.push(description ? title + ' | ' + description : title);
            });
            hidden.value = lines.join('\n');
        });
    })();
</script>
@endif

@endsection
