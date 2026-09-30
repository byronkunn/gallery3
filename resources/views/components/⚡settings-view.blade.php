<?php

use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;
    public string $activeCategory = 'account';

    public string $mobileScreen = 'list'; // 'list', 'detail'

    public string $searchQuery = '';

    // Account
    public string $name = '';

    public string $username = '';

    public string $email = '';

    public string $language = 'en';

    public string $region = 'US';

    public string $timezone = 'UTC';

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    // Profile
    public string $bio = '';

    public string $website = '';

    public mixed $avatarUpload = null;

    public mixed $bannerUpload = null;

    public string $profileVisibility = 'public';

    public bool $showJoinDate = true;

    public bool $showFollowerCount = true;

    public bool $showFollowingCount = true;

    public bool $showLikesTab = true;

    public bool $showCollectionsTab = true;

    // Privacy & Safety
    public string $whoCanFollow = 'everyone'; // 'everyone', 'approval'

    public string $whoCanComment = 'everyone'; // 'everyone', 'followers', 'following', 'nobody'

    public string $whoCanMention = 'everyone'; // 'everyone', 'following', 'nobody'

    public string $likesVisibility = 'everyone'; // 'everyone', 'followers', 'only_me'

    public string $followingVisibility = 'everyone'; // 'everyone', 'only_me'

    // Content Filters
    public bool $ratingSafe = true;

    public bool $ratingQuestionable = true;

    public bool $ratingExplicit = false;

    public bool $blurNsfw = true;

    public bool $hideNsfw = false;

    // Feed & Discovery
    public bool $feedUseLikes = true;

    public bool $feedUseFollowedTags = true;

    public bool $feedUseRecentlyViewed = true;

    public string $followingFeedSort = 'newest'; // 'newest', 'popular'

    // Messages Privacy
    public string $whoCanDm = 'everyone'; // 'everyone', 'following', 'mutuals', 'nobody'

    public string $whoCanAddGroupDm = 'everyone'; // 'everyone', 'following', 'mutuals', 'nobody'

    public bool $routeUnknownToRequests = true;

    public bool $showOnlineStatus = true;

    public bool $showTypingIndicator = true;

    public bool $sendReadReceipts = true;

    public bool $previewExternalLinks = true;

    public bool $previewGalleryPosts = true;

    public bool $previewArtists = true;

    public bool $previewCollections = true;

    public bool $previewTags = true;

    // Lounges
    public bool $loungeMentionNotify = true;

    public bool $loungeRoleNotify = true;

    public bool $loungeReplyNotify = true;

    public bool $loungeThreadNotify = true;

    public bool $loungeInviteNotify = true;

    public bool $loungeAnnouncementNotify = true;

    public string $loungeUploadDefault = 'lounge_only'; // 'lounge_only', 'remember', 'ask'

    // Notifications
    public bool $notifyFollows = true;

    public bool $notifyLikes = true;

    public bool $notifyComments = true;

    public bool $notifyMentions = true;

    public bool $notifyArtistActivity = true;

    public bool $notifyTagActivity = true;

    public bool $notifyCollectionActivity = true;

    public bool $notifyDirectMessages = true;

    public bool $notifySecurityAlerts = true;

    // Uploads & Media Defaults
    public string $defaultRating = 'Safe';

    public string $defaultVisibility = 'Public';

    public bool $commentsEnabledDefault = true;

    public bool $rememberTags = true;

    public bool $rememberArtists = true;

    // Tags & Filters
    public string $newMutedTag = '';

    // Collections Defaults
    public string $defaultCollectionVisibility = 'Private';

    // Appearance
    public string $themeMode = 'dark';

    public string $themePalette = 'violet';

    public string $galleryDensity = 'comfortable';

    public string $chatDensity = 'comfortable';

    public string $fontSize = 'md';

    // Accessibility
    public bool $reducedMotion = false;

    public function mount(?string $category = null)
    {
        if ($category && in_array($category, [
            'account', 'profile', 'privacy', 'content', 'feed', 'messages',
            'lounges', 'notifications', 'uploads', 'tags', 'collections',
            'appearance', 'accessibility', 'security', 'blocked', 'sessions',
        ])) {
            $this->activeCategory = $category;
            $this->mobileScreen = 'detail';
        }

        $user = Auth::user();
        if ($user) {
            $this->name = $user->name ?? '';
            $this->username = $user->username ?? '';
            $this->email = $user->email ?? '';
            $this->bio = $user->bio ?? '';
            $this->website = $user->website ?? '';

            $this->themeMode = $user->theme_mode ?? 'dark';
            $this->themePalette = $user->theme_palette ?? 'violet';
            $this->fontSize = $user->font_size ?? 'md';
            $this->reducedMotion = $user->reduced_motion ?? false;

            $this->blurNsfw = $user->blur_nsfw ?? true;
            $this->hideNsfw = $user->hide_nsfw ?? false;
        }
    }

    public function setCategory(string $cat)
    {
        $this->activeCategory = $cat;
        $this->mobileScreen = 'detail';
    }

    public function backToMobileList()
    {
        $this->mobileScreen = 'list';
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
        $this->dispatch('notify', 'Theme preference updated.');
    }

    public function updateFontSize(string $size)
    {
        $this->fontSize = $size;
        if (Auth::check()) {
            Auth::user()->update(['font_size' => $size]);
        }
        $this->dispatch('notify', 'Font size updated.');
    }

    public function toggleReducedMotion()
    {
        $this->reducedMotion = ! $this->reducedMotion;
        if (Auth::check()) {
            Auth::user()->update(['reduced_motion' => $this->reducedMotion]);
        }
        $this->dispatch('notify', 'Motion preference saved.');
    }

    public function updateContentFilters()
    {
        if (! Auth::check()) {
            return;
        }

        Auth::user()->update([
            'blur_nsfw' => $this->blurNsfw,
            'hide_nsfw' => $this->hideNsfw,
        ]);

        $this->dispatch('notify', '✓ Saved content filters.');
    }

    public function addMutedTag()
    {
        if (! Auth::check()) {
            return;
        }

        $tagName = trim($this->newMutedTag);
        if (empty($tagName)) {
            return;
        }

        $tag = Tag::where('name', $tagName)->first();
        if (! $tag) {
            $tag = Tag::create([
                'name' => $tagName,
                'slug' => \Illuminate\Support\Str::slug($tagName),
                'type' => 'general',
            ]);
        }

        $user = Auth::user();
        if (! $user->isTagBlacklisted($tag)) {
            $user->blacklistedTags()->attach($tag->id);
            $this->dispatch('notify', "Added #{$tag->name} to muted tags.");
        }

        $this->newMutedTag = '';
    }

    public function removeMutedTag(int $tagId)
    {
        if (! Auth::check()) {
            return;
        }
        Auth::user()->blacklistedTags()->detach($tagId);
        $this->dispatch('notify', 'Tag removed from muted list.');
    }

    public function unblockUser(int $userId): void
    {
        abort_unless(Auth::check(), 401);
        Auth::user()->blockedUsers()->detach($userId);
        $this->dispatch('notify', 'User unblocked.');
    }

    public function saveProfileMedia(): void
    {
        abort_unless(Auth::check(), 401);

        $this->validate([
            'avatarUpload' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:12288'],
            'bannerUpload' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:12288'],
        ]);

        $user = Auth::user();
        $user->update([
            'avatar_url' => $this->avatarUpload ? '/storage/'.$this->avatarUpload->storePublicly('avatars', 'public') : $user->avatar_url,
            'banner_url' => $this->bannerUpload ? '/storage/'.$this->bannerUpload->storePublicly('banners', 'public') : $user->banner_url,
        ]);

        $this->reset('avatarUpload', 'bannerUpload');
        $this->dispatch('notify', 'Profile images updated successfully!');
    }

    public function saveAccount()
    {
        if (! Auth::check()) {
            return;
        }

        $this->validate([
            'name' => 'required|string|max:60',
            'username' => 'required|string|max:40|unique:users,username,'.Auth::id(),
            'email' => 'required|email|unique:users,email,'.Auth::id(),
            'bio' => 'nullable|string|max:500',
            'website' => 'nullable|url|max:200',
        ]);

        Auth::user()->update([
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'bio' => $this->bio,
            'website' => $this->website,
        ]);

        $this->dispatch('notify', 'Account & profile settings updated!');
    }

    public function changePassword()
    {
        if (! Auth::check()) {
            return;
        }

        $this->validate([
            'currentPassword' => 'required',
            'newPassword' => 'required|min:8|confirmed',
        ]);

        if (! Hash::check($this->currentPassword, Auth::user()->password)) {
            $this->addError('currentPassword', 'Current password does not match.');

            return;
        }

        Auth::user()->update([
            'password' => Hash::make($this->newPassword),
        ]);

        $this->reset('currentPassword', 'newPassword', 'newPasswordConfirmation');
        $this->dispatch('notify', 'Password successfully changed.');
    }

    public function logoutAllOtherSessions(): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', session()->getId())
            ->delete();

        $this->dispatch('notify', 'Logged out of all other sessions.');
    }

    public function render()
    {
        $user = Auth::user();
        $blacklistedTags = $user ? $user->blacklistedTags()->get() : collect();
        $blockedUsers = $user ? $user->blockedUsers()->orderBy('username')->get() : collect();
        $sessions = $user
            ? DB::table('sessions')->where('user_id', $user->id)->orderByDesc('last_activity')->get()
            : collect();

        $allNavItems = [
            ['id' => 'account', 'label' => 'Account', 'icon' => '👤', 'group' => 'YOUR ACCOUNT'],
            ['id' => 'profile', 'label' => 'Profile', 'icon' => '🖼️', 'group' => 'YOUR ACCOUNT'],
            ['id' => 'privacy', 'label' => 'Privacy & Safety', 'icon' => '🔒', 'group' => 'YOUR ACCOUNT'],
            ['id' => 'security', 'label' => 'Security & Passwords', 'icon' => '🔑', 'group' => 'YOUR ACCOUNT'],

            ['id' => 'content', 'label' => 'Content & Ratings', 'icon' => '👁️', 'group' => 'CONTENT'],
            ['id' => 'feed', 'label' => 'Feed & Discovery', 'icon' => '✨', 'group' => 'CONTENT'],
            ['id' => 'tags', 'label' => 'Tags & Filters', 'icon' => '🏷️', 'group' => 'CONTENT'],
            ['id' => 'collections', 'label' => 'Collections', 'icon' => '📚', 'group' => 'CONTENT'],

            ['id' => 'messages', 'label' => 'Direct & Group Messages', 'icon' => '💬', 'group' => 'COMMUNICATION'],
            ['id' => 'lounges', 'label' => 'Lounges', 'icon' => '🛋️', 'group' => 'COMMUNICATION'],
            ['id' => 'notifications', 'label' => 'Notifications', 'icon' => '🔔', 'group' => 'COMMUNICATION'],

            ['id' => 'uploads', 'label' => 'Uploads & Media', 'icon' => '▶️', 'group' => 'PREFERENCES'],
            ['id' => 'appearance', 'label' => 'Appearance & Theme', 'icon' => '◐', 'group' => 'PREFERENCES'],
            ['id' => 'accessibility', 'label' => 'Accessibility', 'icon' => '♿', 'group' => 'PREFERENCES'],

            ['id' => 'blocked', 'label' => 'Blocked & Muted', 'icon' => '🚫', 'group' => 'DATA & PRIVACY'],
            ['id' => 'sessions', 'label' => 'Active Sessions', 'icon' => '💻', 'group' => 'DATA & PRIVACY'],
        ];

        $q = mb_strtolower(trim($this->searchQuery));
        if ($q !== '') {
            $filteredNavItems = array_filter($allNavItems, function ($item) use ($q) {
                return str_contains(mb_strtolower($item['label']), $q)
                    || str_contains(mb_strtolower($item['id']), $q)
                    || str_contains(mb_strtolower($item['group']), $q);
            });
        } else {
            $filteredNavItems = $allNavItems;
        }

        return view('components.⚡settings-view', [
            'currentUser' => $user,
            'blacklistedTags' => $blacklistedTags,
            'blockedUsers' => $blockedUsers,
            'sessions' => $sessions,
            'navItems' => $filteredNavItems,
            'allNavItems' => $allNavItems,
        ]);
    }
};
?>

