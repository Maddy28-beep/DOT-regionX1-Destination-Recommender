{{--
    The strip above a listing grid: how many were found, a mobile Filters button, a Grid/Map switch moved into the sidebar, and a
    "Showing" chip for each filter that is on (the x removes just that one).

    $results          the paginator
    $noun             singular name ("restaurant", "souvenir center")
    $activeFilters    from App\Support\ActiveFilters::from()
    $viewToggle       null, 'destinations' (the map explorer's own switch) or 'grid-map' (the shared one
                      handled by app.js); shown only when there is something to show
--}}
@php
    $viewToggle = $viewToggle ?? null;
@endphp

<div class="results-toolbar">
    <div class="results-count">{{ $results->total() }} {{ \Illuminate\Support\Str::plural($noun, $results->total()) }} found</div>

    <div class="results-toolbar__controls">
        {{-- Expands the filter panel in the mobile page; hidden beside a wide screen. --}}
        <button type="button" class="filter-toggle" data-filter-sheet-open aria-controls="filterPanel" aria-expanded="false">
            <x-icon name="filter" /> Filters
            @if ($activeFilters->isNotEmpty())<span class="filter-toggle__count" aria-label="{{ $activeFilters->count() }} on">{{ $activeFilters->count() }}</span>@endif
        </button>

        <label class="sort-control">
            <span>Sort by</span>
            <select name="sort" form="{{ $formId }}" onchange="this.form.submit()">
                @foreach ($sortOptions as $value => $label)
                    <option value="{{ $value }}" @selected(request('sort', 'recommended') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>

        @if ($viewToggle === 'destinations')
            <div class="destination-view-toggle" role="group" aria-label="Destination display" hidden>
                <button type="button" data-destination-view="grid" aria-pressed="true"><x-icon name="grid" /> Grid</button>
                <button type="button" data-destination-view="map" aria-pressed="false"><x-icon name="map" /> Map</button>
            </div>
        @elseif ($viewToggle === 'grid-map' && $results->count())
            <div class="view-toggle" role="group" aria-label="Switch between grid and map view">
                <button type="button" class="view-toggle__btn active" data-view="grid"><x-icon name="grid" /> Grid</button>
                <button type="button" class="view-toggle__btn" data-view="map"><x-icon name="map" /> Map</button>
            </div>
        @endif
    </div>

    @if ($activeFilters->isNotEmpty())
        <div class="active-filters">
            <span>Showing:</span>
            @foreach ($activeFilters as $key => $label)
                <a href="{{ request()->fullUrlWithQuery([$key => null, 'page' => null]) }}" class="filter-chip" aria-label="Remove filter: {{ $label }}">{{ $label }} <span aria-hidden="true">&times;</span></a>
            @endforeach
        </div>
    @endif
</div>
