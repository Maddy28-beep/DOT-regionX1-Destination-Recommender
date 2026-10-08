<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-side protections that cost nothing and cannot break a page:
 *
 *  - nosniff: the browser must trust the declared file type;
 *  - SAMEORIGIN / frame-ancestors: other sites cannot put ExploreDVO inside a frame
 *    (clickjacking);
 *  - base-uri, object-src, form-action: a page cannot be tricked into posting a form to,
 *    or loading plug-ins from, somewhere else;
 *  - Referrer-Policy: only the site name, never the full address, goes to other sites;
 *  - Permissions-Policy: only this site may ask for the location (the trip planner does),
 *    and the camera and microphone are not available at all;
 *  - Strict-Transport-Security, only on HTTPS: once the site has a domain and a certificate
 *    the browser is told never to use plain HTTP for it again (sent on HTTP it would do nothing);
 *  - no-store for the portal and account pages, so after signing out the Back button
 *    does not show a cached admin or partner page.
 *
 * A strict script-src (full Content-Security-Policy) is deliberately not set: the pages use
 * inline scripts and a few CDN assets, and a policy that breaks them would be worse than none.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(self), camera=(), microphone=(), payment=()');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'; base-uri 'self'; object-src 'none'; form-action 'self'");

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if ($request->is('portal', 'portal/*', 'account', 'account/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
