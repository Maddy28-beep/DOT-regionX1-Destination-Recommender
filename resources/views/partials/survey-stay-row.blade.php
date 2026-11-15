<fieldset class="survey-stay-row" data-stay-row>
    <legend>Stay <span data-stay-number>{{ is_numeric($stayIndex) ? (int) $stayIndex + 1 : '' }}</span></legend>
    <label for="stay-place-{{ $stayIndex }}">Place you stayed</label>
    <select id="stay-place-{{ $stayIndex }}" name="stays[{{ $stayIndex }}][place]" data-stay-choice>
        <option value="" disabled @selected(blank($stayAnswer['place'] ?? null))>Select where you stayed</option>
        <option value="other" @selected(($stayAnswer['place'] ?? '') === 'other')>Other / not in the list</option>
        <option value="friends_family" @selected(($stayAnswer['place'] ?? '') === 'friends_family')>Stayed with friends or family</option>
        <option value="day_trip" @selected(($stayAnswer['place'] ?? '') === 'day_trip')>No overnight stay</option>
        <optgroup label="Accommodations in the directory">
            @foreach ($stayAccommodations as $stayAccommodation)
                <option value="accommodation:{{ $stayAccommodation->id }}" @selected(($stayAnswer['place'] ?? '') === 'accommodation:'.$stayAccommodation->id)>{{ $stayAccommodation->name }} — {{ $stayAccommodation->location }}</option>
            @endforeach
        </optgroup>
    </select>
    <div class="survey-stay-manual" data-stay-manual>
        <div class="field">
            <label for="stay-name-{{ $stayIndex }}">Accommodation name <span>(if not in the list)</span></label>
            <input id="stay-name-{{ $stayIndex }}" name="stays[{{ $stayIndex }}][name]" type="text" maxlength="180" value="{{ $stayAnswer['name'] ?? '' }}" placeholder="Hotel, rental or property name" data-stay-name>
        </div>
        <div class="field">
            <label for="stay-locality-{{ $stayIndex }}">City / municipality or area <span>(if not in the list)</span></label>
            <input id="stay-locality-{{ $stayIndex }}" name="stays[{{ $stayIndex }}][locality]" type="text" maxlength="180" value="{{ $stayAnswer['locality'] ?? '' }}" placeholder="e.g. Matina, Davao City" data-stay-locality>
        </div>
    </div>
    <button type="button" class="survey-stay-remove" data-remove-stay hidden>Remove stay</button>
</fieldset>
