<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security response headers for every web response. Kept deliberately
 * conservative: it does NOT set a script/style CSP (the app uses inline
 * bootstrap + JSON-LD scripts and Vite assets), only a clickjacking backstop
 * plus MIME-sniffing / referrer / transport hardening — none of which can break
 * the app. HSTS is only emitted over HTTPS.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('X-XSS-Protection', '0'); // defer to CSP/escaping; legacy filter is itself a risk
        // Clickjacking backstop without constraining script/style sources.
        $headers->set('Content-Security-Policy', "frame-ancestors 'self'");

        if ($request->secure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
