@extends('layouts.admin')

@section('title', 'Association Rules — DOT Admin')
@section('page-title', 'Association Rules & Co-Visitation Patterns')
@section('page-sub', 'Apriori-derived relationships between destinations and establishments')

@section('content')

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>Support &amp; Confidence-Ranked Rules</h2>
            <p>
                Mined from historical tourist visitation records collected through exit surveys (Sec. 2.3.4,
                Equations 8&ndash;9). Each completed exit survey is treated as one transaction; a rule
                &ldquo;A &rarr; B&rdquo; means tourists who visited A frequently also visited B.
            </p>
        </div>
    </div>
    <div class="panel-body">
        @if ($rules->isEmpty())
            <div class="empty-panel">
                <div class="icon"><x-icon name="link" /></div>
                <h3>Not enough visitation data yet</h3>
                <p>
                    Association rules need enough exit-survey visitation records to compute meaningful
                    Support/Confidence values. Once more exit surveys with places visited come in, rules will
                    appear here automatically.
                </p>
            </div>
        @else
            <div class="table-scroll">
                <table class="data-table">
                    @php
                        // Clicking the column that is already sorted flips direction.
                        $sortLink = fn (string $key) => route('admin.association-rules', [
                            'sort' => $key,
                            'dir' => ($sort === $key && $dir === 'desc') ? 'asc' : 'desc',
                        ]);
                    @endphp
                    <thead>
                        <tr>
                            <th>Rule</th>
                            @foreach (['co_count' => 'Co-visits', 'support' => 'Support', 'confidence' => 'Confidence'] as $key => $label)
                                <th>
                                    <a href="{{ $sortLink($key) }}" class="sort-link {{ $sort === $key ? 'is-sorted' : '' }}">
                                        {{ $label }}
                                        <svg class="sort-arrow" viewBox="0 0 12 12" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                            @if ($sort === $key && $dir === 'asc')
                                                <path fill="currentColor" d="M6 2.5 10 8H2z"/>
                                            @else
                                                <path fill="currentColor" d="M6 9.5 2 4h8z"/>
                                            @endif
                                        </svg>
                                        <span class="sr-only">
                                            {{ $sort === $key ? ($dir === 'asc' ? '(sorted ascending)' : '(sorted descending)') : '(sort)' }}
                                        </span>
                                    </a>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rules as $rule)
                            <tr>
                                <td>
                                    <strong>{{ $rule['a_listing']->name }}</strong>
                                    <span class="cell-muted">({{ str_replace('_', ' ', $rule['a_kind']) }})</span>
                                    &rarr;
                                    <strong>{{ $rule['b_listing']->name }}</strong>
                                    <span class="cell-muted">({{ str_replace('_', ' ', $rule['b_kind']) }})</span>
                                </td>
                                <td>{{ $rule['co_count'] }}</td>
                                <td>{{ number_format($rule['support'] * 100, 1) }}%</td>
                                <td style="white-space:nowrap;">
                                    {{ number_format($rule['confidence'] * 100, 1) }}%
                                    {{-- Data bar, so a fill colour is correct here. Inline and
                                         5px tall so it sits inside the existing line box. --}}
                                    <span class="inline-bar" role="presentation">
                                        <span style="width:{{ min(100, $rule['confidence'] * 100) }}%"></span>
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2>How This Feeds Recommendations</h2>
        </div>
    </div>
    <div class="panel-body">
        <p class="sub">
            These rules power the &ldquo;Frequently visited together&rdquo; suggestions shown in tourists'
            AI-generated itineraries: when the Content-Based Recommendation module ranks a destination highly,
            the Apriori Algorithm looks up its strongest association rules here to suggest complementary
            accommodations, restaurants, souvenir centers, and packages &mdash; matching the Samal Island &rarr;
            BlueJaz Beach Resort example worked through in Sec. 2.3.4.
        </p>
    </div>
</div>

@endsection
