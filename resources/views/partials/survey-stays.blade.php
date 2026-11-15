<section class="survey-stays" aria-labelledby="survey-stays-title" data-survey-stays>
    <h2 id="survey-stays-title">Where did you stay? <span>(optional)</span></h2>
    <p class="field-hint">Scroll through the accommodation names and addresses to find where you stayed. If it’s missing, choose “Other / not in the list”.</p>
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
    <p class="field-hint">No private home address, host name or contact details needed. You can leave this question blank.</p>
    <template data-stay-template>@include('partials.survey-stay-row', ['stayIndex' => '__INDEX__', 'stayAnswer' => []])</template>
</section>
<script src="{{ asset('js/survey-stays.js') }}?v={{ filemtime(public_path('js/survey-stays.js')) }}" defer></script>
