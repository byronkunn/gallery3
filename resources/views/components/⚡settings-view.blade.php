<?php

use Livewire\Component;
use App\Models\User;
use App\Models\Tag;
use Illuminate\Support\Facades\Auth;

new class extends Component
{
    public string $activeCategory = 'display'; // 'display', 'content', 'account', 'notifications', 'privacy'

    // Display
    public string $themeMode = 'dark';
    public string $themePalette = 'violet';
    public string $fontSize = 'md';
    public bool $reducedMotion = false;

    // Content filters & Blacklist
    public bool $blurNsfw = true;
    public bool $hideNsfw = false;
    public string $newBlacklistTag = '';

    // Account
    public string $name = '';
    public string $username = '';
    public string $email = '';
    public string $bio = '';
    public string $website = '';
    public string $commissionStatus = 'Open';

    // Media & Autoplay Settings
    public string $defaultGrid = 'masonry';
    public int $slideshowDuration = 5000;
    public bool $videoAutoplay = true;
    public bool $infiniteScroll = true;

    // Artist & Commission Preferences
    public string $minPrice = '50';
    public string $openSlotsCount = '3/5';
    public array $paymentMethods = ['PayPal', 'Ko-fi', 'Patreon'];
    public bool $watermarkProtection = true;

    public function mount()
    {
        $user = Auth::user();
        if ($user) {
            $this->themeMode = $user->theme_mode ?? 'dark';
            $this->themePalette = $user->theme_palette ?? 'violet';
            $this->fontSize = $user->font_size ?? 'md';
            $this->reducedMotion = $user->reduced_motion ?? false;

            $this->blurNsfw = $user->blur_nsfw ?? true;
            $this->hideNsfw = $user->hide_nsfw ?? false;

            $this->name = $user->name;
            $this->username = $user->username;
            $this->email = $user->email;
            $this->bio = $user->bio ?? '';
            $this->website = $user->website ?? '';
            $this->commissionStatus = $user->commission_status ?? 'Open';
        }
    }

    public function setCategory(string $cat)
    {
        $this->activeCategory = $cat;
    }

    public function updateTheme(string $mode, string $palette)
    {
        $this->themeMode = $mode;
        $this->themePalette = $palette;

        if (Auth::check()) {
            Auth::user()->update([
                'theme_mode' => $this->themeMode,
                'theme_palette' => $this->themePalette,
            ]);
        }

        $this->dispatch('theme-changed', mode: $this->themeMode, palette: $this->themePalette);
        $this->dispatch('notify', 'Theme updated');
    }

    public function updateFontSize(string $size)
    {
        $this->fontSize = $size;
        if (Auth::check()) {
            Auth::user()->update(['font_size' => $size]);
        }
        $this->dispatch('notify', 'Font scale updated');
    }

    public function toggleReducedMotion()
    {
        $this->reducedMotion = !$this->reducedMotion;
        if (Auth::check()) {
            Auth::user()->update(['reduced_motion' => $this->reducedMotion]);
        }
        $this->dispatch('notify', 'Motion preference saved');
    }

    public function updateContentFilters()
    {
        if (!Auth::check()) return;

        Auth::user()->update([
            'blur_nsfw' => $this->blurNsfw,
            'hide_nsfw' => $this->hideNsfw,
        ]);

        $this->dispatch('notify', 'Content filters saved');
    }

    public function addBlacklistTag()
    {
        if (!Auth::check()) return;

        $tagName = trim($this->newBlacklistTag);
        if (empty($tagName)) return;

        $tag = Tag::where('name', $tagName)->first();
        if (!$tag) {
            $tag = Tag::create([
                'name' => $tagName,
                'slug' => \Illuminate\Support\Str::slug($tagName),
                'type' => 'general',
            ]);
        }

        $user = Auth::user();
        if (!$user->isTagBlacklisted($tag)) {
            $user->blacklistedTags()->attach($tag->id);
            $this->dispatch('notify', "Added #{$tag->name} to blacklist");
        }

        $this->newBlacklistTag = '';
    }

    public function removeBlacklistTag(int $tagId)
    {
        if (!Auth::check()) return;
        Auth::user()->blacklistedTags()->detach($tagId);
        $this->dispatch('notify', 'Tag removed from blacklist');
    }

    public function unblockUser(int $userId): void
    {
        abort_unless(Auth::check(), 401);
        Auth::user()->blockedUsers()->detach($userId);
        $this->dispatch('notify', 'Artist unblocked.');
    }

    public function saveAccount()
    {
        if (!Auth::check()) return;

        $this->validate([
            'name' => 'required|string|max:60',
            'username' => 'required|string|max:40|unique:users,username,' . Auth::id(),
            'email' => 'required|email|unique:users,email,' . Auth::id(),
            'bio' => 'nullable|string|max:500',
            'website' => 'nullable|url|max:200',
            'commissionStatus' => 'required|in:Open,Closed,Waitlist',
        ]);

        Auth::user()->update([
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'bio' => $this->bio,
            'website' => $this->website,
            'commission_status' => $this->commissionStatus,
        ]);

        $this->dispatch('notify', 'Account settings saved!');
    }

    public function render()
    {
        $user = Auth::user();
        $blacklistedTags = $user ? $user->blacklistedTags()->get() : collect();
        $blockedUsers = $user ? $user->blockedUsers()->orderBy('username')->get() : collect();

        return view('components.⚡settings-view', [
            'currentUser' => $user,
            'blacklistedTags' => $blacklistedTags,
            'blockedUsers' => $blockedUsers,
        ]);
    }
};
?>

