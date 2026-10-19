@props(['paginator'])

{{-- The DOT Admin console's page links: the same short window of numbers as the public listings
     (partials/pagination.blade.php), so there is one pagination to look after. --}}
@include('partials.pagination', ['paginator' => $paginator])
