@props(['promotions'])

@foreach ($promotions as $promotion)
    <div class="alert alert-success" style="margin-bottom:12px;">
        <strong>{{ $promotion->title }}</strong>
        @if ($promotion->code)
            <span class="promo-code">{{ $promotion->code }}</span>
        @endif
        @if ($promotion->description)
            <div>{{ $promotion->description }}</div>
        @endif
    </div>
@endforeach
