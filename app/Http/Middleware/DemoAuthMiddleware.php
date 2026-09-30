<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\SiteSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class DemoAuthMiddleware
{
    /**
     * Paths that stay reachable while the site is in maintenance.
     *
     * @var array<int, string>
     */
    private const MAINTENANCE_EXEMPT_PATHS = ['login', 'register', 'logout', 'admin'];

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

        if (Schema::hasTable('settings') && SiteSettings::maintenanceActive() && ! $this->mayBypassMaintenance($request, $authenticatedUser)) {
            abort(503, SiteSettings::maintenanceMessage());
        }

        return $next($request);
    }

    /**
     * Admins (optionally), the auth pages, and allow-listed accounts/IPs keep
     * working during a maintenance window.
     */
    private function mayBypassMaintenance(Request $request, ?User $user): bool
    {
        if (in_array($request->path(), self::MAINTENANCE_EXEMPT_PATHS, true)
            || str_starts_with($request->path(), 'password/')
            || str_starts_with($request->path(), 'admin')
            || str_starts_with($request->path(), 'livewire/')
            || str_starts_with($request->path(), '_boost/')) {
            return true;
        }

        if ($user?->isAdmin() && SiteSettings::bool('site_maintenance_allow_admins')) {
            return true;
        }

        $allowlist = array_map('mb_strtolower', SiteSettings::list('site_maintenance_allowlist'));

        if ($allowlist === []) {
            return false;
        }

        $candidates = [mb_strtolower((string) $request->ip())];

        if ($user) {
            $candidates[] = mb_strtolower($user->username);
            $candidates[] = mb_strtolower($user->email);
        }

        return array_intersect($candidates, $allowlist) !== [];
    }
}
