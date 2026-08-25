@extends('layouts.app')

@section('title', $title.' — ExploreDVO')

@section('content')
<div class="error-shell">
    <div>
        <div class="error-icon"><x-icon :name="$icon" /></div>
        <div class="error-code">{{ $code }}</div>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>
        <div class="util-row">
            <a href="{{ route('home') }}" class="btn btn-primary">Back to Home</a>
            <a href="{{ route('destinations.index') }}" class="btn btn-outline">Browse Destinations</a>
        </div>
    </div>
</div>
@endsection