<div class="max-w-7xl mx-auto px-2 sm:px-6 lg:px-8 py-4 sm:py-6 min-h-screen">
    
    <!-- Top Bar with Back Link -->
    <div class="mb-4 sm:mb-6 flex items-center justify-between gap-4 border-b border-[var(--border-subtle)] pb-4">
        <div class="flex items-center gap-3">
            <a href="{{ route('gallery') }}" class="group flex items-center gap-2 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] px-3 py-1.5 text-xs font-bold text-[var(--text-main)] transition hover:accent-bg hover:text-white">
                <svg class="h-4 w-4 transition-transform group-hover:-translate-x-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <span>Back to Site</span>
            </a>
            <div>
                <h1 class="text-xl sm:text-2xl font-black tracking-tight text-[var(--text-main)]">Settings</h1>
                <p class="hidden sm:block text-xs text-[var(--text-dim)]">Manage your preferences, privacy, feeds, and appearance.</p>
            </div>
        </div>
    </div>

    <!-- Responsive Settings Container -->
    <div class="rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] shadow-2xl overflow-hidden grid grid-cols-1 md:grid-cols-12 xl:grid-cols-12 min-h-[640px]">
        
        <!-- ========================================================================= -->
        <!-- 1. LEFT SIDEBAR: CATEGORIES LIST (Visible on md+ or when mobileScreen = list) -->
        <!-- ========================================================================= -->
        <div class="{{ $mobileScreen === 'detail' ? 'hidden md:block' : 'block' }} md:col-span-4 xl:col-span-3 border-r border-[var(--border-subtle)] p-3 space-y-3 bg-[var(--bg-surface)] overflow-y-auto max-h-[800px]">
            
            <!-- Search Bar -->
            <div class="relative">
                <input type="text" 
                       wire:model.live.debounce.150ms="searchQuery" 
                       placeholder="🔍 Search Settings..." 
                       class="w-full px-3.5 py-2 rounded-xl bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] text-xs text-[var(--text-main)] outline-none placeholder:text-[var(--text-dim)] focus:border-[var(--accent-primary)] transition">
            </div>

            <!-- Categorized Navigation List -->
            @php
                $currentGroup = '';
            @endphp

            <div class="space-y-3">
                @forelse($navItems as $item)
                    @if($item['group'] !== $currentGroup)
                        @php $currentGroup = $item['group']; @endphp
                        <div class="px-2 pt-2 text-[10px] font-black uppercase tracking-wider text-[var(--text-dim)]">
                            {{ $currentGroup }}
                        </div>
                    @endif

                    <button wire:click="setCategory('{{ $item['id'] }}')" 
                            class="flex items-center justify-between w-full px-3.5 py-3 sm:py-2.5 rounded-2xl text-xs font-extrabold text-left transition cursor-pointer min-h-[44px] {{ $activeCategory === $item['id'] ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:bg-[var(--bg-surface-elevated)] hover:text-[var(--text-main)]' }}">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="text-base shrink-0">{{ $item['icon'] }}</span>
                            <span class="truncate">{{ $item['label'] }}</span>
                        </div>
                        <span class="text-xs opacity-50 md:hidden">›</span>
                    </button>
                @empty
                    <p class="p-4 text-center text-xs text-[var(--text-dim)]">No settings matching "{{ $searchQuery }}"</p>
                @endforelse
            </div>
        </div>

        <!-- ========================================================================= -->
        <!-- 2. MIDDLE / MAIN CONTENT PANEL (Visible on md+ or when mobileScreen = detail) -->
        <!-- ========================================================================= -->
        <div class="{{ $mobileScreen === 'list' ? 'hidden md:block' : 'block' }} md:col-span-8 xl:col-span-6 p-4 sm:p-6 bg-[var(--bg-surface-elevated)] overflow-y-auto max-h-[800px] border-r border-[var(--border-subtle)]">
            
            <!-- Mobile Sticky Back Header (< 768px) -->
            <div class="md:hidden flex items-center gap-2 pb-4 mb-4 border-b border-[var(--border-subtle)]">
                <button wire:click="backToMobileList" class="flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs font-bold text-[var(--text-main)]">
                    <span>‹ Settings</span>
                </button>
                <h2 class="text-sm font-black text-[var(--text-main)] truncate capitalize">
                    {{ str_replace('_', ' ', $activeCategory) }}
                </h2>
            </div>

            {{-- 1. ACCOUNT --}}
            @if($activeCategory === 'account')
                <div class="space-y-6">
                    <div>
                        <h2 class="text-lg font-black text-[var(--text-main)]">Account Settings</h2>
                        <p class="text-xs text-[var(--text-dim)]">Manage your username, primary email, language, and regional preferences.</p>
                    </div>

                    <form wire:submit.prevent="saveAccount" class="space-y-4">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-bold mb-1 text-[var(--text-main)]">Username</label>
                                <input type="text" wire:model="username" class="w-full px-3.5 py-2.5 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-main)]">
                                @error('username') <span class="text-[11px] text-rose-400">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-bold mb-1 text-[var(--text-main)]">Display Name</label>
                                <input type="text" wire:model="name" class="w-full px-3.5 py-2.5 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-main)]">
                                @error('name') <span class="text-[11px] text-rose-400">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold mb-1 text-[var(--text-main)]">Email Address</label>
                            <input type="email" wire:model="email" class="w-full px-3.5 py-2.5 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-main)]">
                            @error('email') <span class="text-[11px] text-rose-400">{{ $message }}</span> @enderror
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs font-bold mb-1 text-[var(--text-main)]">Language</label>
                                <select wire:model="language" class="w-full px-3 py-2 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-main)]">
                                    <option value="en">English (US)</option>
                                    <option value="ja">日本語 (Japanese)</option>
                                    <option value="es">Español</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold mb-1 text-[var(--text-main)]">Region</label>
                                <select wire:model="region" class="w-full px-3 py-2 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-main)]">
                                    <option value="US">United States</option>
                                    <option value="JP">Japan</option>
                                    <option value="EU">Europe</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold mb-1 text-[var(--text-main)]">Time Zone</label>
                                <select wire:model="timezone" class="w-full px-3 py-2 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-main)]">
                                    <option value="UTC">UTC (GMT+0)</option>
                                    <option value="America/New_York">EST (GMT-5)</option>
                                    <option value="Asia/Tokyo">JST (GMT+9)</option>
                                </select>
                            </div>
                        </div>

                        <button type="submit" class="w-full sm:w-auto min-h-[44px] px-6 py-2.5 rounded-xl accent-bg text-white text-xs font-extrabold shadow-md cursor-pointer">
                            Save Account Changes
                        </button>
                    </form>
                </div>
            @endif

            {{-- 2. PROFILE --}}
            @if($activeCategory === 'profile')
                <div class="space-y-6">
                    <div>
                        <h2 class="text-lg font-black text-[var(--text-main)]">Profile Customization</h2>
                        <p class="text-xs text-[var(--text-dim)]">Customize your public bio, social links, and public profile visibility throughout the gallery and lounges.</p>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold mb-1 text-[var(--text-main)]">Bio</label>
                            <textarea wire:model="bio" rows="3" class="w-full p-3 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-main)]" placeholder="Tell the community about yourself..."></textarea>
                        </div>

                        <div>
                            <label class="block text-xs font-bold mb-1 text-[var(--text-main)]">Website / Portfolio Link</label>
                            <input type="url" wire:model="website" class="w-full px-3.5 py-2.5 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-main)]" placeholder="https://yourportfolio.art">
                        </div>

                        <div class="space-y-4 rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-4">
                            <div>
                                <h3 class="text-xs font-bold text-[var(--text-main)]">Profile Images</h3>
                                <p class="mt-1 text-xs text-[var(--text-dim)]">Choose files from your computer, tablet, or phone.</p>
                            </div>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="block text-xs font-bold mb-1 text-[var(--text-main)]">Avatar</label>
                                    <input type="file" wire:model="avatarUpload" accept="image/jpeg,image/png,image/webp" class="block w-full text-xs text-[var(--text-muted)]">
                                    @error('avatarUpload') <p class="mt-1 text-xs text-rose-400">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-bold mb-1 text-[var(--text-main)]">Banner</label>
                                    <input type="file" wire:model="bannerUpload" accept="image/jpeg,image/png,image/webp" class="block w-full text-xs text-[var(--text-muted)]">
                                    @error('bannerUpload') <p class="mt-1 text-xs text-rose-400">{{ $message }}</p> @enderror
                                </div>
                            </div>
                            <button type="button" wire:click="saveProfileMedia" class="w-full sm:w-auto min-h-[44px] px-5 py-2.5 rounded-xl accent-bg text-white text-xs font-extrabold shadow-md">Save Profile Images</button>
                        </div>

                        <div class="space-y-3 pt-4 border-t border-[var(--border-subtle)]">
                            <h3 class="text-xs font-bold text-[var(--text-main)]">Public Profile Modules</h3>

                            <label class="flex items-center gap-3 cursor-pointer min-h-[44px]">
                                <input type="checkbox" wire:model="showJoinDate" class="w-4 h-4 rounded accent-bg">
                                <span class="text-xs text-[var(--text-main)]">Show Join Date on profile</span>
                            </label>

                            <label class="flex items-center gap-3 cursor-pointer min-h-[44px]">
                                <input type="checkbox" wire:model="showFollowerCount" class="w-4 h-4 rounded accent-bg">
                                <span class="text-xs text-[var(--text-main)]">Show Follower count</span>
                            </label>

                            <label class="flex items-center gap-3 cursor-pointer min-h-[44px]">
                                <input type="checkbox" wire:model="showLikesTab" class="w-4 h-4 rounded accent-bg">
                                <span class="text-xs text-[var(--text-main)]">Show Likes tab to visitors</span>
                            </label>

                            <label class="flex items-center gap-3 cursor-pointer min-h-[44px]">
                                <input type="checkbox" wire:model="showCollectionsTab" class="w-4 h-4 rounded accent-bg">
                                <span class="text-xs text-[var(--text-main)]">Show Collections tab to visitors</span>
                            </label>
                        </div>

                        <button wire:click="saveAccount" class="w-full sm:w-auto min-h-[44px] px-6 py-2.5 rounded-xl accent-bg text-white text-xs font-extrabold shadow-md cursor-pointer">
                            Save Profile Preferences
                        </button>
                    </div>
                </div>
            @endif

            {{-- 3. PRIVACY & SAFETY --}}
            @if($activeCategory === 'privacy')
                <div class="space-y-6">
                    <div>
                        <h2 class="text-lg font-black text-[var(--text-main)]">Privacy & Safety</h2>
                        <p class="text-xs text-[var(--text-dim)]">Control who can follow you, leave comments, mention your username, or see your activity.</p>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold mb-1 text-[var(--text-main)]">Who can follow me?</label>
                            <select wire:model="whoCanFollow" class="w-full px-3.5 py-2.5 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-main)]">
                                <option value="everyone">Everyone (Instant Follow)</option>
                                <option value="approval">Approval Required (Follow Requests)</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold mb-1 text-[var(--text-main)]">Who can comment on my uploads?</label>
                            <select wire:model="whoCanComment" class="w-full px-3.5 py-2.5 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-main)]">
                                <option value="everyone">Everyone</option>
                                <option value="followers">Followers Only</option>
                                <option value="following">People I Follow Only</option>
                                <option value="nobody">Nobody</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold mb-1 text-[var(--text-main)]">Who can mention me?</label>
                            <select wire:model="whoCanMention" class="w-full px-3.5 py-2.5 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-main)]">
                                <option value="everyone">Everyone</option>
                                <option value="following">People I Follow Only</option>
                                <option value="nobody">Nobody</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold mb-1 text-[var(--text-main)]">Who can see my Likes?</label>
                            <select wire:model="likesVisibility" class="w-full px-3.5 py-2.5 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-main)]">
                                <option value="everyone">Everyone</option>
                                <option value="followers">Followers Only</option>
                                <option value="only_me">Only Me</option>
                            </select>
                        </div>
                    </div>
                </div>
            @endif

            {{-- 4. CONTENT & RATINGS --}}
            @if($activeCategory === 'content')
                <div class="space-y-6">
                    <div>
                        <h2 class="text-lg font-black text-[var(--text-main)]">Content Filters & Ratings</h2>
                        <p class="text-xs text-[var(--text-dim)]">Set rating tolerances and thumbnail blur options for sensitive booru content.</p>
                    </div>

                    <div class="space-y-4">
                        <div class="p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-3">
                            <h3 class="text-xs font-bold text-[var(--text-main)]">Booru Rating Eligibility</h3>
                            <label class="flex items-center gap-3 cursor-pointer min-h-[44px]">
                                <input type="checkbox" wire:model="ratingSafe" class="w-4 h-4 rounded accent-bg">
                                <span class="text-xs font-semibold text-emerald-400">Safe (SFW Content)</span>
                            </label>
                            <label class="flex items-center gap-3 cursor-pointer min-h-[44px]">
                                <input type="checkbox" wire:model="ratingQuestionable" class="w-4 h-4 rounded accent-bg">
                                <span class="text-xs font-semibold text-amber-400">Questionable (Ecchi & Mild Content)</span>
                            </label>
                            <label class="flex items-center gap-3 cursor-pointer min-h-[44px]">
                                <input type="checkbox" wire:model="ratingExplicit" class="w-4 h-4 rounded accent-bg">
                                <span class="text-xs font-semibold text-rose-400">Explicit (NSFW 18+ Content)</span>
                            </label>
                        </div>

                        <div class="p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-3">
                            <h3 class="text-xs font-bold text-[var(--text-main)]">Sensitive Thumbnail Behavior</h3>
                            <label class="flex items-center gap-3 cursor-pointer min-h-[44px]">
                                <input type="checkbox" wire:model="blurNsfw" wire:change="updateContentFilters" class="w-4 h-4 rounded accent-bg">
                                <span class="text-xs text-[var(--text-main)]">Blur sensitive images until hovered/clicked</span>
                            </label>
                            <label class="flex items-center gap-3 cursor-pointer min-h-[44px]">
                                <input type="checkbox" wire:model="hideNsfw" wire:change="updateContentFilters" class="w-4 h-4 rounded accent-bg">
                                <span class="text-xs text-[var(--text-main)]">Completely hide sensitive content from search & feed</span>
                            </label>
                        </div>
                    </div>
                </div>
            @endif

            {{-- 5. FEED & DISCOVERY --}}
            @if($activeCategory === 'feed')
                <div class="space-y-6">
                    <div>
                        <h2 class="text-lg font-black text-[var(--text-main)]">Feed & Discovery Recommendation Signals</h2>
                        <p class="text-xs text-[var(--text-dim)]">Control how the algorithm builds your personalized For You feed and Following stream.</p>
                    </div>

                    <div class="space-y-4">
                        <div class="p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-3">
                            <h3 class="text-xs font-bold text-[var(--text-main)]">Signals Used for For You Feed</h3>
                            <label class="flex items-center gap-3 cursor-pointer min-h-[44px]">
                                <input type="checkbox" wire:model="feedUseLikes" class="w-4 h-4 rounded accent-bg">
                                <span class="text-xs text-[var(--text-main)]">Include liked posts</span>
                            </label>
                            <label class="flex items-center gap-3 cursor-pointer min-h-[44px]">
                                <input type="checkbox" wire:model="feedUseFollowedTags" class="w-4 h-4 rounded accent-bg">
                                <span class="text-xs text-[var(--text-main)]">Include followed tags & artists</span>
                            </label>
                            <label class="flex items-center gap-3 cursor-pointer min-h-[44px]">
                                <input type="checkbox" wire:model="feedUseRecentlyViewed" class="w-4 h-4 rounded accent-bg">
                                <span class="text-xs text-[var(--text-main)]">Include recently viewed posts</span>
                            </label>
                        </div>
                    </div>
                </div>
            @endif

            {{-- 6. MESSAGES --}}
            @if($activeCategory === 'messages')
                <div class="space-y-6">
                    <div>
                        <h2 class="text-lg font-black text-[var(--text-main)]">Direct & Group Messaging Settings</h2>
                        <p class="text-xs text-[var(--text-dim)]">Configure private conversation permissions, message requests, and chat status.</p>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold mb-1 text-[var(--text-main)]">Who can DM me?</label>
                            <select wire:model="whoCanDm" class="w-full px-3.5 py-2.5 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-main)]">
                                <option value="everyone">Everyone</option>
                                <option value="following">People I Follow</option>
                                <option value="mutuals">Mutual Follows Only</option>
                                <option value="nobody">Nobody</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold mb-1 text-[var(--text-main)]">Who can add me to Group DMs?</label>
                            <select wire:model="whoCanAddGroupDm" class="w-full px-3.5 py-2.5 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-main)]">
                                <option value="everyone">Everyone</option>
                                <option value="following">People I Follow</option>
                                <option value="mutuals">Mutual Follows Only</option>
                            </select>
                        </div>

                        <div class="p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-3">
                            <label class="flex items-center gap-3 cursor-pointer min-h-[44px]">
                                <input type="checkbox" wire:model="routeUnknownToRequests" class="w-4 h-4 rounded accent-bg">
                                <span class="text-xs font-semibold text-[var(--text-main)]">Send unknown user DMs to Message Requests inbox</span>
                            </label>
                            <label class="flex items-center gap-3 cursor-pointer min-h-[44px]">
                                <input type="checkbox" wire:model="showOnlineStatus" class="w-4 h-4 rounded accent-bg">
                                <span class="text-xs text-[var(--text-main)]">Show Online Presence Status</span>
                            </label>
                            <label class="flex items-center gap-3 cursor-pointer min-h-[44px]">
                                <input type="checkbox" wire:model="showTypingIndicator" class="w-4 h-4 rounded accent-bg">
                                <span class="text-xs text-[var(--text-main)]">Show Typing Indicator when writing messages</span>
                            </label>
                            <label class="flex items-center gap-3 cursor-pointer min-h-[44px]">
                                <input type="checkbox" wire:model="sendReadReceipts" class="w-4 h-4 rounded accent-bg">
                                <span class="text-xs text-[var(--text-main)]">Send Read Receipts</span>
                            </label>
                        </div>
                    </div>
                </div>
            @endif

            {{-- 7. LOUNGES --}}
            @if($activeCategory === 'lounges')
                <div class="space-y-6">
                    <div>
                        <h2 class="text-lg font-black text-[var(--text-main)]">Lounge Preferences</h2>
                        <p class="text-xs text-[var(--text-dim)]">Manage default upload behaviors and global community server notifications.</p>
                    </div>

                    <div class="space-y-4">
                        <div class="p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-3">
                            <h3 class="text-xs font-bold text-[var(--text-main)]">Default Lounge Upload Mode</h3>
                            <label class="flex items-center gap-3 cursor-pointer min-h-[44px]">
                                <input type="radio" wire:model="loungeUploadDefault" value="lounge_only" class="w-4 h-4 accent-bg">
                                <span class="text-xs text-[var(--text-main)] font-bold">Lounge Only by Default (Casual chat media, no tags required)</span>
                            </label>
                            <label class="flex items-center gap-3 cursor-pointer min-h-[44px]">
                                <input type="radio" wire:model="loungeUploadDefault" value="remember" class="w-4 h-4 accent-bg">
                                <span class="text-xs text-[var(--text-main)]">Remember Last Upload Choice</span>
                            </label>
                            <label class="flex items-center gap-3 cursor-pointer min-h-[44px]">
                                <input type="radio" wire:model="loungeUploadDefault" value="ask" class="w-4 h-4 accent-bg">
                                <span class="text-xs text-[var(--text-main)]">Prompt every time media is attached</span>
                            </label>
                        </div>
                    </div>
                </div>
            @endif

            {{-- 8. NOTIFICATIONS --}}
            @if($activeCategory === 'notifications')
                <div class="space-y-6">
                    <div>
                        <h2 class="text-lg font-black text-[var(--text-main)]">Notification Preferences</h2>
                        <p class="text-xs text-[var(--text-dim)]">Choose what events notify you and how they are delivered.</p>
                    </div>

                    <div class="p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-3">
                        <label class="flex items-center gap-3 cursor-pointer min-h-[44px]">
                            <input type="checkbox" wire:model="notifyFollows" class="w-4 h-4 rounded accent-bg">
                            <span class="text-xs text-[var(--text-main)]">New followers & follow requests</span>
                        </label>
                        <label class="flex items-center gap-3 cursor-pointer min-h-[44px]">
                            <input type="checkbox" wire:model="notifyLikes" class="w-4 h-4 rounded accent-bg">
                            <span class="text-xs text-[var(--text-main)]">Likes on your posts</span>
                        </label>
                        <label class="flex items-center gap-3 cursor-pointer min-h-[44px]">
                            <input type="checkbox" wire:model="notifyComments" class="w-4 h-4 rounded accent-bg">
                            <span class="text-xs text-[var(--text-main)]">Comments & replies</span>
                        </label>
                        <label class="flex items-center gap-3 cursor-pointer min-h-[44px]">
                            <input type="checkbox" wire:model="notifyDirectMessages" class="w-4 h-4 rounded accent-bg">
                            <span class="text-xs text-[var(--text-main)]">Direct messages & group invites</span>
                        </label>
                    </div>
                </div>
            @endif

            {{-- 9. UPLOADS & MEDIA --}}
            @if($activeCategory === 'uploads')
                <div class="space-y-6">
                    <div>
                        <h2 class="text-lg font-black text-[var(--text-main)]">Uploads & Media Defaults</h2>
                        <p class="text-xs text-[var(--text-dim)]">Set default ratings and comments settings for new gallery posts.</p>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold mb-1 text-[var(--text-main)]">Default Rating</label>
                            <select wire:model="defaultRating" class="w-full px-3.5 py-2.5 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-main)]">
                                <option value="Safe">Safe (SFW)</option>
                                <option value="Questionable">Questionable</option>
                                <option value="Explicit">Explicit (NSFW)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold mb-1 text-[var(--text-main)]">Default Visibility</label>
                            <select wire:model="defaultVisibility" class="w-full px-3.5 py-2.5 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-main)]">
                                <option value="Public">Public</option>
                                <option value="Unlisted">Unlisted</option>
                                <option value="Private">Private</option>
                            </select>
                        </div>
                    </div>
                </div>
            @endif

            {{-- 10. TAGS & FILTERS --}}
            @if($activeCategory === 'tags')
                <div class="space-y-6">
                    <div>
                        <h2 class="text-lg font-black text-[var(--text-main)]">Muted Tags & Content Filters</h2>
                        <p class="text-xs text-[var(--text-dim)]">Add tags to your personal blacklist to exclude matching artwork across the site.</p>
                    </div>

                    <form wire:submit.prevent="addMutedTag" class="flex gap-2">
                        <input type="text" wire:model="newMutedTag" placeholder="e.g. spoiler, horror, ai_generated" class="flex-1 px-3.5 py-2.5 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-main)]">
                        <button type="submit" class="px-4 py-2.5 rounded-xl accent-bg text-white text-xs font-bold min-h-[44px]">Mute Tag</button>
                    </form>

                    <div class="flex flex-wrap gap-2">
                        @forelse($blacklistedTags as $tag)
                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-rose-500/15 border border-rose-500/30 text-rose-400 text-xs font-bold">
                                #{{ $tag->name }}
                                <button type="button" wire:click="removeMutedTag({{ $tag->id }})" class="hover:text-white">✕</button>
                            </span>
                        @empty
                            <p class="text-xs text-[var(--text-dim)] italic">No muted tags currently active.</p>
                        @endforelse
                    </div>
                </div>
            @endif

            {{-- 11. COLLECTIONS --}}
            @if($activeCategory === 'collections')
                <div class="space-y-6">
                    <div>
                        <h2 class="text-lg font-black text-[var(--text-main)]">Collection Defaults</h2>
                        <p class="text-xs text-[var(--text-dim)]">Set default privacy for new art collections and series folders.</p>
                    </div>

                    <div>
                        <label class="block text-xs font-bold mb-1 text-[var(--text-main)]">Default Visibility for New Collections</label>
                        <select wire:model="defaultCollectionVisibility" class="w-full px-3.5 py-2.5 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-main)]">
                            <option value="Private">Private (Only Me & Invited Collaborators)</option>
                            <option value="Unlisted">Unlisted (Anyone with Link)</option>
                            <option value="Public">Public (Discoverable & Shareable)</option>
                        </select>
                    </div>
                </div>
            @endif

            {{-- 12. APPEARANCE --}}
            @if($activeCategory === 'appearance')
                <div class="space-y-6">
                    <div>
                        <h2 class="text-lg font-black text-[var(--text-main)]">Appearance & Color Palette</h2>
                        <p class="text-xs text-[var(--text-dim)]">Customize dark mode, accent palettes, and display font scaling.</p>
                    </div>

                    <div class="space-y-3">
                        <h3 class="text-xs font-bold text-[var(--text-main)]">Accent Color Palette</h3>
                        <div class="flex flex-wrap gap-3">
                            @foreach(['violet' => 'bg-violet-600', 'emerald' => 'bg-emerald-600', 'sky' => 'bg-sky-600', 'rose' => 'bg-rose-600', 'amber' => 'bg-amber-600'] as $paletteKey => $bgClass)
                                <button wire:click="updateTheme('dark', '{{ $paletteKey }}')" 
                                        class="flex items-center gap-2 px-3.5 py-2.5 rounded-xl border transition min-h-[44px] cursor-pointer {{ $themePalette === $paletteKey ? 'border-white shadow-lg scale-105' : 'border-[var(--border-subtle)]' }}">
                                    <span class="w-4 h-4 rounded-full {{ $bgClass }}"></span>
                                    <span class="text-xs font-bold text-[var(--text-main)] capitalize">{{ $paletteKey }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="space-y-3">
                        <h3 class="text-xs font-bold text-[var(--text-main)]">Text Size</h3>
                        <div class="flex flex-wrap gap-3">
                            @foreach(['sm' => 'Small', 'md' => 'Medium', 'lg' => 'Large'] as $sizeKey => $sizeLabel)
                                <button wire:click="updateFontSize('{{ $sizeKey }}')"
                                        class="flex items-center gap-2 px-3.5 py-2.5 rounded-xl border transition min-h-[44px] cursor-pointer {{ $fontSize === $sizeKey ? 'border-white shadow-lg scale-105' : 'border-[var(--border-subtle)]' }}">
                                    <span class="text-xs font-bold text-[var(--text-main)]">{{ $sizeLabel }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            {{-- 13. ACCESSIBILITY --}}
            @if($activeCategory === 'accessibility')
                <div class="space-y-6">
                    <div>
                        <h2 class="text-lg font-black text-[var(--text-main)]">Accessibility Preferences</h2>
                        <p class="text-xs text-[var(--text-dim)]">Interface contrast, animation reductions, and visual aid options.</p>
                    </div>

                    <div class="p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-3">
                        <label class="flex items-center gap-3 cursor-pointer min-h-[44px]">
                            <input type="checkbox" wire:model="reducedMotion" wire:change="toggleReducedMotion" class="w-4 h-4 rounded accent-bg">
                            <span class="text-xs text-[var(--text-main)]">Reduce UI Animations & Motion</span>
                        </label>
                    </div>
                </div>
            @endif

            {{-- 14. SECURITY --}}
            @if($activeCategory === 'security')
                <div class="space-y-6">
                    <div>
                        <h2 class="text-lg font-black text-[var(--text-main)]">Security & Password</h2>
                        <p class="text-xs text-[var(--text-dim)]">Update your password and manage account security settings.</p>
                    </div>

                    <form wire:submit.prevent="changePassword" class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold mb-1 text-[var(--text-main)]">Current Password</label>
                            <input type="password" wire:model="currentPassword" class="w-full px-3.5 py-2.5 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-main)]">
                            @error('currentPassword') <span class="text-[11px] text-rose-400">{{ $message }}</span> @enderror
                        </div>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-bold mb-1 text-[var(--text-main)]">New Password</label>
                                <input type="password" wire:model="newPassword" class="w-full px-3.5 py-2.5 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-main)]">
                                @error('newPassword') <span class="text-[11px] text-rose-400">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-bold mb-1 text-[var(--text-main)]">Confirm New Password</label>
                                <input type="password" wire:model="newPasswordConfirmation" class="w-full px-3.5 py-2.5 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-main)]">
                            </div>
                        </div>
                        <button type="submit" class="w-full sm:w-auto min-h-[44px] px-6 py-2.5 rounded-xl accent-bg text-white text-xs font-extrabold shadow-md cursor-pointer">Update Password</button>
                    </form>
                </div>
            @endif

            {{-- 15. BLOCKED & MUTED --}}
            @if($activeCategory === 'blocked')
                <div class="space-y-6">
                    <div>
                        <h2 class="text-lg font-black text-[var(--text-main)]">Blocked & Muted Users</h2>
                        <p class="text-xs text-[var(--text-dim)]">Manage accounts you have blocked or muted across gallery comments and lounges.</p>
                    </div>

                    <div class="space-y-2">
                        @forelse($blockedUsers as $blocked)
                            <div class="flex items-center justify-between p-3 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)]">
                                <div class="flex items-center gap-3">
                                    <img src="{{ $blocked->avatar_url }}" class="w-8 h-8 rounded-full object-cover">
                                    <div>
                                        <div class="font-bold text-xs text-[var(--text-main)]">{{ $blocked->name }}</div>
                                        <div class="text-[10px] text-[var(--text-dim)]">@<span>{{ $blocked->username }}</span></div>
                                    </div>
                                </div>
                                <button wire:click="unblockUser({{ $blocked->id }})" class="min-h-[44px] px-3 py-1 rounded-xl bg-rose-500/15 text-rose-400 text-xs font-bold hover:bg-rose-500 hover:text-white transition">Unblock</button>
                            </div>
                        @empty
                            <p class="text-xs text-[var(--text-dim)] italic">No blocked users currently.</p>
                        @endforelse
                    </div>
                </div>
            @endif

            {{-- 16. SESSIONS --}}
            @if($activeCategory === 'sessions')
                <div class="space-y-6">
                    <div>
                        <h2 class="text-lg font-black text-[var(--text-main)]">Active Sessions</h2>
                        <p class="text-xs text-[var(--text-dim)]">Devices currently logged into your account.</p>
                    </div>

                    @php($currentSessionId = session()->getId())
                    <div class="space-y-3">
                        @forelse($sessions as $session)
                            @php($isCurrent = $session->id === $currentSessionId)
                            @php($agent = $session->user_agent ?? '')
                            @php($isMobile = (bool) preg_match('/Mobile|Android|iPhone|iPad/i', $agent))
                            @php($browser = \Illuminate\Support\Str::contains($agent, 'Firefox') ? 'Firefox' : (\Illuminate\Support\Str::contains($agent, ['Edg', 'Chrome']) ? 'Chrome' : (\Illuminate\Support\Str::contains($agent, 'Safari') ? 'Safari' : 'Unknown browser')))
                            <div class="flex items-center justify-between gap-3 p-4 rounded-2xl bg-[var(--bg-surface)] border {{ $isCurrent ? 'border-emerald-500/30' : 'border-[var(--border-subtle)]' }}">
                                <div class="flex items-center gap-3 min-w-0">
                                    <span class="text-xl">{{ $isMobile ? '📱' : '💻' }}</span>
                                    <div class="min-w-0">
                                        <div class="font-bold text-xs text-[var(--text-main)] truncate">
                                            {{ $isMobile ? 'Mobile device' : 'Desktop browser' }}
                                            @if($isCurrent)
                                                <span class="ml-1 text-[10px] font-bold text-emerald-400">Active now • This device</span>
                                            @endif
                                        </div>
                                        <div class="text-[10px] text-[var(--text-dim)] truncate">
                                            {{ $browser }} · {{ $session->ip_address ?: 'unknown IP' }} · Last active {{ \Illuminate\Support\Carbon::createFromTimestamp($session->last_activity)->diffForHumans() }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-dim)]">No active sessions recorded yet.</div>
                        @endforelse

                        <button wire:click="logoutAllOtherSessions" class="w-full sm:w-auto min-h-[44px] px-4 py-2.5 rounded-xl bg-rose-500/15 border border-rose-500/30 text-rose-400 text-xs font-extrabold hover:bg-rose-500 hover:text-white transition cursor-pointer">
                            Log Out All Other Sessions
                        </button>
                    </div>
                </div>
            @endif

        </div>

        <!-- ========================================================================= -->
        <!-- 3. RIGHT PANEL: LIVE CONTEXT & APPEARANCE PREVIEW (Visible on xl+ 1280px+) -->
        <!-- ========================================================================= -->
        <div class="hidden xl:block xl:col-span-3 p-4 bg-[var(--bg-surface)] border-l border-[var(--border-subtle)] space-y-4">
            <h3 class="text-xs font-black uppercase tracking-wider text-[var(--text-dim)]">Live Preview & Info</h3>

            <!-- Live Card Preview -->
            <div class="p-4 rounded-2xl bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] space-y-3 shadow-md">
                <div class="flex items-center gap-2">
                    <div class="w-6 h-6 rounded-full accent-bg text-white text-[10px] font-bold flex items-center justify-center">B</div>
                    <div class="text-xs font-bold text-[var(--text-main)]">Artwork Card Preview</div>
                </div>
                
                <div class="relative aspect-video rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] overflow-hidden flex items-center justify-center text-xs text-[var(--text-dim)]">
                    <span class="{{ $blurNsfw ? 'blur-sm' : '' }}">Artwork Image</span>
                </div>

                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-semibold text-[var(--text-muted)]">Theme Mode</span>
                    <span class="text-[11px] font-bold accent-text capitalize">{{ $themeMode }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-semibold text-[var(--text-muted)]">Accent Color</span>
                    <span class="text-[11px] font-bold accent-text capitalize">{{ $themePalette }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-semibold text-[var(--text-muted)]">Font Scale</span>
                    <span class="text-[11px] font-bold accent-text uppercase">{{ $fontSize }}</span>
                </div>
            </div>

            <!-- Contextual Help Box -->
            <div class="p-4 rounded-2xl bg-sky-500/10 border border-sky-500/20 text-xs text-[var(--text-main)] space-y-2">
                <div class="font-bold text-sky-400 flex items-center gap-1.5">
                    <span>💡 Contextual Help</span>
                </div>
                <p class="text-[11px] text-[var(--text-muted)] leading-relaxed">
                    Changes made to immediate settings (themes, ratings, filters) auto-save instantly across your device.
                </p>
            </div>
        </div>

    </div>
</div>
