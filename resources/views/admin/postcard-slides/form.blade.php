@extends('layouts.admin')

@section('title', ($slide->exists ? 'Edit' : 'Add').' slide — DOT Admin')
@section('page-title', $slide->exists ? 'Edit slide' : 'Add slide')
@section('page-sub', 'A card in the landing page\'s What Davao is known for row')

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
        @include('admin.postcard-slides._form')
    </div>
</div>

@endsection
