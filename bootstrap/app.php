<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every browser gets an opaque random token so visits can be counted
        // and saved places remembered without collecting anything personal.
        $middleware->web(append: [
            \App\Http\Middleware\EnsureVisitorToken::class,
        ]);

        /*
         * Most guarded routes in this app are portal routes (DOT Admin /
         * establishment partner), so an unauthenticated hit belongs at the
         * portal login screen by default. The one exception is the optional
         * tourist account area under /account -- a guest bounced from there
         * belongs at the tourist login, not the DOT/partner one.
         *
         * This has to be configured HERE, not from a service provider.
         * withMiddleware() installs the framework default,
         * redirectGuestsTo(fn () => route('login')), inside an
         * afterResolving(HttpKernel) hook -- and this app defines no route
         * named "login". Whether a provider-registered override survives
         * depends on whether the HTTP kernel resolves before or after
         * providers boot: it does in a normal web request, but not under the
         * test kernel, where the default won and a guest hit died with
         * "Route [login] not defined" instead of redirecting. Setting it in
         * this closure runs after the default and always wins.
         */
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('account/*')
            ? route('account.login')
            : route('portal.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
