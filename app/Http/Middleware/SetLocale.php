<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** The page language the visitor chose with the top bar's EN / FIL switch (kept in the session; English until chosen). */
class SetLocale
{
    public const LOCALES = ['en', 'fil'];

    public function handle(Request $request, Closure $next)
    {
        $chosen = $request->hasSession() ? $request->session()->get('locale') : null;
        app()->setLocale(in_array($chosen, self::LOCALES, true) ? $chosen : 'en');

        return $next($request);
    }
}
