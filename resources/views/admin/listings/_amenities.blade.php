{{--
    Amenities and accessibility for a listing, from the one shared list (App\Support\Amenities). A package has who it
    suits, what is on the trip, a difficulty and a note instead. $type (the admin route name), $listing.
--}}
@php
    $kind = \App\Services\Admin\DataCompleteness::TYPES[$type]['kind'] ?? null;
@endphp
@if ($kind && \App\Support\Amenities::hasList($kind))
    @php
        $ticked = old($kind === 'package' ? 'features' : 'amenities', $listing->exists ? app(\App\Services\Amenities\AmenityStore::class)->keys($kind, $listing) : []);
        $groups = $kind === 'package'
            ? [\App\Support\Amenities::WHO => 'Who it suits', \App\Support\Amenities::TRIP => 'On the trip']
            : [\App\Support\Amenities::FACILITY => 'Facilities', \App\Support\Amenities::ACCESS => 'Accessibility'];
    @endphp
    <fieldset class="field" style="border:1px solid var(--border); border-radius:10px; padding:14px 16px; margin:18px 0;">
        <legend style="padding:0 6px; font-weight:600;">{{ $kind === 'package' ? 'Accessibility &amp; suitability' : 'Amenities' }}</legend>
        <input type="hidden" name="amenities_form" value="1">

        @if ($kind === 'package')
            <div class="field">
                <label for="difficulty">How demanding is it?</label>
                <select id="difficulty" name="difficulty" class="form-select">
                    <option value="">Not set</option>
                    @foreach (\App\Support\Amenities::DIFFICULTY as $key => [$label])
                        <option value="{{ $key }}" @selected(old('difficulty', $listing->difficulty) === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        @foreach ($groups as $group => $title)
            <div class="about-card__label" style="margin:10px 0 6px;">{{ $title }}</div>
            <div style="display:flex; flex-wrap:wrap; gap:8px 18px;">
                @foreach (\App\Support\Amenities::keysFor($kind, $group) as $key)
                    <label class="field-check" style="margin:0;"><input type="checkbox" name="{{ $kind === 'package' ? 'features' : 'amenities' }}[]" value="{{ $key }}" @checked(in_array($key, (array) $ticked, true))><span>{{ \App\Support\Amenities::label($key) }}</span></label>
                @endforeach
            </div>
        @endforeach

        @if ($kind === 'package')
            <div class="field" style="margin-top:12px;">
                <label for="accessibility_note">Anything travellers with health conditions should know</label>
                <textarea id="accessibility_note" name="accessibility_note" rows="2" maxlength="300">{{ old('accessibility_note', $listing->accessibility_note) }}</textarea>
            </div>
        @endif
    </fieldset>
@endif
