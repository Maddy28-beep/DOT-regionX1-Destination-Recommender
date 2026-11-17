<section class="survey-stays" aria-labelledby="survey-stays-title" data-survey-stays>
    <h2 id="survey-stays-title"><x-icon name="bed" /> Where did you stay overnight? <span>(optional)</span></h2>
    <p class="field-hint">Search accommodations by name or area. Add up to 5 stays.</p>
    @php
        $stayAnswers = old('stays');
        $stayAnswers = is_array($stayAnswers) ? array_values(array_slice($stayAnswers, 0, 5)) : [];
        $stayAnswers = array_filter($stayAnswers, 'is_array') ?: [['place' => '']];
    @endphp
    <div data-stay-rows>
        @foreach ($stayAnswers as $index => $stay)
            @include('partials.survey-stay-row', ['stayIndex' => $index, 'stayAnswer' => $stay])
        @endforeach
    </div>
    <button class="survey-stay-add" type="button" data-add-stay hidden>+ Add another place you stayed</button>
    <p class="field-hint">Optional. No home address, host name or contact details needed.</p>
    <p class="field-hint">A day visit to a resort belongs under Places you visited.</p>
    <template data-stay-template>@include('partials.survey-stay-row', ['stayIndex' => '__INDEX__', 'stayAnswer' => []])</template>
</section>
<script src="{{ asset('js/survey-stays.js') }}?v={{ filemtime(public_path('js/survey-stays.js')) }}" defer></script>
