{{-- The EN / FIL switch: a small two-part control. The choice is kept in the session (App\Http\Middleware\SetLocale). --}}
<div class="lang-switch" role="group" aria-label="{{ __('Language') }}">
    <a href="{{ route('locale.switch', 'en') }}" lang="en" @if (app()->getLocale() === 'en') aria-current="true" @endif>EN</a>
    <a href="{{ route('locale.switch', 'fil') }}" lang="fil" @if (app()->getLocale() === 'fil') aria-current="true" @endif>FIL</a>
</div>
