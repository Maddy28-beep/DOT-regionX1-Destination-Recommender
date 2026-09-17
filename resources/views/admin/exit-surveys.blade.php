@extends('layouts.admin')

@section('title', 'Exit Survey Insights — DOT Admin')
@section('page-title', 'Exit Survey Insights')
@section('page-sub', 'Visitor profile, satisfaction ratings, and visitation trends from submitted exit surveys')

@section('content')

<div class="panel">
    <div class="panel-body">
        <form method="GET" class="filter-inline">
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
            <button type="submit" class="btn btn-primary">Apply Filter</button>
            @if ($hasActiveFilters)
                <a href="{{ route('admin.exit-surveys') }}" class="btn btn-outline">Clear Filters</a>
            @endif
        </form>
    </div>
</div>

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
    <div class="stat-cards stat-cards--kpi">
        <div class="stat-card">
            <div class="stat-card-val">{{ $count }}</div>
            <div class="stat-card-label">Exit Survey Responses</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-val">{{ $checkedInVisitors }}</div>
            <div class="stat-card-label">Distinct Check-ins</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-val">{{ $avgRatings['Overall Satisfaction'] !== null ? number_format($avgRatings['Overall Satisfaction'], 2) : '—' }}<span class="stat-card-val__suffix">/5</span></div>
            <div class="stat-card-label">Avg. Overall Satisfaction</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-val">{{ $wouldRecommendPct !== null ? $wouldRecommendPct.'%' : '—' }}</div>
            <div class="stat-card-label">Would Recommend Davao Region</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-val">{{ $avgDaysStayed !== null ? $avgDaysStayed : '—' }}</div>
            <div class="stat-card-label">Avg. Days Stayed</div>
        </div>
        <div class="stat-card" title="Self-reported. Only counts responses that answered this optional question.">
            <div class="stat-card-val">{{ $avgTotalSpend !== null ? '₱'.number_format($avgTotalSpend, 2) : '—' }}</div>
            <div class="stat-card-label">Avg. Spend per Trip</div>
        </div>
    </div>
    <p style="color:var(--muted); font-size:.85rem; margin-top:-8px;">
        Survey activity is based on voluntary, anonymous submissions. Distinct check-ins and survey responses are not necessarily one-to-one, so these figures should be interpreted as population-level indicators rather than a per-visitor response rate.
    </p>

    @php
        $insightLines = array_filter([
            $insights['highest_rated_category'] ? "Highest-rated category: <strong>{$insights['highest_rated_category']}</strong>" : null,
            $insights['lowest_rated_category'] ? "Lowest-rated category: <strong>{$insights['lowest_rated_category']}</strong>" : null,
            $insights['most_common_origin'] ? "Most common visitor origin: <strong>".e($insights['most_common_origin'])."</strong>" : null,
            $insights['most_common_purpose'] ? "Most common purpose of travel: <strong>{$insights['most_common_purpose']}</strong>" : null,
            $insights['most_visited_place'] ? "Most visited place: <strong>".e($insights['most_visited_place'])."</strong>" : null,
            $insights['most_popular_activity'] ? "Most popular activity: <strong>{$insights['most_popular_activity']}</strong>" : null,
        ]);
    @endphp
    @if (! empty($insightLines))
        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2>Key Insights</h2>
                    <p>Calculated automatically from the responses currently shown.</p>
                </div>
            </div>
            <div class="panel-body">
                <ul class="rank-list">
                    @foreach ($insightLines as $line)
                        <li style="border-bottom:none; padding:4px 0;">{!! $line !!}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <div class="panel">
        <div class="panel-head">
            <div>
                <h2>Average Ratings by Category</h2>
                <p>Out of 5 stars, across all responses that rated each category.</p>
            </div>
        </div>
        <div class="panel-body">
            {{-- Bars are a data visualisation, so a fill colour is correct here --
                 this is not a status badge. Bands: leaf green >= 4.0,
                 amber 3.5-3.99, stamp red < 3.5. --}}
            <div class="bar-chart">
                @foreach ($avgRatings as $label => $value)
                    @php
                        $band = $value === null ? 'low' : ($value >= 4 ? 'good' : ($value >= 3.5 ? 'mid' : 'low'));
                    @endphp
                    <div class="bar-row">
                        <span class="bar-row-label">
                            {{ $label }}
                            @if ($label === $insights['highest_rated_category'])
                                <span class="badge badge-tone-good">Highest</span>
                            @elseif ($label === $insights['lowest_rated_category'] && $insights['highest_rated_category'] !== $insights['lowest_rated_category'])
                                <span class="badge badge-tone-low">Lowest</span>
                            @endif
                        </span>
                        <div class="bar-track"><div class="bar-fill bar-fill--{{ $band }}" style="width:{{ $value ? ($value / 5 * 100) : 0 }}%"></div></div>
                        <span class="bar-row-value">{{ $value !== null ? number_format($value, 2) : '—' }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <div>
                <h2>Visitor Origin</h2>
                <p>Where respondents say they are visiting from (self-reported, optional field).</p>
            </div>
        </div>
        <div class="panel-body">
            @if ($originBreakdown->isEmpty())
                <p style="color:var(--muted); font-size:.85rem;">No origin data available yet.</p>
            @else
                <div class="bar-chart">
                    @php $maxOrigin = $originBreakdown->max('total'); @endphp
                    @foreach ($originBreakdown as $row)
                        <div class="bar-row">
                            <span class="bar-row-label">{{ $row['label'] }}</span>
                            <div class="bar-track"><div class="bar-fill" style="width:{{ $maxOrigin ? ($row['total'] / $maxOrigin * 100) : 0 }}%"></div></div>
                            <span class="bar-row-value">{{ $row['total'] }} ({{ $originTotal ? round($row['total'] / $originTotal * 100) : 0 }}%)</span>
                        </div>
                    @endforeach
                </div>
                <p style="color:var(--muted); font-size:.82rem; margin:12px 0 0;">
                    {{ $originTotal }} of {{ $count }} response{{ $count === 1 ? '' : 's' }} reported a place of origin.
                    @if ($otherOriginsCount > 0)
                        {{ $otherOriginsCount }} more response{{ $otherOriginsCount === 1 ? '' : 's' }} reported {{ $otherOriginsCount === 1 ? 'a location' : 'locations' }} outside the top {{ $originBreakdown->count() }} shown above.
                    @endif
                </p>
            @endif
        </div>
    </div>

    <div class="two-col-panels">
        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2>Visitor Residency</h2>
                    <p>Self-reported by respondents (optional field).</p>
                </div>
            </div>
            <div class="panel-body">
                @if ($residencyBreakdown->isEmpty())
                    <p style="color:var(--muted); font-size:.85rem;">No responses provided this yet.</p>
                @else
                    <div class="bar-chart">
                        @php $maxResidency = $residencyBreakdown->max(); @endphp
                        @foreach ($residencyBreakdown as $label => $total)
                            <div class="bar-row">
                                <span class="bar-row-label">{{ $label }}</span>
                                <div class="bar-track"><div class="bar-fill" style="width:{{ $maxResidency ? ($total / $maxResidency * 100) : 0 }}%"></div></div>
                                <span class="bar-row-value">{{ $total }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2>Visit Type</h2>
                    <p>First-time vs. returning vs. regular/local visitors.</p>
                </div>
            </div>
            <div class="panel-body">
                @if ($visitorTypeBreakdown->isEmpty())
                    <p style="color:var(--muted); font-size:.85rem;">No responses provided this yet.</p>
                @else
                    <div class="bar-chart">
                        @php $maxVisitorType = $visitorTypeBreakdown->max(); @endphp
                        @foreach ($visitorTypeBreakdown as $label => $total)
                            <div class="bar-row">
                                <span class="bar-row-label">{{ $label }}</span>
                                <div class="bar-track"><div class="bar-fill" style="width:{{ $maxVisitorType ? ($total / $maxVisitorType * 100) : 0 }}%"></div></div>
                                <span class="bar-row-value">{{ $total }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <div>
                <h2>Average Spending</h2>
                <p>Self-reported total spend for the whole trip (₱), across respondents who answered this optional question.</p>
            </div>
        </div>
        <div class="panel-body">
            @if ($spendRespondentCount === 0)
                <p style="color:var(--muted); font-size:.85rem;">No spending data available yet.</p>
            @else
                <p style="color:var(--muted); font-size:.82rem; margin:0 0 14px;">
                    {{ $spendRespondentCount }} of {{ $count }} response{{ $count === 1 ? '' : 's' }} ({{ round($spendRespondentCount / $count * 100) }}%) provided spending data.
                    Overall average: <strong style="color:var(--ink);">₱{{ number_format($avgTotalSpend, 2) }}</strong> per trip.
                </p>
                @if ($spendByResidency->isNotEmpty())
                    <div class="bar-chart">
                        @php $maxSpend = $spendByResidency->max(); @endphp
                        @foreach ($spendByResidency as $label => $avgSpend)
                            <div class="bar-row">
                                <span class="bar-row-label">{{ $label }}</span>
                                <div class="bar-track"><div class="bar-fill" style="width:{{ $maxSpend ? ($avgSpend / $maxSpend * 100) : 0 }}%"></div></div>
                                <span class="bar-row-value">₱{{ number_format($avgSpend, 2) }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <div>
                <h2>Purpose of Travel</h2>
                <p>Why visitors say they came to the Davao Region.</p>
            </div>
        </div>
        <div class="panel-body">
            @if ($travelPurposeBreakdown->isEmpty())
                <p style="color:var(--muted); font-size:.85rem;">No responses provided this yet.</p>
            @else
                <div class="bar-chart">
                    @php $maxPurpose = $travelPurposeBreakdown->max(); @endphp
                    @foreach ($travelPurposeBreakdown as $label => $total)
                        <div class="bar-row">
                            <span class="bar-row-label">{{ $label }}</span>
                            <div class="bar-track"><div class="bar-fill" style="width:{{ $maxPurpose ? ($total / $maxPurpose * 100) : 0 }}%"></div></div>
                            <span class="bar-row-value">{{ $total }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="two-col-panels">
        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2>Most Visited Places</h2>
                    <p>Ranked by mentions across all exit survey responses.</p>
                </div>
            </div>
            <div class="panel-body">
                @if ($topPlaces->isEmpty())
                    <p style="color:var(--muted); font-size:.85rem;">No places reported yet.</p>
                @else
                    <ul class="rank-list">
                        @foreach ($topPlaces as $place)
                            <li>
                                <span class="rank-name">{{ $place['name'] }} <span style="color:var(--muted); font-size:.78rem;">({{ ucfirst(str_replace('_', ' ', $place['kind'])) }})</span></span>
                                <span class="rank-count">{{ $place['visits'] }} visit{{ $place['visits'] === 1 ? '' : 's' }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <div>
                    <h2>Popular Activities</h2>
                    <p>What visitors say they did during their trip.</p>
                </div>
            </div>
            <div class="panel-body">
                @if ($topActivities->isEmpty())
                    <p style="color:var(--muted); font-size:.85rem;">No activities reported yet.</p>
                @else
                    <ul class="rank-list">
                        @foreach ($topActivities as $activity)
                            <li>
                                <span class="rank-name">{{ $activity['activity'] }}</span>
                                <span class="rank-count">{{ $activity['total'] }} mention{{ $activity['total'] === 1 ? '' : 's' }}{{ $activity['pct'] !== null ? ' ('.$activity['pct'].'%)' : '' }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
@endif

@endsection
