@extends('layouts.admin')

@section('title', ($event->exists ? 'Edit' : 'Add').' Event — DOT Admin')
@section('page-title', $event->exists ? 'Edit Event' : 'Add New Event')
@section('page-sub', 'Shown on the public events calendar and the happening-soon strip')

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
        @include('admin.events._form')
    </div>
</div>

@endsection
