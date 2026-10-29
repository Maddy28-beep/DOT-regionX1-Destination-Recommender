@extends('layouts.admin')

@section('title', ($advisory->exists ? 'Edit' : 'Post').' Advisory — DOT Admin')
@section('page-title', $advisory->exists ? 'Edit Advisory' : 'Post New Advisory')
@section('page-sub', 'Shown to travelers on the listing page, or site-wide for a general notice')

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
        @include('admin.advisories._form')
    </div>
</div>

@endsection
