{{--
    The listing's details, on their own: My Listing opens them in a modal (public/js/admin-modal.js) and the full page
    wraps them in a panel. Expects $establishment, $listing.
--}}
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

    // One line per day, "Title | Description", matching what the form posts back.
    $itineraryText = $establishment->listing_kind === 'package'
        ? $listing->itineraryDays->map(fn ($day) => $day->description ? "{$day->title} | {$day->description}" : $day->title)->implode("\n")
        : '';
@endphp
<form method="POST" action="{{ route('establishment.listing.update') }}" id="listing-form">
    @csrf
    @method('PUT')
    <input type="hidden" name="section" value="details">

    <div class="ptr-cols">
        <section class="ptr-col">
            <h3>About</h3>
            <div class="field">
                <label for="description">Description</label>
                <textarea id="description" name="description" rows="6" maxlength="1000">{{ old('description', $listing->description) }}</textarea>
                <p class="field-hint">At least 60 characters. Say what makes the place worth the trip.</p>
            </div>
            <div class="field">
                <label for="price_tier">Budget tier</label>
                <select id="price_tier" name="price_tier" class="form-select">
                    <option value="">Not specified</option>
                    @foreach (['Budget-Friendly', 'Mid-range', 'Premium'] as $tier)
                        <option value="{{ $tier }}" @selected(old('price_tier', $listing->price_tier) === $tier)>{{ $tier }}</option>
                    @endforeach
                </select>
            </div>
            @if ($priceLabel)
                <div class="field">
                    <label for="price_amount">{{ $priceLabel }}</label>
                    <input type="number" id="price_amount" name="price_amount" step="0.01" min="0" value="{{ old('price_amount', $priceValue) }}">
                </div>
            @endif
        </section>

        <section class="ptr-col">
            <h3>Online presence</h3>
            <div class="field">
                <label for="website_url">Official website</label>
                <input type="url" id="website_url" name="website_url" placeholder="https://www.yourbusiness.com" value="{{ old('website_url', $listing->website_url) }}">
            </div>
            <div class="field">
                <label for="facebook_url">Facebook page</label>
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
        </section>

        @if ($establishment->listing_kind === 'package')
            <section class="ptr-col">
                <h3>Day-by-day itinerary</h3>
                <p class="field-hint" style="margin-top:0;">One card per day of the trip, in order. A short title is enough; the detail is optional.</p>
                @include('establishment.partials.itinerary-editor', ['formId' => 'listing-form', 'text' => $itineraryText])
            </section>
        @endif
    </div>

    <div class="util-row" style="margin-top:22px;">
        <button type="submit" class="btn btn-primary">Save changes</button>
        <a href="{{ route('establishment.listing.edit') }}" class="btn btn-ghost" data-modal-close>Cancel</a>
    </div>
</form>

