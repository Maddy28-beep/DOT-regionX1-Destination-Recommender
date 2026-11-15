{{-- The sidebar is retired. Keep only the invisible GET form used by sorting and map state.
     Category links and existing bookmarked queries still work; no filter drawer is rendered. --}}
<link rel="stylesheet" href="{{ asset('css/catalog-without-sidebar.css') }}?v={{ filemtime(public_path('css/catalog-without-sidebar.css')) }}">
@php
    $formId = $formId ?? 'catalogFilters';
    $hidden = $hidden ?? [];
    $carriedNames = array_column($hidden, 'name');
@endphp
<form method="GET" action="{{ route($route) }}" id="{{ $formId }}" hidden>
    @foreach ($hidden as $field)
        <input type="hidden" name="{{ $field['name'] }}" value="{{ $field['value'] }}" @if (!empty($field['id'])) id="{{ $field['id'] }}" @endif>
    @endforeach
    @foreach (request()->except(['page', 'sort']) as $name => $value)
        @continue(in_array($name, $carriedNames, true))
        @if (is_scalar($value))
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @elseif (is_array($value))
            @foreach ($value as $item)
                @if (is_scalar($item))<input type="hidden" name="{{ $name }}[]" value="{{ $item }}">@endif
            @endforeach
        @endif
    @endforeach
</form>
