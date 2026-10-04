<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a temporary public tunnel (Cloudflare Tunnel, ngrok, localtunnel) to
 * the exit survey and nothing else.
 *
 * Running `cloudflared tunnel --url http://localhost:8000` gives the whole
 * local app a public address, including the DOT Admin and partner consoles and
 * a development database. For a short survey pilot only the survey needs to be
 * reachable, so a request that arrives through a tunnel can see the survey
 * (and its stylesheets and scripts) and gets a 404 for everything else.
 *
 * A request counts as tunnelled when its host is a known tunnel domain or it
 * carries Cloudflare's edge headers. Requests made directly to localhost
 * carry neither, so working on the site locally is unaffected. Spoofing the
 * headers can only restrict the person sending them.
 *
 * Debug output is also switched off for tunnelled requests: a stack trace on
 * a public address would show file paths and settings from this machine.
 */
class RestrictTunnelToSurvey
{
    private const TUNNEL_HOST_SUFFIXES = [
        '.trycloudflare.com', '.ngrok-free.app', '.ngrok-free.dev', '.ngrok.app', '.ngrok.io', '.loca.lt',
    ];

    /** Paths a tunnelled visitor may reach. Everything else is a 404. */
    private const ALLOWED = [
        'exit-survey', 'exit-survey/*', 'up',
        'css/*', 'js/*', 'images/*', 'img/*', 'fonts/*', 'vendor/*', 'storage/*',
        'favicon.ico', 'favicon.svg', 'robots.txt',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->viaTunnel($request)) {
            return $next($request);
        }

        config(['app.debug' => false]);

        if ($request->is('/')) {
            // A relative target: this runs before the proxy headers are trusted, so an
            // absolute URL built here would say http even though the visitor is on https.
            return new \Illuminate\Http\RedirectResponse('/exit-survey');
        }

        abort_unless($request->is(...self::ALLOWED), 404);

        // The survey is the only thing a tunnelled visitor may submit.
        abort_if($request->isMethod('POST') && ! $request->is('exit-survey'), 404);

        return $next($request);
    }

    private function viaTunnel(Request $request): bool
    {
        $host = strtolower($request->getHost());

        foreach (self::TUNNEL_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return $request->headers->has('Cf-Ray') && $request->headers->has('Cf-Connecting-Ip');
    }
}
