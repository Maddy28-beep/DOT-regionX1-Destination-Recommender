@props([
    'name',
    'items',
    'selected' => [],
    'placeholder' => 'Search…',
    'label' => null,
    'hint' => null,
])

{{--
    Searchable tag/chip picker: replaces a static wall of checkboxes with a
    text field that filters a list as the user types, up to 8 results in a
    dropdown, click-to-add-a-chip, click-the-chip's-x-to-remove.

    Built for the exit survey's "which of 25-370 places did you visit"
    fields, where the wall this replaces made a respondent scroll a huge
    alphabetical grid to find the two or three places they actually went --
    a real completion-rate risk on a form nobody is obligated to finish.

    Deliberately generic: `items` is a plain [{value, label}, ...] list and
    `selected` a plain array of already-chosen values, with no assumption
    about what a "value" means. This is what lets the same component serve
    six different establishment kinds here by pointing it at six different
    lists, and what would let an admin bulk-selection screen reuse it later
    for the same reason -- picking a handful of rows out of a few hundred is
    the same interaction problem whether the picker is public-facing or not.

    All matching happens client-side against the `items` list this prop
    passes in (see the <script type="application/json"> below): the largest
    single list here (accommodations, ~220) is small enough that shipping it
    once and filtering in the browser is simpler than a debounced endpoint,
    with no added network round-trip per keystroke.
--}}
<div class="tag-search" data-tag-search>
    @if ($label)
        <label>{{ $label }}</label>
    @endif
    @if ($hint)
        <p class="field-hint">{{ $hint }}</p>
    @endif

    <div class="tag-search__box" data-tag-search-box>
        <div class="tag-search__chips" data-tag-search-chips></div>
        <input
            type="text"
            class="tag-search__input"
            data-tag-search-input
            placeholder="{{ $placeholder }}"
            autocomplete="off"
            role="combobox"
            aria-expanded="false"
            aria-autocomplete="list"
        >
    </div>
    <ul class="tag-search__dropdown" data-tag-search-dropdown hidden role="listbox"></ul>
    <p class="tag-search__count" data-tag-search-count>0 selected</p>

    {{-- Hidden inputs are added/removed here as chips are added/removed;
         the name matches whatever the surrounding form already expects
         (places_visited[]), so no controller change is needed to consume
         this component's output. --}}
    <div data-tag-search-hidden-inputs></div>

    {{--
        json_encode(), not Js::from(): Js::from() returns a JS *expression*
        ("JSON.parse('...')") meant to be assigned inline in a <script> block,
        not raw JSON -- wrapping that expression's text in a JSON.parse() of
        its own on the JS side threw exactly the "Unexpected token 'J'" error
        this comment is here to stop from being reintroduced. The HEX flags
        keep a listing name containing '</script>', a quote, or an ampersand
        from breaking out of the tag or double-encoding.
    --}}
    <script type="application/json" data-tag-search-items>{!! json_encode(collect($items)->values(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}</script>
    <script type="application/json" data-tag-search-selected>{!! json_encode(array_values($selected), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!}</script>
    <input type="hidden" data-tag-search-name value="{{ $name }}">
</div>
