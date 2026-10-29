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

    @if (! empty($amenityChoices))
        <div class="field" id="photo_shows_box" hidden>
            <label for="photo_shows">Which one does it show?</label>
            <select id="photo_shows" name="amenity" class="form-select">
                <option value="">Choose</option>
            </select>
            <p class="field-hint" id="photo_shows_hint">The system does not read the picture, so say what it is. That is what lets it sit under the right amenity.</p>
        </div>
    @endif

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


        // Amenities and Accessibility photos say which amenity they show, from the same list the listing ticks.
        var choices = @json($amenityChoices ?? []);
        var ticked = @json(app(\App\Services\Amenities\AmenityStore::class)->keys($establishment->listing_kind, $listing));
        var cat = document.getElementById('photo_category'), box = document.getElementById('photo_shows_box'), shows = document.getElementById('photo_shows'), hint = document.getElementById('photo_shows_hint');
        if (cat && box && shows) {
            function fill() {
                var list = choices[cat.value];
                box.hidden = !list;
                shows.required = !!list;
                shows.innerHTML = '<option value="">Choose</option>' + Object.keys(list || {}).map(function (k) { return '<option value="' + k + '">' + list[k] + '</option>'; }).join('');
            }
            cat.addEventListener('change', fill);
            shows.addEventListener('change', function () {
                hint.textContent = shows.value && ticked.indexOf(shows.value) === -1 ? 'You have not ticked this amenity yet. It will be ticked for you when you upload.' : 'The system does not read the picture, so say what it is. That is what lets it sit under the right amenity.';
            });
            fill();
        }

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
