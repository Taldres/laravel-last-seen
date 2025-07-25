<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Taldres\LastSeen\Events\UserSeenEvent;

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
            || ! $user->hasAttribute('last_seen')
            || ! method_exists($user, 'updateLastSeen')
            || ! config('last-seen.enabled', true)
        ) {
            return $next($request);
        }

        event(new UserSeenEvent($user));

        return $next($request);
    }
}
