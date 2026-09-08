<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every browser an opaque, random token so the site can count a visit
 * and remember saved places without knowing who anyone is.
 *
 * The token is a random UUID and nothing else. It is not derived from an IP,
 * a device fingerprint, or any personal detail, and it is never linked to a
 * name, email, or account -- there are no accounts. Its only jobs are:
 *
 *   - stop the same phone counting the same establishment twice in one day
 *     (the double-counting case DOT raised), and
 *   - keep a visitor's saved list attached to their own browser.
 *
 * It is a cookie rather than session state because the session expires long
 * before the day does, and a same-day rescan after a session timeout would
 * otherwise be counted a second time.
 */
class EnsureVisitorToken
{
    public const COOKIE = 'visitor_token';

    /** A year: long enough for a returning visitor's saved list to survive. */
    private const LIFETIME_MINUTES = 60 * 24 * 365;

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie(self::COOKIE);

        if (! is_string($token) || ! Str::isUuid($token)) {
            $token = (string) Str::uuid();
        }

        // Make it readable within this request too, so controllers see the
        // same value on the very first visit rather than only the next one.
        $request->cookies->set(self::COOKIE, $token);

        $response = $next($request);

        /*
         * setCookie() on the headers rather than the response's own
         * withCookie(): that helper only exists on Laravel's own response
         * classes, and this middleware runs on every web route -- including
         * the ones that hand back a Symfony StreamedResponse. The admin
         * reports CSV export did exactly that and died on
         * "Call to undefined method StreamedResponse::withCookie()", so the
         * download 500'd for every admin who tried it.
         *
         * withCookie() is itself only a wrapper around this call, so the
         * cookie is set and encrypted exactly as it was before.
         */
        $response->headers->setCookie(cookie(
            name: self::COOKIE,
            value: $token,
            minutes: self::LIFETIME_MINUTES,
            httpOnly: true,
        ));

        return $response;
    }

    /** The current browser's token. */
    public static function get(Request $request): string
    {
        return (string) $request->cookie(self::COOKIE);
    }
}
