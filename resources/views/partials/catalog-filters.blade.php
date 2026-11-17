{{--
    The filter card beside a listing grid: a "Filters" title with Clear all, a search box, "Province or
    city", page-specific refinements, and a button that counts the filters that are switched on.

    Shared by every listing page; each passes only what it has.

    $route            index route name ("restaurants.index")
    $placeholder      example for the search box
    $regions          collection of Region
    $activeFilters    from App\Support\ActiveFilters::from()
    $categoryParam    optional name of the page's category filter ("type", "cuisine_type"); it is carried as a
                      hidden field because the category chips above the grid set it
    $hidden           optional extra hidden fields: list of ['name', 'value', 'id' => optional]
    $formId           the form's id
    $clearParams      optional query to keep when clearing (e.g. ['view' => 'map'])
--}}
@php
    // The listing panel no longer offers budget tiers or price ranges. Existing search
    // context remains removable via its active-filter chips and survives form submission.
    $hidden = array_merge($hidden ?? [], \App\Support\ActiveFilters::carry(request(), ['price_tier', 'price_min', 'price_max']));
    $hidden = collect($hidden)->keyBy('name')->values()->all();
    $optionGroups = array_values(array_filter($groups ?? [], fn ($group) => $group['type'] === 'toggle'));
    $groups = array_values(array_filter($groups ?? [], fn ($group) => ! in_array($group['type'], ['range', 'toggle'], true)));
    $groups = array_map(fn ($group) => ($group['fold'] ?? 0) > 4 ? array_replace($group, ['fold' => 4]) : $group, $groups);
    // Put useful page-specific choices first and the optional rating refinement last.
    $groups = array_merge(array_values(array_filter($groups, fn ($group) => $group['type'] !== 'rating' && $group['type'] !== 'toggle')),
        array_values(array_filter($groups, fn ($group) => $group['type'] === 'rating')),
        array_values(array_filter($groups, fn ($group) => $group['type'] === 'toggle')));
    // Fields drawn by a refinement group are not also carried as hidden fields.
    $managed = collect(array_merge($groups, $optionGroups))->flatMap(fn ($g) => $g['names'])->all();
    $hidden = array_values(array_filter($hidden, fn ($f) => ! in_array($f['name'], $managed, true)));
    $formId = $formId ?? 'catalogFilters';
    $clearParams = $clearParams ?? [];
    $filterCount = $activeFilters->count();
@endphp

{{-- On a phone the panel is a bottom sheet over this dim layer; on a wide screen neither is shown. --}}
<div class="filter-sheet-overlay" id="filterOverlay" data-filter-sheet-overlay></div>

<aside class="filter-panel filter-panel--v2 filter-panel--refined filter-panel--page-scroll" id="filterPanel" tabindex="-1">
    <div class="filter-panel__head">
        <h2 id="filterPanelTitle"><x-icon name="filter" /> Filters</h2>
        @if ($filterCount)
            <a href="{{ route($route, $clearParams) }}" class="filter-panel__clear">Reset</a>
        @endif
        <button type="button" class="filter-panel__close" data-filter-sheet-close aria-label="Close filters">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>
        </button>
    </div>

    {{-- Move the existing control, keeping its original map handlers and filter state. --}}
    <div class="filter-panel__view" data-filter-view-slot hidden></div>
    <script>
        document.addEventListener("DOMContentLoaded", function () {
            var slot = document.querySelector("[data-filter-view-slot]");
            var toggle = document.querySelector(".destination-view-toggle, .results-toolbar .view-toggle");
            if (!slot || !toggle) return;
            slot.appendChild(toggle);
            slot.hidden = false;
            toggle.querySelectorAll("button").forEach(function (button) {
                button.addEventListener("click", function () {
                    // Reveal the chosen view after selecting it inside the mobile filter sheet.
                    var panel = slot.closest(".filter-panel");
                    if (panel && panel.classList.contains("open")) {
                        panel.querySelector("[data-filter-sheet-close]").click();
                    }
                });
            });
        });
    </script>

    <form method="GET" action="{{ route($route) }}" id="{{ $formId }}" data-filter-form>
        <div class="filter-panel__fields">
        @foreach ($hidden as $field)
            <input type="hidden" name="{{ $field['name'] }}" value="{{ $field['value'] }}" @if (! empty($field['id'])) id="{{ $field['id'] }}" @endif @if($field['name'] !== 'view') data-filter-count @endif>
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

        @include('partials.catalog-nearby')

        @if ($optionGroups)
            <section class="catalog-filter-options" aria-labelledby="catalogOptionsTitle">
                <h3 id="catalogOptionsTitle"><x-icon name="sliders" /> Options</h3>
                <label class="catalog-option-toggle">
                    <span>Exclude closed places<small>Based on listed operating status</small></span>
                    <input type="checkbox" role="switch" name="open_now" value="1" @checked(request()->boolean('open_now')) data-filter-count>
                    <span class="catalog-option-toggle__track" aria-hidden="true"></span>
                </label>
            </section>
        @endif

        @include('partials.catalog-filter-groups', ['groups' => $groups])

        </div>
        <div class="filter-panel__actions">
        <a href="{{ route($route, $clearParams) }}" class="filter-panel__reset">Clear all</a>
        <button type="submit" class="btn btn-poster-primary btn-block" data-filter-submit>
            Show results{{ $filterCount ? ' ('.$filterCount.' '.\Illuminate\Support\Str::plural('filter', $filterCount).')' : '' }}
        </button>
        </div>
    </form>
</aside>
<script src="{{ asset('js/filter-sheet.js') }}?v={{ filemtime(public_path('js/filter-sheet.js')) }}" defer></script>
<script src="{{ asset('js/catalog-nearby.js') }}?v={{ filemtime(public_path('js/catalog-nearby.js')) }}" defer></script>

@push('styles')
    <link rel="stylesheet" href="{{ \App\Support\StyleAsset::url('css/catalog-filters-refined.css') }}">
@endpush
