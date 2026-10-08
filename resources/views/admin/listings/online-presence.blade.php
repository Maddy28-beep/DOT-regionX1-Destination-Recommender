{{--
    Online presence: the four optional links shown as "Find them online" on the public listing page.
    Leave a field empty to hide that tile. Shared by every listing type's form.

    $listing: the listing being edited (or a new, empty one).
--}}
<div class="form-section" style="margin-top:22px;">
    <h3 style="font-size:.78rem; letter-spacing:.08em; text-transform:uppercase; color:var(--muted); margin:0 0 4px;">
        Online presence <span class="status-pill status-active" style="text-transform:none; letter-spacing:0; margin-left:6px;">all optional</span>
    </h3>
    <p class="field-hint" style="margin:0 0 10px;">Shown on the public page as links that open in a new tab. Enter the full address, starting with http:// or https://.</p>

    @foreach ([
        'website_url' => ['Official website', 'https://www.example.com'],
        'facebook_url' => ['Facebook page', 'https://www.facebook.com/yourpage'],
    ] as $field => [$label, $placeholder])
        <div class="field">
            <label for="{{ $field }}">{{ $label }}</label>
            <input type="url" id="{{ $field }}" name="{{ $field }}" value="{{ old($field, $listing->{$field}) }}" placeholder="{{ $placeholder }}" maxlength="255">
            @error($field)<p class="field-error" style="color:var(--stamp-red); font-size:.8rem; margin:4px 0 0;">{{ $message }}</p>@enderror
        </div>
    @endforeach

    <div class="filter-inline" style="align-items:start;">
        @foreach ([
            'instagram_url' => ['Instagram', 'https://www.instagram.com/yourpage'],
            'tiktok_url' => ['TikTok', 'https://www.tiktok.com/@yourpage'],
        ] as $field => [$label, $placeholder])
            <div class="field" style="flex:1; min-width:200px;">
                <label for="{{ $field }}">{{ $label }}</label>
                <input type="url" id="{{ $field }}" name="{{ $field }}" value="{{ old($field, $listing->{$field}) }}" placeholder="{{ $placeholder }}" maxlength="255">
                @error($field)<p class="field-error" style="color:var(--stamp-red); font-size:.8rem; margin:4px 0 0;">{{ $message }}</p>@enderror
            </div>
        @endforeach
    </div>
</div>
