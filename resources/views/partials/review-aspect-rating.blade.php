@php $aspectChosen = (int) old($field, 0); @endphp
<fieldset class="review-form__rating review-form__rating--aspect">
    <legend>{{ $aspectLabel }} <span class="field-hint">(optional)</span></legend>
    <div class="star-input" data-star-input data-star-optional>
        @foreach ([5, 4, 3, 2, 1] as $value)
            <input type="radio" name="{{ $field }}" id="{{ $field }}-{{ $listing->id }}-{{ $value }}" value="{{ $value }}" @checked($aspectChosen === $value)>
            <label for="{{ $field }}-{{ $listing->id }}-{{ $value }}" title="{{ $aspectLabel }}: {{ $value }} — {{ $ratingWords[$value] }}">
                <x-icon name="star" />
                <span class="sr-only">{{ $aspectLabel }}: {{ $value }} — {{ $ratingWords[$value] }}</span>
            </label>
        @endforeach
    </div>
</fieldset>
