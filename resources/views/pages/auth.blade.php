<x-layouts.app :title="$mode === 'login' ? 'Log In' : 'Create Account'">
    <div class="mx-auto flex min-h-[80vh] w-full max-w-lg items-center px-4 py-10">
        <section class="w-full rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 shadow-xl sm:p-8">
            <h1 class="text-2xl font-black text-[var(--text-main)]">
                {{ $mode === 'login' ? 'Welcome back' : 'Create your account' }}
            </h1>
            <p class="mt-2 text-sm text-[var(--text-muted)]">
                {{ $mode === 'login' ? 'Log in to continue to Booru.art.' : 'Join the community and share your artwork.' }}
            </p>

            <form method="POST" action="{{ $mode === 'login' ? route('login.store') : route('register.store') }}" class="mt-6 space-y-4">
                @csrf

                @if($mode === 'register')
                    <label class="block text-sm font-semibold text-[var(--text-main)]">
                        Name
                        <input name="name" value="{{ old('name') }}" required maxlength="60" autocomplete="name" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2.5 text-sm outline-none focus:border-[var(--accent-primary)]">
                        @error('name') <span class="mt-1 block text-xs text-rose-400">{{ $message }}</span> @enderror
                    </label>
                    <label class="block text-sm font-semibold text-[var(--text-main)]">
                        Username
                        <input name="username" value="{{ old('username') }}" required maxlength="40" autocomplete="username" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2.5 text-sm outline-none focus:border-[var(--accent-primary)]">
                        @error('username') <span class="mt-1 block text-xs text-rose-400">{{ $message }}</span> @enderror
                    </label>
                @endif

                <label class="block text-sm font-semibold text-[var(--text-main)]">
                    Email
                    <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2.5 text-sm outline-none focus:border-[var(--accent-primary)]">
                    @error('email') <span class="mt-1 block text-xs text-rose-400">{{ $message }}</span> @enderror
                </label>

                <label class="block text-sm font-semibold text-[var(--text-main)]">
                    Password
                    <input type="password" name="password" required autocomplete="{{ $mode === 'login' ? 'current-password' : 'new-password' }}" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2.5 text-sm outline-none focus:border-[var(--accent-primary)]">
                    @error('password') <span class="mt-1 block text-xs text-rose-400">{{ $message }}</span> @enderror
                </label>

                @if($mode === 'register')
                    <label class="block text-sm font-semibold text-[var(--text-main)]">
                        Confirm password
                        <input type="password" name="password_confirmation" required autocomplete="new-password" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2.5 text-sm outline-none focus:border-[var(--accent-primary)]">
                    </label>
                @else
                    <label class="flex items-center gap-2 text-sm text-[var(--text-muted)]">
                        <input type="checkbox" name="remember" value="1" class="rounded border-[var(--border-subtle)]">
                        Remember me
                    </label>
                @endif

                <button type="submit" class="w-full rounded-xl accent-bg px-4 py-3 text-sm font-bold text-white shadow">
                    {{ $mode === 'login' ? 'Log in' : 'Create account' }}
                </button>
            </form>

            <p class="mt-5 text-center text-sm text-[var(--text-muted)]">
                @if($mode === 'login')
                    New here? <a href="{{ route('register') }}" class="accent-text font-bold hover:underline">Create an account</a>
                @else
                    Already have an account? <a href="{{ route('login') }}" class="accent-text font-bold hover:underline">Log in</a>
                @endif
            </p>
        </section>
    </div>
</x-layouts.app>
