{{--
    The filter card beside a listing grid: a "Filters" title with Clear all, a search box, "Province or
    city", budget as toggle tiles, and a button that counts the filters that are switched on.

    Shared by every listing page; each passes only what it has.

    $route            index route name ("restaurants.index")
    $placeholder      example for the search box
    $regions          collection of Region
    $activeFilters    from App\Support\ActiveFilters::from()
    $tiers            optional list of [value, name, sub]; leave out for listings with no budget filter
    $anyBudget        optional; put an "Any budget" tile first (selected while no budget is chosen)
    $categoryParam    optional name of the page's category filter ("type", "cuisine_type"); it is carried as a
                      hidden field because the category chips above the grid set it
    $hidden           optional extra hidden fields: list of ['name', 'value', 'id' => optional]
    $formId           the form's id (the sort select in the toolbar is attached to it)
    $clearParams      optional query to keep when clearing (e.g. ['view' => 'map'])
--}}
@php
    $tiers = $tiers ?? [];
    if (! empty($anyBudget) && $tiers) {
        array_unshift($tiers, ['', 'Any budget', 'All']);
    }
    $hidden = $hidden ?? [];
    $formId = $formId ?? 'catalogFilters';
    $clearParams = $clearParams ?? [];
    $filterCount = $activeFilters->count();
@endphp

<aside class="filter-panel filter-panel--v2" id="filterPanel">
    <div class="filter-panel__head">
        <h3>Filters</h3>
        @if ($filterCount)
            <a href="{{ route($route, $clearParams) }}" class="filter-panel__clear">Clear all</a>
        @endif
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
                            <span class="budget-tile__sub">{{ $sub }}</span>
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
