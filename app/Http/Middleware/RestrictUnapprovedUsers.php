<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps accounts that are awaiting registration approval read-only.
 *
 * The platform only ever writes through POST/PUT/PATCH/DELETE requests (Livewire
 * posts every component action to /livewire/update), so refusing non-GET
 * requests is a complete and cheap gate.
 */
class RestrictUnapprovedUsers
{
    /**
     * @var array<int, string>
     */
    private const ALLOWED_PATHS = ['login', 'logout', 'register', 'appeals', 'notifications'];

    private const MESSAGE = 'Your account is awaiting approval, so posting is disabled for now. You can still browse.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user || $user->isApproved() || $request->isMethod('GET')) {
            return $next($request);
        }

        foreach (self::ALLOWED_PATHS as $path) {
            if ($request->is($path) || $request->is($path.'/*')) {
                return $next($request);
            }
        }

        // Headless JSON/API clients keep the hard 403 contract. Livewire also
        // negotiates JSON, but it follows redirects with a full page load, so
        // bouncing it back lets the layout surface the message as a toast
        // instead of Livewire's raw HTML error modal.
        if ($request->expectsJson() && ! $request->hasHeader('X-Livewire')) {
            abort(403, self::MESSAGE);
        }

        return back()->with('error', self::MESSAGE);
    }
}
