{{-- Upload photos: files, a category, and who took them (shown under each photo). Expects $categories. --}}
<form method="POST" action="{{ route('establishment.photos.store') }}" enctype="multipart/form-data">
    @csrf

    <div class="field">
        <label for="photo_files">Photos</label>
        <label class="file-field">
            <input type="file" id="photo_files" name="photos[]" accept="image/png,image/jpeg,image/webp" multiple required>
            <span class="file-field__btn"><x-icon name="camera" /> Choose photos</span>
            <span class="file-field__status" data-file-status>No files chosen</span>
        </label>
        <p class="field-hint">Up to 10 at a time.</p>
    </div>

    <div class="ptr-two">
        <div class="field">
            <label for="photo_category">Category</label>
            <select id="photo_category" name="category" class="form-select" required>
                @foreach ($categories as $cat)
                    <option value="{{ $cat }}" @selected(old('category') === $cat)>{{ $cat }}</option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="photo_credit">Photographer or source</label>
            <input type="text" id="photo_credit" name="credit_text" maxlength="120" value="{{ old('credit_text') }}" placeholder="e.g. Rey Santos">
        </div>
    </div>

    <label class="ptr-check"><input type="checkbox" name="own" value="1" id="photo_own" @checked(old('own'))> These are my own photos</label>
    <p class="field-hint">Travellers see this credit under each photo.</p>

    <div class="util-row" style="margin-top:22px;">
        <button type="submit" class="btn btn-primary">Upload</button>
        <a href="{{ route('establishment.photos') }}" class="btn btn-ghost" data-modal-close>Cancel</a>
    </div>
</form>

<script>
    (function () {
        var own = document.getElementById('photo_own');
        var credit = document.getElementById('photo_credit');
        if (!own || !credit) return;
        function sync() { credit.disabled = own.checked; }
        own.addEventListener('change', sync);
        sync();

        // Markup set by the modal does not get app.js's file-status handler, so the chosen names are shown here.
        var input = document.getElementById('photo_files');
        var status = document.querySelector('[data-file-status]');
        if (input && status) {
            input.addEventListener('change', function () {
                var n = input.files.length;
                status.textContent = n === 0 ? 'No files chosen' : n === 1 ? input.files[0].name : n + ' files chosen';
            });
        }
    })();
</script>
