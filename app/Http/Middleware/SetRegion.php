<?php

namespace App\Http\Middleware;

use App\Support\Regions;
use App\Support\Url;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\URL as UrlGenerator;
use Symfony\Component\HttpFoundation\Response;

/**
 * The region in front of every public URL (/en-sg/…).
 *
 * Makes it the current region for this request — what Url::path builds and
 * what the listings filter by — remembers it in a cookie (so "/" sends the
 * visitor back to it), and takes the parameter off the route so no
 * controller has to declare it.
 *
 * A region that is not switched on gets the same page in the default region
 * (a 302: regions can be turned back on), so an old /en-xx/ link never 404s.
 */
class SetRegion
{
    public function handle(Request $request, Closure $next): Response
    {
        $code = strtolower((string) $request->route('region'));
        $region = Regions::find($code);

        if (! $region) {
            $rest = substr($request->path(), strlen($code));
            $query = $request->getQueryString();

            return redirect(Url::slash(rtrim(url('/'), '/').'/'.Regions::default()['code'].$rest).($query ? '?'.$query : ''));
        }

        Regions::setCurrent($region);
        UrlGenerator::defaults(['region' => $region['code']]);
        $request->route()->forgetParameter('region');

        $response = $next($request);

        if ($request->cookie('region') !== $region['code']) {
            Cookie::queue(Cookie::make('region', $region['code'], 60 * 24 * 365, null, null, null, false, false, 'lax'));
        }

        return $response;
    }
}