<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="mb-6">
        <h1 class="text-2xl font-black tracking-tight text-[var(--text-main)]">Settings</h1>
        <p class="text-xs text-[var(--text-dim)]">Customize your display theme, content filtering, and profile preferences.</p>
    </div>

    <!-- Twitter-Style 2-Pane Settings Layout (Category List + Detail) -->
    <div class="rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] shadow-xl overflow-hidden grid grid-cols-1 md:grid-cols-12 min-h-[600px]">
        <!-- Left Pane: Categories -->
        <div class="md:col-span-4 border-r border-[var(--border-subtle)] p-3 space-y-1 bg-[var(--bg-surface)]">
            <!-- Display & Theme -->
            <button wire:click="setCategory('display')" 
                    class="flex items-center gap-3 w-full px-4 py-3.5 rounded-2xl text-sm font-bold text-left transition {{ $activeCategory === 'display' ? 'accent-bg text-white shadow' : 'text-[var(--text-muted)] hover:bg-[var(--bg-surface-elevated)] hover:text-[var(--text-main)]' }}">
                <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"></path>
                </svg>
                <div class="flex-1 truncate">Display & Theming</div>
            </button>

            <!-- Content Filters & Tag Blacklist -->
            <button wire:click="setCategory('content')" 
                    class="flex items-center gap-3 w-full px-4 py-3.5 rounded-2xl text-sm font-bold text-left transition {{ $activeCategory === 'content' ? 'accent-bg text-white shadow' : 'text-[var(--text-muted)] hover:bg-[var(--bg-surface-elevated)] hover:text-[var(--text-main)]' }}">
                <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
                <div class="flex-1 truncate">Content Filters & Blacklist</div>
            </button>

            <!-- Account -->
            <button wire:click="setCategory('account')" 
                    class="flex items-center gap-3 w-full px-4 py-3.5 rounded-2xl text-sm font-bold text-left transition {{ $activeCategory === 'account' ? 'accent-bg text-white shadow' : 'text-[var(--text-muted)] hover:bg-[var(--bg-surface-elevated)] hover:text-[var(--text-main)]' }}">
                <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
                <div class="flex-1 truncate">Account Profile</div>
            </button>

            <!-- Notifications Preferences -->
            <button wire:click="setCategory('notifications')" 
                    class="flex items-center gap-3 w-full px-4 py-3.5 rounded-2xl text-sm font-bold text-left transition {{ $activeCategory === 'notifications' ? 'accent-bg text-white shadow' : 'text-[var(--text-muted)] hover:bg-[var(--bg-surface-elevated)] hover:text-[var(--text-main)]' }}">
                <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                </svg>
                <div class="flex-1 truncate">Notifications</div>
            </button>

            <!-- Media & Autoplay -->
            <button wire:click="setCategory('media')" 
                    class="flex items-center gap-3 w-full px-4 py-3.5 rounded-2xl text-sm font-bold text-left transition {{ $activeCategory === 'media' ? 'accent-bg text-white shadow' : 'text-[var(--text-muted)] hover:bg-[var(--bg-surface-elevated)] hover:text-[var(--text-main)]' }}">
                <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <div class="flex-1 truncate">Media & Autoplay</div>
            </button>

            <!-- Artist & Commissions -->
            <button wire:click="setCategory('artist')" 
                    class="flex items-center gap-3 w-full px-4 py-3.5 rounded-2xl text-sm font-bold text-left transition {{ $activeCategory === 'artist' ? 'accent-bg text-white shadow' : 'text-[var(--text-muted)] hover:bg-[var(--bg-surface-elevated)] hover:text-[var(--text-main)]' }}">
                <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"></path>
                </svg>
                <div class="flex-1 truncate">Artist & Commissions</div>
            </button>

            <!-- Privacy & Security -->
            <button wire:click="setCategory('privacy')" 
                    class="flex items-center gap-3 w-full px-4 py-3.5 rounded-2xl text-sm font-bold text-left transition {{ $activeCategory === 'privacy' ? 'accent-bg text-white shadow' : 'text-[var(--text-muted)] hover:bg-[var(--bg-surface-elevated)] hover:text-[var(--text-main)]' }}">
                <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                </svg>
                <div class="flex-1 truncate">Privacy & Security</div>
            </button>
        </div>

        <!-- Right Pane: Details & Controls -->
        <div class="md:col-span-8 p-6 sm:p-8 space-y-8 bg-[var(--bg-page)]/40 overflow-y-auto">
            <!-- 1. Display & Theming Category -->
            @if($activeCategory === 'display')
                <div class="space-y-6">
                    <div>
                        <h2 class="text-xl font-bold">Display & Theming</h2>
                        <p class="text-xs text-[var(--text-dim)] mt-1">Manage dark mode, OLED true black, and curated color palettes.</p>
                    </div>

                    <!-- Background Mode (Dark, OLED, Light) -->
                    <div class="space-y-3">
                        <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Theme Mode</label>
                        <div class="grid grid-cols-3 gap-3">
                            <button wire:click="updateTheme('dark', '{{ $themePalette }}')" 
                                    class="p-4 rounded-2xl border text-center transition {{ $themeMode === 'dark' ? 'accent-border bg-[var(--accent-glow)] font-bold text-[var(--text-main)]' : 'border-[var(--border-subtle)] hover:bg-[var(--bg-surface-elevated)] text-[var(--text-muted)]' }}">
                                <div class="w-6 h-6 rounded-full bg-slate-900 border border-slate-700 mx-auto mb-2"></div>
                                <span class="text-xs">Dark (Default)</span>
                            </button>

                            <button wire:click="updateTheme('oled', '{{ $themePalette }}')" 
                                    class="p-4 rounded-2xl border text-center transition {{ $themeMode === 'oled' ? 'accent-border bg-[var(--accent-glow)] font-bold text-[var(--text-main)]' : 'border-[var(--border-subtle)] hover:bg-[var(--bg-surface-elevated)] text-[var(--text-muted)]' }}">
                                <div class="w-6 h-6 rounded-full bg-black border border-neutral-700 mx-auto mb-2"></div>
                                <span class="text-xs">OLED True Black</span>
                            </button>

                            <button wire:click="updateTheme('light', '{{ $themePalette }}')" 
                                    class="p-4 rounded-2xl border text-center transition {{ $themeMode === 'light' ? 'accent-border bg-[var(--accent-glow)] font-bold text-[var(--text-main)]' : 'border-[var(--border-subtle)] hover:bg-[var(--bg-surface-elevated)] text-[var(--text-muted)]' }}">
                                <div class="w-6 h-6 rounded-full bg-white border border-slate-300 mx-auto mb-2"></div>
                                <span class="text-xs">Light Mode</span>
                            </button>
                        </div>
                    </div>

                    <!-- 6 Curated Color Palettes -->
                    <div class="space-y-3">
                        <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Accent Palette (6 Curated Options)</label>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                            @php
                                $palettes = [
                                    ['id' => 'violet', 'name' => 'Electric Violet', 'color' => '#8b5cf6'],
                                    ['id' => 'cyan', 'name' => 'Cyber Cyan', 'color' => '#06b6d4'],
                                    ['id' => 'sakura', 'name' => 'Sakura Rose', 'color' => '#ec4899'],
                                    ['id' => 'emerald', 'name' => 'Emerald Mint', 'color' => '#10b981'],
                                    ['id' => 'sunset', 'name' => 'Sunset Amber', 'color' => '#f97316'],
                                    ['id' => 'crimson', 'name' => 'Ruby Crimson', 'color' => '#ef4444'],
                                ];
                            @endphp

                            @foreach($palettes as $p)
                                <button wire:click="updateTheme('{{ $themeMode }}', '{{ $p['id'] }}')" 
                                        class="flex items-center gap-3 p-3 rounded-2xl border transition text-left {{ $themePalette === $p['id'] ? 'accent-border bg-[var(--accent-glow)] font-bold' : 'border-[var(--border-subtle)] hover:bg-[var(--bg-surface-elevated)]' }}">
                                    <span class="w-6 h-6 rounded-full shrink-0 shadow-sm" style="background-color: {{ $p['color'] }};"></span>
                                    <span class="text-xs truncate">{{ $p['name'] }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <!-- Font Scaling -->
                    <div class="space-y-3">
                        <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Font Scaling</label>
                        <div class="flex items-center gap-3">
                            <button wire:click="updateFontSize('sm')" class="px-4 py-2 rounded-xl border text-xs font-semibold {{ $fontSize === 'sm' ? 'accent-bg text-white font-bold' : 'border-[var(--border-subtle)] hover:bg-[var(--bg-surface-elevated)]' }}">Small</button>
                            <button wire:click="updateFontSize('md')" class="px-4 py-2 rounded-xl border text-xs font-semibold {{ $fontSize === 'md' ? 'accent-bg text-white font-bold' : 'border-[var(--border-subtle)] hover:bg-[var(--bg-surface-elevated)]' }}">Medium (Default)</button>
                            <button wire:click="updateFontSize('lg')" class="px-4 py-2 rounded-xl border text-xs font-semibold {{ $fontSize === 'lg' ? 'accent-bg text-white font-bold' : 'border-[var(--border-subtle)] hover:bg-[var(--bg-surface-elevated)]' }}">Large</button>
                        </div>
                    </div>

                    <!-- Reduced Motion -->
                    <div class="flex items-center justify-between p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)]">
                        <div>
                            <div class="font-bold text-sm">Reduced Motion</div>
                            <div class="text-xs text-[var(--text-dim)] mt-0.5">Minimizes smooth animations and transitions across the gallery.</div>
                        </div>
                        <button wire:click="toggleReducedMotion" class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out {{ $reducedMotion ? 'accent-bg' : 'bg-neutral-700' }}">
                            <span class="inline-block h-5 w-5 transform rounded-full bg-white transition duration-200 ease-in-out {{ $reducedMotion ? 'translate-x-5' : 'translate-x-0' }}"></span>
                        </button>
                    </div>
                </div>
            @endif

            <!-- 2. Content Filters & Tag Blacklist Category -->
            @if($activeCategory === 'content')
                <div class="space-y-6">
                    <div>
                        <h2 class="text-xl font-bold">Content Filters & Blacklist</h2>
                        <p class="text-xs text-[var(--text-dim)] mt-1">Control NSFW media visibility and blacklist specific tags.</p>
                    </div>

                    <!-- NSFW Toggles -->
                    <div class="space-y-3">
                        <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">NSFW Preferences</label>
                        <div class="space-y-2">
                            <label class="flex items-center justify-between p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] cursor-pointer">
                                <div>
                                    <div class="font-bold text-sm">Blur NSFW Content</div>
                                    <div class="text-xs text-[var(--text-dim)] mt-0.5">Show mature media with a click-to-reveal blur overlay.</div>
                                </div>
                                <input type="checkbox" wire:model.live="blurNsfw" wire:change="updateContentFilters" class="w-5 h-5 rounded accent-bg">
                            </label>

                            <label class="flex items-center justify-between p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] cursor-pointer">
                                <div>
                                    <div class="font-bold text-sm">Completely Hide NSFW Content</div>
                                    <div class="text-xs text-[var(--text-dim)] mt-0.5">Exclude all mature tagged posts from appearing in your feed.</div>
                                </div>
                                <input type="checkbox" wire:model.live="hideNsfw" wire:change="updateContentFilters" class="w-5 h-5 rounded accent-bg">
                            </label>
                        </div>
                    </div>

                    <!-- Tag Blacklist -->
                    <div class="space-y-3">
                        <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Tag Blacklist</label>
                        <p class="text-xs text-[var(--text-dim)]">Posts matching blacklisted tags will be filtered based on your preference.</p>

                        <div class="flex items-center gap-2">
                            <input type="text" 
                                   wire:model="newBlacklistTag"
                                   wire:keydown.enter="addBlacklistTag"
                                   placeholder="Add tag to blacklist (e.g. spoilers, gore)..." 
                                   class="flex-1 p-3 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)]">
                            <button wire:click="addBlacklistTag" class="px-5 py-3 rounded-2xl accent-bg text-white font-bold text-xs shadow">
                                Add Tag
                            </button>
                        </div>

                        <!-- Blacklisted Tag Chips -->
                        <div class="flex flex-wrap gap-2 pt-2">
                            @forelse($blacklistedTags as $bt)
                                <div class="flex items-center gap-2 px-3 py-1.5 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-400 text-xs font-bold">
                                    <span>#{{ $bt->name }}</span>
                                    <button wire:click="removeBlacklistTag({{ $bt->id }})" class="hover:text-rose-200">×</button>
                                </div>
                            @empty
                                <p class="text-xs text-[var(--text-dim)]">No tags currently blacklisted.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            @endif

            <!-- 3. Account Settings Category -->
            @if($activeCategory === 'account')
                <div class="space-y-6">
                    <div>
                        <h2 class="text-xl font-bold">Account Profile</h2>
                        <p class="text-xs text-[var(--text-dim)] mt-1">Update your artist identity, bio, and commission status.</p>
                    </div>

                    <form wire:submit.prevent="saveAccount" class="space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Display Name</label>
                                <input type="text" wire:model="name" class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)]">
                            </div>
                            <div>
                                <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Username</label>
                                <input type="text" wire:model="username" class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)]">
                            </div>
                        </div>

                        <div>
                            <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Email Address</label>
                            <input type="email" wire:model="email" class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)]">
                        </div>

                        <div>
                            <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Bio</label>
                            <textarea wire:model="bio" rows="3" class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)]"></textarea>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Website</label>
                                <input type="url" wire:model="website" class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)]">
                            </div>
                            <div>
                                <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Commission Status</label>
                                <select wire:model="commissionStatus" class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)]">
                                    <option value="Open">Open</option>
                                    <option value="Closed">Closed</option>
                                    <option value="Waitlist">Waitlist</option>
                                </select>
                            </div>
                        </div>

                        <div class="pt-2">
                            <button type="submit" class="px-6 py-2.5 rounded-2xl accent-bg text-white font-bold text-sm shadow hover:opacity-90 transition">
                                Save Profile Changes
                            </button>
                        </div>
                    </form>
                </div>
            @endif

            <!-- 4. Notifications Preferences -->
            @if($activeCategory === 'notifications')
                <div class="space-y-6">
                    <div>
                        <h2 class="text-xl font-bold">Notification Preferences</h2>
                        <p class="text-xs text-[var(--text-dim)] mt-1">Choose which events trigger notification updates.</p>
                    </div>

                    <div class="space-y-3">
                        <label class="flex items-center justify-between p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] cursor-pointer">
                            <div>
                                <div class="font-bold text-sm">Followed Pools Chapters</div>
                                <div class="text-xs text-[var(--text-dim)] mt-0.5">Notify when a new chapter is released in pools you follow.</div>
                            </div>
                            <input type="checkbox" checked class="w-5 h-5 rounded accent-bg">
                        </label>

                        <label class="flex items-center justify-between p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] cursor-pointer">
                            <div>
                                <div class="font-bold text-sm">Followed Collections Updates</div>
                                <div class="text-xs text-[var(--text-dim)] mt-0.5">Notify when items are added to collections you follow.</div>
                            </div>
                            <input type="checkbox" checked class="w-5 h-5 rounded accent-bg">
                        </label>

                        <label class="flex items-center justify-between p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] cursor-pointer">
                            <div>
                                <div class="font-bold text-sm">Artwork Likes & Comments</div>
                                <div class="text-xs text-[var(--text-dim)] mt-0.5">Notify when other users like or comment on your creations.</div>
                            </div>
                            <input type="checkbox" checked class="w-5 h-5 rounded accent-bg">
                        </label>
                    </div>
                </div>
            @endif

            <!-- 5. Privacy & Security Category -->
            @if($activeCategory === 'privacy')
                <div class="space-y-6">
                    <div>
                        <h2 class="text-xl font-bold">Privacy & Security</h2>
                        <p class="text-xs text-[var(--text-dim)] mt-1">Manage direct messaging permissions, online presence, and account security.</p>
                    </div>

                    <div class="space-y-4">
                        <div class="p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-3">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="font-bold text-sm">Direct Messaging Requests</div>
                                    <div class="text-xs text-[var(--text-dim)] mt-0.5">Allow non-followed artists to send direct message requests.</div>
                                </div>
                                <input type="checkbox" checked class="w-5 h-5 rounded accent-bg">
                            </div>
                        </div>

                        <div class="p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-3">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="font-bold text-sm">Online & Activity Status</div>
                                    <div class="text-xs text-[var(--text-dim)] mt-0.5">Show active status indicator on chat and profile pages.</div>
                                </div>
                                <input type="checkbox" checked class="w-5 h-5 rounded accent-bg">
                            </div>
                        </div>

                        <div class="p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-3">
                            <div class="font-bold text-sm">Active Sessions & Security</div>
                            <p class="text-xs text-[var(--text-dim)]">You are logged in on Mac OS (Current Browser Session).</p>
                            <button wire:click="$dispatch('notify', 'All other active sessions revoked')" 
                                    class="px-4 py-2 rounded-xl bg-rose-500/10 border border-rose-500/20 text-rose-400 font-bold text-xs hover:bg-rose-500/20 transition">
                                Log Out Other Sessions
                            </button>
                        </div>

                        <div class="p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-3">
                            <div class="font-bold text-sm">Blocked artists</div>
                            @forelse($blockedUsers as $blockedUser)
                                <div class="flex items-center justify-between gap-3 border-t border-[var(--border-subtle)] pt-3">
                                    <a href="{{ route('profile', $blockedUser->username) }}" class="text-sm font-semibold hover:underline">{{ '@'.$blockedUser->username }}</a>
                                    <button wire:click="unblockUser({{ $blockedUser->id }})" class="text-xs font-bold text-rose-400">Unblock</button>
                                </div>
                            @empty
                                <p class="text-xs text-[var(--text-dim)]">You haven’t blocked anyone.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            @endif

            <!-- 6. Media & Autoplay Category -->
            @if($activeCategory === 'media')
                <div class="space-y-6">
                    <div>
                        <h2 class="text-xl font-bold">Media & Autoplay Settings</h2>
                        <p class="text-xs text-[var(--text-dim)] mt-1">Configure grid layout defaults, video autoplay, and slideshow speeds.</p>
                    </div>

                    <div class="space-y-4">
                        <div class="p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-3">
                            <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Default Gallery Feed Layout</label>
                            <div class="flex items-center gap-3">
                                <button wire:click="$set('defaultGrid', 'masonry'); $dispatch('notify', 'Masonry grid layout saved')"
                                        class="px-4 py-2 rounded-xl border text-xs font-semibold {{ $defaultGrid === 'masonry' ? 'accent-bg text-white font-bold' : 'border-[var(--border-subtle)] hover:bg-[var(--bg-surface-elevated)]' }}">
                                    Masonry Dynamic Height
                                </button>
                                <button wire:click="$set('defaultGrid', 'square'); $dispatch('notify', 'Uniform square layout saved')"
                                        class="px-4 py-2 rounded-xl border text-xs font-semibold {{ $defaultGrid === 'square' ? 'accent-bg text-white font-bold' : 'border-[var(--border-subtle)] hover:bg-[var(--bg-surface-elevated)]' }}">
                                    Uniform Square Grid
                                </button>
                            </div>
                        </div>

                        <div class="p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-3">
                            <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Lightbox Slideshow Interval</label>
                            <div class="flex items-center gap-2">
                                <button wire:click="$set('slideshowDuration', 3000); $dispatch('notify', 'Slideshow set to 3 seconds')" class="px-3.5 py-2 rounded-xl border text-xs {{ $slideshowDuration === 3000 ? 'accent-bg text-white font-bold' : 'border-[var(--border-subtle)]' }}">3s</button>
                                <button wire:click="$set('slideshowDuration', 5000); $dispatch('notify', 'Slideshow set to 5 seconds')" class="px-3.5 py-2 rounded-xl border text-xs {{ $slideshowDuration === 5000 ? 'accent-bg text-white font-bold' : 'border-[var(--border-subtle)]' }}">5s (Default)</button>
                                <button wire:click="$set('slideshowDuration', 10000); $dispatch('notify', 'Slideshow set to 10 seconds')" class="px-3.5 py-2 rounded-xl border text-xs {{ $slideshowDuration === 10000 ? 'accent-bg text-white font-bold' : 'border-[var(--border-subtle)]' }}">10s</button>
                                <button wire:click="$set('slideshowDuration', 15000); $dispatch('notify', 'Slideshow set to 15 seconds')" class="px-3.5 py-2 rounded-xl border text-xs {{ $slideshowDuration === 15000 ? 'accent-bg text-white font-bold' : 'border-[var(--border-subtle)]' }}">15s</button>
                            </div>
                        </div>

                        <div class="p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] flex items-center justify-between cursor-pointer"
                             @click="$wire.videoAutoplay = !$wire.videoAutoplay; $dispatch('notify', 'Video autoplay saved')">
                            <div>
                                <div class="font-bold text-sm">Autoplay Muted Videos on Scroll</div>
                                <div class="text-xs text-[var(--text-dim)] mt-0.5">Automatically play MP4 animated media when visible.</div>
                            </div>
                            <input type="checkbox" wire:model.live="videoAutoplay" class="w-5 h-5 rounded accent-bg">
                        </div>

                        <div class="p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] flex items-center justify-between cursor-pointer"
                             @click="$wire.infiniteScroll = !$wire.infiniteScroll; $dispatch('notify', 'Infinite scroll preference saved')">
                            <div>
                                <div class="font-bold text-sm">Infinite Scroll Feed</div>
                                <div class="text-xs text-[var(--text-dim)] mt-0.5">Automatically load more artworks as you scroll down the feed.</div>
                            </div>
                            <input type="checkbox" wire:model.live="infiniteScroll" class="w-5 h-5 rounded accent-bg">
                        </div>
                    </div>
                </div>
            @endif

            <!-- 7. Artist & Commission Preferences Category -->
            @if($activeCategory === 'artist')
                <div class="space-y-6">
                    <div>
                        <h2 class="text-xl font-bold">Artist & Commission Preferences</h2>
                        <p class="text-xs text-[var(--text-dim)] mt-1">Configure pricing baseline, slot capacity, and accepted payment methods.</p>
                    </div>

                    <div class="space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-2">
                                <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Minimum Commission Price ($ USD)</label>
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-bold">$</span>
                                    <input type="text" wire:model="minPrice" class="w-full p-2.5 rounded-xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm font-bold outline-none">
                                </div>
                            </div>

                            <div class="p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-2">
                                <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Open Slots Counter</label>
                                <input type="text" wire:model="openSlotsCount" placeholder="e.g. 3/5 slots open" class="w-full p-2.5 rounded-xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm font-bold outline-none">
                            </div>
                        </div>

                        <div class="p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-3">
                            <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Accepted Payment Badges</label>
                            <div class="flex flex-wrap gap-2">
                                @php
                                    $allPayments = ['PayPal', 'Stripe', 'Ko-fi', 'Patreon', 'Crypto'];
                                @endphp
                                @foreach($allPayments as $method)
                                    @php $selected = in_array($method, $paymentMethods); @endphp
                                    <button wire:click="
                                        if (in_array('{{ $method }}', $paymentMethods)) {
                                            $paymentMethods = array_diff($paymentMethods, ['{{ $method }}']);
                                        } else {
                                            $paymentMethods[] = '{{ $method }}';
                                        }
                                        $dispatch('notify', 'Payment methods updated');
                                    " class="px-3.5 py-2 rounded-xl text-xs font-bold border transition {{ $selected ? 'accent-bg text-white border-transparent' : 'border-[var(--border-subtle)] text-[var(--text-dim)]' }}">
                                        {{ $selected ? '✓ ' : '+ ' }}{{ $method }}
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        <div class="p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] flex items-center justify-between cursor-pointer"
                             @click="$wire.watermarkProtection = !$wire.watermarkProtection; $dispatch('notify', 'Watermark protection preference updated')">
                            <div>
                                <div class="font-bold text-sm">Download & Original Resolution Protection</div>
                                <div class="text-xs text-[var(--text-dim)] mt-0.5">Protect high-resolution original artwork files from unauthorized right-click downloads.</div>
                            </div>
                            <input type="checkbox" wire:model.live="watermarkProtection" class="w-5 h-5 rounded accent-bg">
                        </div>

                        <div>
                            <button wire:click="$dispatch('notify', 'Artist preferences saved successfully!')" 
                                    class="px-6 py-2.5 rounded-2xl accent-bg text-white font-bold text-sm shadow hover:opacity-90 transition">
                                Save Artist Settings
                            </button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
