<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BugReportController extends Controller
{
    public function create(): View
    {
        return view('pages.bug-report');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => Auth::check() ? ['nullable', 'email', 'max:255'] : ['required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'min:5', 'max:150'],
            'description' => ['required', 'string', 'min:20', 'max:5000'],
            'steps_to_reproduce' => ['nullable', 'string', 'max:5000'],
            'page_url' => ['nullable', 'url', 'max:2048'],
        ]);

        DB::table('bug_reports')->insert([
            'user_id' => Auth::id(),
            'email' => Auth::user()?->email ?? $validated['email'],
            'subject' => trim($validated['subject']),
            'description' => trim($validated['description']),
            'steps_to_reproduce' => trim($validated['steps_to_reproduce'] ?? '') ?: null,
            'page_url' => $validated['page_url'] ?? null,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->route('bug-reports.create')->with('status', 'Thanks. Your bug report was sent to the site admins.');
    }
}
