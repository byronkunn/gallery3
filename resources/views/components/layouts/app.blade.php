@props(['title' => null, 'lounge' => false, 'flush' => false])
@php
    $siteName = (string) \App\Support\SiteSettings::get('site_name');
    $siteTagline = (string) \App\Support\SiteSettings::get('site_tagline');
    $siteDescription = (string) \App\Support\SiteSettings::get('site_description');
    $siteKeywords = (string) \App\Support\SiteSettings::get('site_meta_keywords');
    $siteFavicon = \App\Support\SiteSettings::get('site_favicon_url');
    $siteLogo = \App\Support\SiteSettings::get('site_logo_url');
    $pendingApproval = auth()->check() && ! auth()->user()->isApproved();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      x-data="{
          themeMode: '{{ auth()->user()->theme_mode ?? 'dark' }}',
          themePalette: '{{ auth()->user()->theme_palette ?? 'violet' }}',
          fontSize: '{{ auth()->user()->font_size ?? 'md' }}',
          reducedMotion: {{ (auth()->user()->reduced_motion ?? false) ? 'true' : 'false' }},
          navCollapsed: localStorage.getItem('nav_collapsed') === 'true',
          mobileMenuOpen: false,
          toggleNav() {
              this.navCollapsed = !this.navCollapsed;
              localStorage.setItem('nav_collapsed', this.navCollapsed);
          },
          setTheme(mode, palette) {
              if (mode) this.themeMode = mode;
              if (palette) this.themePalette = palette;
              localStorage.setItem('theme_mode', this.themeMode);
              localStorage.setItem('theme_palette', this.themePalette);
          }
      }"
      :data-theme-mode="themeMode"
      :data-palette="themePalette"
      :data-font-size="fontSize"
      :data-reduced-motion="reducedMotion"
      class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ? $title.' — '.$siteName : $siteName.' — '.$siteTagline }}</title>
    <meta name="description" content="{{ $siteDescription }}">
    @if($siteKeywords !== '')
        <meta name="keywords" content="{{ $siteKeywords }}">
    @endif
    @if($siteFavicon)
        <link rel="icon" href="{{ $siteFavicon }}">
    @endif

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    <style>
        [x-cloak] { display: none !important; }
        body { font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        
        .accent-bg { background-color: var(--accent-primary); }
        .accent-text { color: var(--accent-primary); }
        .accent-border { border-color: var(--accent-primary); }
        .accent-glow { box-shadow: 0 0 20px var(--accent-glow); }
        
        .hover-accent:hover { color: var(--accent-light); }
        .hover-accent-bg:hover { background-color: var(--accent-primary); }
    </style>
</head>
<body class="min-h-screen text-[var(--text-main)] bg-[var(--bg-page)] antialiased transition-colors duration-200"
      @theme-changed.window="themeMode = $event.detail.mode || themeMode; themePalette = $event.detail.palette || themePalette;">

    @php
        $flashError = session('error');
        $flashSuccess = session('success');
    @endphp
    @if($flashError || $flashSuccess)
        <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 6000)" x-show="show" x-transition.opacity role="alert"
             class="fixed left-1/2 top-4 z-[60] flex w-[min(92vw,30rem)] -translate-x-1/2 items-start gap-3 rounded-2xl border px-4 py-3 shadow-lg backdrop-blur-xl {{ $flashError ? 'border-rose-500/40 bg-rose-500/10' : 'border-emerald-500/40 bg-emerald-500/10' }}">
            <span class="mt-0.5 shrink-0" aria-hidden="true">{{ $flashError ? '⚠️' : '✅' }}</span>
            <p class="text-sm font-semibold {{ $flashError ? 'text-rose-200' : 'text-emerald-200' }}">{{ $flashError ?? $flashSuccess }}</p>
            <button type="button" class="{{ $flashError ? 'text-rose-200/70 hover:text-rose-100' : 'text-emerald-200/70 hover:text-emerald-100' }} ml-auto shrink-0" @click="show = false" aria-label="Dismiss">✕</button>
        </div>
    @endif

    <div class="{{ $flush ? 'flex h-dvh flex-col overflow-hidden bg-[var(--bg-page)]' : 'min-h-screen bg-[var(--bg-page)] relative overflow-x-clip' }}">
        @if($lounge)
            <header class="z-40 shrink-0 border-b border-[var(--border-subtle)] bg-[var(--bg-surface)]/95 backdrop-blur-xl {{ $flush ? '' : 'sticky top-0' }}">
                <nav aria-label="Lounge navigation" class="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-3 sm:px-6 lg:px-8">
                    <div class="flex items-center gap-3">
                        <a href="{{ route('gallery') }}" class="group flex items-center gap-2 rounded-xl bg-[var(--bg-surface-elevated)] px-3 py-1.5 text-xs font-bold text-[var(--text-main)] border border-[var(--border-subtle)] transition hover:accent-bg hover:text-white">
                            <svg class="h-4 w-4 transition-transform group-hover:-translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                            <span>Back to Site</span>
                        </a>
                        <a href="{{ route('lounge.explore') }}" class="flex min-w-0 items-center gap-2 font-black tracking-tight">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-xl accent-bg text-base text-white">◉</span>
                            <span class="truncate hidden sm:inline">Booru Lounge</span>
                        </a>
                    </div>
                    <div class="flex shrink-0 items-center gap-1 sm:gap-2">
                        <a href="{{ route('lounge.explore') }}" class="rounded-xl px-2.5 py-2 text-sm font-semibold sm:px-4 {{ request()->routeIs('lounge.explore') ? 'accent-bg text-white' : 'hover:bg-[var(--bg-surface-elevated)]' }}" @if(request()->routeIs('lounge.explore')) aria-current="page" @endif>Explore</a>
                        @auth
                            <a href="{{ route('lounge.dms') }}" class="hidden rounded-xl px-4 py-2 text-sm font-semibold {{ request()->routeIs('lounge.dms*') ? 'accent-bg text-white' : 'text-[var(--text-muted)] hover:bg-[var(--bg-surface-elevated)] hover:text-[var(--text-main)]' }} sm:inline-flex">Direct Messages</a>
                            <a href="{{ route('profile', auth()->user()->username) }}" class="hidden size-9 items-center justify-center overflow-hidden rounded-full border border-[var(--border-medium)] sm:inline-flex" aria-label="Your profile"><img src="{{ auth()->user()->avatar_url }}" alt="" class="size-full object-cover"></a>
                        @else
                            <a href="{{ route('login') }}" class="hidden rounded-xl border border-[var(--border-medium)] px-4 py-2 text-sm font-semibold sm:inline-flex">Log in</a>
                        @endauth
                    </div>
                </nav>
            </header>
        @endif
        @unless($lounge)
        <!-- Desktop Left Navigation (Collapsible, Twitter style) -->
        <aside :class="navCollapsed ? 'nav-aside-collapsed' : 'nav-aside-expanded'"
               class="hidden md:flex flex-col fixed top-0 bottom-0 left-0 z-40 transition-all duration-300 ease-in-out border-r border-[var(--border-subtle)] bg-[var(--bg-surface)] backdrop-blur-xl">
            
            <!-- Brand Logo & Collapse Toggle -->
            <div :class="navCollapsed ? 'flex flex-col items-center justify-center py-4 gap-2.5' : 'flex flex-row items-center justify-between h-20 px-4 py-0 gap-2.5'"
                 class="border-b border-[var(--border-subtle)] transition-all duration-300">
                <a href="{{ route('gallery') }}" class="flex items-center gap-3 overflow-hidden group">
                    <div class="relative flex items-center justify-center w-10 h-10 rounded-2xl accent-bg text-white font-black text-xl tracking-tighter shadow-lg transition-transform duration-300 group-hover:scale-105 shrink-0">
                        @if($siteLogo)
                            <img src="{{ $siteLogo }}" alt="{{ $siteName }}" class="h-full w-full rounded-2xl object-cover">
                        @else
                            <span>{{ mb_substr($siteName, 0, 1) }}</span>
                        @endif
                        <span class="absolute -top-1 -right-1 w-3 h-3 rounded-full bg-emerald-400 ring-2 ring-[var(--bg-surface)]"></span>
                    </div>
                    <span x-show="!navCollapsed" class="hidden md:inline font-extrabold text-xl tracking-tight bg-gradient-to-r from-[var(--text-main)] to-[var(--text-muted)] bg-clip-text text-transparent truncate">
                        {{ $siteName }}
                    </span>
                </a>

                <!-- Collapse Toggle Icon Button (Sits under logo when closed, beside when expanded) -->
                <button @click="toggleNav()" 
                        class="p-2 rounded-xl text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)] transition flex items-center justify-center"
                        :title="navCollapsed ? 'Expand navigation' : 'Collapse navigation'">
                    <svg class="w-5 h-5 transition-transform duration-300" :class="navCollapsed ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path>
                    </svg>
                </button>
            </div>

            <!-- Navigation Links -->
            @php
                $unreadNotifications = auth()->check() ? auth()->user()->notifications()->whereNull('read_at')->count() : 0;
                $unreadMessages = auth()->check() ? \App\Models\Message::whereHas('conversation', function($q) {
                    $q->where('user_one_id', auth()->id())->orWhere('user_two_id', auth()->id());
                })->where('sender_id', '!=', auth()->id())->where('is_read', false)->count() : 0;
            @endphp

            <nav class="flex-1 py-6 px-3 space-y-2 overflow-y-auto" aria-label="Main navigation">
                <a href="{{ route('gallery') }}" class="flex items-center gap-4 rounded-2xl px-3.5 py-3.5 font-semibold transition-all duration-200 group {{ request()->routeIs('gallery') ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:bg-[var(--bg-surface-elevated)] hover:text-[var(--text-main)]' }}" :title="navCollapsed ? 'Gallery' : ''">
                    <svg class="h-6 w-6 shrink-0 transition-transform group-hover:scale-110" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                    <span x-show="!navCollapsed" class="hidden truncate text-base md:inline">Gallery</span>
                </a>
                <a href="{{ route('pools.index') }}" class="flex items-center gap-4 rounded-2xl px-3.5 py-3.5 font-semibold transition-all duration-200 group {{ request()->routeIs('pools.*') ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:bg-[var(--bg-surface-elevated)] hover:text-[var(--text-main)]' }}" :title="navCollapsed ? 'Pools' : ''">
                    <svg class="h-6 w-6 shrink-0 transition-transform group-hover:scale-110" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                    <span x-show="!navCollapsed" class="hidden truncate text-base md:inline">Pools</span>
                </a>
                <a href="{{ route('upload') }}" class="flex items-center gap-4 rounded-2xl px-3.5 py-3.5 font-semibold transition-all duration-200 group {{ request()->routeIs('upload') ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:bg-[var(--bg-surface-elevated)] hover:text-[var(--text-main)]' }}" :title="navCollapsed ? 'Upload' : ''">
                    <svg class="h-6 w-6 shrink-0 transition-transform group-hover:scale-110" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                    <span x-show="!navCollapsed" class="hidden truncate text-base md:inline">Upload</span>
                </a>
                <a href="{{ route('lounge.explore') }}" class="relative flex items-center gap-4 rounded-2xl px-3.5 py-3.5 font-semibold transition-all duration-200 group {{ request()->routeIs('lounge.*') ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:bg-[var(--bg-surface-elevated)] hover:text-[var(--text-main)]' }}" :title="navCollapsed ? 'Lounge' : ''">
                    <span class="relative shrink-0 text-xl leading-6">◉
                        @if($unreadMessages > 0)<span class="absolute -right-2 -top-2 flex h-4 min-w-4 items-center justify-center rounded-full bg-sky-500 px-1 text-[9px] font-bold text-white">{{ $unreadMessages }}</span>@endif
                    </span>
                    <span x-show="!navCollapsed" class="hidden truncate text-base md:inline">Lounge</span>
                </a>

            </nav>

            <!-- Bottom Account & Switcher Card (Twitter style) -->
            <div class="p-3 border-t border-[var(--border-subtle)]" x-data="{ userMenuOpen: false }">
                @if(auth()->check())
                    <div class="relative">
                        <button @click="userMenuOpen = !userMenuOpen" 
                                class="flex items-center gap-3 w-full p-2 rounded-2xl hover:bg-[var(--bg-surface-elevated)] transition text-left">
                            <img src="{{ auth()->user()->avatar_url ?? 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=150&q=80' }}"
                                 alt="{{ auth()->user()->name }}"
                                 class="w-10 h-10 rounded-full object-cover ring-2 ring-[var(--border-medium)] shrink-0">
                            
                            <div x-show="!navCollapsed" class="hidden md:block flex-1 min-w-0">
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    <span class="font-bold text-sm truncate">{{ auth()->user()->name }}</span>
                                    @if(auth()->user()->is_artist)
                                        <svg class="w-3.5 h-3.5 accent-text shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                        </svg>
                                    @endif
                                    @if(auth()->user()->is_admin)
                                        <span class="px-1.5 py-0.5 rounded text-[9px] font-black bg-amber-500/20 text-amber-400 border border-amber-500/30 uppercase tracking-wider shrink-0">Admin</span>
                                    @endif
                                </div>
                                <div class="text-xs text-[var(--text-dim)] truncate">{{ '@' . auth()->user()->username }}</div>
                            </div>

                            <svg x-show="!navCollapsed" class="hidden md:block w-4 h-4 text-[var(--text-dim)] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"></path>
                            </svg>
                        </button>

                        <!-- Account Popover Menu -->
                        <div x-show="userMenuOpen" 
                             @click.outside="userMenuOpen = false"
                             x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0 translate-y-2 scale-95"
                             x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                             class="absolute bottom-full left-0 mb-3 w-64 p-3 rounded-2xl glass-panel shadow-2xl border border-[var(--border-medium)] z-50">
                            
                            <div class="space-y-1 border-b border-[var(--border-subtle)] pb-2">
                                <a href="{{ route('profile', auth()->user()->username) }}" @click="userMenuOpen = false" class="flex items-center gap-3 rounded-xl px-2.5 py-2 text-sm font-semibold text-[var(--text-main)] transition hover:bg-[var(--bg-surface-elevated)]">👤 <span>View Profile</span></a>
                                <a href="{{ route('notifications') }}" @click="userMenuOpen = false" class="flex items-center justify-between rounded-xl px-2.5 py-2 text-sm font-semibold text-[var(--text-main)] transition hover:bg-[var(--bg-surface-elevated)]"><span>🔔 Notifications</span>@if($unreadNotifications > 0)<span class="rounded-full bg-rose-500 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ $unreadNotifications }}</span>@endif</a>
                                <a href="{{ route('lounge.dms') }}" @click="userMenuOpen = false" class="flex items-center justify-between rounded-xl px-2.5 py-2 text-sm font-semibold text-[var(--text-main)] transition hover:bg-[var(--bg-surface-elevated)]"><span>💬 Direct Messages</span>@if($unreadMessages > 0)<span class="rounded-full bg-sky-500 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ $unreadMessages }}</span>@endif</a>
                                <a href="{{ route('tags.index') }}" @click="userMenuOpen = false" class="flex items-center gap-3 rounded-xl px-2.5 py-2 text-sm font-semibold text-[var(--text-main)] transition hover:bg-[var(--bg-surface-elevated)]">🏷️ <span>Tags &amp; Wiki</span></a>
                                <a href="{{ route('settings') }}" @click="userMenuOpen = false" class="flex items-center gap-3 rounded-xl px-2.5 py-2 text-sm font-semibold text-[var(--text-main)] transition hover:bg-[var(--bg-surface-elevated)]">⚙️ <span>Settings</span></a>
                                <a href="{{ route('bug-reports.create') }}" @click="userMenuOpen = false" class="flex items-center gap-3 rounded-xl px-2.5 py-2 text-sm font-semibold text-[var(--text-main)] transition hover:bg-[var(--bg-surface-elevated)]">🐛 <span>Report a bug</span></a>
                                @if(auth()->user()->isAdmin())
                                    <a href="{{ route('moderation') }}" @click="userMenuOpen = false" class="flex items-center gap-3 rounded-xl px-2.5 py-2 text-sm font-bold text-amber-400 transition hover:bg-amber-500/10">🛡️ <span>Moderation</span></a>
                                    <a href="{{ route('admin') }}" @click="userMenuOpen = false" class="flex items-center gap-3 rounded-xl px-2.5 py-2 text-sm font-bold text-amber-400 transition hover:bg-amber-500/10">⚙️ <span>Admin Panel</span></a>
                                @endif
                            </div>

                            @if(app()->environment(['local', 'testing']))
                            <div class="text-xs font-semibold text-[var(--text-dim)] px-2 mb-2 uppercase tracking-wider">Switch Demo User</div>
                            <div class="space-y-1 mb-3">
                                @foreach(\App\Models\User::all() as $u)
                                    <form method="POST" action="{{ route('switch-user', $u->id) }}">
                                        @csrf
                                        <button type="submit" class="w-full text-left flex items-center gap-2.5 px-2.5 py-2 rounded-xl text-sm transition {{ auth()->id() === $u->id ? 'accent-bg text-white font-bold' : 'hover:bg-[var(--bg-surface-elevated)] text-[var(--text-main)]' }}">
                                        <img src="{{ $u->avatar_url }}" class="w-6 h-6 rounded-full object-cover">
                                        <div class="flex items-center gap-1.5 min-w-0 flex-1">
                                            <span class="truncate">{{ $u->name }}</span>
                                            @if($u->is_admin)
                                                <span class="px-1 py-0.5 rounded text-[8px] font-black bg-amber-500/20 text-amber-400 border border-amber-500/30 uppercase tracking-wider shrink-0">Admin</span>
                                            @endif
                                        </div>
                                        <span class="text-xs opacity-70">{{ '@' . $u->username }}</span>
                                        </button>
                                    </form>
                                @endforeach
                            </div>

                            <div class="pt-2 border-t border-[var(--border-subtle)] space-y-1">
                                <form method="POST" action="{{ route('switch-user', 'guest') }}">
                                    @csrf
                                    <button type="submit" class="flex items-center gap-2 px-2.5 py-2 rounded-xl text-sm text-amber-400 hover:bg-amber-400/10 transition">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                                    </svg>
                                    <span>Browse as Guest (Log Out)</span>
                                    </button>
                                </form>
                            </div>
                            @endif

                            <div class="pt-2 border-t border-[var(--border-subtle)]">
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="w-full text-left flex items-center gap-2 px-2.5 py-2 rounded-xl text-sm text-[var(--text-muted)] hover:bg-[var(--bg-surface-elevated)] transition">Log out</button>
                                </form>
                            </div>
                        </div>
                    </div>
                @else
                    <!-- Guest Card -->
                    <div class="p-2 text-center">
                        <div x-show="!navCollapsed" class="hidden md:block text-xs text-[var(--text-dim)] mb-2">You are viewing as Guest</div>
                        <a href="{{ route('login') }}" class="inline-flex items-center justify-center gap-2 w-full py-2 px-3 rounded-xl accent-bg text-white font-bold text-sm shadow">
                            <span x-show="!navCollapsed" class="hidden md:inline">Log In</span>
                            <span :class="navCollapsed ? 'inline' : 'inline md:hidden'">Log In</span>
                        </a>
                    </div>
                @endif
            </div>
        </aside>
        @endunless

        <!-- Main Content Area -->
        <main @unless($lounge) :class="navCollapsed ? 'nav-main-collapsed' : 'nav-main-expanded'" @endunless
              class="transition-all duration-300 w-full min-w-0 flex flex-col {{ $flush ? 'min-h-0 flex-1 overflow-hidden' : 'min-h-screen flex-1 overflow-x-clip '.($lounge ? 'pb-8' : 'pb-20 md:pb-8') }}">
            @unless($lounge)
                <x-announcement-banner />

                @if($pendingApproval)
                    <div role="status" class="mx-4 md:mx-6 mt-4 flex items-start gap-3 rounded-2xl border border-amber-500/40 bg-amber-500/10 px-4 py-3">
                        <span class="mt-0.5 shrink-0" aria-hidden="true">⏳</span>
                        <p class="text-sm font-semibold text-amber-200">
                            Your account is awaiting approval. You can browse the gallery, but posting, uploading and messaging stay disabled until an administrator approves you.
                        </p>
                    </div>
                @endif
            @endunless
            {{ $slot }}

            @unless($lounge)
                <footer class="mt-8 border-t border-[var(--border-subtle)] px-4 md:px-6 py-5 text-xs text-[var(--text-dim)] flex flex-wrap items-center gap-x-4 gap-y-2">
                    <span>&copy; {{ now()->year }} {{ $siteName }}</span>
                    @php($tosUrl = \App\Support\SiteSettings::get('site_tos_url'))
                    @php($privacyUrl = \App\Support\SiteSettings::get('site_privacy_url'))
                    @php($contactEmail = \App\Support\SiteSettings::get('site_contact_email'))
                    @if($tosUrl)
                        <a href="{{ $tosUrl }}" class="hover:text-[var(--text-main)]">Terms of service</a>
                    @endif
                    @if($privacyUrl)
                        <a href="{{ $privacyUrl }}" class="hover:text-[var(--text-main)]">Privacy policy</a>
                    @endif
                    @if($contactEmail)
                        <a href="mailto:{{ $contactEmail }}" class="hover:text-[var(--text-main)]">Contact</a>
                    @endif
                </footer>
            @endunless
        </main>
    </div>

    @unless($lounge)
    <!-- Mobile Bottom Navigation (5 items: Home, Pools, Upload, Lounge, Profile Modal) -->
    <nav class="md:hidden fixed bottom-0 left-0 right-0 z-40 h-16 border-t border-[var(--border-subtle)] bg-[var(--bg-surface)]/95 backdrop-blur-xl flex items-center justify-around px-1">
        <!-- 1. Home -->
        <a href="{{ route('gallery') }}" class="flex-1 flex flex-col items-center justify-center p-1.5 {{ request()->routeIs('gallery') ? 'accent-text font-bold' : 'text-[var(--text-muted)]' }}">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
            </svg>
            <span class="text-[10px] mt-0.5">Home</span>
        </a>

        <!-- 2. Pools -->
        <a href="{{ route('pools.index') }}" class="flex-1 flex flex-col items-center justify-center p-1.5 {{ request()->routeIs('pools.*') ? 'accent-text font-bold' : 'text-[var(--text-muted)]' }}">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
            </svg>
            <span class="text-[10px] mt-0.5">Pools</span>
        </a>

        <!-- 3. Upload (Center Action Badge) -->
        <a href="{{ route('upload') }}" class="flex-1 flex flex-col items-center justify-center p-1.5 {{ request()->routeIs('upload') ? 'accent-text font-bold' : 'text-[var(--text-muted)]' }}">
            <div class="w-10 h-10 -mt-4 rounded-full accent-bg text-white shadow-lg flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path>
                </svg>
            </div>
            <span class="text-[10px] mt-0.5">Upload</span>
        </a>

        <!-- 4. Lounge -->
        <a href="{{ route('lounge.explore') }}" class="flex-1 flex flex-col items-center justify-center p-1.5 {{ request()->routeIs('lounge.*') ? 'accent-text font-bold' : 'text-[var(--text-muted)]' }}">
            <span class="text-xl leading-5">◉</span>
            <span class="text-[10px] mt-0.5">Lounge</span>
        </a>

        <!-- 5. Profile Button (Triggers Profile Slide-Up Modal with all secondary options) -->
        <button @click="mobileMenuOpen = true" aria-label="Open profile menu{{ auth()->check() && ($unreadMessages > 0 || $unreadNotifications > 0) ? ', ' . $unreadMessages . ' unread messages, ' . $unreadNotifications . ' unread notifications' : '' }}" class="relative flex-1 flex flex-col items-center justify-center p-1.5 text-[var(--text-muted)]">
            @if(auth()->check())
                <span class="relative inline-flex">
                    <img src="{{ auth()->user()->avatar_url }}" class="w-6 h-6 rounded-full object-cover ring-2 ring-[var(--accent-primary)]">
                    @if($unreadMessages > 0 || $unreadNotifications > 0)
                        @php($unreadActivity = $unreadMessages + $unreadNotifications)
                        <span aria-hidden="true" class="absolute -right-1 -top-1 min-w-4 h-4 rounded-full bg-rose-500 px-1 text-[9px] leading-4 text-center font-bold text-white ring-2 ring-[var(--bg-surface)]">{{ $unreadActivity > 99 ? '99+' : $unreadActivity }}</span>
                    @endif
                </span>
            @else
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
            @endif
            <span class="text-[10px] mt-0.5">Profile</span>
        </button>
    </nav>
    @endunless

    @unless($lounge)
    <!-- Mobile Slide-Up Modal (Holds everything else: Profile Page, Notifications, Admin Panel, Settings, Theme, Account Switcher) -->
    <div x-show="mobileMenuOpen" 
         x-cloak 
         class="fixed inset-0 z-50 md:hidden flex flex-col justify-end bg-black/60 backdrop-blur-sm"
         @click.self="mobileMenuOpen = false">
        
        <div x-show="mobileMenuOpen"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="translate-y-full"
             x-transition:enter-end="translate-y-0"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="translate-y-0"
             x-transition:leave-end="translate-y-full"
             class="bg-[var(--bg-surface)] border-t border-[var(--border-medium)] rounded-t-3xl p-6 shadow-2xl space-y-4 max-h-[85vh] overflow-y-auto">
            
            <div class="flex items-center justify-between pb-3 border-b border-[var(--border-subtle)]">
                @if(auth()->check())
                    <a href="{{ route('profile', auth()->user()->username) }}" @click="mobileMenuOpen = false" class="flex items-center gap-3">
                        <img src="{{ auth()->user()->avatar_url }}" class="w-12 h-12 rounded-full object-cover ring-2 ring-[var(--border-medium)]">
                        <div>
                            <div class="font-bold text-base flex items-center gap-2">
                                <span>{{ auth()->user()->name }}</span>
                                @if(auth()->user()->isAdmin())
                                    <span class="px-1.5 py-0.5 rounded text-[9px] font-black bg-amber-500/20 text-amber-400 border border-amber-500/30">ADMIN</span>
                                @endif
                            </div>
                            <div class="text-xs text-[var(--text-dim)]">{{ '@' . auth()->user()->username }}</div>
                        </div>
                    </a>
                @else
                    <span class="font-bold text-base">Guest Visitor</span>
                @endif

                <button @click="mobileMenuOpen = false" class="p-2 rounded-full hover:bg-[var(--bg-surface-elevated)]">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <!-- Profile Menu Items Grid (Holds all secondary features) -->
            <div class="grid grid-cols-2 gap-3">
                @if(auth()->check())
                    <a href="{{ route('profile', auth()->user()->username) }}" @click="mobileMenuOpen = false" class="flex items-center gap-3 p-3 rounded-2xl bg-[var(--bg-surface-elevated)] font-semibold text-xs">
                        <svg class="w-5 h-5 accent-text" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                        </svg>
                        <span>View Profile</span>
                    </a>
                @endif

                <a href="{{ route('notifications') }}" @click="mobileMenuOpen = false" class="flex items-center justify-between p-3 rounded-2xl bg-[var(--bg-surface-elevated)] font-semibold text-xs">
                    <div class="flex items-center gap-3">
                        <svg class="w-5 h-5 accent-text" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                        </svg>
                        <span>Notifications</span>
                    </div>
                    @if($unreadNotifications > 0)
                        <span class="px-1.5 py-0.5 rounded-full bg-rose-500 text-white font-extrabold text-[10px]">{{ $unreadNotifications }}</span>
                    @endif
                </a>

                <a href="{{ route('lounge.dms') }}" @click="mobileMenuOpen = false" class="flex items-center justify-between p-3 rounded-2xl bg-[var(--bg-surface-elevated)] font-semibold text-xs">
                    <div class="flex items-center gap-3">
                        <svg class="w-5 h-5 accent-text" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                        </svg>
                        <span>Direct Messages</span>
                    </div>
                    @if($unreadMessages > 0)
                        <span class="px-1.5 py-0.5 rounded-full bg-sky-500 text-white font-extrabold text-[10px]">{{ $unreadMessages }}</span>
                    @endif
                </a>

                <a href="{{ route('tags.index') }}" @click="mobileMenuOpen = false" class="flex items-center gap-3 rounded-2xl bg-[var(--bg-surface-elevated)] p-3 text-xs font-semibold">
                    <span class="accent-text" aria-hidden="true">🏷️</span><span>Tags &amp; Wiki</span>
                </a>

                <a href="{{ route('settings') }}" @click="mobileMenuOpen = false" class="flex items-center gap-3 p-3 rounded-2xl bg-[var(--bg-surface-elevated)] font-semibold text-xs">
                    <svg class="w-5 h-5 accent-text" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                    </svg>
                    <span>Settings</span>
                </a>

                @if(auth()->check() && auth()->user()->isAdmin())
                    <a href="{{ route('admin') }}" @click="mobileMenuOpen = false" class="flex items-center gap-3 p-3 rounded-2xl bg-amber-500/10 border border-amber-500/20 font-bold text-xs text-amber-400">
                        <span>🛡️ Admin Panel</span>
                    </a>
                    <a href="{{ route('moderation') }}" @click="mobileMenuOpen = false" class="flex items-center gap-3 rounded-2xl border border-amber-500/20 bg-amber-500/10 p-3 text-xs font-bold text-amber-400">🛡️ Moderation</a>
                @endif
                <a href="{{ route('bug-reports.create') }}" @click="mobileMenuOpen = false" class="flex items-center gap-3 rounded-2xl bg-[var(--bg-surface-elevated)] p-3 text-xs font-semibold">🐛 Report a bug</a>
            </div>

            <!-- Quick Theme Selector in Mobile Modal -->
            <div class="p-4 rounded-2xl bg-[var(--bg-surface-elevated)] space-y-3">
                <div class="text-xs font-bold uppercase tracking-wider text-[var(--text-dim)]">Appearance</div>
                <div class="flex items-center justify-between gap-2">
                    <button @click="setTheme('dark')" :class="themeMode === 'dark' ? 'accent-bg text-white' : 'bg-[var(--bg-surface)]'" class="flex-1 py-2 text-xs font-bold rounded-xl border border-[var(--border-subtle)]">Dark</button>
                    <button @click="setTheme('oled')" :class="themeMode === 'oled' ? 'accent-bg text-white' : 'bg-[var(--bg-surface)]'" class="flex-1 py-2 text-xs font-bold rounded-xl border border-[var(--border-subtle)]">OLED</button>
                    <button @click="setTheme('light')" :class="themeMode === 'light' ? 'accent-bg text-white' : 'bg-[var(--bg-surface)]'" class="flex-1 py-2 text-xs font-bold rounded-xl border border-[var(--border-subtle)]">Light</button>
                </div>
                <div class="flex items-center justify-between gap-2 pt-2">
                    <button @click="setTheme(null, 'violet')" class="w-7 h-7 rounded-full bg-violet-500 ring-2 ring-white/20"></button>
                    <button @click="setTheme(null, 'cyan')" class="w-7 h-7 rounded-full bg-cyan-500 ring-2 ring-white/20"></button>
                    <button @click="setTheme(null, 'sakura')" class="w-7 h-7 rounded-full bg-pink-500 ring-2 ring-white/20"></button>
                    <button @click="setTheme(null, 'emerald')" class="w-7 h-7 rounded-full bg-emerald-500 ring-2 ring-white/20"></button>
                    <button @click="setTheme(null, 'sunset')" class="w-7 h-7 rounded-full bg-orange-500 ring-2 ring-white/20"></button>
                    <button @click="setTheme(null, 'crimson')" class="w-7 h-7 rounded-full bg-rose-500 ring-2 ring-white/20"></button>
                </div>
            </div>

            @if(app()->environment(['local', 'testing']) && auth()->check())
            <!-- Demo User Switcher -->
            <div class="pt-2 border-t border-[var(--border-subtle)]">
                <div class="text-xs font-bold uppercase tracking-wider text-[var(--text-dim)] mb-2">Switch Demo Account</div>
                <div class="space-y-1">
                    @foreach(\App\Models\User::all() as $u)
                        <form method="POST" action="{{ route('switch-user', $u->id) }}">@csrf
                        <button type="submit" class="w-full text-left flex items-center gap-2 p-2 rounded-xl text-sm {{ auth()->id() === $u->id ? 'accent-bg text-white font-bold' : 'hover:bg-[var(--bg-surface-elevated)]' }}">
                            <img src="{{ $u->avatar_url }}" class="w-6 h-6 rounded-full object-cover">
                            <span>{{ $u->name }}</span>
                        </button></form>
                    @endforeach
                    <form method="POST" action="{{ route('switch-user', 'guest') }}">@csrf
                    <button type="submit" class="w-full text-left flex items-center gap-2 p-2 rounded-xl text-sm text-amber-400 hover:bg-amber-400/10">
                        <span>Browse as Guest (Log Out)</span>
                    </button></form>
                </div>
            </div>
            @endif
            @if(auth()->check())
                <form method="POST" action="{{ route('logout') }}" class="pt-2 border-t border-[var(--border-subtle)]">
                    @csrf
                    <button type="submit" class="w-full text-left flex items-center gap-2 p-2 rounded-xl text-sm text-[var(--text-muted)] hover:bg-[var(--bg-surface-elevated)]">Log out</button>
                </form>
            @endif
        </div>
    </div>

    @endunless
    <!-- GLOBAL LIGHTBOX COMPONENT (Spec: Opens from post media view, profile Media tab, and pools; pagination, slideshow mode 3s/5s/10s/15s) -->
    <div x-data="{
             open: false,
             mediaItems: [],
             currentIndex: 0,
             isPlaying: false,
             slideDuration: 5000,
             slideTimer: null,
             progressWidth: 0,
             postTitle: '',
             authorName: '',
             postId: null,
             init() {
                 window.addEventListener('open-lightbox', (e) => {
                     this.mediaItems = e.detail.items || [];
                     this.currentIndex = e.detail.startIndex || 0;
                     this.postTitle = e.detail.title || '';
                     this.authorName = e.detail.author || '';
                     this.postId = e.detail.postId || null;
                     this.open = true;
                     this.stopSlideshow();
                 });
                 window.addEventListener('keydown', (e) => {
                     if (!this.open) return;
                     if (e.key === 'Escape') this.closeLightbox();
                     if (e.key === 'ArrowRight') this.next();
                     if (e.key === 'ArrowLeft') this.prev();
                     if (e.key === ' ') { e.preventDefault(); this.toggleSlideshow(); }
                 });
             },
             closeLightbox() {
                 this.open = false;
                 this.stopSlideshow();
             },
             next() {
                 if (this.mediaItems.length === 0) return;
                 this.currentIndex = (this.currentIndex + 1) % this.mediaItems.length;
                 if (this.isPlaying) this.restartSlideTimer();
             },
             prev() {
                 if (this.mediaItems.length === 0) return;
                 this.currentIndex = (this.currentIndex - 1 + this.mediaItems.length) % this.mediaItems.length;
                 if (this.isPlaying) this.restartSlideTimer();
             },
             setDuration(ms) {
                 this.slideDuration = ms;
                 if (this.isPlaying) this.restartSlideTimer();
             },
             toggleSlideshow() {
                 if (this.isPlaying) {
                     this.stopSlideshow();
                 } else {
                     this.startSlideshow();
                 }
             },
             startSlideshow() {
                 this.isPlaying = true;
                 this.restartSlideTimer();
             },
             stopSlideshow() {
                 this.isPlaying = false;
                 if (this.slideTimer) clearInterval(this.slideTimer);
                 this.slideTimer = null;
                 this.progressWidth = 0;
             },
             restartSlideTimer() {
                 if (this.slideTimer) clearInterval(this.slideTimer);
                 this.progressWidth = 0;
                 const stepMs = 50;
                 const stepPercent = (stepMs / this.slideDuration) * 100;
                 this.slideTimer = setInterval(() => {
                     this.progressWidth += stepPercent;
                     if (this.progressWidth >= 100) {
                         this.progressWidth = 0;
                         this.currentIndex = (this.currentIndex + 1) % this.mediaItems.length;
                     }
                 }, stepMs);
             }
         }"
         x-show="open"
         x-cloak
         class="fixed inset-0 z-[60] bg-black/95 backdrop-blur-2xl flex flex-col justify-between select-none">
        
        <!-- Lightbox Top Bar -->
        <div class="flex items-center justify-between p-4 z-10 bg-gradient-to-b from-black/80 to-transparent">
            <!-- Left Info -->
            <div class="flex items-center gap-3">
                <span class="px-3 py-1 rounded-full bg-white/10 text-white font-mono text-xs font-semibold backdrop-blur-md">
                    <span x-text="currentIndex + 1"></span> / <span x-text="mediaItems.length"></span>
                </span>
                <div class="hidden sm:block">
                    <h3 class="text-white font-bold text-sm truncate max-w-md" x-text="postTitle"></h3>
                    <p class="text-white/60 text-xs truncate" x-text="authorName ? 'by ' + authorName : ''"></p>
                </div>
            </div>

            <!-- Slideshow Controls -->
            <div class="flex items-center gap-2">
                <!-- Duration Selector -->
                <div class="relative" x-data="{ durationMenuOpen: false }">
                    <button @click="durationMenuOpen = !durationMenuOpen" 
                            class="px-2.5 py-1.5 rounded-xl bg-white/10 hover:bg-white/20 text-white text-xs font-bold flex items-center gap-1 transition">
                        <span x-text="(slideDuration / 1000) + 's'"></span>
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>
                    <div x-show="durationMenuOpen" 
                         @click.outside="durationMenuOpen = false" 
                         class="absolute right-0 mt-2 py-1 w-24 bg-neutral-900 border border-white/20 rounded-xl shadow-2xl z-20">
                        <button @click="setDuration(3000); durationMenuOpen = false" :class="slideDuration === 3000 ? 'text-violet-400 font-bold' : 'text-white/80'" class="w-full text-left px-3 py-1.5 text-xs hover:bg-white/10">3 seconds</button>
                        <button @click="setDuration(5000); durationMenuOpen = false" :class="slideDuration === 5000 ? 'text-violet-400 font-bold' : 'text-white/80'" class="w-full text-left px-3 py-1.5 text-xs hover:bg-white/10">5 seconds</button>
                        <button @click="setDuration(10000); durationMenuOpen = false" :class="slideDuration === 10000 ? 'text-violet-400 font-bold' : 'text-white/80'" class="w-full text-left px-3 py-1.5 text-xs hover:bg-white/10">10 seconds</button>
                        <button @click="setDuration(15000); durationMenuOpen = false" :class="slideDuration === 15000 ? 'text-violet-400 font-bold' : 'text-white/80'" class="w-full text-left px-3 py-1.5 text-xs hover:bg-white/10">15 seconds</button>
                    </div>
                </div>

                <!-- Play/Pause Button -->
                <button @click="toggleSlideshow()" 
                        class="p-2 rounded-xl bg-white/10 hover:bg-white/20 text-white transition"
                        :title="isPlaying ? 'Pause slideshow' : 'Play slideshow'">
                    <template x-if="!isPlaying">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd"></path>
                        </svg>
                    </template>
                    <template x-if="isPlaying">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zM7 8a1 1 0 012 0v4a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v4a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                        </svg>
                    </template>
                </button>

                <!-- Close Button -->
                <button @click="closeLightbox()" class="p-2 rounded-xl bg-white/10 hover:bg-white/20 text-white transition ml-2">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Progress Bar for Slideshow -->
        <div x-show="isPlaying" class="w-full h-1 bg-white/10 overflow-hidden">
            <div class="h-full bg-violet-400 transition-all duration-75" :style="'width: ' + progressWidth + '%'"></div>
        </div>

        <!-- Main Display Media Area -->
        <div class="flex-1 relative flex items-center justify-center p-4 overflow-hidden">
            <!-- Prev Button -->
            <button @click="prev()" 
                    x-show="mediaItems.length > 1"
                    class="absolute left-4 z-20 p-3 rounded-full bg-black/50 hover:bg-black/80 text-white/80 hover:text-white transition backdrop-blur-md">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
            </button>

            <!-- Media Container -->
            <template x-if="mediaItems[currentIndex]">
                <div class="max-w-full max-h-full flex items-center justify-center">
                    <template x-if="mediaItems[currentIndex].type === 'video' || (mediaItems[currentIndex].url && mediaItems[currentIndex].url.endsWith('.mp4'))">
                        <video :src="mediaItems[currentIndex].url" 
                               controls 
                               autoplay 
                               class="max-h-[82vh] max-w-[90vw] rounded-lg shadow-2xl object-contain"></video>
                    </template>
                    <template x-if="!(mediaItems[currentIndex].type === 'video' || (mediaItems[currentIndex].url && mediaItems[currentIndex].url.endsWith('.mp4')))">
                        <img :src="mediaItems[currentIndex].url" 
                             :alt="postTitle"
                             class="max-h-[82vh] max-w-[90vw] rounded-lg shadow-2xl object-contain transition-transform duration-300">
                    </template>
                </div>
            </template>

            <!-- Next Button -->
            <button @click="next()" 
                    x-show="mediaItems.length > 1"
                    class="absolute right-4 z-20 p-3 rounded-full bg-black/50 hover:bg-black/80 text-white/80 hover:text-white transition backdrop-blur-md">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                </svg>
            </button>
        </div>

        <!-- Lightbox Bottom Thumbnail Strip -->
        <div class="p-3 bg-gradient-to-t from-black/80 to-transparent flex items-center justify-center gap-2 overflow-x-auto max-w-full">
            <template x-for="(item, idx) in mediaItems" :key="idx">
                <button @click="currentIndex = idx; if(isPlaying) restartSlideTimer();"
                        :class="currentIndex === idx ? 'ring-2 ring-violet-400 scale-105 opacity-100' : 'opacity-40 hover:opacity-80'"
                        class="w-12 h-12 rounded-lg overflow-hidden shrink-0 transition-all duration-200">
                    <img :src="item.thumbnail_url || item.url" class="w-full h-full object-cover">
                </button>
            </template>
        </div>
    </div>

    <!-- Global Flash Toast Notification & Real-Time WebSocket Listener -->
    <div x-data="{
             show: false,
             message: '',
             initNotificationSocket() {
                 @if(auth()->check())
                     if (window.Echo) {
                         try {
                             window.Echo.private('users.{{ auth()->id() }}')
                                 .listen('.notification.sent', (e) => {
                                     this.message = (e.notificationData.actor_name || 'System') + ': ' + e.notificationData.message;
                                     this.show = true;
                                     setTimeout(() => this.show = false, 4000);
                                     if (window.Livewire) {
                                         window.Livewire.dispatch('refresh-notifications');
                                     }
                                 });
                         } catch (err) {
                             console.log('WebSocket Notification Echo:', err);
                         }
                     }
                 @endif
             }
         }"
         x-init="initNotificationSocket()"
         @notify.window="message = $event.detail; show = true; setTimeout(() => show = false, 3500)"
         x-show="show"
         x-cloak
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 translate-y-4 scale-95"
         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 translate-y-0 scale-100"
         x-transition:leave-end="opacity-0 translate-y-4 scale-95"
         class="fixed bottom-6 right-6 z-50 px-5 py-3 rounded-2xl glass-panel shadow-2xl border border-[var(--border-medium)] flex items-center gap-3">
        <div class="w-2.5 h-2.5 rounded-full accent-bg animate-pulse"></div>
        <span class="text-sm font-semibold" x-text="message"></span>
    </div>

    @livewireScripts
</body>
</html>
