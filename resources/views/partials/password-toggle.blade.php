@props(['target'])

{{--
    Reveal control for a password input. Extracted because this form carries
    two of them and an inline copy would drift.

    type="button" is load-bearing: a bare <button> inside a form defaults to
    type="submit", so tapping the eye would submit the registration.
--}}
<button type="button" class="password-toggle" data-password-toggle="{{ $target }}"
        aria-controls="{{ $target }}" aria-pressed="false" aria-label="Show password">
    <svg class="icon-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/>
        <circle cx="12" cy="12" r="3"/>
    </svg>
    <svg class="icon-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path d="M9.9 4.24A9.1 9.1 0 0 1 12 4c6.4 0 10 7 10 7a17.6 17.6 0 0 1-2.2 3.2M6.6 6.6A17.7 17.7 0 0 0 2 11s3.6 7 10 7a9 9 0 0 0 4.4-1.1"/>
        <path d="M14.1 14.1a3 3 0 1 1-4.2-4.2"/>
        <line x1="2" y1="2" x2="22" y2="22"/>
    </svg>
</button>
