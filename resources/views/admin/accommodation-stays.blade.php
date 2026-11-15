@extends('layouts.admin')
@section('title', 'Accommodation Stays — DOT Admin')
@section('page-title', 'Accommodation stays')
@section('page-sub', 'Places visitors reported staying, including properties outside the directory')
@section('page-actions')
    <a class="btn btn-outline" href="{{ route('admin.accommodation-stays.export', $filters) }}"><x-icon name="arrow-up-right" />Export places</a>
@endsection
@section('content')
<div class="adm-origin">
    @if ($errors->any())<div class="alert alert-error" role="alert">{{ $errors->first() }}</div>@endif
    <form method="GET" class="adm-toolbar adm-expenditure-filters" aria-label="Filter reported stays">
        <label>From date<input type="date" name="from" value="{{ $filters['from'] }}"></label>
        <label>To date<input type="date" name="to" value="{{ $filters['to'] }}"></label>
        <label>Directory status<select name="status">
            <option value="unverified" @selected($filters['status'] === 'unverified')>Manual entries to check</option>
            <option value="directory_match" @selected($filters['status'] === 'directory_match')>Directory matches</option>
            <option value="all" @selected($filters['status'] === 'all')>All reported properties</option>
        </select></label>
        <button class="btn btn-primary" type="submit"><x-icon name="filter" />Apply filters</button>
        <a class="btn btn-outline" href="{{ route('admin.accommodation-stays') }}">Reset</a>
    </form>
    <p class="adm-origin__note">Real survey responses only. Demo, test and imported itinerary transactions are excluded.</p>
    <section class="adm-card adm-expenditure" aria-labelledby="stays-report-title">
        <header class="adm-card__head"><div><h2 id="stays-report-title">Reported places to stay</h2><p>Use manual entries as leads for accreditation verification and outreach.</p></div></header>
        <div class="adm-expenditure__summary">
            <div><strong>{{ $answered }}</strong><span>Surveys with a stay answer</span></div>
            <div><strong>{{ $unverified }}</strong><span>Surveys naming an unverified place</span></div>
            <div><strong>{{ $total - $answered }}</strong><span>No stay answer supplied</span></div>
        </div>
        <div class="table-scroll">
            <table class="adm-table adm-expenditure__table">
                <caption class="sr-only">Reported accommodation names and areas. Entries are not verified findings of non-accreditation.</caption>
                <thead><tr><th scope="col">Accommodation / property</th><th scope="col">City / area</th><th scope="col">Directory status at submission</th><th scope="col">Responses</th><th scope="col">Last reported</th></tr></thead>
                <tbody>
                    @forelse ($places as $place)
                        <tr>
                            <th scope="row">{{ $place->place_name }}</th><td>{{ $place->locality }}</td>
                            <td>{{ $place->directory_status === 'unverified' ? 'Unverified · manual entry' : 'Directory match' }}</td>
                            <td>{{ $place->responses }}</td><td>{{ \Illuminate\Support\Carbon::parse($place->last_reported)->format('M j, Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">No reported properties in this selection yet. New exit surveys can include where the visitor stayed.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $places->links() }}
        <footer class="adm-expenditure__note">
            Manual entries may include accredited properties under another name, spelling variations or incomplete details. Confirm the property and current accreditation before any follow-up. Directory matches also require a current accreditation check. Entries do not automatically create listings or alter accreditation.
            Counts represent survey responses, not unique tourists; one response can report several stays. Only capitalization and whitespace are normalized; different spellings remain separate.
            In this date range: {{ $private }} surveys reported friends/family and {{ $dayTrips }} reported no overnight stay. Names and addresses of private hosts are not requested. Older surveys were not backfilled.
        </footer>
    </section>
</div>
@endsection
