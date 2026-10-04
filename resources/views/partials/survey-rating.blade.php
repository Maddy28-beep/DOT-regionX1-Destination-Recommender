<fieldset class="survey-rating">
    <legend>{{ $label }} <span>{{ ($required ?? false) ? '(required)' : '(optional)' }}</span></legend>
    <div class="survey-rating-options">
        @foreach ([1 => 'Very poor', 2 => 'Poor', 3 => 'Okay', 4 => 'Good', 5 => 'Excellent'] as $score => $description)
            <label>
                <input type="radio" name="{{ $name }}" value="{{ $score }}" @checked((string) old($name) === (string) $score) @required($required ?? false)>
                <span><strong>{{ $score }}</strong><small>{{ $description }}</small></span>
            </label>
        @endforeach
    </div>
    @unless ($required ?? false)
        <label class="survey-rating-skip"><input type="radio" name="{{ $name }}" value="" @checked(old($name) === '')> Not applicable / didn’t use</label>
    @endunless
    @error($name)<p class="survey-field-error">{{ $message }}</p>@enderror
</fieldset>
