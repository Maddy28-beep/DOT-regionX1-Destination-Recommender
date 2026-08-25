@props(['name', 'filled' => false])

{{--
    Single, consistent line-icon set for the whole app (24x24, stroke-based,
    currentColor) so nothing relies on platform emoji rendering.
--}}

@switch($name)
    @case('heart')
        <svg {{ $attributes->merge(['viewBox' => '0 0 24 24']) }} fill="{{ $filled ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-10-9.3C.5 8 2 4 6 4c2 0 3.5 1.2 4.5 2.7C11.5 5.2 13 4 15 4c4 0 5.5 4 4 7.7C19.5 16.4 12 21 12 21z"/></svg>
        @break

    @case('filter')
        <svg {{ $attributes->merge(['viewBox' => '0 0 24 24']) }} fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h18l-7 8v6l-4 2v-8L3 4z"/></svg>
        @break

    @case('camera')
        <svg {{ $attributes->merge(['viewBox' => '0 0 24 24']) }} fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h3l2-2h6l2 2h3v11H4V8z"/><circle cx="12" cy="13" r="3.3"/></svg>
        @break

    @case('chat')
        <svg {{ $attributes->merge(['viewBox' => '0 0 24 24']) }} fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16v11H8l-4 4V5z"/></svg>
        @break

    @case('chart')
        <svg {{ $attributes->merge(['viewBox' => '0 0 24 24']) }} fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="20" x2="5" y2="12"/><line x1="12" y1="20" x2="12" y2="6"/><line x1="19" y1="20" x2="19" y2="15"/></svg>
        @break

    @case('link')
        <svg {{ $attributes->merge(['viewBox' => '0 0 24 24']) }} fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 15l6-6"/><path d="M11 6l.9-.9a3.5 3.5 0 015 5l-.9.9"/><path d="M13 18l-.9.9a3.5 3.5 0 01-5-5l.9-.9"/></svg>
        @break

    @case('clock')
        <svg {{ $attributes->merge(['viewBox' => '0 0 24 24']) }} fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
        @break

    @case('x-circle')
        <svg {{ $attributes->merge(['viewBox' => '0 0 24 24']) }} fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/></svg>
        @break

    @case('menu')
        <svg {{ $attributes->merge(['viewBox' => '0 0 24 24']) }} fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        @break

    @case('chevron-left')
        <svg {{ $attributes->merge(['viewBox' => '0 0 24 24']) }} fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
        @break

    @case('chevron-right')
        <svg {{ $attributes->merge(['viewBox' => '0 0 24 24']) }} fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
        @break

    @case('map')
        <svg {{ $attributes->merge(['viewBox' => '0 0 24 24']) }} fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4L3 6v14l6-2 6 2 6-2V4l-6 2-6-2z"/><line x1="9" y1="4" x2="9" y2="18"/><line x1="15" y1="6" x2="15" y2="20"/></svg>
        @break

    @case('building')
        <svg {{ $attributes->merge(['viewBox' => '0 0 24 24']) }} fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18"/><line x1="9" y1="7.5" x2="9" y2="7.51"/><line x1="15" y1="7.5" x2="15" y2="7.51"/><line x1="9" y1="11.5" x2="9" y2="11.51"/><line x1="15" y1="11.5" x2="15" y2="11.51"/><path d="M10 21v-4h4v4"/></svg>
        @break

    @case('landmark')
        <svg {{ $attributes->merge(['viewBox' => '0 0 24 24']) }} fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 20l6-10 4 6 2-3 6 7H3z"/><circle cx="17" cy="6" r="2"/></svg>
        @break

    @case('star')
        <svg {{ $attributes->merge(['viewBox' => '0 0 24 24']) }} fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14l-5-4.87 6.91-1.01L12 2z"/></svg>
        @break

    @case('shield-check')
        <svg {{ $attributes->merge(['viewBox' => '0 0 24 24']) }} fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v6c0 5-3.5 8-7 9-3.5-1-7-4-7-9V6l7-3z"/><path d="M9 12l2 2 4-4"/></svg>
        @break

    @case('map-pin')
        <svg {{ $attributes->merge(['viewBox' => '0 0 24 24']) }} fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-6.5 7-12a7 7 0 10-14 0c0 5.5 7 12 7 12z"/><circle cx="12" cy="9" r="2.3"/></svg>
        @break

    @case('target')
        <svg {{ $attributes->merge(['viewBox' => '0 0 24 24']) }} fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1" fill="currentColor" stroke="none"/></svg>
        @break

    @case('compass')
        <svg {{ $attributes->merge(['viewBox' => '0 0 24 24']) }} fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M15 9l-2 6-4-2 2-6 4 2z"/></svg>
        @break

    @case('lock')
        <svg {{ $attributes->merge(['viewBox' => '0 0 24 24']) }} fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="9" rx="1.5"/><path d="M8 11V7a4 4 0 018 0v4"/></svg>
        @break

    @case('alert-triangle')
        <svg {{ $attributes->merge(['viewBox' => '0 0 24 24']) }} fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l10 18H2L12 3z"/><line x1="12" y1="9.5" x2="12" y2="14"/><line x1="12" y1="17" x2="12" y2="17.01"/></svg>
        @break

    @case('wrench')
        <svg {{ $attributes->merge(['viewBox' => '0 0 24 24']) }} fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a4 4 0 00-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 005.4-5.4l-2.3 2.3-2-2 2.3-2.3z"/></svg>
        @break

    @case('bell')
        <svg {{ $attributes->merge(['viewBox' => '0 0 24 24']) }} fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 0112 0c0 5 2 6 2 6H4s2-1 2-6z"/><path d="M10 20a2 2 0 004 0"/></svg>
        @break
@endswitch
