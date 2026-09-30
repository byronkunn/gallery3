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

    <title>{{ $title ?? 'Booru Gallery' }} — Modern Art & Media Social Platform</title>
    <meta name="description" content="A visual-first modern booru-style art gallery with Twitter aesthetics, rich Telegram chat, collections, and manga pools.">

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

    <div class="min-h-screen bg-[var(--bg-page)] relative overflow-x-clip">
        <!-- Desktop Left Navigation (Collapsible, Twitter style) -->
        <aside :class="navCollapsed ? 'nav-aside-collapsed' : 'nav-aside-expanded'"
               class="hidden md:flex flex-col fixed top-0 bottom-0 left-0 z-40 transition-all duration-300 ease-in-out border-r border-[var(--border-subtle)] bg-[var(--bg-surface)] backdrop-blur-xl">
            
            <!-- Brand Logo & Collapse Toggle -->
            <div class="flex items-center justify-between h-20 px-4 xl:px-5 border-b border-[var(--border-subtle)]">
                <a href="{{ route('gallery') }}" class="flex items-center gap-3 overflow-hidden group">
                    <div class="relative flex items-center justify-center w-10 h-10 rounded-2xl accent-bg text-white shadow-lg transition-transform duration-300 group-hover:scale-105 shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                        <span class="absolute -top-1 -right-1 w-3 h-3 rounded-full bg-emerald-400 ring-2 ring-[var(--bg-surface)]"></span>
                    </div>
                    <span x-show="!navCollapsed" class="hidden xl:inline font-extrabold text-xl tracking-tight bg-gradient-to-r from-[var(--text-main)] to-[var(--text-muted)] bg-clip-text text-transparent truncate">
                        Booru<span class="accent-text">.art</span>
                    </span>
                </a>

                <!-- Collapse Toggle Icon Button -->
                <button @click="toggleNav()" 
                        class="p-2 rounded-xl text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)] transition"
                        :title="navCollapsed ? 'Expand navigation' : 'Collapse navigation'">
                    <svg class="w-5 h-5 transition-transform duration-300" :class="navCollapsed ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path>
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

            <nav class="flex-1 py-6 px-3 space-y-2 overflow-y-auto">
                <!-- Gallery (Home, Explore, Search all unified) -->
                <a href="{{ route('gallery') }}"
                   class="flex items-center gap-4 px-3.5 py-3.5 rounded-2xl font-semibold transition-all duration-200 group {{ request()->routeIs('gallery') ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}"
                   :title="navCollapsed ? 'Gallery' : ''">
                    <svg class="w-6 h-6 shrink-0 transition-transform group-hover:scale-110" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
                    </svg>
                    <span x-show="!navCollapsed" class="hidden xl:inline truncate text-base">Gallery</span>
                </a>

                <!-- Following Management -->
                @if(auth()->check())
                    <a href="{{ route('following.index') }}"
                       class="flex items-center gap-4 px-3.5 py-3.5 rounded-2xl font-semibold transition-all duration-200 group {{ request()->routeIs('following.*') ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}"
                       :title="navCollapsed ? 'Following' : ''">
                        <svg class="w-6 h-6 shrink-0 transition-transform group-hover:scale-110" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                        </svg>
                        <span x-show="!navCollapsed" class="hidden xl:inline truncate text-base">Following</span>
                    </a>
                @endif

                <!-- Collections Hub -->
                <a href="{{ route('collections.hub') }}"
                   class="flex items-center gap-4 px-3.5 py-3.5 rounded-2xl font-semibold transition-all duration-200 group {{ request()->routeIs('collections.*') || request()->routeIs('collection.*') ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}"
                   :title="navCollapsed ? 'Collections' : ''">
                    <svg class="w-6 h-6 shrink-0 transition-transform group-hover:scale-110" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                    </svg>
                    <span x-show="!navCollapsed" class="hidden xl:inline truncate text-base">Collections</span>
                </a>

                <!-- Artists Directory -->
                <a href="{{ route('artists.index') }}"
                   class="flex items-center gap-4 px-3.5 py-3.5 rounded-2xl font-semibold transition-all duration-200 group {{ request()->routeIs('artists.*') || request()->routeIs('artist.*') ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}"
                   :title="navCollapsed ? 'Artists' : ''">
                    <svg class="w-6 h-6 shrink-0 transition-transform group-hover:scale-110" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"></path>
                    </svg>
                    <span x-show="!navCollapsed" class="hidden xl:inline truncate text-base">Artists</span>
                </a>

                <!-- Pools (Series & Manga) -->
                <a href="{{ route('pools.index') }}"
                   class="flex items-center gap-4 px-3.5 py-3.5 rounded-2xl font-semibold transition-all duration-200 group {{ request()->routeIs('pools.*') ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}"
                   :title="navCollapsed ? 'Pools & Manga' : ''">
                    <svg class="w-6 h-6 shrink-0 transition-transform group-hover:scale-110" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                    </svg>
                    <span x-show="!navCollapsed" class="hidden xl:inline truncate text-base">Pools</span>
                </a>

                <!-- Notifications -->
                <a href="{{ route('notifications') }}"
                   class="relative flex items-center gap-4 px-3.5 py-3.5 rounded-2xl font-semibold transition-all duration-200 group {{ request()->routeIs('notifications') ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}"
                   :title="navCollapsed ? 'Notifications' : ''">
                    <div class="relative shrink-0">
                        <svg class="w-6 h-6 transition-transform group-hover:scale-110" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                        </svg>
                        @if($unreadNotifications > 0)
                            <span class="absolute -top-1.5 -right-1.5 flex items-center justify-center min-w-5 h-5 px-1 text-xs font-bold text-white bg-rose-500 rounded-full ring-2 ring-[var(--bg-surface)] animate-pulse">
                                {{ $unreadNotifications }}
                            </span>
                        @endif
                    </div>
                    <span x-show="!navCollapsed" class="hidden xl:inline text-base font-semibold">Notifications</span>
                    @if($unreadNotifications > 0)
                        <span x-show="!navCollapsed" class="hidden xl:inline ml-auto px-2 py-0.5 text-xs font-bold rounded-full bg-rose-500/20 text-rose-400 border border-rose-500/30">
                            {{ $unreadNotifications }}
                        </span>
                    @endif
                </a>

                <!-- Direct Messages (Lounge Area DMs) -->
                <a href="{{ route('lounge.dms') }}"
                   class="relative flex items-center gap-4 px-3.5 py-3.5 rounded-2xl font-semibold transition-all duration-200 group {{ request()->routeIs('lounge.dms*') ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}"
                   :title="navCollapsed ? 'Direct Messages' : ''">
                    <div class="relative shrink-0">
                        <svg class="w-6 h-6 transition-transform group-hover:scale-110" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                        </svg>
                        @if($unreadMessages > 0)
                            <span class="absolute -top-1.5 -right-1.5 flex items-center justify-center min-w-5 h-5 px-1 text-xs font-bold text-white bg-sky-500 rounded-full ring-2 ring-[var(--bg-surface)]">
                                {{ $unreadMessages }}
                            </span>
                        @endif
                    </div>
                    <span x-show="!navCollapsed" class="hidden xl:inline text-base font-semibold">Direct Messages</span>
                    @if($unreadMessages > 0)
                        <span x-show="!navCollapsed" class="hidden xl:inline ml-auto px-2 py-0.5 text-xs font-bold rounded-full bg-sky-500/20 text-sky-400 border border-sky-500/30">
                            {{ $unreadMessages }}
                        </span>
                    @endif
                </a>

                <!-- Upload -->
                <a href="{{ route('upload') }}"
                   class="flex items-center gap-4 px-3.5 py-3.5 rounded-2xl font-semibold transition-all duration-200 group {{ request()->routeIs('upload') ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}"
                   :title="navCollapsed ? 'Upload Media' : ''">
                    <svg class="w-6 h-6 shrink-0 transition-transform group-hover:scale-110" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                    </svg>
                    <span x-show="!navCollapsed" class="hidden xl:inline truncate text-base">Upload</span>
                </a>

                <!-- Profile -->
                @if(auth()->check())
                    <a href="{{ route('profile', ['username' => auth()->user()->username]) }}"
                       class="flex items-center gap-4 px-3.5 py-3.5 rounded-2xl font-semibold transition-all duration-200 group {{ request()->is('profile/' . auth()->user()->username . '*') ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}"
                       :title="navCollapsed ? 'Profile' : ''">
                        <svg class="w-6 h-6 shrink-0 transition-transform group-hover:scale-110" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                        </svg>
                        <span x-show="!navCollapsed" class="hidden xl:inline truncate text-base">Profile</span>
                    </a>
                @endif

                <!-- Admin Panel (Only for Admins) -->
                @if(auth()->check() && auth()->user()->isAdmin())
                    <a href="{{ route('admin') }}"
                       class="flex items-center gap-4 px-3.5 py-3.5 rounded-2xl font-semibold transition-all duration-200 group {{ request()->routeIs('admin') ? 'accent-bg text-white shadow-md' : 'text-rose-400 hover:text-rose-300 hover:bg-rose-500/10' }}"
                       :title="navCollapsed ? 'Admin Console' : ''">
                        <svg class="w-6 h-6 shrink-0 transition-transform group-hover:scale-110 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                        </svg>
                        <span x-show="!navCollapsed" class="hidden xl:inline truncate text-base font-bold">Admin Console</span>
                    </a>
                @endif

                <!-- Settings -->
                <a href="{{ route('settings') }}"
                   class="flex items-center gap-4 px-3.5 py-3.5 rounded-2xl font-semibold transition-all duration-200 group {{ request()->routeIs('settings') ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}"
                   :title="navCollapsed ? 'Settings' : ''">
                    <svg class="w-6 h-6 shrink-0 transition-transform group-hover:scale-110" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                    <span x-show="!navCollapsed" class="hidden xl:inline truncate text-base">Settings</span>
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
                            
                            <div x-show="!navCollapsed" class="hidden xl:block flex-1 min-w-0">
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

                            <svg x-show="!navCollapsed" class="hidden xl:block w-4 h-4 text-[var(--text-dim)] shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
                            
                            <div class="text-xs font-semibold text-[var(--text-dim)] px-2 mb-2 uppercase tracking-wider">Switch Demo User</div>
                            <div class="space-y-1 mb-3">
                                @foreach(\App\Models\User::all() as $u)
                                    <a href="{{ route('switch-user', $u->id) }}" 
                                       class="flex items-center gap-2.5 px-2.5 py-2 rounded-xl text-sm transition {{ auth()->id() === $u->id ? 'accent-bg text-white font-bold' : 'hover:bg-[var(--bg-surface-elevated)] text-[var(--text-main)]' }}">
                                        <img src="{{ $u->avatar_url }}" class="w-6 h-6 rounded-full object-cover">
                                        <div class="flex items-center gap-1.5 min-w-0 flex-1">
                                            <span class="truncate">{{ $u->name }}</span>
                                            @if($u->is_admin)
                                                <span class="px-1 py-0.5 rounded text-[8px] font-black bg-amber-500/20 text-amber-400 border border-amber-500/30 uppercase tracking-wider shrink-0">Admin</span>
                                            @endif
                                        </div>
                                        <span class="text-xs opacity-70">{{ '@' . $u->username }}</span>
                                    </a>
                                @endforeach
                            </div>

                            <div class="pt-2 border-t border-[var(--border-subtle)] space-y-1">
                                <a href="{{ route('switch-user', 'guest') }}" 
                                   class="flex items-center gap-2 px-2.5 py-2 rounded-xl text-sm text-amber-400 hover:bg-amber-400/10 transition">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                                    </svg>
                                    <span>Browse as Guest (Log Out)</span>
                                </a>
                            </div>
                        </div>
                    </div>
                @else
                    <!-- Guest Card -->
                    <div class="p-2 text-center">
                        <div x-show="!navCollapsed" class="hidden xl:block text-xs text-[var(--text-dim)] mb-2">You are viewing as Guest</div>
                        <a href="{{ route('switch-user', 1) }}" class="inline-flex items-center justify-center gap-2 w-full py-2 px-3 rounded-xl accent-bg text-white font-bold text-sm shadow">
                            <span x-show="!navCollapsed" class="hidden xl:inline">Log In (Demo)</span>
                            <span :class="navCollapsed ? 'inline' : 'inline xl:hidden'">Log In</span>
                        </a>
                    </div>
                @endif
            </div>
        </aside>

        <!-- Main Content Area -->
        <main :class="navCollapsed ? 'nav-main-collapsed' : 'nav-main-expanded'"
              class="flex-1 w-full min-w-0 pb-20 md:pb-8 transition-all duration-300 min-h-screen flex flex-col overflow-x-clip">
            {{ $slot ?? '' }}
            @yield('content')
        </main>
    </div>

    <!-- Mobile Bottom Navigation (Spec: Gallery, Messages, Upload, Profile. Twitter style clean glassmorphic) -->
    <nav class="md:hidden fixed bottom-0 left-0 right-0 z-40 h-16 border-t border-[var(--border-subtle)] bg-[var(--bg-surface)]/95 backdrop-blur-xl flex items-center justify-around px-2">
        <!-- Gallery -->
        <a href="{{ route('gallery') }}" class="flex-1 flex flex-col items-center justify-center p-2 {{ request()->routeIs('gallery') ? 'accent-text font-bold' : 'text-[var(--text-muted)]' }}">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
            </svg>
            <span class="text-[10px] mt-0.5">Gallery</span>
        </a>

        <!-- Direct Messages -->
        <a href="{{ route('lounge.dms') }}" class="flex-1 relative flex flex-col items-center justify-center p-2 {{ request()->routeIs('lounge.dms*') ? 'accent-text font-bold' : 'text-[var(--text-muted)]' }}">
            <div class="relative">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                </svg>
                @if($unreadMessages > 0)
                    <span class="absolute -top-1 -right-1 w-2.5 h-2.5 rounded-full bg-sky-500 ring-2 ring-[var(--bg-surface)]"></span>
                @endif
            </div>
            <span class="text-[10px] mt-0.5">Messages</span>
        </a>

        <!-- Upload -->
        <a href="{{ route('upload') }}" class="flex-1 flex flex-col items-center justify-center p-2 {{ request()->routeIs('upload') ? 'accent-text font-bold' : 'text-[var(--text-muted)]' }}">
            <div class="w-11 h-11 -mt-5 rounded-full accent-bg text-white shadow-lg flex items-center justify-center">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path>
                </svg>
            </div>
            <span class="text-[10px] mt-0.5">Upload</span>
        </a>

        <!-- Mobile Profile Button (Spec: Profile opens a modal holding notifications, settings, theme, etc.) -->
        <button @click="mobileMenuOpen = true" class="flex-1 flex flex-col items-center justify-center p-2 text-[var(--text-muted)]">
            @if(auth()->check())
                <img src="{{ auth()->user()->avatar_url }}" class="w-6 h-6 rounded-full object-cover ring-1 ring-[var(--border-medium)]">
            @else
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
            @endif
            <span class="text-[10px] mt-0.5">Profile</span>
        </button>
    </nav>

    <!-- Mobile Slide-Up Modal (Spec: holds notifications, settings, theme, profile) -->
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
                            <div class="font-bold text-base">{{ auth()->user()->name }}</div>
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

            <div class="grid grid-cols-2 gap-3">
                <a href="{{ route('notifications') }}" @click="mobileMenuOpen = false" class="flex items-center gap-3 p-3 rounded-2xl bg-[var(--bg-surface-elevated)] font-semibold">
                    <svg class="w-5 h-5 accent-text" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                    </svg>
                    <span>Notifications</span>
                </a>

                <a href="{{ route('settings') }}" @click="mobileMenuOpen = false" class="flex items-center gap-3 p-3 rounded-2xl bg-[var(--bg-surface-elevated)] font-semibold">
                    <svg class="w-5 h-5 accent-text" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                    </svg>
                    <span>Settings</span>
                </a>
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

            <!-- Demo User Switcher -->
            <div class="pt-2 border-t border-[var(--border-subtle)]">
                <div class="text-xs font-bold uppercase tracking-wider text-[var(--text-dim)] mb-2">Switch Demo Account</div>
                <div class="space-y-1">
                    @foreach(\App\Models\User::all() as $u)
                        <a href="{{ route('switch-user', $u->id) }}" class="flex items-center gap-2 p-2 rounded-xl text-sm {{ auth()->id() === $u->id ? 'accent-bg text-white font-bold' : 'hover:bg-[var(--bg-surface-elevated)]' }}">
                            <img src="{{ $u->avatar_url }}" class="w-6 h-6 rounded-full object-cover">
                            <span>{{ $u->name }}</span>
                        </a>
                    @endforeach
                    <a href="{{ route('switch-user', 'guest') }}" class="flex items-center gap-2 p-2 rounded-xl text-sm text-amber-400 hover:bg-amber-400/10">
                        <span>Browse as Guest (Log Out)</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

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

    <!-- Global Flash Toast Notification -->
    <div x-data="{ show: false, message: '' }"
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
