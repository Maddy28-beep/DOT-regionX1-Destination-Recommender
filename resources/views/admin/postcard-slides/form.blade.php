@extends('layouts.admin')

@section('title', ($slide->exists ? 'Edit' : 'Add').' slide — DOT Admin')
@section('page-title', $slide->exists ? 'Edit slide' : 'Add slide')
@section('page-sub', 'A slide in the homepage Popular Right Now carousel')

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
        <form method="POST" enctype="multipart/form-data"
              action="{{ $slide->exists ? route('admin.postcard-slides.update', $slide) : route('admin.postcard-slides.store') }}">
            @csrf
            @if ($slide->exists)
                @method('PUT')
            @endif

            <div class="field">
                <label for="image">Photo {{ $slide->exists ? '(leave empty to keep the current one)' : '' }}</label>
                <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp" {{ $slide->exists ? '' : 'required' }}>
                <p class="field-hint">At least 1920 x 1080 pixels, JPG, PNG or WebP, up to 10 MB. It is resized and converted to WebP automatically. Avoid visible brand logos and bystanders, and confirm consent for identifiable people and for traditional attire before publishing.</p>
            </div>

            <div class="field">
                <label for="alt_text">Photo description (alt text)</label>
                <input type="text" id="alt_text" name="alt_text" value="{{ old('alt_text', $slide->alt_text) }}" required minlength="10" maxlength="255"
                       placeholder="e.g. Fisherman casting a net from a banca at sunrise, Samal Island">
                <p class="field-hint">Describe what is in the picture for people using a screen reader. Not the category.</p>
            </div>

            <div class="field">
                <label>Focal point</label>
                <p class="field-hint" style="margin-top:0;">Click the subject (a face, an instrument, the summit). The photo is cropped and zoomed around this point on every screen size.</p>
                <div id="focalPicker" style="position:relative; max-width:520px; cursor:crosshair; border-radius:10px; overflow:hidden; background:#e6ece8; min-height:120px;">
                    <img id="focalImage" src="{{ $slide->exists ? $slide->imageUrl() : '' }}" alt="" style="display:block; width:100%; height:auto;" {{ $slide->exists ? '' : 'hidden' }}>
                    <span id="focalDot" aria-hidden="true" style="position:absolute; width:22px; height:22px; margin:-11px 0 0 -11px; border-radius:50%; border:3px solid #fff; background:rgba(217,71,44,.85); box-shadow:0 0 0 2px #1a2420; pointer-events:none; left:{{ old('focal_x', $slide->focal_x ?? 50) }}%; top:{{ old('focal_y', $slide->focal_y ?? 50) }}%;"></span>
                    <p id="focalEmpty" class="cell-muted" style="margin:0; padding:40px 16px; text-align:center;" {{ $slide->exists ? 'hidden' : '' }}>Choose a photo above to set the focal point.</p>
                </div>
                <div class="filter-inline" style="margin-top:8px;">
                    <div class="field" style="flex:1; min-width:120px;">
                        <label for="focal_x">Horizontal (%)</label>
                        <input type="number" id="focal_x" name="focal_x" min="0" max="100" value="{{ old('focal_x', $slide->focal_x ?? 50) }}" required>
                    </div>
                    <div class="field" style="flex:1; min-width:120px;">
                        <label for="focal_y">Vertical (%)</label>
                        <input type="number" id="focal_y" name="focal_y" min="0" max="100" value="{{ old('focal_y', $slide->focal_y ?? 50) }}" required>
                    </div>
                </div>
            </div>

            <div class="filter-inline" style="align-items:start;">
                <div class="field" style="flex:1; min-width:200px;">
                    <label for="kicker">Category line</label>
                    <input type="text" id="kicker" name="kicker" value="{{ old('kicker', $slide->kicker) }}" required maxlength="60" placeholder="e.g. living traditions">
                </div>
                <div class="field" style="flex:1; min-width:200px;">
                    <label for="title">Name of the place or experience</label>
                    <input type="text" id="title" name="title" value="{{ old('title', $slide->title) }}" required maxlength="100" placeholder="e.g. Mount Apo Natural Park">
                </div>
                <div class="field" style="flex:1; min-width:200px;">
                    <label for="location">Location (optional)</label>
                    <input type="text" id="location" name="location" value="{{ old('location', $slide->location) }}" maxlength="100" placeholder="e.g. Davao del Sur">
                </div>
            </div>

            <div class="filter-inline" style="align-items:start;">
                <div class="field" style="flex:1; min-width:200px;">
                    <label for="cta_label">Button label</label>
                    <input type="text" id="cta_label" name="cta_label" value="{{ old('cta_label', $slide->cta_label) }}" required maxlength="60" placeholder="e.g. View destination →">
                </div>
                <div class="field" style="flex:2; min-width:260px;">
                    <label for="cta_url">Button links to</label>
                    <input type="text" id="cta_url" name="cta_url" value="{{ old('cta_url', $slide->cta_url) }}" required maxlength="255" placeholder="/destinations/mount-apo-natural-park">
                    <p class="field-hint">A page on this site, like /destinations/mount-apo-natural-park, or a filtered list like /destinations?type=Wildlife.</p>
                </div>
            </div>

            <div class="filter-inline" style="align-items:center;">
                <div class="field" style="flex:1; min-width:120px;">
                    <label for="sort_order">Order</label>
                    <input type="number" id="sort_order" name="sort_order" min="0" max="65535" value="{{ old('sort_order', $slide->sort_order) }}" required>
                </div>
                <label style="display:flex; align-items:center; gap:8px; margin-top:18px;">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $slide->is_active))>
                    Show on the homepage
                </label>
            </div>

            <button type="submit" class="btn btn-primary" style="margin-top:10px;">{{ $slide->exists ? 'Save changes' : 'Add slide' }}</button>
            <a href="{{ route('admin.postcard-slides.index') }}" class="btn btn-outline" style="margin-top:10px;">Cancel</a>
        </form>
    </div>
</div>

<script>
    (function () {
        var picker = document.getElementById('focalPicker');
        var img = document.getElementById('focalImage');
        var dot = document.getElementById('focalDot');
        var empty = document.getElementById('focalEmpty');
        var fx = document.getElementById('focal_x');
        var fy = document.getElementById('focal_y');
        var file = document.getElementById('image');
        if (!picker) return;

        function clamp(n) { return Math.max(0, Math.min(100, Math.round(n))); }
        function place() { dot.style.left = clamp(fx.value) + '%'; dot.style.top = clamp(fy.value) + '%'; }

        picker.addEventListener('click', function (e) {
            if (img.hidden) return;
            var r = img.getBoundingClientRect();
            fx.value = clamp((e.clientX - r.left) / r.width * 100);
            fy.value = clamp((e.clientY - r.top) / r.height * 100);
            place();
        });
        fx.addEventListener('input', place);
        fy.addEventListener('input', place);

        file.addEventListener('change', function () {
            if (!file.files[0]) return;
            img.src = URL.createObjectURL(file.files[0]);
            img.hidden = false;
            empty.hidden = true;
        });
    })();
</script>

@endsection
