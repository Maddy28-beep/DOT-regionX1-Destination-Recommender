{{--
    What is waiting between an establishment and DOT: the draft not sent yet, what is waiting for DOT, and what DOT sent back.
    Travelers see only what DOT approved; the establishment sees its own draft laid over it. Expects $establishment.
--}}
@php
    $drafts = app(\App\Services\Listings\ListingDraft::class);
    $draft = $drafts->current($establishment)?->load('changes');
    $waiting = \App\Models\ListingSubmission::where('establishment_account_id', $establishment->id)->where('status', \App\Models\ListingSubmission::PENDING)->with('changes')->latest('id')->get();
    $returned = \App\Models\ListingSubmission::where('establishment_account_id', $establishment->id)->where('status', \App\Models\ListingSubmission::RETURNED)->with('changes')->latest('id')->get();
    $labels = ['details' => 'details', 'hours' => 'opening hours', 'position' => 'map position', 'amenities' => 'amenities', 'photo' => 'photo', 'promotion' => 'offer'];
    $summary = fn ($submission) => $submission->changes->groupBy('kind')
        ->map(fn ($group, $kind) => ($kind === 'photo' || $kind === 'promotion') ? $group->count().' '.\Illuminate\Support\Str::plural($labels[$kind], $group->count()) : $labels[$kind])
        ->implode(', ');
@endphp

@if ($draft && $draft->changes->isNotEmpty())
    <section class="draft-bar draft-bar--draft" aria-label="Changes not sent yet">
        <div class="draft-bar__text">
            <strong>You have {{ $draft->changes->count() }} {{ \Illuminate\Support\Str::plural('change', $draft->changes->count()) }} not sent yet</strong>
            <span>{{ ucfirst($summary($draft)) }}. Travelers still see your approved page.</span>
        </div>
        <div class="draft-bar__actions">
            <form method="POST" action="{{ route('establishment.listing.discard') }}" onsubmit="return confirm('Discard these changes? Photos and offers you added in this draft are removed too.');">
                @csrf
                <button type="submit" class="btn btn-outline">Discard changes</button>
            </form>
            <form method="POST" action="{{ route('establishment.listing.submit') }}">
                @csrf
                <button type="submit" class="btn btn-primary">Submit for approval</button>
            </form>
        </div>
    </section>
@endif

@foreach ($waiting as $submission)
    <section class="draft-bar draft-bar--waiting" aria-label="Waiting for DOT">
        <div class="draft-bar__text">
            <strong>Waiting for DOT <span class="draft-bar__pill">{{ $submission->changes->count() }} {{ \Illuminate\Support\Str::plural('change', $submission->changes->count()) }} · sent {{ $submission->submitted_at?->timezone('Asia/Manila')->format('j M') }}</span></strong>
            <span>{{ ucfirst($summary($submission)) }}. You can keep editing; new edits go in the next draft.</span>
        </div>
    </section>
@endforeach

@foreach ($returned as $submission)
    <section class="draft-bar draft-bar--returned" aria-label="Returned by DOT">
        <div class="draft-bar__text">
            <strong>Returned by DOT <span class="draft-bar__pill">{{ $submission->changes->count() }} {{ \Illuminate\Support\Str::plural('change', $submission->changes->count()) }}</span></strong>
            <span>&ldquo;{{ $submission->note }}&rdquo;</span>
        </div>
        <div class="draft-bar__actions">
            <form method="POST" action="{{ route('establishment.listing.reopen', $submission) }}">
                @csrf
                <button type="submit" class="btn btn-outline">Edit and resubmit</button>
            </form>
        </div>
    </section>
@endforeach
