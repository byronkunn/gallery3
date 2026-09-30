<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class TrackUserActivity
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        if ($user && ! $user->is_banned && ! $request->is('livewire/update')) {
            $activityKey = 'user-last-active-recorded:'.$user->id;
            if (Cache::add($activityKey, true, now()->addMinutes(10))) {
                User::whereKey($user->id)->update(['last_active_at' => now()]);
            }
        }

        return $next($request);
    }
}
