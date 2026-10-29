@extends('layouts.establishment')

@section('title', $title.' — Partner Dashboard')
@section('page-title', $title)
@section('page-sub', $sub ?? '')

@section('content')

@if ($errors->any())
    <div class="alert alert-error">
        @foreach ($errors->all() as $error)
            <div>{{ $error }}</div>
        @endforeach
    </div>
@endif

<div class="panel">
    <div class="panel-body">
        @include('establishment.forms.'.$section)
    </div>
</div>

@endsection
