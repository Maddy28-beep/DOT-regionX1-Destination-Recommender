@extends('layouts.admin')

@section('title', ($listing->exists ? 'Edit' : 'Add').' '.$config['singular'].' — DOT Admin')
@section('page-title', ($listing->exists ? 'Edit' : 'Add New').' '.$config['singular'])
@section('page-sub', 'Tourism Information Management')

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
        @include('admin.listings._form')
    </div>
</div>

@endsection
