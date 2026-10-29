{{--
    One package for a DOT admin to read and decide: the package on the left, the checks and the decision on the right.
    The full page wraps it in a panel and the queue loads it into a modal (public/js/admin-modal.js).

    $package (with tourOperator, itineraryDays, inclusions, events), $checks, $tab
--}}
@php
    $waiting = $package->status === \App\Models\Package::STATUS_IN_REVIEW;
    $blocked = ! $checks[0]['ok'];
    $failedSoft = collect($checks)->where('hard', false)->where('ok', false)->count();
    $old = old('decision', $blocked ? 'changes' : null);
@endphp
<div class="adm-rv2">
    <section class="adm-rv2__main">
        <h3>The package</h3>
        <p class="adm-rv2__desc">{{ $package->description ?: 'No description.' }}</p>
        <dl class="adm-rv2__facts">
            <dt>Price</dt><dd>{{ $package->price_per_pax ? '₱'.number_format($package->price_per_pax).' per person' : 'Not set' }}{{ $package->price_tier ? ' · '.$package->price_tier : '' }}</dd>
            <dt>Length</dt><dd>{{ $package->itineraryDays->count() }} {{ \Illuminate\Support\Str::plural('day', $package->itineraryDays->count()) }}</dd>
            <dt>Meets at</dt><dd>{!! $package->meeting_point ? e($package->meeting_point) : '<span class="adm-rv2__miss">Not given</span>' !!}</dd>
            <dt>Included</dt><dd>@forelse ($package->inclusions as $inclusion)<span class="adm-rv2__chip">{{ $inclusion->item }}</span>@empty<span class="adm-rv2__miss">Nothing ticked</span>@endforelse</dd>
        </dl>

        <h3>Accessibility</h3>
        <p class="adm-rv2__desc" style="margin-bottom:6px;"><b>Difficulty:</b> {!! $package->difficulty ? e(\App\Support\Amenities::DIFFICULTY[$package->difficulty][0]) : '<span class="adm-rv2__miss">Not set</span>' !!}</p>
        <div>@include('partials.amenity-chips', ['keys' => $featureKeys, 'evidence' => $package->photos->pluck('amenity')->filter()->unique()->all(), 'empty' => 'Nothing ticked'])</div>
        @if ($package->accessibility_note)<p class="adm-rv2__desc" style="margin-top:6px;">{{ $package->accessibility_note }}</p>@endif

        <h3>Day by day</h3>
        @forelse ($package->itineraryDays as $day)
            <div class="adm-rv2__day"><span>Day {{ $day->day_number }}</span><div><strong>{{ $day->title }}</strong>@if ($day->description)<p>{{ $day->description }}</p>@endif</div></div>
        @empty
            <p class="adm-rv2__miss">No days added.</p>
        @endforelse

        @if ($package->events->isNotEmpty())
            <h3>History</h3>
            @foreach ($package->events as $event)
                <div class="adm-rv2__hist">
                    <b>{{ ['submitted' => 'Sent to DOT', 'approved' => 'Approved', 'changes_requested' => 'Changes requested', 'withdrawn' => 'Withdrawn', 'paused' => 'Paused', 'resumed' => 'Made live again'][$event->action] ?? $event->action }}</b>
                    &middot; {{ $event->created_at->timezone('Asia/Manila')->format('M j') }} &middot; {{ $event->actor_name }}
                    @if ($event->note)<p>{{ $event->note }}</p>@endif
                </div>
            @endforeach
        @endif
    </section>

    <aside class="adm-rv2__side">
        <h3>Checks</h3>
        <ul class="adm-rv2__checks">
            @foreach ($checks as $check)
                @php $isSample = $check['sample'] ?? false; @endphp
                <li class="{{ $check['ok'] ? 'is-ok' : 'is-no' }}{{ $isSample ? ' is-sample' : '' }}">
                    <span class="adm-rv2__ic" aria-hidden="true"><x-icon :name="$isSample ? 'info' : ($check['ok'] ? 'check' : 'x')" /></span>
                    <span><b>{{ $check['title'] }}</b>@if ($isSample)<x-sample-tag label="Sample data, not DOT-verified" title="This operator's accreditation number is a placeholder, not an official DOT number." />@endif<small>{{ $check['detail'] }}</small></span>
                    <span class="sr-only">{{ $isSample ? 'sample data, not verified' : ($check['ok'] ? 'passes' : 'fails') }}</span>
                </li>
            @endforeach
        </ul>

        @if ($waiting)
            <h3>Your decision</h3>
            @if ($blocked)
                <x-banner tone="warn"><strong>Cannot approve yet.</strong> The operator&rsquo;s accreditation has lapsed, so approving would put a lapsed operator in front of travellers. Ask them to renew first.</x-banner>
            @elseif ($failedSoft > 0)
                <p class="field-hint" style="margin-top:0;">{{ $failedSoft }} {{ $failedSoft === 1 ? 'check does' : 'checks do' }} not pass. They are guidance: you can still approve.</p>
            @endif

            <form method="POST" action="{{ route('admin.package-approvals.decide', $package) }}" id="decision-form">
                @csrf
                <input type="hidden" name="tab" value="{{ $tab }}">
                <div class="adm-rv2__choices" role="radiogroup" aria-label="Decision">
                    <label><input type="radio" name="decision" value="approve" @disabled($blocked) @checked($old === 'approve')><span>Approve<small>It goes live now and the operator is told.</small></span></label>
                    <label><input type="radio" name="decision" value="changes" @checked($old === 'changes')><span>Ask for changes<small>It stays hidden. The operator reads your note word for word.</small></span></label>
                </div>
                <div class="field" id="decision-note" style="margin-top:12px;" @unless ($old === 'changes') hidden @endunless>
                    <label for="note">Note to the operator</label>
                    <textarea id="note" name="note" rows="3" maxlength="400" placeholder="Say what to fix.">{{ old('note') }}</textarea>
                    <div class="adm-rv2__reasons">
                        @foreach (['Add a meeting point', 'Add the time each day starts', 'The price looks wrong', 'The description is too short', 'Add more photos', 'Check the inclusions'] as $reason)
                            <button type="button" class="btn btn-outline btn-sm" data-reason="{{ $reason }}.">{{ $reason }}</button>
                        @endforeach
                    </div>
                </div>

                <div class="util-row" style="margin-top:18px;">
                    <button type="submit" class="btn btn-primary" id="decision-submit">{{ $blocked ? 'Send note to operator' : 'Record decision' }}</button>
                    <a href="{{ route('admin.package-approvals', ['tab' => $tab]) }}" class="btn btn-ghost" data-modal-close>Cancel</a>
                </div>
            </form>

            <script>
                (function () {
                    var form = document.getElementById('decision-form');
                    if (!form) return;
                    var note = document.getElementById('decision-note'), box = document.getElementById('note'), go = document.getElementById('decision-submit');
                    function sync() {
                        var chosen = form.querySelector('input[name="decision"]:checked');
                        note.hidden = !chosen || chosen.value !== 'changes';
                        go.textContent = !chosen ? 'Record decision' : chosen.value === 'approve' ? 'Approve package' : 'Send note to operator';
                    }
                    form.querySelectorAll('input[name="decision"]').forEach(function (r) { r.addEventListener('change', sync); });
                    form.querySelectorAll('[data-reason]').forEach(function (b) {
                        b.addEventListener('click', function () { box.value = (box.value.trim() ? box.value.trim() + ' ' : '') + b.dataset.reason; box.focus(); });
                    });
                    sync();
                })();
            </script>
        @else
            <h3>Status</h3>
            <p style="margin:0 0 10px;"><span class="status-pill {{ $package->status === 'live' ? 'status-active' : 'status-expired' }}">{{ $package->statusLabel() }}</span></p>
            @if ($package->status === \App\Models\Package::STATUS_CHANGES && $package->review_note)
                <div class="adm-rv2__hist"><b>Your note</b><p>{{ $package->review_note }}</p></div>
            @endif
            @if (in_array($package->status, [\App\Models\Package::STATUS_LIVE, \App\Models\Package::STATUS_PAUSED], true))
                <form method="POST" action="{{ route($package->status === 'live' ? 'admin.package-approvals.pause' : 'admin.package-approvals.resume', $package) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline">{{ $package->status === 'live' ? 'Pause this package' : 'Make it live again' }}</button>
                    <p class="field-hint">{{ $package->status === 'live' ? 'It is hidden from travellers and the operator is told.' : 'Travellers can see it again.' }}</p>
                </form>
            @endif
            <div class="util-row" style="margin-top:18px;"><a href="{{ route('admin.package-approvals', ['tab' => $tab]) }}" class="btn btn-ghost" data-modal-close>Close</a></div>
        @endif
    </aside>
</div>
