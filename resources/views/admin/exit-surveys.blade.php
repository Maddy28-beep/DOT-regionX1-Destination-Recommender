@extends('layouts.admin')

@section('title', 'Exit Survey Insights — DOT Admin')
@section('page-title', 'Exit Survey Insights')
@section('page-sub', 'Visitor profile, satisfaction ratings, and visitation trends from submitted exit surveys')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/admin-exit-surveys.css') }}?v={{ filemtime(public_path('css/admin-exit-surveys.css')) }}">
@endpush

@section('content')
<div class="xs">

<div class="xs-head">
    <p>Collecting more responses?</p>
    <a href="{{ route('admin.exit-survey-qr') }}" class="btn btn-outline"><x-icon name="qr" /> Printable survey QR code</a>
</div>

<div class="panel xs-filters">
    <div class="panel-body">
        <form method="GET" class="filter-inline" aria-label="Filter survey responses">
            <div class="field">
                <label for="from">From date</label>
                <input type="date" id="from" name="from" value="{{ $filters['from'] }}">
            </div>
            <div class="field">
                <label for="to">To date</label>
                <input type="date" id="to" name="to" value="{{ $filters['to'] }}">
            </div>
            <div class="field">
                <label for="residency">Residency</label>
                <select id="residency" name="residency">
                    <option value="">All</option>
                    @foreach ($residencyOptions as $option)
                        <option value="{{ $option }}" @selected($filters['residency'] === $option)>{{ $option }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="visitor_type">Visit type</label>
                <select id="visitor_type" name="visitor_type">
                    <option value="">All</option>
                    @foreach ($visitorTypeOptions as $option)
                        <option value="{{ $option }}" @selected($filters['visitor_type'] === $option)>{{ $option }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="purpose">Purpose of travel</label>
                <select id="purpose" name="purpose">
                    <option value="">All</option>
                    @foreach ($purposeOptions as $option)
                        <option value="{{ $option }}" @selected($filters['purpose'] === $option)>{{ $option }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field xs-sim">
                <label class="check-inline" for="include_simulated" style="font-weight:500;">
                    <input type="checkbox" id="include_simulated" name="include_simulated" value="1" @checked($includeSimulated)>
                    Include simulated survey rows
                </label>
            </div>
            <button type="submit" class="btn btn-primary">Apply Filter</button>
            @if ($hasActiveFilters)
                <a href="{{ route('admin.exit-surveys') }}" class="btn btn-outline">Clear Filters</a>
            @endif
        </form>
    </div>
</div>

@if ($includeSimulated)
    <x-banner tone="warn">These figures include simulated demo surveys. They show how the system works and are not what visitors said.</x-banner>
@elseif ($simulatedHidden > 0)
    <x-banner tone="info">Showing real tourist responses only. {{ $simulatedHidden }} simulated demo survey{{ $simulatedHidden === 1 ? '' : 's' }} {{ $simulatedHidden === 1 ? 'is' : 'are' }} left out; tick &ldquo;Include simulated survey rows&rdquo; to add them.</x-banner>
@endif

@if ($count === 0)
    <div class="panel">
        <div class="empty-panel">
            <div class="icon"><x-icon name="chart" /></div>
            <h3>{{ $hasActiveFilters ? 'No exit survey responses match these filters' : 'No exit survey responses yet' }}</h3>
            <p>
                @if ($hasActiveFilters)
                    Try widening the date range or clearing a filter.
                @else
                    Once tourists complete the exit survey (2.2.1.7), visitor profile, satisfaction, and visitation charts will appear here automatically.
                @endif
            </p>
        </div>
    </div>
@else
    @php
        $fmtPct = fn ($n, $d) => $d ? round($n / $d * 100) : 0;
        $firstOf = fn ($c) => $c->isEmpty() ? null : (is_array($c->first()) ? $c->first() : null);

        // Ratings, highest first; categories nobody rated yet go last, in their usual order.
        $ratedRows = collect($avgRatings)->filter(fn ($v) => $v !== null)->sortDesc();
        $unratedRows = collect($avgRatings)->filter(fn ($v) => $v === null);

        // The donut: each activity's share, in the project palette. Anything past the fifth is one "Other" slice.
        $palette = ['#0E6B4A', '#8C5F08', '#2F5D8A', '#9B3B3B', '#6B7268'];
        $slices = [];
        foreach ($topActivities->values() as $i => $activity) {
            $slices[] = ['label' => $activity['activity'], 'total' => $activity['total'], 'pct' => $activity['pct'], 'color' => $palette[$i % count($palette)]];
        }
        $activityTotal = array_sum(array_column($slices, 'total'));
        $stops = [];
        $acc = 0;
        foreach ($slices as $sl) {
            $share = $activityTotal ? $sl['total'] / $activityTotal * 100 : 0;
            $stops[] = $sl['color'].' '.round($acc, 2).'% '.round($acc + $share, 2).'%';
            $acc += $share;
        }
        $donutStyle = $stops ? 'background:conic-gradient('.implode(',', $stops).')' : '';

        $maxPlace = max(1, (int) ($topPlaces->max('visits') ?? 1));
        $kindClass = fn ($k) => $k === 'restaurant' ? 'is-restaurant' : (in_array($k, ['souvenir_center', 'package', 'tour_operator'], true) ? 'is-shop' : '');
    @endphp

    <section class="xs-kpis" aria-label="Summary">
        <div class="xs-kpi">
            <span class="xs-ico"><x-icon name="chart" /></span>
            <div>
                <div class="stat-card-val">{{ $count }}</div>
                <div class="stat-card-label">Exit Survey Responses</div>
            </div>
        </div>
        <div class="xs-kpi" title="A rule needs two places in the same survey, so only real responses that list two or more places count towards Apriori. Simulated demo rows are not included.">
            <span class="xs-ico"><x-icon name="check" /></span>
            <div>
                <div class="stat-card-val">{{ $realUsableForRules }}<span class="stat-card-val__suffix"> of {{ $realCount }}</span></div>
                <div class="stat-card-label">Real Surveys Usable for Rules</div>
            </div>
        </div>
        <div class="xs-kpi">
            <span class="xs-ico is-blue"><x-icon name="map-pin" /></span>
            <div>
                <div class="stat-card-val">{{ $checkedInVisitors }}</div>
                <div class="stat-card-label">Distinct Check-ins</div>
            </div>
        </div>
        <div class="xs-kpi">
            <span class="xs-ico is-amber"><x-icon name="star" /></span>
            <div>
                <div class="stat-card-val">{{ $avgRatings['Overall Satisfaction'] !== null ? number_format($avgRatings['Overall Satisfaction'], 2) : '—' }}<span class="stat-card-val__suffix">/5</span></div>
                <div class="stat-card-label">Avg. Overall Satisfaction</div>
            </div>
        </div>
        <div class="xs-kpi">
            <span class="xs-ico"><x-icon name="heart" /></span>
            <div>
                <div class="stat-card-val">{{ $wouldRecommendPct !== null ? $wouldRecommendPct.'%' : '—' }}</div>
                <div class="stat-card-label">Would Recommend Davao Region</div>
            </div>
        </div>
        <div class="xs-kpi">
            <span class="xs-ico is-blue"><x-icon name="clock" /></span>
            <div>
                <div class="stat-card-val">{{ $avgDaysStayed !== null ? $avgDaysStayed : '—' }}</div>
                <div class="stat-card-label">Avg. Days Stayed</div>
            </div>
        </div>
        <div class="xs-kpi" title="Self-reported. Only counts responses that answered this optional question.">
            <span class="xs-ico is-amber"><x-icon name="wallet" /></span>
            <div>
                <div class="stat-card-val">{{ $avgTotalSpend !== null ? '₱'.number_format($avgTotalSpend, 2) : '—' }}</div>
                <div class="stat-card-label">Avg. Spend per Trip</div>
            </div>
        </div>
    </section>
    <p class="xs-foot">
        Survey activity is based on voluntary, anonymous submissions. Distinct check-ins and survey responses are not necessarily one-to-one, so these figures should be interpreted as population-level indicators rather than a per-visitor response rate.
    </p>

    @php
        $insightPairs = array_filter([
            'Highest-rated category' => $insights['highest_rated_category'],
            'Lowest-rated category' => $insights['lowest_rated_category'],
            'Most common visitor origin' => $insights['most_common_origin'],
            'Most common purpose of travel' => $insights['most_common_purpose'],
            'Most visited place' => $insights['most_visited_place'],
            'Most popular activity' => $insights['most_popular_activity'],
        ]);
    @endphp
    @if (! empty($insightPairs))
        <section class="panel xs-card" aria-labelledby="xs-insights">
            <div class="panel-head">
                <div>
                    <h2 id="xs-insights">Key Insights</h2>
                    <p>Calculated automatically from the responses currently shown.</p>
                </div>
            </div>
            <dl class="xs-pairs">
                @foreach ($insightPairs as $label => $value)
                    <div class="xs-pair"><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                @endforeach
            </dl>
        </section>
    @endif

    <div class="xs-two">
        <section class="panel xs-card" aria-labelledby="xs-ratings">
            <div class="panel-head">
                <div>
                    <h2 id="xs-ratings">Average Ratings by Category</h2>
                    <p>Out of 5 stars, across all responses that rated each category.</p>
                </div>
            </div>
            <div class="panel-body">
                {{-- Bars are a data visualisation, so a fill colour is correct here -- this is not a status badge.
                     Bands: leaf green >= 4.0, amber 3.5-3.99, stamp red < 3.5. --}}
                <div class="xs-bars">
                    @foreach ($ratedRows as $label => $value)
                        @php $band = $value >= 4 ? 'good' : ($value >= 3.5 ? 'mid' : 'low'); @endphp
                        <div class="xs-bar">
                            <div class="xs-bar__top">
                                <span class="xs-bar__name">
                                    {{ $label }}
                                    @if ($label === $insights['highest_rated_category'])
                                        <span class="xs-pill is-good">Highest</span>
                                    @elseif ($label === $insights['lowest_rated_category'] && $insights['highest_rated_category'] !== $insights['lowest_rated_category'])
                                        <span class="xs-pill is-low">Lowest</span>
                                    @endif
                                </span>
                                <span class="xs-bar__val">{{ number_format($value, 2) }}</span>
                            </div>
                            <div class="xs-track" role="img" aria-label="{{ $label }}: {{ number_format($value, 2) }} out of 5"><div class="xs-fill is-{{ $band }}" style="width:{{ $value / 5 * 100 }}%"></div></div>
                        </div>
                    @endforeach
                    @foreach ($unratedRows as $label => $value)
                        <div class="xs-bar is-empty">
                            <div class="xs-bar__top"><span class="xs-bar__name">{{ $label }}</span><span class="xs-bar__val">No data yet</span></div>
                            <div class="xs-track"></div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="panel xs-card" aria-labelledby="xs-activities">
            <div class="panel-head">
                <div>
                    <h2 id="xs-activities">Popular Activities</h2>
                    <p>What visitors say they did during their trip.</p>
                </div>
            </div>
            <div class="panel-body xs-center">
                @if ($topActivities->isEmpty())
                    <p class="xs-none">No activities reported yet.</p>
                @else
                    <div class="xs-donut-wrap">
                        <div class="xs-donut" style="{{ $donutStyle }}" role="img" aria-label="{{ collect($slices)->map(fn ($sl) => $sl['label'].' '.($sl['pct'] ?? round($activityTotal ? $sl['total'] / $activityTotal * 100 : 0)).' percent')->implode(', ') }}">
                            <b>{{ $activityTotal }}<small>{{ $activityTotal === 1 ? 'mention' : 'mentions' }}</small></b>
                        </div>
                        <ul class="xs-legend">
                            @foreach ($slices as $sl)
                                <li>
                                    <i class="xs-sw" style="background:{{ $sl['color'] }}"></i>
                                    <span>{{ $sl['label'] }}</span>
                                    <span class="xs-legend__p">{{ $sl['total'] }} mention{{ $sl['total'] === 1 ? '' : 's' }}{{ $sl['pct'] !== null ? ' ('.$sl['pct'].'%)' : '' }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </section>
    </div>

    @php
        // A one-line summary of a breakdown: its top answer, how many responded, and the other answers as small bars.
        $topOf = fn ($c) => $c->isEmpty() ? null : $c->sortDesc()->keys()->first();
    @endphp
    <div class="xs-two xs-two--even">
        <section class="panel xs-card" aria-labelledby="xs-profile">
            <div class="panel-head">
                <div>
                    <h2 id="xs-profile">Visitor Profile</h2>
                    <p>Self-reported by respondents (optional fields).</p>
                </div>
            </div>
            <ul class="xs-rows">
                <li>
                    <span class="xs-ico"><x-icon name="map-pin" /></span>
                    <div class="xs-rows__t">
                        <h3>Visitor Origin</h3>
                        @if ($originBreakdown->isEmpty())
                            <strong class="is-none">No origin data available yet.</strong>
                        @else
                            <strong>{{ $originBreakdown->first()['label'] }}</strong>
                            <em>
                                {{ $originTotal }} of {{ $count }} response{{ $count === 1 ? '' : 's' }} ({{ $fmtPct($originTotal, $count) }}%) reported a place of origin.
                                @if ($otherOriginsCount > 0)
                                    {{ $otherOriginsCount }} more response{{ $otherOriginsCount === 1 ? '' : 's' }} reported {{ $otherOriginsCount === 1 ? 'a location' : 'locations' }} outside the top {{ $originBreakdown->count() }} shown below.
                                @endif
                            </em>
                            @if ($originBreakdown->count() > 1)
                                @php $maxOrigin = $originBreakdown->max('total'); @endphp
                                <div class="xs-mini-list">
                                    @foreach ($originBreakdown as $row)
                                        <div class="xs-mini"><span>{{ $row['label'] }}</span><span class="xs-track"><i class="xs-fill" style="width:{{ $maxOrigin ? $row['total'] / $maxOrigin * 100 : 0 }}%"></i></span><span>{{ $row['total'] }} ({{ $fmtPct($row['total'], $originTotal) }}%)</span></div>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                    </div>
                </li>
                <li>
                    <span class="xs-ico is-blue"><x-icon name="building" /></span>
                    <div class="xs-rows__t">
                        <h3>Visitor Residency</h3>
                        @if ($residencyBreakdown->isEmpty())
                            <strong class="is-none">No responses provided this yet.</strong>
                        @else
                            <strong>{{ $topOf($residencyBreakdown) }}</strong>
                            @if ($residencyBreakdown->count() > 1)
                                @php $maxResidency = $residencyBreakdown->max(); @endphp
                                <div class="xs-mini-list">
                                    @foreach ($residencyBreakdown as $label => $total)
                                        <div class="xs-mini"><span>{{ $label }}</span><span class="xs-track"><i class="xs-fill" style="width:{{ $maxResidency ? $total / $maxResidency * 100 : 0 }}%"></i></span><span>{{ $total }}</span></div>
                                    @endforeach
                                </div>
                            @else
                                <em>{{ $residencyBreakdown->sum() }} of {{ $count }} response{{ $count === 1 ? '' : 's' }}.</em>
                            @endif
                        @endif
                    </div>
                </li>
                <li>
                    <span class="xs-ico is-amber"><x-icon name="user" /></span>
                    <div class="xs-rows__t">
                        <h3>Visit Type</h3>
                        <em class="xs-sub">First-time vs. returning vs. regular/local visitors.</em>
                        @if ($visitorTypeBreakdown->isEmpty())
                            <strong class="is-none">No responses provided this yet.</strong>
                        @else
                            <strong>{{ $topOf($visitorTypeBreakdown) }}</strong>
                            @if ($visitorTypeBreakdown->count() > 1)
                                @php $maxVisitorType = $visitorTypeBreakdown->max(); @endphp
                                <div class="xs-mini-list">
                                    @foreach ($visitorTypeBreakdown as $label => $total)
                                        <div class="xs-mini"><span>{{ $label }}</span><span class="xs-track"><i class="xs-fill" style="width:{{ $maxVisitorType ? $total / $maxVisitorType * 100 : 0 }}%"></i></span><span>{{ $total }}</span></div>
                                    @endforeach
                                </div>
                            @else
                                <em>{{ $visitorTypeBreakdown->sum() }} of {{ $count }} response{{ $count === 1 ? '' : 's' }}.</em>
                            @endif
                        @endif
                    </div>
                </li>
                <li>
                    <span class="xs-ico"><x-icon name="compass" /></span>
                    <div class="xs-rows__t">
                        <h3>Purpose of Travel</h3>
                        <em class="xs-sub">Why visitors say they came to the Davao Region.</em>
                        @if ($travelPurposeBreakdown->isEmpty())
                            <strong class="is-none">No responses provided this yet.</strong>
                        @else
                            <strong>{{ $topOf($travelPurposeBreakdown) }}</strong>
                            @if ($travelPurposeBreakdown->count() > 1)
                                @php $maxPurpose = $travelPurposeBreakdown->max(); @endphp
                                <div class="xs-mini-list">
                                    @foreach ($travelPurposeBreakdown as $label => $total)
                                        <div class="xs-mini"><span>{{ $label }}</span><span class="xs-track"><i class="xs-fill" style="width:{{ $maxPurpose ? $total / $maxPurpose * 100 : 0 }}%"></i></span><span>{{ $total }}</span></div>
                                    @endforeach
                                </div>
                            @else
                                <em>{{ $travelPurposeBreakdown->sum() }} of {{ $count }} response{{ $count === 1 ? '' : 's' }}.</em>
                            @endif
                        @endif
                    </div>
                </li>
            </ul>
        </section>

        <section class="panel xs-card" aria-labelledby="xs-economics">
            <div class="panel-head">
                <div>
                    <h2 id="xs-economics">Trip Economics</h2>
                    <p>Self-reported total spend for the whole trip (₱), across respondents who answered this optional question.</p>
                </div>
            </div>
            <ul class="xs-rows">
                <li>
                    <span class="xs-ico is-amber"><x-icon name="wallet" /></span>
                    <div class="xs-rows__t">
                        <h3>Average Spending</h3>
                        @if ($spendRespondentCount === 0)
                            <strong class="is-none">No spending data available yet.</strong>
                        @else
                            <strong>₱{{ number_format($avgTotalSpend, 2) }} per trip</strong>
                            <em>{{ $spendRespondentCount }} of {{ $count }} response{{ $count === 1 ? '' : 's' }} ({{ $fmtPct($spendRespondentCount, $count) }}%) provided spending data.</em>
                        @endif
                    </div>
                </li>
                @if ($spendRespondentCount > 0 && $spendByResidency->isNotEmpty())
                    <li>
                        <span class="xs-ico"><x-icon name="building" /></span>
                        <div class="xs-rows__t">
                            <h3>Average spending by residency</h3>
                            @if ($spendByResidency->count() === 1)
                                <strong>{{ $spendByResidency->keys()->first() }} &middot; ₱{{ number_format($spendByResidency->first(), 2) }}</strong>
                                @if ($spendRespondentCount < 3)<em>Only {{ $spendRespondentCount === 1 ? 'one respondent' : $spendRespondentCount.' respondents' }} so far, so treat this as indicative.</em>@endif
                            @else
                                @php $maxSpend = $spendByResidency->max(); @endphp
                                <div class="xs-mini-list">
                                    @foreach ($spendByResidency as $label => $avgSpend)
                                        <div class="xs-mini"><span>{{ $label }}</span><span class="xs-track"><i class="xs-fill" style="width:{{ $maxSpend ? $avgSpend / $maxSpend * 100 : 0 }}%"></i></span><span>₱{{ number_format($avgSpend, 2) }}</span></div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </li>
                @endif
                <li>
                    <span class="xs-ico is-blue"><x-icon name="clock" /></span>
                    <div class="xs-rows__t">
                        <h3>Avg. Days Stayed</h3>
                        <strong class="{{ $avgDaysStayed === null ? 'is-none' : '' }}">{{ $avgDaysStayed !== null ? $avgDaysStayed.' '.($avgDaysStayed == 1 ? 'day' : 'days') : 'No data yet' }}</strong>
                    </div>
                </li>
            </ul>
        </section>
    </div>

    <section class="panel xs-card" aria-labelledby="xs-places">
        <div class="panel-head">
            <div>
                <h2 id="xs-places">Most Visited Places</h2>
                <p>Ranked by mentions across all exit survey responses.</p>
            </div>
        </div>
        @if ($topPlaces->isEmpty())
            <p class="xs-none" style="padding:0 22px 20px;">No places reported yet.</p>
        @else
            <ol class="xs-rank">
                @foreach ($topPlaces as $place)
                    <li>
                        <span class="xs-pos">{{ $loop->iteration }}</span>
                        <span class="xs-pname">{{ $place['name'] }}</span>
                        <span class="xs-kind {{ $kindClass($place['kind']) }}">{{ ucfirst(str_replace('_', ' ', $place['kind'])) }}</span>
                        <span class="xs-mini-bar" role="img" aria-label="{{ $place['visits'] }} visit{{ $place['visits'] === 1 ? '' : 's' }}"><i style="width:{{ $place['visits'] / $maxPlace * 100 }}%"></i></span>
                        <span class="xs-vc">{{ $place['visits'] }} visit{{ $place['visits'] === 1 ? '' : 's' }}</span>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>
@endif

</div>
@endsection
