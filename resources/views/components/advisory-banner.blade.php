@props(['advisories'])

@foreach ($advisories as $advisory)
    <div class="alert alert-{{ $advisory->severity }}" style="margin-bottom:12px;">
        <strong>{{ $advisory->title }}</strong>
        <div>{{ $advisory->message }}</div>
    </div>
@endforeach
