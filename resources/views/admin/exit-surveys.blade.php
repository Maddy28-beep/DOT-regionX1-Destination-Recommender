@extends('layouts.admin')

@section('title', 'Exit Survey Insights — DOT Admin')
@section('page-title', 'Exit Survey Insights')
@section('page-sub', 'Visitor profile, satisfaction ratings, and visitation trends from submitted exit surveys')

@section('content')

@if ($count === 0)
    <div class="panel">
        <div class="empty-panel">
            <div class="icon"><x-icon name="chart" /></div>
            <h3>No exit survey responses yet</h3>
            <p>Once tourists complete the exit survey (2.2.1.7), visitor profile, satisfaction, and visitation charts will appear here automatically.</p>
        </div>
    </div>
@else
    <div class="stat-cards" style="grid-template-columns: repeat(4, 1fr);">
        <div class="stat-card">
            <div class="stat-card-val">{{ $count }}</div>
            <div class="stat-card-label">Total Responses</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-val">{{ $avgRatings['Overall Satisfaction'] ?: '—' }}<span style="font-size:1rem; color:var(--muted);">/5</span></div>
            <div class="stat-card-label">Avg. Overall Satisfaction</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-val">{{ $wouldRecommendPct !== null ? $wouldRecommendPct.'%' : '—' }}</div>
            <div class="stat-card-label">Would Recommend Davao Region</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-val">{{ $avgDaysStayed ?: '—' }}</div>
            <div class="stat-card-label">Avg. Days Stayed</div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <div>
                <h2>Average Ratings by Category</h2>
                <p>Out of 5 stars, across all responses that rated each category.</p>
            </div>
        </div>
        <div class="panel-body">
            <div class="bar-chart">
                @foreach ($avgRatings as $label => $value)
                    <div class="bar-row">
                        <span class="bar-row-label">{{ $label }}</span>
                        <div class="bar-track"><div class="bar-fill" style="width:{{ $value ? ($value / 5 * 100) : 0 }}%"></div></div>
                        <span class="bar-row-value">{{ $value ?: '—' }}</span>
                    </div>
                @endforeach
            </div>
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
                                <span class="rank-name">{{ $activity->activity }}</span>
                                <span class="rank-count">{{ $activity->total }} mention{{ $activity->total === 1 ? '' : 's' }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
@endif

@endsection
