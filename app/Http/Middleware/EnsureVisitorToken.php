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

        return $response->withCookie(cookie(
            name: self::COOKIE,
            value: $token,
            minutes: self::LIFETIME_MINUTES,
            httpOnly: true,
        ));
    }

    /** The current browser's token. */
    public static function get(Request $request): string
    {
        return (string) $request->cookie(self::COOKIE);
    }
}
