<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('pages.auth', ['mode' => 'login']);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'These credentials do not match our records.'])->onlyInput('email');
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

        if ($authenticatedUser?->is_banned) {
            $request->session()->regenerate();

            return redirect()->route('appeals.index')->with('status', 'Your account is suspended. You can submit an appeal below.');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('gallery'));
    }

    public function showRegister(): View
    {
        abort_unless(Cache::get('site_registrations', true), 403, 'Registration is currently closed.');

        return view('pages.auth', ['mode' => 'register']);
    }

    public function register(Request $request): RedirectResponse
    {
        abort_unless(Cache::get('site_registrations', true), 403, 'Registration is currently closed.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'username' => ['required', 'string', 'alpha_dash', 'max:40', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::create($validated);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('gallery');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if (app()->environment(['local', 'testing'])) {
            $request->session()->put('is_guest', true);
        }

        return redirect()->route('gallery');
    }
}
