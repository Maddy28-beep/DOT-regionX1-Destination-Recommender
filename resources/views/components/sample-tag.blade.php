@props([
    'label' => 'Involves sample data',
    'title' => 'At least one place in this rule is sample data: its accreditation number is a placeholder, not an official DOT number.',
])
{{-- Marks a rule, pick, record or operator whose accreditation number is a placeholder, not an official DOT number. --}}
<span class="sample-tag" title="{{ $title }}">{{ $label }}</span>
