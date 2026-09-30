<x-layouts.app title="Report a Bug — Booru Art Gallery">
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        <div class="rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 shadow-sm sm:p-8">
            <h1 class="text-2xl font-black text-[var(--text-main)]">Report a bug</h1>
            <p class="mt-2 text-sm text-[var(--text-muted)]">Tell us what went wrong and how to reproduce it. Site admins will review your report.</p>

            @if(session('status'))
                <div role="status" class="mt-6 rounded-2xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-sm text-emerald-400">{{ session('status') }}</div>
            @endif

            <form method="POST" action="{{ route('bug-reports.store') }}" class="mt-7 space-y-5">
                @csrf
                @guest
                    <div>
                        <label for="email" class="mb-2 block text-sm font-bold">Email for follow-up</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="255" autocomplete="email" class="w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] p-3 text-[var(--text-main)]">
                        @error('email') <p class="mt-1 text-sm text-rose-400">{{ $message }}</p> @enderror
                    </div>
                @endguest
                <div>
                    <label for="subject" class="mb-2 block text-sm font-bold">Short summary</label>
                    <input id="subject" name="subject" type="text" value="{{ old('subject') }}" required minlength="5" maxlength="150" class="w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] p-3 text-[var(--text-main)]">
                    @error('subject') <p class="mt-1 text-sm text-rose-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="description" class="mb-2 block text-sm font-bold">What happened?</label>
                    <textarea id="description" name="description" rows="6" required minlength="20" maxlength="5000" class="w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] p-3 text-[var(--text-main)]">{{ old('description') }}</textarea>
                    @error('description') <p class="mt-1 text-sm text-rose-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="steps_to_reproduce" class="mb-2 block text-sm font-bold">Steps to reproduce <span class="font-normal text-[var(--text-muted)]">(optional)</span></label>
                    <textarea id="steps_to_reproduce" name="steps_to_reproduce" rows="4" maxlength="5000" class="w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] p-3 text-[var(--text-main)]">{{ old('steps_to_reproduce') }}</textarea>
                    @error('steps_to_reproduce') <p class="mt-1 text-sm text-rose-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="page_url" class="mb-2 block text-sm font-bold">Page URL <span class="font-normal text-[var(--text-muted)]">(optional)</span></label>
                    <input id="page_url" name="page_url" type="url" value="{{ old('page_url') }}" maxlength="2048" placeholder="https://..." class="w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] p-3 text-[var(--text-main)]">
                    @error('page_url') <p class="mt-1 text-sm text-rose-400">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="rounded-xl accent-bg px-6 py-3 text-sm font-bold text-white">Send bug report</button>
            </form>
        </div>
    </div>
</x-layouts.app>
