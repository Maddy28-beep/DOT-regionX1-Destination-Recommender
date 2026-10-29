@extends('layouts.establishment')

@section('title', 'Guest Reviews — Partner Dashboard')
@section('page-title', 'Guest Reviews')
@section('page-sub', 'Feedback travelers left about your listing')

@section('content')

@if (! $listing)
    <div class="panel">
        <div class="empty-panel">
            <div class="icon"><x-icon name="chat" /></div>
            <h3>No listing linked yet</h3>
            <p>Reviews will appear here once your account is linked to a catalog listing.</p>
        </div>
    </div>
@else
    <div class="adm-card">
        <div class="adm-card__head">
            <div>
                <h2>{{ $reviews->total() }} {{ \Illuminate\Support\Str::plural('review', $reviews->total()) }}</h2>
                <p>{{ ($listing->review_count ?? 0) > 0 ? 'Average rating '.number_format($listing->rating, 1).' ★ from '.$listing->review_count.' total ratings.' : 'Nobody has rated this listing yet.' }}</p>
            </div>
        </div>
        <div class="adm-card__body">
            @forelse ($reviews as $review)
                @include('establishment.partials.review', ['review' => $review])
            @empty
                <p class="ptr-empty">No reviews yet.</p>
            @endforelse
        </div>
        @if ($reviews->hasPages())
            <div class="adm-card__foot">@include('partials.pagination', ['paginator' => $reviews])</div>
        @endif
    </div>
@endif

@endsection
