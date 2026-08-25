@extends('layouts.establishment')

@section('title', 'Photos — Partner Dashboard')
@section('page-title', 'Photos')
@section('page-sub', 'Manage the photos travelers see for '.$listing->name)

@section('content')

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Upload Photos</h2>
            <p>JPG, PNG, or WebP &middot; up to 2MB each &middot; up to 10 at a time.</p>
        </div>
    </div>
    <div class="panel-body">
        <form method="POST" action="{{ route('establishment.photos.store') }}" enctype="multipart/form-data">
            @csrf
            <div class="upload-dropzone">
                <div class="upload-icon"><x-icon name="camera" /></div>
                <p>Choose photos to upload, tag them with a category, and add them to your listing.</p>
                <input type="file" name="photos[]" accept="image/png,image/jpeg,image/webp" multiple required>
                <div class="upload-controls">
                    <select name="category" required>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat }}">{{ $cat }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-primary">Upload</button>
                </div>
            </div>
            @error('photos.*')
                <div class="alert alert-error" style="margin-top:14px;">{{ $message }}</div>
            @enderror
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>{{ $photos->count() }} Photo{{ $photos->count() === 1 ? '' : 's' }}</h2>
            <p>The cover photo is what travelers see first on cards and search results.</p>
        </div>
    </div>
    <div class="panel-body">
        @if ($photos->isEmpty())
            <div class="empty-panel" style="padding:24px;">
                <div class="icon"><x-icon name="camera" /></div>
                <h3>No photos yet</h3>
                <p>Upload your first photo above &mdash; travelers currently see a placeholder color block instead.</p>
            </div>
        @else
            <div class="photo-grid">
                @foreach ($photos as $photo)
                    <div class="photo-tile">
                        <img src="{{ $photo->url() }}" alt="{{ $listing->name }} photo">
                        @if ($photo->is_primary)
                            <span class="cover-badge">Cover</span>
                        @endif
                        <span class="cat-badge">{{ $photo->category }}</span>
                        <div class="photo-tile-actions">
                            @unless ($photo->is_primary)
                                <form method="POST" action="{{ route('establishment.photos.primary', $photo) }}">
                                    @csrf
                                    <button type="submit">Set Cover</button>
                                </form>
                            @endunless
                            <form method="POST" action="{{ route('establishment.photos.up', $photo) }}">
                                @csrf
                                <button type="submit" title="Move earlier">&uarr;</button>
                            </form>
                            <form method="POST" action="{{ route('establishment.photos.down', $photo) }}">
                                @csrf
                                <button type="submit" title="Move later">&darr;</button>
                            </form>
                            <form method="POST" action="{{ route('establishment.photos.destroy', $photo) }}" onsubmit="return confirm('Remove this photo?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="danger">Delete</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

@endsection
