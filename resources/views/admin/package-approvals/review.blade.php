@extends('layouts.admin')

@section('title', $package->name.' — Package Approvals')
@section('page-title', $package->name)
@section('page-sub', $package->tourOperator?->name)

@section('content')
<div class="panel">
    <div class="panel-body">
        @include('admin.package-approvals._review')
    </div>
</div>
@endsection
