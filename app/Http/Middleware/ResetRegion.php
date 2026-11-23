<?php

namespace App\Http\Middleware;

use App\Support\Cities;
use App\Support\Regions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Start every request with no region and no remembered city list. Both are
 * held statically for the length of a request; in a long-lived process (a
 * test run, Octane) the previous request's would otherwise carry over.
 */
class ResetRegion
{
    public function handle(Request $request, Closure $next): Response
    {
        Regions::setCurrent(null);
        Cities::flush();

        return $next($request);
    }
}
