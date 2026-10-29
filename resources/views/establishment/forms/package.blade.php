{{--
    A tour operator's package, in three tabs: the basics, what is included, and the day by day. A new package, or one
    DOT asked to be changed, can be saved as a draft or sent to DOT; one that is live saves straight away.

    $package (new or saved)
--}}
@php
    $status = $package->exists ? $package->status : null;
    $sendable = ! $package->exists || in_array($status, ['draft', 'changes_requested'], true);
    $included = old('inclusions', $package->exists ? $package->inclusions->pluck('item')->all() : []);
    $text = $package->exists
        ? $package->itineraryDays->map(fn ($d) => $d->description ? "{$d->title} | {$d->description}" : $d->title)->implode("\n")
        : '';
    $features = old('features', $package->exists ? app(\App\Services\Amenities\AmenityStore::class)->keys('package', $package) : []);
    $difficulty = old('difficulty', $package->difficulty);
    $action = $package->exists ? route('establishment.packages.update', $package) : route('establishment.packages.store');
@endphp
<form method="POST" action="{{ $action }}" id="package-form">
    @csrf
    @if ($package->exists) @method('PUT') @endif
    {{-- Not named "action": a field with that name hides the form's own action URL from form.action, which the modal posts to. --}}
    <input type="hidden" name="intent" id="package-intent" value="draft">

    @if ($status === 'changes_requested' && $package->review_note)
        <x-banner tone="warn"><strong>Note from DOT:</strong> {{ $package->review_note }}</x-banner>
    @elseif ($status === 'in_review')
        <x-banner tone="info">This package is with DOT. Saving keeps it there.</x-banner>
    @elseif (in_array($status, ['live', 'paused'], true))
        <x-banner tone="success">This package is {{ $status }}. Saving goes live straight away; DOT only reviews new packages.</x-banner>
    @endif

    <div class="ptr-tabs" role="tablist" aria-label="Package sections">
        <button type="button" role="tab" id="tab-basics" aria-selected="true" aria-controls="pane-basics" data-tab="basics">1 &middot; Basics</button>
        <button type="button" role="tab" id="tab-included" aria-selected="false" aria-controls="pane-included" data-tab="included" tabindex="-1">2 &middot; What&rsquo;s included</button>
        <button type="button" role="tab" id="tab-days" aria-selected="false" aria-controls="pane-days" data-tab="days" tabindex="-1">3 &middot; Day by day <span class="ptr-tabn" id="itinerary-count">0</span></button>
        <button type="button" role="tab" id="tab-access" aria-selected="false" aria-controls="pane-access" data-tab="access" tabindex="-1">4 &middot; Accessibility</button>
    </div>

    <section id="pane-basics" role="tabpanel" aria-labelledby="tab-basics" data-pane="basics">
        <div class="field">
            <label for="pkg_name">Package name</label>
            <input type="text" id="pkg_name" name="name" maxlength="150" required value="{{ old('name', $package->name) }}" placeholder="e.g. Samal Island Day Escape">
        </div>
        <div class="field">
            <label for="pkg_description">Description</label>
            <textarea id="pkg_description" name="description" rows="4" maxlength="1000" placeholder="What makes this trip worth booking?">{{ old('description', $package->description) }}</textarea>
            <p class="field-hint">At least 40 characters to send it to DOT.</p>
        </div>
        <div class="ptr-two">
            <div class="field">
                <label for="pkg_price">Price per person (₱)</label>
                <input type="number" id="pkg_price" name="price_per_pax" min="0" step="0.01" value="{{ old('price_per_pax', $package->price_per_pax) }}">
            </div>
            <div class="field">
                <label for="pkg_tier">Budget tier</label>
                <select id="pkg_tier" name="price_tier" class="form-select">
                    @foreach (['Budget-Friendly', 'Mid-range', 'Premium'] as $tier)
                        <option value="{{ $tier }}" @selected(old('price_tier', $package->price_tier) === $tier)>{{ $tier }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="field">
            <label for="pkg_meeting">Meeting point</label>
            <input type="text" id="pkg_meeting" name="meeting_point" maxlength="150" value="{{ old('meeting_point', $package->meeting_point) }}" placeholder="e.g. Sasa Wharf, Davao City">
            <p class="field-hint">Travellers ask this first.</p>
        </div>
    </section>

    <section id="pane-included" role="tabpanel" aria-labelledby="tab-included" data-pane="included" hidden>
        <span class="ptr-eyebrow">Included in the price</span>
        <div class="ptr-incl">
            @foreach (\App\Models\Package::INCLUSION_CHOICES as $choice)
                <label class="ptr-chk"><input type="checkbox" name="inclusions[]" value="{{ $choice }}" @checked(in_array($choice, (array) $included, true))> {{ $choice }}</label>
            @endforeach
        </div>
    </section>

    <section id="pane-days" role="tabpanel" aria-labelledby="tab-days" data-pane="days" hidden>
        <p class="field-hint" style="margin-top:0;">One card per day, in order. A short title is enough; the detail is optional.</p>
        @include('establishment.partials.itinerary-editor', ['formId' => 'package-form', 'text' => $text])
    </section>

    <section id="pane-access" role="tabpanel" aria-labelledby="tab-access" data-pane="access" hidden>
        <span class="ptr-eyebrow">How demanding is it?</span>
        <div class="ptr-choices" role="radiogroup" aria-label="Difficulty">
            @foreach (\App\Support\Amenities::DIFFICULTY as $key => [$label, $hint])
                <label><input type="radio" name="difficulty" value="{{ $key }}" @checked($difficulty === $key)><span>{{ $label }}<small>{{ $hint }}</small></span></label>
            @endforeach
        </div>

        @foreach ([\App\Support\Amenities::WHO => 'Who is it suitable for?', \App\Support\Amenities::TRIP => 'What is there on the trip?'] as $group => $title)
            <span class="ptr-eyebrow" style="margin-top:14px;">{{ $title }}</span>
            <div class="ptr-incl">
                @foreach (\App\Support\Amenities::keysFor('package', $group) as $key)
                    <label class="ptr-chk"><input type="checkbox" name="features[]" value="{{ $key }}" @checked(in_array($key, (array) $features, true))> {{ \App\Support\Amenities::label($key) }}</label>
                @endforeach
            </div>
        @endforeach

        <div class="field" style="margin-top:14px;">
            <label for="pkg_access_note">Anything travellers with health conditions should know</label>
            <textarea id="pkg_access_note" name="accessibility_note" rows="3" maxlength="300" placeholder="e.g. 4 hours of walking on uneven ground; no vehicle access after the trailhead.">{{ old('accessibility_note', $package->accessibility_note) }}</textarea>
            <p class="field-hint">Shown on the public page and read by DOT when they review it.</p>
        </div>
    </section>

    <div class="util-row" style="margin-top:22px;">
        @if ($sendable)
            <button type="submit" class="btn btn-primary" data-action="submit">{{ $status === 'changes_requested' ? 'Resubmit for review' : 'Submit for review' }}</button>
            <button type="submit" class="btn btn-outline" data-action="draft">Save draft</button>
        @else
            <button type="submit" class="btn btn-primary" data-action="save">Save changes</button>
        @endif
        <a href="{{ route('establishment.packages') }}" class="btn btn-ghost" data-modal-close>Cancel</a>
    </div>
</form>

<script>
    (function () {
        var form = document.getElementById('package-form');
        if (!form) return;
        var tabs = [].slice.call(form.querySelectorAll('[data-tab]'));
        function show(tab) {
            tabs.forEach(function (t) { var on = t === tab; t.setAttribute('aria-selected', on ? 'true' : 'false'); t.tabIndex = on ? 0 : -1; });
            form.querySelectorAll('[data-pane]').forEach(function (p) { p.hidden = p.dataset.pane !== tab.dataset.tab; });
        }
        tabs.forEach(function (t, i) {
            t.addEventListener('click', function () { show(t); });
            t.addEventListener('keydown', function (e) {
                var to = e.key === 'ArrowRight' ? tabs[(i + 1) % tabs.length] : e.key === 'ArrowLeft' ? tabs[(i - 1 + tabs.length) % tabs.length] : null;
                if (to) { e.preventDefault(); show(to); to.focus(); }
            });
        });
        // The modal sends FormData, which leaves out the button that was pressed, so the choice goes in a hidden field.
        form.querySelectorAll('button[data-action]').forEach(function (b) {
            b.addEventListener('click', function () { document.getElementById('package-intent').value = b.dataset.action; });
        });
    })();
</script>
