{{--
    Feedback statistics for one set of reviews. Expects $stats (App\Services\Feedback\ReviewStatistics::analyse()).
    Server-rendered tables and CSS bars only. An aspect nobody rated says so; no zero is shown in place of "no data".
--}}
@php
    $labels = \App\Services\Feedback\ReviewStatistics::LABELS;
    $fmt = fn ($v, $d = 2) => $v === null ? '—' : number_format((float) $v, $d);
@endphp
<div class="fb-index">
    @if ($stats['index']['score'] !== null)
        <span class="fb-index__score">{{ number_format($stats['index']['score'], 1) }}<small>/100</small></span>
        <span class="fb-index__note">Service quality index &mdash; mean of the {{ implode(', ', array_map(fn ($k) => strtolower($labels[$k]), $stats['index']['aspects_used'])) }} average{{ count($stats['index']['aspects_used']) > 1 ? 's' : '' }}, scaled so 1 star = 0 and 5 stars = 100.</span>
    @else
        <span class="fb-index__note"><strong>Service quality index:</strong> {{ $stats['index']['message'] }}</span>
    @endif
</div>

<div class="fb-aspects">
    @foreach ($stats['aspects'] as $key => $a)
        <section class="fb-aspect" aria-label="{{ $labels[$key] }} rating">
            <h3>{{ $labels[$key] }}</h3>
            @if ($a['n'] === 0)
                <p class="fb-aspect__empty">No ratings yet</p>
            @else
                <table class="fb-stats">
                    <tr><th scope="row">Ratings (n)</th><td>{{ $a['n'] }}</td></tr>
                    <tr><th scope="row">Mean</th><td>{{ $fmt($a['mean']) }}</td></tr>
                    <tr><th scope="row">Median</th><td>{{ $fmt($a['median'], 1) }}</td></tr>
                    <tr><th scope="row">Std. deviation</th><td>{{ $a['sd'] === null ? 'n/a (needs 2+ ratings)' : $fmt($a['sd']) }}</td></tr>
                    <tr><th scope="row">95% CI of mean</th><td>{{ $a['ci'] === null ? 'n/a (needs 2+ ratings)' : $fmt($a['ci']['low']).' to '.$fmt($a['ci']['high']) }}</td></tr>
                </table>
                <div class="fb-dist" role="table" aria-label="{{ $labels[$key] }} rating distribution">
                    @foreach ([5, 4, 3, 2, 1] as $star)
                        <span>{{ $star }} ★</span>
                        <span class="fb-dist__bar"><i style="width: {{ $a['distribution'][$star]['percent'] }}%"></i></span>
                        <span class="fb-dist__n">{{ $a['distribution'][$star]['count'] }} ({{ number_format($a['distribution'][$star]['percent'], 1) }}%)</span>
                    @endforeach
                </div>
                <p class="fb-trend">
                    @if ($a['trend'])
                        Last 30 days {{ $fmt($a['trend']['recent_mean']) }} (n={{ $a['trend']['recent_n'] }}) vs previous 30 days {{ $fmt($a['trend']['previous_mean']) }} (n={{ $a['trend']['previous_n'] }}):
                        <strong>{{ $a['trend']['difference'] > 0 ? '+' : '' }}{{ number_format($a['trend']['difference'], 2) }}</strong>
                    @else
                        Trend: not enough reviews (needs at least 3 in each of the last two 30-day periods).
                    @endif
                </p>
            @endif
        </section>
    @endforeach
</div>
