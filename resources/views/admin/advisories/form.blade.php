@extends('layouts.admin')

@section('title', ($advisory->exists ? 'Edit' : 'Post').' Advisory — DOT Admin')
@section('page-title', $advisory->exists ? 'Edit Advisory' : 'Post New Advisory')
@section('page-sub', 'Shown to travelers on the listing page, or site-wide for a general notice')

@section('content')

@if ($errors->any())
    <div class="alert alert-error">
        @foreach ($errors->all() as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
@endif

<div class="panel">
    <div class="panel-body">
        <form method="POST" action="{{ $advisory->exists ? route('admin.advisories.update', $advisory) : route('admin.advisories.store') }}">
            @csrf
            @if ($advisory->exists)
                @method('PUT')
            @endif

            <div class="field">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" value="{{ old('title', $advisory->title) }}" placeholder="e.g. Mt. Apo Closed This Season" required maxlength="150">
            </div>

            <div class="field">
                <label for="message">Message</label>
                <textarea id="message" name="message" rows="4" required maxlength="1000">{{ old('message', $advisory->message) }}</textarea>
            </div>

            <div class="filter-inline" style="align-items:start;">
                <div class="field" style="flex:1; min-width:160px;">
                    <label for="severity">Severity</label>
                    <select id="severity" name="severity">
                        @foreach ($severities as $severity)
                            <option value="{{ $severity }}" @selected(old('severity', $advisory->severity ?: 'warning') === $severity)>{{ ucfirst($severity) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field" style="flex:1; min-width:200px;">
                    <label for="listing_kind">Applies To</label>
                    <select id="listing_kind" name="listing_kind" data-advisory-kind>
                        <option value="">General (all of ExploreDVO)</option>
                        @foreach ($listingKinds as $key => $modelClass)
                            <option value="{{ $key }}" @selected(old('listing_kind', $advisory->listing_kind) === $key)>{{ ucfirst(str_replace('_', ' ', $key)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field" style="flex:1; min-width:200px;" data-advisory-listing-field {{ old('listing_kind', $advisory->listing_kind) ? '' : 'hidden' }}>
                    <label for="listing_id">Which One</label>
                    <select id="listing_id" name="listing_id" data-advisory-listing>
                        @foreach ($listingOptions as $option)
                            <option value="{{ $option->id }}" @selected((string) old('listing_id', $advisory->listing_id) === (string) $option->id)>{{ $option->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="filter-inline" style="align-items:start;">
                <div class="field" style="flex:1; min-width:160px;">
                    <label for="starts_at">Starts (optional)</label>
                    <input type="date" id="starts_at" name="starts_at" value="{{ old('starts_at', optional($advisory->starts_at)->format('Y-m-d')) }}">
                </div>
                <div class="field" style="flex:1; min-width:160px;">
                    <label for="ends_at">Ends (optional)</label>
                    <input type="date" id="ends_at" name="ends_at" value="{{ old('ends_at', optional($advisory->ends_at)->format('Y-m-d')) }}">
                </div>
            </div>
            <p class="field-hint">Leave both blank to keep the advisory up until you remove it.</p>

            <button type="submit" class="btn btn-primary" style="margin-top:10px;">{{ $advisory->exists ? 'Save Changes' : 'Post Advisory' }}</button>
        </form>
    </div>
</div>

<script>
    (function () {
        var kindSelect = document.querySelector('[data-advisory-kind]');
        var listingField = document.querySelector('[data-advisory-listing-field]');
        var listingSelect = document.querySelector('[data-advisory-listing]');
        if (!kindSelect || !listingField || !listingSelect) return;

        var optionsByKind = @json($listingOptionsByKind);

        kindSelect.addEventListener('change', function () {
            var kind = kindSelect.value;
            listingField.hidden = !kind;
            listingSelect.innerHTML = '';
            (optionsByKind[kind] || []).forEach(function (option) {
                var el = document.createElement('option');
                el.value = option.id;
                el.textContent = option.name;
                listingSelect.appendChild(el);
            });
        });
    })();
</script>

@endsection
