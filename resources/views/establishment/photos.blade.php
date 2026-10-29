@extends('layouts.establishment')

@section('title', 'Photos — Partner Dashboard')
@section('page-title', 'Photos')
@section('page-sub', 'Drag to reorder. The cover photo is what travellers see first.')

@section('page-actions')
    <a href="{{ route('establishment.photos.create') }}" class="btn btn-primary" data-modal-form data-title="Upload photos" data-sub="JPG, PNG or WebP, up to 2 MB each" data-size="narrow"><x-icon name="camera" /> Upload photos</a>
@endsection

@section('content')

@php
    $readiness = app(\App\Services\Establishment\ListingReadiness::class);
    $missing = $readiness->realPhotos($listing)->reject(fn ($p) => $readiness->credited($p))->count();
@endphp

<div class="adm-card">
    <div class="adm-card__head">
        <div>
            <h2>{{ $photos->count() }} {{ \Illuminate\Support\Str::plural('photo', $photos->count()) }}</h2>
            <p>
                @if ($missing > 0)
                    {{ $missing }} without a credit. Travellers see the credit under each photo.
                    <a class="adm-link" href="{{ route('establishment.photos.credit.bulk.form') }}" data-modal-form data-title="Photo credit" data-sub="Travellers see who took each photo" data-size="narrow">Add credits</a>
                @else
                    Every photo is credited.
                @endif
            </p>
        </div>
    </div>
    <div class="adm-card__body">
        @if ($photos->isEmpty())
            {{-- The illustrated placeholder travellers see today, at the same 4:3 the card uses. --}}
            <div class="empty-panel" style="padding:24px;">
                <div class="photo-empty-caption">What travelers see today</div>
                <div class="photo-empty-preview">
                    @include('partials.poster-illustration', ['scene' => $listing->posterScene()])
                </div>
                <h3>No photos yet</h3>
                <p>Your listing falls back to this illustration until you upload a photo. A real photo replaces it everywhere &mdash; search results, cards, and your listing page.</p>
            </div>
        @else
            <div class="ptr-photos" id="photo-grid" data-reorder-url="{{ route('establishment.photos.reorder') }}">
                @foreach ($photos as $photo)
                    <div class="ptr-photo" draggable="true" data-id="{{ $photo->id }}">
                        <div class="ptr-photo__img">
                            <img src="{{ $photo->url() }}" alt="{{ $listing->name }}, {{ $photo->category }}" loading="lazy">
                            @if ($photo->is_primary)<span class="ptr-photo__cover"><x-icon name="star" /> Cover</span>@endif
                        </div>
                        <div class="ptr-photo__meta">
                            <span class="ptr-photo__grip" title="Drag to reorder" aria-hidden="true">&#8942;&#8942;</span>
                            <strong>{{ $photo->category }}</strong>
                            @php $credited = $readiness->credited($photo); @endphp
                            <a href="{{ route('establishment.photos.credit.form', $photo) }}" class="ptr-credit {{ $credited ? 'is-ok' : 'is-missing' }}" data-modal-form data-title="Photo credit" data-sub="Travellers see who took each photo" data-size="narrow"
                               aria-label="{{ $credited ? 'Credit: '.($photo->credit_text ?: $photo->source_name).'. Change it' : 'Add a credit for this '.$photo->category.' photo' }}">{{ $credited ? 'Credited' : 'Add credit' }}</a>
                        </div>
                        <div class="ptr-photo__acts">
                            @unless ($photo->is_primary)
                                <form method="POST" action="{{ route('establishment.photos.primary', $photo) }}">@csrf<button type="submit" class="adm-icon-btn" title="Make cover photo" aria-label="Make this {{ $photo->category }} photo the cover"><x-icon name="star" /></button></form>
                            @endunless
                            <form method="POST" action="{{ route('establishment.photos.up', $photo) }}">@csrf<button type="submit" class="adm-icon-btn" title="Move earlier" aria-label="Move this {{ $photo->category }} photo earlier"><x-icon name="chevron-left" /></button></form>
                            <form method="POST" action="{{ route('establishment.photos.down', $photo) }}">@csrf<button type="submit" class="adm-icon-btn" title="Move later" aria-label="Move this {{ $photo->category }} photo later"><x-icon name="chevron-right" /></button></form>
                            <form method="POST" action="{{ route('establishment.photos.destroy', $photo) }}" onsubmit="return confirm('Remove this photo?');" style="margin-left:auto;">@csrf @method('DELETE')<button type="submit" class="adm-icon-btn is-danger" title="Remove" aria-label="Remove this {{ $photo->category }} photo"><x-icon name="x" /></button></form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

@endsection
