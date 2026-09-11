@extends('layouts.establishment')

@section('title', 'Promos & Discounts — Partner Dashboard')
@section('page-title', 'Promos & Discounts')
@section('page-sub', 'Shown to travelers on '.$listing->name.'\'s public page')

@section('content')

@if ($errors->any())
    <div class="alert alert-error">
        @foreach ($errors->all() as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
@endif

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Add a Promo</h2>
            <p>A discount code, a seasonal offer, or any deal you want travelers to know about.</p>
        </div>
    </div>
    <div class="panel-body">
        <form method="POST" action="{{ route('establishment.promotions.store') }}">
            @csrf

            <div class="filter-inline" style="align-items:start;">
                <div class="field" style="flex:2; min-width:220px;">
                    <label for="title">Title</label>
                    <input type="text" id="title" name="title" value="{{ old('title') }}" placeholder="e.g. 15% Off Walk-ins" required maxlength="150">
                </div>
                <div class="field" style="flex:1; min-width:160px;">
                    <label for="code">Promo Code (optional)</label>
                    <input type="text" id="code" name="code" value="{{ old('code') }}" placeholder="e.g. EXPLORE15" maxlength="30">
                </div>
            </div>

            <div class="field">
                <label for="description">Details (optional)</label>
                <textarea id="description" name="description" rows="3" maxlength="500">{{ old('description') }}</textarea>
            </div>

            <div class="filter-inline" style="align-items:start;">
                <div class="field" style="flex:1; min-width:160px;">
                    <label for="starts_at">Starts (optional)</label>
                    <input type="date" id="starts_at" name="starts_at" value="{{ old('starts_at') }}">
                </div>
                <div class="field" style="flex:1; min-width:160px;">
                    <label for="ends_at">Ends (optional)</label>
                    <input type="date" id="ends_at" name="ends_at" value="{{ old('ends_at') }}">
                </div>
            </div>
            <p class="field-hint">Leave both blank to keep the promo up until you remove it.</p>

            <button type="submit" class="btn btn-primary" style="margin-top:10px;">Add Promo</button>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>{{ $promotions->count() }} Promo{{ $promotions->count() === 1 ? '' : 's' }}</h2>
        </div>
    </div>
    <div class="panel-body">
        @if ($promotions->isEmpty())
            <div class="empty-panel" style="padding:24px;">
                <h3>No promos yet</h3>
                <p>Add one above and it will appear on your listing page right away.</p>
            </div>
        @else
            <div class="table-scroll">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Title</th><th>Code</th><th>Window</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($promotions as $promotion)
                        <tr>
                            <td>
                                {{ $promotion->title }}
                                @if ($promotion->description)
                                    <div class="cell-muted" style="font-size:.82rem; margin-top:2px;">{{ \Illuminate\Support\Str::limit($promotion->description, 80) }}</div>
                                @endif
                            </td>
                            <td class="cell-muted">{{ $promotion->code ?: '—' }}</td>
                            <td class="cell-muted">
                                @if ($promotion->starts_at || $promotion->ends_at)
                                    {{ $promotion->starts_at?->format('M j, Y') ?? 'Now' }} &ndash; {{ $promotion->ends_at?->format('M j, Y') ?? 'Until removed' }}
                                @else
                                    Until removed
                                @endif
                            </td>
                            <td>
                                <form method="POST" action="{{ route('establishment.promotions.destroy', $promotion) }}" onsubmit="return confirm('Remove this promo? Travelers will no longer see it.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-outline btn-xs">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>
</div>

@endsection
