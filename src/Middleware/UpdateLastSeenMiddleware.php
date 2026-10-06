<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpFoundation\Response;
use Taldres\LastSeen\Events\UserWasActiveEvent;
use Taldres\LastSeen\LastSeenManager;

class UpdateLastSeenMiddleware
{
    public function __construct(private readonly LastSeenManager $lastSeen) {}

    /**
     * Handle an incoming request and update the user's last seen timestamp if applicable.
     *
     * The user is resolved after the request has been handled, so authentication middleware
     * that runs later in the stack (e.g. route-level `auth:sanctum`) has already taken effect.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('last-seen.enabled', true) || $request->attributes->getBoolean('last-seen.handled')) {
            return $response;
        }

        $request->attributes->set('last-seen.handled', true);

        $user = Auth::user();

        if (! $user instanceof Model || ! $user->exists || ! $this->lastSeen->shouldTrack($user)) {
            return $response;
        }

        Event::dispatch(new UserWasActiveEvent($user));

        return $response;
    }
}
