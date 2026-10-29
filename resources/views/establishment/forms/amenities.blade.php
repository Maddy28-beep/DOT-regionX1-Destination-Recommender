{{-- Tick what is really there. Only choices that make sense for this kind of listing are offered. Expects $establishment, $ticked. --}}
@php
    $kind = $establishment->listing_kind;
    $current = old('amenities', $ticked);
    $groups = [\App\Support\Amenities::FACILITY => 'Facilities', \App\Support\Amenities::ACCESS => 'Accessibility'];
@endphp
<form method="POST" action="{{ route('establishment.listing.update') }}">
    @csrf
    @method('PUT')
    <input type="hidden" name="section" value="amenities">

    @foreach ($groups as $group => $title)
        @php $keys = \App\Support\Amenities::keysFor($kind, $group); @endphp
        @if ($keys !== [])
            <span class="ptr-eyebrow">{{ $title }}</span>
            <div class="ptr-incl" style="margin-bottom:14px;">
                @foreach ($keys as $key)
                    <label class="ptr-chk"><input type="checkbox" name="amenities[]" value="{{ $key }}" @checked(in_array($key, (array) $current, true))> {{ \App\Support\Amenities::label($key) }}</label>
                @endforeach
            </div>
        @endif
    @endforeach
    <p class="field-hint">The same list is used on every listing and by the trip planner, so what travellers ask for is what you can say you have. Add a photo of each from the Photos page.</p>

    <div class="util-row" style="margin-top:22px;">
        <button type="submit" class="btn btn-primary">Save amenities</button>
        <a href="{{ route('establishment.listing.edit') }}" class="btn btn-ghost" data-modal-close>Cancel</a>
    </div>
</form>
