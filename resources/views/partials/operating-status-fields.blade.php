{{-- Operating status fields, shared by the DOT admin listing form and the partner portal.
     Expects $listing. Trip plans skip a place while it is closed on the traveller's dates. --}}
<fieldset class="field" id="operating-status" style="border:1px solid var(--border); border-radius:10px; padding:14px 16px; margin:18px 0;">
    <legend style="padding:0 6px; font-weight:600;">Operating status</legend>
    <div class="field">
        <label for="operating_status">Is this place operating?</label>
        <select id="operating_status" name="operating_status" class="form-select">
            @foreach (\App\Models\Destination::OPERATING_STATUSES as $value => $label)
                <option value="{{ $value }}" @selected(old('operating_status', $listing->operating_status ?? 'open') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <small class="muted">Closed places are not recommended in trip plans and show a notice on their page. Open is the normal setting.</small>
    </div>
    <div class="field">
        <label for="reopens_on">Reopens on (for a temporary closure)</label>
        <input type="date" id="reopens_on" name="reopens_on" value="{{ old('reopens_on', optional($listing->reopens_on)->format('Y-m-d')) }}">
        <small class="muted">The first day it is open again. Leave empty if there is no date yet.</small>
    </div>
    <div class="field">
        <label for="closure_reason">Reason shown to travellers (optional)</label>
        <input type="text" id="closure_reason" name="closure_reason" maxlength="255" value="{{ old('closure_reason', $listing->closure_reason) }}" placeholder="e.g. Repairs after the storm">
    </div>
</fieldset>
