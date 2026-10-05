@extends('layouts.admin')

@section('title', ($event->exists ? 'Edit' : 'Add').' Event — DOT Admin')
@section('page-title', $event->exists ? 'Edit Event' : 'Add New Event')
@section('page-sub', 'Shown on the public events calendar and the happening-soon strip')

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
        <form method="POST" action="{{ $event->exists ? route('admin.events.update', $event) : route('admin.events.store') }}">
            @csrf
            @if ($event->exists)
                @method('PUT')
            @endif

            <div class="field">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" value="{{ old('title', $event->title) }}" placeholder="e.g. Kadayawan Festival" required maxlength="150">
            </div>

            <div class="filter-inline" style="align-items:start;">
                <div class="field" style="flex:1; min-width:180px;">
                    <label for="category">Category</label>
                    <select id="category" name="category" required>
                        @foreach ($categories as $key => $label)
                            <option value="{{ $key }}" @selected(old('category', $event->category ?: 'festival') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field" style="flex:2; min-width:220px;">
                    <label for="location">Location</label>
                    <input type="text" id="location" name="location" value="{{ old('location', $event->location) }}" placeholder="e.g. Rizal Park, Davao City" required maxlength="150">
                </div>
            </div>

            <div class="filter-inline" style="align-items:start;">
                <div class="field" style="flex:1; min-width:160px;">
                    <label for="starts_on">Starts</label>
                    <input type="date" id="starts_on" name="starts_on" value="{{ old('starts_on', optional($event->starts_on)->format('Y-m-d')) }}" required>
                </div>
                <div class="field" style="flex:1; min-width:160px;">
                    <label for="ends_on">Ends (optional)</label>
                    <input type="date" id="ends_on" name="ends_on" value="{{ old('ends_on', optional($event->ends_on)->format('Y-m-d')) }}">
                </div>
                <div class="field" style="flex:1; min-width:160px;">
                    <label for="time_label">Time (optional)</label>
                    <input type="text" id="time_label" name="time_label" value="{{ old('time_label', $event->time_label) }}" placeholder="e.g. 6:00 PM" maxlength="60">
                </div>
            </div>
            <p class="field-hint">Leave the end date blank for a one-day event.</p>

            <div class="field">
                <label for="description">Description (optional)</label>
                <textarea id="description" name="description" rows="4" maxlength="2000">{{ old('description', $event->description) }}</textarea>
            </div>

            <button type="submit" class="btn btn-primary" style="margin-top:10px;">{{ $event->exists ? 'Save Changes' : 'Add Event' }}</button>
            <a href="{{ route('admin.events.index') }}" class="btn btn-outline" style="margin-top:10px;">Cancel</a>
        </form>
    </div>
</div>

@endsection
