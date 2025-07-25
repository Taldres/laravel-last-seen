<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class UpdateLastSeenMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user
            || ! property_exists($user, 'last_seen')
            || ! method_exists($user, 'updateLastSeen')
        ) {
            return $next($request);
        }

        if (!config('last-seen.enabled', true)) {
            return $next($request);
        }

        $threshold = config('last-seen.update_threshold', 60);

        if (! $user->last_seen || $user->last_seen->diffInSeconds(now()) > $threshold) {
            $user->updateLastSeen();
        }

        return $next($request);
    }
}
