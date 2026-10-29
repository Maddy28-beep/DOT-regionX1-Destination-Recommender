@extends('layouts.establishment')

@section('title', 'Promos & Discounts — Partner Dashboard')
@section('page-title', 'Promos & Discounts')
@section('page-sub', 'Shown to travellers on '.$listing->name.'’s public page')

@section('page-actions')
    <a href="{{ route('establishment.promotions.create') }}" class="btn btn-primary" data-modal-form data-title="Add a promo" data-sub="Shown on your public page" data-size="narrow"><x-icon name="plus" /> Add a promo</a>
@endsection

@section('content')

@php $today = now('Asia/Manila')->startOfDay(); @endphp

<div class="adm-card">
    <div class="adm-card__head">
        <div><h2>{{ $promotions->count() }} {{ \Illuminate\Support\Str::plural('promo', $promotions->count()) }}</h2><p>Start and end dates switch a promo on and off for you</p></div>
    </div>
    <div class="adm-card__body">
        <table class="data-table adm-table">
            <thead>
                <tr><th>Title</th><th>Code</th><th>Window</th><th>Status</th><th class="adm-table__actions"><span class="sr-only">Remove</span></th></tr>
            </thead>
            <tbody>
                @forelse ($promotions as $promotion)
                    @php
                        $state = $promotion->ends_at && $promotion->ends_at->lt($today) ? 'ended' : ($promotion->starts_at && $promotion->starts_at->gt($today) ? 'scheduled' : 'live');
                    @endphp
                    <tr>
                        <td><strong>{{ $promotion->title }}</strong>@if ($promotion->description)<div class="cell-sub">{{ \Illuminate\Support\Str::limit($promotion->description, 70) }}</div>@endif</td>
                        <td>{!! $promotion->code ? '<code>'.e($promotion->code).'</code>' : '<span class="muted">None</span>' !!}</td>
                        <td>
                            @if ($promotion->starts_at || $promotion->ends_at)
                                {{ $promotion->starts_at ? $promotion->starts_at->format('M j, Y') : 'Now' }} &ndash; {{ $promotion->ends_at ? $promotion->ends_at->format('M j, Y') : 'open' }}
                            @else
                                Always on
                            @endif
                        </td>
                        <td><span class="status-pill {{ ['live' => 'status-active', 'scheduled' => 'status-pending', 'ended' => 'status-expired'][$state] }}">{{ ['live' => 'Showing', 'scheduled' => 'Scheduled', 'ended' => 'Ended'][$state] }}</span></td>
                        <td class="adm-table__actions">
                            <form method="POST" action="{{ route('establishment.promotions.destroy', $promotion) }}" onsubmit="return confirm('Remove this promo? Travelers will no longer see it.');">
                                @csrf @method('DELETE')
                                <button type="submit" class="adm-icon-btn is-danger" title="Remove" aria-label="Remove {{ $promotion->title }}"><x-icon name="x" /></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="ptr-empty">No promos yet. Add one and travellers see it on your page.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection
