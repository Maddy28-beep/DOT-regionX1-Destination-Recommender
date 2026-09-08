@props(['name', 'label', 'items', 'kind' => null, 'placeholder' => 'Type to search…', 'selected' => []])

{{--
    Searchable multi-select for a long list.

    Built for the exit survey, where six of these replaced 395 raw checkboxes --
    a respondent had to scan an unfiltered alphabetical wall to find the two or
    three places they actually went, which is the kind of friction that costs
    you the response entirely.

    Deliberately generic: it takes a list of {id, name, meta} and an input name,
    so it is not tied to establishments. Anywhere else in the app that asks a
    person to pick a handful of rows out of hundreds should use this rather than
    grow its own.

    Filtering is client-side. The whole list is already in the page -- it was
    being rendered as checkboxes anyway -- so a round trip per keystroke would
    add latency to buy nothing. If a catalogue ever outgrows that (roughly a few
    thousand rows), swap the `filter()` call for a debounced fetch; nothing else
    in the component needs to change.

    Without JavaScript the <noscript> block restores a plain checkbox list, so
    the survey stays completable.
--}}
@php
    $id = 'tagpick-'.\Illuminate\Support\Str::slug($label);
    $prefix = $kind ? $kind.':' : '';
    $selectedValues = collect($selected)->all();
@endphp

<div class="tag-picker" id="{{ $id }}" data-tag-picker
     data-name="{{ $name }}" data-prefix="{{ $prefix }}"
     data-items="{{ json_encode(collect($items)->map(fn ($i) => [
         'id' => is_array($i) ? $i['id'] : $i->id,
         'name' => is_array($i) ? $i['name'] : $i->name,
         'meta' => is_array($i) ? ($i['meta'] ?? null) : ($i->meta ?? null),
     ])->values()) }}"
     data-selected="{{ json_encode($selectedValues) }}">

    <label for="{{ $id }}-search">{{ $label }}</label>

    <div class="tag-picker__box">
        <div class="tag-picker__chips" data-chips></div>
        <input type="text" id="{{ $id }}-search" class="tag-picker__search"
               placeholder="{{ $placeholder }}" autocomplete="off" role="combobox"
               aria-expanded="false" aria-autocomplete="list" data-search>
    </div>

    {{-- Results live outside the box so the box's overflow cannot clip them. --}}
    <ul class="tag-picker__results" role="listbox" hidden data-results></ul>

    <p class="tag-picker__count" aria-live="polite" data-count>None selected</p>

    {{-- Hidden inputs are written here; this is what actually posts. --}}
    <div data-values></div>
</div>

<noscript>
    <div class="checkbox-grid" style="margin-top:8px;">
        @foreach ($items as $item)
            @php
                $iid = is_array($item) ? $item['id'] : $item->id;
                $iname = is_array($item) ? $item['name'] : $item->name;
            @endphp
            <label class="field-check">
                <input type="checkbox" name="{{ $name }}" value="{{ $prefix }}{{ $iid }}"
                       @checked(in_array($prefix.$iid, $selectedValues))>
                <span>{{ $iname }}</span>
            </label>
        @endforeach
    </div>
</noscript>
