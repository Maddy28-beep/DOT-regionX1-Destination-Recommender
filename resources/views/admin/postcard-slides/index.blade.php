@extends('layouts.admin')

@section('title', 'Carousel slides — DOT Admin')
@section('page-title', 'Popular Right Now carousel')
@section('page-sub', 'The photo slides at the top of the homepage, after the hero')

@section('content')

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>{{ $slides->count() }} {{ \Illuminate\Support\Str::plural('slide', $slides->count()) }}</h2>
            <p>Shown in this order. Hidden slides stay here but do not appear on the homepage.</p>
        </div>
        <a href="{{ route('admin.postcard-slides.create') }}" class="btn btn-primary">Add slide</a>
    </div>

    <div class="table-scroll">
    <table class="data-table">
        <thead>
            <tr><th>Order</th><th>Photo</th><th>Caption</th><th>Links to</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
            @forelse ($slides as $slide)
                <tr>
                    <td class="cell-muted">{{ $slide->sort_order }}</td>
                    <td><img src="{{ $slide->thumbUrl() }}" alt="{{ $slide->alt_text }}" width="96" height="60" style="object-fit:cover; border-radius:8px; display:block;"></td>
                    <td>
                        {{ $slide->title }}
                        <div class="cell-muted" style="font-size:.82rem; margin-top:2px;">{{ $slide->kicker }}@if($slide->location) &middot; {{ $slide->location }}@endif</div>
                    </td>
                    <td class="cell-muted">{{ $slide->cta_label }}<div style="font-size:.78rem;">{{ $slide->cta_url }}</div></td>
                    <td><span class="status-pill {{ $slide->is_active ? 'status-active' : 'status-expired' }}">{{ $slide->is_active ? 'Shown' : 'Hidden' }}</span></td>
                    <td>
                        <div class="util-row">
                            <a href="{{ route('admin.postcard-slides.edit', $slide) }}" class="btn btn-primary btn-xs">Edit</a>
                            <form method="POST" action="{{ route('admin.postcard-slides.destroy', $slide) }}" onsubmit="return confirm('Remove this slide? Its photo files are deleted too.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-outline btn-xs">Remove</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="cell-muted">No slides yet. The homepage hides the carousel until there is at least one.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>

@endsection
