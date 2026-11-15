<section class="adm-card adm-expenditure" id="expenditure" aria-labelledby="expenditure-title">
    <header class="adm-card__head">
        <div><h2 id="expenditure-title">Reported expenditure ranges</h2><p>{{ $includeSimulated ? 'Includes simulated demo responses' : 'Real tourist responses only' }} &middot; Current survey filters apply</p></div>
        <a class="btn btn-outline" href="{{ route('admin.expenditure.export', array_filter($filters + ['include_simulated' => $includeSimulated ? 1 : null])) }}"><x-icon name="arrow-up-right" />Export ranges</a>
    </header>
    <div class="adm-expenditure__summary">
        <div><strong>{{ $expenditure['answered'] }}</strong><span>Spending answers</span></div>
        <div><strong>{{ $expenditure['coverage'] }}%</strong><span>Answered this question</span></div>
        <div><strong>{{ $expenditure['unanswered'] }}</strong><span>No spending range supplied</span></div>
    </div>
    @if ($expenditure['answered'] === 0)
        <p class="adm-expenditure__empty">No spending answers in this selection yet. Tourists can provide a range in the exit survey.</p>
    @endif
    <div class="table-scroll">
        <table class="adm-table adm-expenditure__table">
            <caption class="sr-only">Self-reported expenditure ranges; percentages are calculated among those who supplied a recognized spending range.</caption>
            <thead><tr><th scope="col">Spending range</th><th scope="col">Share of spending answers</th><th scope="col">Responses</th><th scope="col">Percent</th></tr></thead>
            <tbody>
                @foreach ($expenditure['ranges'] as $range)
                    <tr><th scope="row">{{ $range['label'] }}</th><td><span class="adm-expenditure__track" aria-hidden="true"><i style="width:{{ $range['percent'] }}%"></i></span></td><td>{{ $range['count'] }}</td><td>{{ $range['percent'] }}%</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <footer class="adm-expenditure__note">Self-reported ranges, not exact spending or tourism revenue. The current question covers trip spending but excludes accommodation paid separately in advance. Percentages use the {{ $expenditure['answered'] }} spending answers, not all {{ $expenditure['total'] }} survey responses.</footer>
</section>
