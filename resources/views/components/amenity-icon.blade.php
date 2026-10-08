@props(['name'])

{{--
    A small icon for a destination amenity, chosen by the amenity's slug ("parking-area", "wi-fi"). An
    amenity added later that has no icon here falls back to a plain check mark.
--}}
@php $slug = \Illuminate\Support\Str::slug($name); @endphp

<svg {{ $attributes->merge(['viewBox' => '0 0 24 24']) }} fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($slug)
        @case('parking-area')
            <rect x="3" y="3" width="18" height="18" rx="3"/><path d="M9 17V7h4a3 3 0 010 6H9"/>
            @break
        @case('restroom')
            <circle cx="7" cy="5" r="1.6"/><circle cx="17" cy="5" r="1.6"/><path d="M5 21v-7H4l1.5-5h3L10 14H9v7M17 21v-5h-2.5L16 9h2l1.5 7H18v5"/>
            @break
        @case('accessibility-ramp')
            <circle cx="12" cy="4.5" r="1.7"/><path d="M12 8v6h5l2 6M12 11h4M8 14a5 5 0 105 6"/>
            @break
        @case('wi-fi')
            <path d="M2 9a15 15 0 0120 0M5 12.5a10 10 0 0114 0M8.5 16a5 5 0 017 0"/><circle cx="12" cy="19.5" r="1" fill="currentColor"/>
            @break
        @case('swimming-pool')
            <path d="M2 17c2 0 2-1.5 5-1.5s3 1.5 5 1.5 2-1.5 5-1.5 3 1.5 5 1.5M2 21c2 0 2-1.5 5-1.5s3 1.5 5 1.5 2-1.5 5-1.5 3 1.5 5 1.5M8 15V6a2 2 0 014 0M16 15V6a2 2 0 014 0M8 9h8"/>
            @break
        @case('air-conditioning')
            <path d="M12 2v20M3.3 7l17.4 10M3.3 17L20.7 7M9 3.5l3 2 3-2M9 20.5l3-2 3 2"/>
            @break
        @case('restaurant')
            <path d="M6 3v7a2 2 0 002 2v9M10 3v7a2 2 0 01-2 2M18 21V3c-2.5 1-4 4-4 7.5 0 1.5 1 2.5 4 2.5"/>
            @break
        @default
            <path d="M5 12.5l4.5 4.5L19 7.5"/>
    @endswitch
</svg>
