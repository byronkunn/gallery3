<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\SiteSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        abort_unless(SiteSettings::bool('site_registrations'), 403, 'Registration is currently closed.');

        return view('pages.auth', ['mode' => 'register']);
    }

    public function register(Request $request): RedirectResponse
    {
        abort_unless(SiteSettings::bool('site_registrations'), 403, 'Registration is currently closed.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'username' => ['required', 'string', 'alpha_dash', 'max:40', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'invite_code' => [SiteSettings::bool('site_registration_invite_only') ? 'required' : 'nullable', 'string', 'max:64'],
        ]);

        if (SiteSettings::bool('site_registration_invite_only') && ! $this->consumeInviteCode((string) ($validated['invite_code'] ?? ''))) {
            return back()->withErrors(['invite_code' => 'That invite code is not valid or has already been used.'])->onlyInput('name', 'username', 'email');
        }

        $needsApproval = SiteSettings::bool('site_registration_approval');

        unset($validated['invite_code']);
        $validated['approved_at'] = $needsApproval ? null : now();

        $user = User::create($validated);
        Auth::login($user);
        $request->session()->regenerate();

        DB::table('admin_audit_logs')->insert([
            'actor_id' => null,
            'action' => $needsApproval ? 'user.registration_pending' : 'user.registered',
            'target_type' => 'user',
            'target_id' => $user->id,
            'reason' => $needsApproval ? 'Registration awaiting administrator approval.' : 'Self registration.',
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return redirect()->route('gallery')->with('status', $needsApproval
            ? 'Welcome! Your account is awaiting approval — you can browse right away.'
            : 'Welcome to Booru.art!');
    }

    /**
     * Invite codes are single use: a successful redemption removes the code.
     */
    private function consumeInviteCode(string $code): bool
    {
        $code = trim($code);
        if ($code === '') {
            return false;
        }

        $codes = SiteSettings::list('site_registration_invite_codes');
        $index = array_search($code, $codes, true);

        if ($index === false) {
            return false;
        }

        unset($codes[$index]);
        SiteSettings::set('site_registration_invite_codes', array_values($codes));

        return true;
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
