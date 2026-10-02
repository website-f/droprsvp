<?php

namespace App\Http\Middleware;

use App\Support\Chat\IpGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Refuse chat requests from a banned IP. One cache read; see IpGuard. */
class ChatIpGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        if (IpGuard::isBanned($request->ip())) {
            return $request->expectsJson() || $request->is('chat/poll')
                ? response()->json(['error' => 'banned', 'n' => 600], 403)
                : response('Messaging is not available from your network right now.', 403);
        }

        return $next($request);
    }
}
