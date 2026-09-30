<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AppealController extends Controller
{
    public function index(): View|RedirectResponse
    {
        $user = Auth::user();
        abort_unless($user, 401);
        $hasAppeals = DB::table('user_appeals')->where('user_id', $user->id)->exists();
        if (! $user->is_banned && ! $hasAppeals) {
            return redirect()->route('gallery');
        }

        $appeals = DB::table('user_appeals')
            ->where('user_id', $user->id)
            ->latest()
            ->limit(10)
            ->get();

        $canAppeal = $user->is_banned;

        return view('pages.appeals', compact('user', 'appeals', 'canAppeal'));
    }

    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();
        abort_unless($user?->is_banned, 403);

        $validated = $request->validate([
            'statement' => ['required', 'string', 'min:20', 'max:3000'],
        ]);

        $hasPendingAppeal = DB::table('user_appeals')
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->exists();
        if ($hasPendingAppeal) {
            return back()->withErrors(['statement' => 'You already have an appeal waiting for review.']);
        }

        $suspensionLogId = DB::table('admin_audit_logs')
            ->where('target_type', 'user')
            ->where('target_id', $user->id)
            ->where('action', 'user.suspended')
            ->latest('created_at')
            ->value('id');

        DB::table('user_appeals')->insert([
            'user_id' => $user->id,
            'admin_audit_log_id' => $suspensionLogId,
            'statement' => trim($validated['statement']),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('status', 'Your appeal was submitted. You can return here to check its status.');
    }
}
