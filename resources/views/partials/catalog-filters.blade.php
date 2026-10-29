{{--
    The filter card beside a listing grid: a "Filters" title with Clear all, a search box, "Province or
    city", budget as toggle tiles, and a button that counts the filters that are switched on.

    Shared by every listing page; each passes only what it has.

    $route            index route name ("restaurants.index")
    $placeholder      example for the search box
    $regions          collection of Region
    $activeFilters    from App\Support\ActiveFilters::from()
    $tiers            optional list of [value, name, sub]; leave out for listings with no budget filter (sub may be '')
    $anyBudget        optional; put an "Any budget" tile first (selected while no budget is chosen)
    $categoryParam    optional name of the page's category filter ("type", "cuisine_type"); it is carried as a
                      hidden field because the category chips above the grid set it
    $hidden           optional extra hidden fields: list of ['name', 'value', 'id' => optional]
    $formId           the form's id (the sort select in the toolbar is attached to it)
    $clearParams      optional query to keep when clearing (e.g. ['view' => 'map'])
--}}
@php
    $tiers = $tiers ?? [];
    // A tile's third value is a small line under its name (the peso signs). A page whose tiles have none (dining
    // names its bands in words only) gets plain tiles, "Any budget" included.
    $tileHasSub = collect($tiers)->contains(fn ($tier) => ($tier[2] ?? '') !== '');
    if (! empty($anyBudget) && $tiers) {
        array_unshift($tiers, ['', 'Any budget', $tileHasSub ? 'All' : '']);
    }
    $hidden = $hidden ?? [];
    $formId = $formId ?? 'catalogFilters';
    $clearParams = $clearParams ?? [];
    $filterCount = $activeFilters->count();
@endphp

{{-- On a phone the panel is a bottom sheet over this dim layer; on a wide screen neither is shown. --}}
<div class="filter-sheet-overlay" id="filterOverlay" data-filter-sheet-overlay></div>

<aside class="filter-panel filter-panel--v2" id="filterPanel" tabindex="-1">
    <div class="filter-panel__head">
        <h2 id="filterPanelTitle">Filters</h2>
        @if ($filterCount)
            <a href="{{ route($route, $clearParams) }}" class="filter-panel__clear">Clear all</a>
        @endif
        <button type="button" class="filter-panel__close" data-filter-sheet-close aria-label="Close filters">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>
        </button>
    </div>

    <form method="GET" action="{{ route($route) }}" id="{{ $formId }}" data-filter-form>
        @foreach ($hidden as $field)
            <input type="hidden" name="{{ $field['name'] }}" value="{{ $field['value'] }}" @if (! empty($field['id'])) id="{{ $field['id'] }}" @endif>
        @endforeach
        @if (! empty($categoryParam))
            <input type="hidden" name="{{ $categoryParam }}" value="{{ request($categoryParam) }}" data-filter-count>
        @endif

        <div class="field">
            <label for="q">Search by name</label>
            <div class="input-icon">
                <x-icon name="search" />
                <input type="text" id="q" name="q" value="{{ request('q') }}" placeholder="{{ $placeholder }}" data-filter-count>
            </div>
        </div>

        <div class="field">
            <label for="region_id">Province or city</label>
            <select id="region_id" name="region_id" data-filter-count>
                <option value="">All of Davao Region</option>
                @foreach ($regions as $region)
                    <option value="{{ $region->id }}" @selected(request('region_id') == $region->id)>{{ $region->name }}</option>
                @endforeach
            </select>
        </div>

        @if ($tiers)
            <fieldset class="field budget-field">
                <legend>Budget</legend>
                <div class="budget-tiles">
                    @foreach ($tiers as [$value, $name, $sub])
                        <label class="budget-tile">
                            <input type="radio" name="price_tier" value="{{ $value }}" @checked($value === '' ? ! request()->filled('price_tier') : request('price_tier') === $value) data-filter-count @if ($value === '') data-any @endif>
                            <span class="budget-tile__name">{{ $name }}</span>
                            @if ($sub !== '')<span class="budget-tile__sub">{{ $sub }}</span>@endif
                        </label>
                    @endforeach
                </div>
            </fieldset>
        @endif

        <button type="submit" class="btn btn-poster-primary btn-block" data-filter-submit>
            Show results{{ $filterCount ? ' ('.$filterCount.' '.\Illuminate\Support\Str::plural('filter', $filterCount).')' : '' }}
        </button>
    </form>
</aside>
<script src="{{ asset('js/filter-sheet.js') }}?v={{ filemtime(public_path('js/filter-sheet.js')) }}" defer></script>
