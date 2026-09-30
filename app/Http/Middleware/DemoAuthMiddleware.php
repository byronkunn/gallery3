<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class DemoAuthMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isAuthPage = in_array($request->path(), ['login', 'register'], true);
        if (app()->environment(['local', 'testing']) && ! $isAuthPage && ! Auth::check() && ! $request->session()->get('is_guest')) {
            if (Schema::hasTable('users')) {
                $defaultUser = User::where('username', 'kira_art')->first() ?? User::first();
                if ($defaultUser) {
                    Auth::login($defaultUser);
                }
            }
        }

        $authenticatedUser = Auth::user();
        if ($authenticatedUser?->is_banned
            && $authenticatedUser->suspended_until
            && now()->greaterThanOrEqualTo($authenticatedUser->suspended_until)) {
            $authenticatedUser->update(['is_banned' => false, 'suspended_until' => null]);
            DB::table('admin_audit_logs')->insert([
                'action' => 'user.suspension_expired', 'target_type' => 'user', 'target_id' => $authenticatedUser->id,
                'reason' => 'Temporary suspension expired automatically.', 'created_at' => now(),
            ]);
            $authenticatedUser = $authenticatedUser->fresh();
        }

        if ($authenticatedUser?->is_banned && ! $request->is('appeals*') && ! $request->is('logout')) {
            Auth::logout();
            abort(403, 'This account has been suspended.');
        }

        if (Cache::get('site_maintenance', false)
            && ! Auth::user()?->isAdmin()
            && ! in_array($request->path(), ['login', 'register', 'logout'], true)
            && ! str_starts_with($request->path(), 'password/')) {
            abort(503, 'The gallery is temporarily unavailable for maintenance.');
        }

        return $next($request);
    }
}
