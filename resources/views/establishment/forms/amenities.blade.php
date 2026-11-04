{{-- Tick what is really there. Only choices that make sense for this kind of listing are offered. Expects $establishment, $ticked. --}}
@php
    $kind = $establishment->listing_kind;
    $current = old('amenities', $ticked);
    $offered = \App\Support\Amenities::keysFor($kind);
    $names = old('amenity_names', app(\App\Services\Amenities\AmenityStore::class)->displayNames($kind, $listing));
@endphp
<form method="POST" action="{{ route('establishment.listing.update') }}" data-amenity-editor>
    @csrf
    @method('PUT')
    <input type="hidden" name="section" value="amenities">

    <div class="ptr-amenity-toolbar">
        <input type="search" data-amenity-search aria-label="Search amenities" placeholder="Search amenities (e.g. Swimming Pool)">
        <span class="ptr-amenity-count" data-amenity-count aria-live="polite">{{ count(array_intersect($offered, (array) $current)) }} selected</span>
    </div>
    <p class="field-hint" data-amenity-empty hidden>No matching amenities.</p>
    <div class="ptr-amenity-picks" data-amenity-picks hidden>
        <div class="ptr-amenity-picks__head">
            <strong>Your amenities</strong>
            <button type="button" class="ptr-amenity-link" data-amenity-clear-all>Clear all</button>
        </div>
        <div class="ptr-amenity-picks__list" data-amenity-picks-list></div>
    </div>
    @foreach (\App\Support\Amenities::EDITOR_GROUPS as $title => $groupKeys)
        @php $keys = array_intersect($offered, $groupKeys); @endphp
        @if ($keys !== [])
            <details class="ptr-amenity-group" open>
                <summary>{{ $title }} <small class="ptr-amenity-count" data-amenity-group-count>{{ count(array_intersect($keys, (array) $current)) }}/{{ count($keys) }}</small></summary>
                <div class="ptr-amenity-group__tools">
                    <button type="button" class="ptr-amenity-link" data-amenity-group-all>Select all</button>
                    <button type="button" class="ptr-amenity-link" data-amenity-group-none>Clear</button>
                </div>
                <div class="ptr-incl">
                    @foreach ($keys as $key)
                        @php $label = \App\Support\Amenities::label($key); @endphp
                        <div class="ptr-amenity-option" data-amenity-option="{{ $label }}">
                            <label class="ptr-chk"><input type="checkbox" name="amenities[]" value="{{ $key }}" @checked(in_array($key, (array) $current, true))> <span data-amenity-label>{{ filled($names[$key] ?? null) ? $names[$key] : $label }}</span><small class="ptr-amenity-renamed" data-amenity-renamed @if (blank($names[$key] ?? null)) hidden @endif>Renamed</small></label>
                            <button type="button" class="ptr-amenity-pencil" popovertarget="amenity-name-{{ $key }}" title="Edit display name for {{ $label }}" aria-label="Edit display name for {{ $label }}"><x-icon name="pencil" /></button>
                            <div class="ptr-amenity-name" id="amenity-name-{{ $key }}" popover>
                                <label class="field">
                                    <span>{{ $label }} display name (optional)</span>
                                    <input type="text" name="amenity_names[{{ $key }}]" maxlength="80" placeholder="{{ $label }}" value="{{ $names[$key] ?? '' }}" autofocus>
                                </label>
                                <small class="field-hint">Guests see this name. The category stays {{ $label }}.</small>
                                <div class="ptr-amenity-name-actions">
                                    <button type="button" class="btn btn-primary" popovertarget="amenity-name-{{ $key }}" popovertargetaction="hide">Done</button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </details>
        @endif
    @endforeach
    <p class="field-hint">Select what is available on site. Add photos from the Photos page.</p>

    <div class="util-row ptr-amenity-actions">
        <a href="{{ route('establishment.listing.edit') }}" class="btn btn-ghost" data-modal-close>Cancel</a>
        <button type="submit" class="btn btn-primary">Save amenities</button>
    </div>
</form>
