<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Account appeal</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[var(--bg-page)] p-4 text-[var(--text-main)] sm:p-8">
    <main class="mx-auto max-w-2xl space-y-6 pt-8">
        <header class="rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 sm:p-8">
            <p class="text-xs font-black uppercase tracking-widest {{ $canAppeal ? 'text-rose-400' : 'text-emerald-400' }}">{{ $canAppeal ? 'Account suspended' : 'Appeal history' }}</p>
            <h1 class="mt-2 text-3xl font-black">{{ $canAppeal ? 'Appeal your suspension' : 'Your appeal history' }}</h1>
            <p class="mt-3 text-sm text-[var(--text-muted)]">Signed in as {{ $user->username }}. @if($canAppeal)Explain why you think we should review this decision. An admin will review your appeal.@elseYour account currently has access. Review the outcome below.@endif</p>
            @if($user->suspension_reason)<div class="mt-4 rounded-2xl bg-[var(--bg-page)] p-4 text-sm"><b>Reason given:</b><p class="mt-1 whitespace-pre-line">{{ $user->suspension_reason }}</p>@if($user->suspended_until)<p class="mt-2 text-xs text-[var(--text-dim)]">Suspension ends {{ $user->suspended_until->format('M j, Y g:i A') }}</p>@endif</div>@endif
        </header>

        @if(session('status'))<div role="status" class="rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-sm">{{ session('status') }}</div>@endif

        @php($pendingAppeal = $appeals->firstWhere('status', 'pending'))
        @if($canAppeal && !$pendingAppeal)
            <form method="POST" action="{{ route('appeals.store') }}" class="space-y-3 rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6">
                @csrf
                <label for="statement" class="block text-sm font-bold">Your appeal</label>
                <textarea id="statement" name="statement" minlength="20" maxlength="3000" required rows="7" class="w-full rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-page)] p-4" placeholder="Share relevant context or explain what should be reconsidered.">{{ old('statement') }}</textarea>
                @error('statement')<p class="text-sm text-rose-400">{{ $message }}</p>@enderror
                <button class="rounded-xl accent-bg px-5 py-3 text-sm font-bold text-white">Submit appeal</button>
            </form>
        @elseif($canAppeal)
            <div class="rounded-3xl border border-amber-500/30 bg-[var(--bg-surface)] p-6"><h2 class="font-black">Appeal in review</h2><p class="mt-2 text-sm text-[var(--text-muted)]">You have a pending appeal. The team will review it before you can submit another.</p><p class="mt-3 whitespace-pre-line rounded-2xl bg-[var(--bg-page)] p-4 text-sm">{{ $pendingAppeal->statement }}</p></div>
        @else
            <div class="rounded-3xl border border-emerald-500/30 bg-[var(--bg-surface)] p-6"><h2 class="font-black">You can use your account</h2><p class="mt-2 text-sm text-[var(--text-muted)]">Your suspension expired or was lifted. The decision and any response are recorded below.</p></div>
        @endif

        @if($appeals->isNotEmpty())
            <section class="space-y-3"><h2 class="text-lg font-black">Appeal history</h2>@foreach($appeals as $appeal)<article class="rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-4 text-sm"><p class="font-bold">{{ ucfirst($appeal->status) }}@if($appeal->reviewed_at) · {{ \Illuminate\Support\Carbon::parse($appeal->reviewed_at)->format('M j, Y') }}@endif</p><p class="mt-2 whitespace-pre-line">{{ $appeal->statement }}</p>@if($appeal->response)<p class="mt-2 border-t border-[var(--border-subtle)] pt-2 text-[var(--text-muted)]">{{ $appeal->response }}</p>@endif</article>@endforeach</section>
        @endif
        <form method="POST" action="{{ route('logout') }}">@csrf<button class="text-sm underline text-[var(--text-muted)]">Sign out</button></form>
    </main>
</body>
</html>
