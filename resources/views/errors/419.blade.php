@extends('layouts.app')

@section('title', 'Session Expired — ExploreDVO')

@section('content')
<div class="error-shell">
    <div>
        <div class="error-icon"><x-icon name="clock" /></div>
        <div class="error-code">419</div>
        <h1>Your session expired</h1>
        <p>This happens after a period of inactivity, for your security. Please go back and try submitting again.</p>
        <div class="util-row">
            <a href="javascript:history.back()" class="btn btn-primary">Go Back</a>
            <a href="{{ route('home') }}" class="btn btn-outline">Back to Home</a>
        </div>
    </div>
</div>
@endsection
