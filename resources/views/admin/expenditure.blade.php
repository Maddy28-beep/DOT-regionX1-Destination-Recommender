@extends('layouts.admin')
@section('title', 'Visitor Expenditure - DOT Admin')
@section('page-title', 'Visitor expenditure')
@section('page-sub', 'Spending ranges reported by tourists in the exit survey')
@section('page-actions')
    <a class="btn btn-outline" href="{{ route('admin.exit-surveys', request()->query()) }}">Exit survey insights<x-icon name="chevron-right" /></a>
@endsection
@section('content')
<div class="adm-origin">
    <form method="GET" class="adm-toolbar adm-expenditure-filters" aria-label="Filter expenditure responses">
        <label>From date<input type="date" name="from" value="{{ $filters['from'] }}"></label>
        <label>To date<input type="date" name="to" value="{{ $filters['to'] }}"></label>
        @foreach (['residency' => ['Residency', $residencyOptions], 'visitor_type' => ['Visit type', $visitorTypeOptions], 'purpose' => ['Travel purpose', $purposeOptions]] as $key => [$label, $options])
            <label>{{ $label }}<select name="{{ $key }}"><option value="">All</option>@foreach ($options as $option)<option value="{{ $option }}" @selected($filters[$key] === $option)>{{ $option }}</option>@endforeach</select></label>
        @endforeach
        <label class="adm-expenditure-demo"><input type="checkbox" name="include_simulated" value="1" @checked($includeSimulated)>Include simulated responses</label>
        <button class="btn btn-primary" type="submit"><x-icon name="filter" />Apply filters</button>
        @if ($hasActiveFilters || $includeSimulated)<a class="btn btn-outline" href="{{ route('admin.expenditure') }}">Reset</a>@endif
    </form>
    @if ($includeSimulated)
        <x-banner tone="warn">These results include simulated demo responses. They demonstrate the report and must not be presented as observed tourist spending.</x-banner>
    @else
        <p class="adm-origin__note">Showing real tourist responses only. Demo and imported itinerary transactions are excluded.</p>
    @endif
    @include('admin.partials.expenditure-distribution')
</div>
@endsection
