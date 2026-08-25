@extends('layouts.app')

@section('title', 'Health & Accessibility Profile — ExploreDVO')

@section('content')
@php
    $selectedConditions = $healthProfile?->conditions->pluck('condition')->all() ?? [];
    $conditionOptions = [
        'mobility' => 'Mobility / Physical',
        'visual' => 'Visual Impairment',
        'hearing' => 'Hearing Impairment',
        'cardio' => 'Cardiovascular',
        'respiratory' => 'Respiratory',
        'diabetes' => 'Diabetes',
        'allergies' => 'Allergies',
        'pregnancy' => 'Pregnancy',
        'elderly' => 'Elderly / Senior Care',
        'other' => 'Other (specify below)',
    ];
@endphp

<div class="dash-shell">
    <div class="dash-header">
        <div class="container">
            <div>
                <h1>Health &amp; Accessibility Profile</h1>
                <div class="sub">Optional information to help us suggest destinations that suit your needs.</div>
            </div>
            <a href="{{ route('tourist.dashboard') }}" class="btn btn-outline">Back to My Trip</a>
        </div>
    </div>

    <div class="dash-body">
        <div class="container" style="max-width:720px;">
            @if ($errors->any())
                <div class="alert alert-error">
                    <ul style="margin:0; padding-left:18px;">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Data Privacy Notice</h2>
                        <p>This information is <strong>completely optional</strong> and requires your explicit consent. It is used only to adjust the presentation of destination suitability guidance for you (e.g. flagging accessibility considerations), and is <strong>never linked</strong> to the anonymous Exit Survey responses. You can edit or delete this information at any time. Handled per RA 10173 (Data Privacy Act of 2012).</p>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <div>
                        <h2>Your Health &amp; Accessibility Information</h2>
                        <p>Select any that apply to you or your travel companions.</p>
                    </div>
                </div>
                <div class="panel-body">
                    <form method="POST" action="{{ route('tourist.health-profile.update') }}">
                        @csrf
                        @method('PUT')

                        <label class="field-check" style="margin-top:0; font-size:.92rem; color:var(--ink);">
                            <input type="checkbox" name="consent" value="1" @checked(old('consent', $healthProfile?->consent))>
                            <span>I consent to sharing my health/accessibility information with ExploreDVO for the purpose described above.</span>
                        </label>

                        <div class="field" style="margin-top:18px;">
                            <label>Conditions (optional)</label>
                            <div class="checkbox-grid">
                                @foreach ($conditionOptions as $value => $label)
                                    <label class="field-check">
                                        <input type="checkbox" name="conditions[]" value="{{ $value }}" @checked(in_array($value, old('conditions', $selectedConditions)))>
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div class="field" style="margin-top:18px;">
                            <label for="other_text">Additional details (optional)</label>
                            <textarea id="other_text" name="other_text" rows="3" placeholder="Anything else we should know to help suggest suitable destinations?">{{ old('other_text', $healthProfile?->other_text) }}</textarea>
                        </div>

                        <p style="font-size:.8rem; color:var(--muted); margin-top:14px;">Unchecking consent and saving will permanently delete any stored health information.</p>

                        <button type="submit" class="btn btn-primary" style="margin-top:8px;">Save</button>
                    </form>

                    @if ($healthProfile)
                        <form method="POST" action="{{ route('tourist.health-profile.destroy') }}" style="margin-top:14px;" onsubmit="return confirm('Delete all your stored health and accessibility information? This cannot be undone.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline" style="color:var(--danger); border-color:var(--danger);">Delete My Health Information</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
