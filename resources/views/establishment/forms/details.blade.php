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
            </section>
        @endif
    </div>

    <div class="util-row" style="margin-top:22px;">
        <button type="submit" class="btn btn-primary">Save changes</button>
        <a href="{{ route('establishment.listing.edit') }}" class="btn btn-ghost" data-modal-close>Cancel</a>
    </div>
</form>

@if ($establishment->listing_kind === 'package')
<script>
    /*
     * Day-by-day itinerary editor: builds the "Title | Description" text the server has always expected
     * (EstablishmentDashboardController::syncItineraryDays()) from one card per day. #itinerary is still a plain
     * textarea, hidden and filled in right before the form is sent. This script runs again each time the form is
     * loaded into the modal.
     */
    (function () {
        var form = document.getElementById('listing-form');
        var editor = document.getElementById('itinerary-editor');
        var template = document.getElementById('itinerary-day-template');
        var addBtn = document.getElementById('itinerary-add-day');
        var hidden = document.getElementById('itinerary');
        if (!form || !editor || !template || !addBtn || !hidden) return;

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
            card.querySelector('[data-remove]').addEventListener('click', function () { card.remove(); renumber(); });
            card.querySelector('[data-move-up]').addEventListener('click', function () { var p = card.previousElementSibling; if (p) editor.insertBefore(card, p); renumber(); });
            card.querySelector('[data-move-down]').addEventListener('click', function () { var n = card.nextElementSibling; if (n) editor.insertBefore(n, card); renumber(); });
            editor.appendChild(card);
        }

        var lines = hidden.value.split('\n').map(function (l) { return l.trim(); }).filter(Boolean);
        if (lines.length) {
            lines.forEach(function (line) { var bar = line.indexOf('|'); if (bar === -1) addCard(line, ''); else addCard(line.slice(0, bar).trim(), line.slice(bar + 1).trim()); });
        } else {
            addCard('', '');
        }
        renumber();
        addBtn.addEventListener('click', function () { addCard('', ''); renumber(); });

        // Capture phase, so the text is ready before the modal's own submit handler reads the form.
        form.addEventListener('submit', function () {
            var out = [];
            editor.querySelectorAll('.itinerary-day-card').forEach(function (card) {
                var title = card.querySelector('[data-day-title]').value.trim();
                var description = card.querySelector('[data-day-description]').value.trim();
                if (!title) return;
                out.push(description ? title + ' | ' + description : title);
            });
            hidden.value = out.join('\n');
        }, true);
    })();
</script>
@endif
