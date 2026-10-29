{{--
    A row of amenity chips. $keys are catalog keys (App\Support\Amenities). With $evidence (the keys a photo shows) each
    chip also says whether there is a photo of it; without, it is the plain public list.
--}}
@php $evidence = $evidence ?? null; @endphp
@forelse ($keys as $key)
    @php $access = in_array(\App\Support\Amenities::group($key), [\App\Support\Amenities::ACCESS, \App\Support\Amenities::WHO], true); @endphp
    <span class="amen-chip {{ $access ? 'amen-chip--access' : '' }}">{{ \App\Support\Amenities::label($key) }}@if ($evidence !== null)@if (in_array($key, $evidence, true))<span class="amen-chip__ev" title="A photo shows this"><x-icon name="camera" /><span class="sr-only"> has a photo</span></span>@else<span class="amen-chip__ev is-no" title="No photo of this yet">no photo</span>@endif @endif</span>
@empty
    <span class="amen-none">{{ $empty ?? 'None listed' }}</span>
@endforelse
