{{-- Photos for one package: files, a category, a credit, and what an Amenities or Accessibility photo shows. Expects $package, $categories, $choices. --}}
<form method="POST" action="{{ route('establishment.packages.photos.store', $package) }}" enctype="multipart/form-data">
    @csrf

    <div class="field">
        <label for="pp_files">Photos</label>
        <label class="file-field">
            <input type="file" id="pp_files" name="photos[]" accept="image/png,image/jpeg,image/webp" multiple required>
            <span class="file-field__btn"><x-icon name="camera" /> Choose photos</span>
            <span class="file-field__status" data-file-status>No files chosen</span>
        </label>
        <p class="field-hint">Up to 10 at a time.</p>
    </div>

    <div class="ptr-two">
        <div class="field">
            <label for="pp_category">Category</label>
            <select id="pp_category" name="category" class="form-select" required>
                @foreach ($categories as $cat)
                    <option value="{{ $cat }}" @selected(old('category') === $cat)>{{ $cat }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="pp_credit">Photographer or source</label>
            <input type="text" id="pp_credit" name="credit_text" maxlength="120" value="{{ old('credit_text') }}" placeholder="e.g. Rey Santos">
        </div>
    </div>

    <div class="field" id="pp_shows_box" hidden>
        <label for="pp_shows">What does it show?</label>
        <select id="pp_shows" name="amenity" class="form-select">
            <option value="">Choose</option>
            @foreach ($choices as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
        <p class="field-hint">The system does not read the picture, so say what it is.</p>
    </div>

    <label class="ptr-check"><input type="checkbox" name="own" value="1" id="pp_own" @checked(old('own'))> These are my own photos</label>

    <div class="util-row" style="margin-top:22px;">
        <button type="submit" class="btn btn-primary">Add photos</button>
        <a href="{{ route('establishment.packages.show', $package) }}" class="btn btn-ghost" data-modal-close>Cancel</a>
    </div>
</form>

<script>
    (function () {
        var own = document.getElementById('pp_own'), credit = document.getElementById('pp_credit');
        var cat = document.getElementById('pp_category'), box = document.getElementById('pp_shows_box'), shows = document.getElementById('pp_shows');
        var input = document.getElementById('pp_files'), status = document.querySelector('[data-file-status]');
        if (!own || !cat) return;
        function sync() { credit.disabled = own.checked; }
        function kind() { var on = cat.value === 'Amenities' || cat.value === 'Accessibility'; box.hidden = !on; shows.required = on; }
        own.addEventListener('change', sync); cat.addEventListener('change', kind); sync(); kind();
        if (input && status) input.addEventListener('change', function () { var n = input.files.length; status.textContent = n === 0 ? 'No files chosen' : n === 1 ? input.files[0].name : n + ' files chosen'; });
    })();
</script>
