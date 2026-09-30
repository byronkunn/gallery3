<?php

use App\Models\Collection;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Pool;
use App\Models\Post;
use App\Models\User;
use App\Support\ContentReports;
use App\Support\Notifier;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public string $username;

    public string $activeTab = 'posts'; // 'posts', 'media', 'likes', 'collections', 'pools'

    public bool $editModalOpen = false;

    public bool $followModalOpen = false;

    public bool $reportModalOpen = false;

    public string $reportReason = '';

    public string $reportDetails = '';

    public string $followModalTab = 'followers'; // 'followers' or 'following'

    // Commission Modal Fields
    public bool $commissionModalOpen = false;

    public string $commissionCategory = 'Illustration';

    public string $commissionBudget = '$100 - $250';

    public string $commissionDeadline = '2 Weeks';

    public string $commissionDescription = '';

    // Edit form fields
    public string $editName = '';

    public string $editBio = '';

    public string $editWebsite = '';

    public string $editCommissionStatus = 'Open';

    public mixed $avatarUpload = null;

    public mixed $bannerUpload = null;

    protected $queryString = ['activeTab' => ['except' => 'posts']];

    public function mount(string $username)
    {
        $this->username = $username;
        $user = User::where('username', $username)->firstOrFail();
        if (Auth::check() && Auth::id() !== $user->id && (Auth::user()->blockedUsers()->whereKey($user->id)->exists() || $user->blockedUsers()->whereKey(Auth::id())->exists())) {
            abort(404);
        }

        $this->editName = $user->name;
        $this->editBio = $user->bio ?? '';
        $this->editWebsite = $user->website ?? '';
        $this->editCommissionStatus = $user->commission_status ?? 'Open';
    }

    public function setTab(string $tab)
    {
        $this->activeTab = $tab;
    }

    public function openFollowModal(string $tab = 'followers')
    {
        $this->followModalTab = $tab;
        $this->followModalOpen = true;
    }

    public function closeFollowModal()
    {
        $this->followModalOpen = false;
    }

    public function toggleFollowUser(int $targetUserId)
    {
        if (! Auth::check()) {
            $this->dispatch('notify', 'Please log in to follow users');

            return;
        }

        $currentUser = Auth::user();
        if ($currentUser->id === $targetUserId) {
            return;
        }

        $targetUser = User::find($targetUserId);
        if (! $targetUser) {
            return;
        }

        if ($currentUser->isFollowing($targetUser)) {
            $currentUser->following()->detach($targetUser->id);
            $this->dispatch('notify', "Unfollowed @{$targetUser->username}");
        } else {
            $currentUser->following()->attach($targetUser->id);
            Notifier::follow($targetUser, $currentUser);
            $this->dispatch('notify', "Followed @{$targetUser->username}");
        }
    }

    public function submitUserReport(): void
    {
        abort_unless(Auth::check(), 401);
        $targetUser = User::where('username', $this->username)->firstOrFail();

        $validated = $this->validate([
            'reportReason' => ['required', 'string', 'max:80'],
            'reportDetails' => ['nullable', 'string', 'max:1000'],
        ]);

        $filed = ContentReports::file(Auth::user(), 'user', $targetUser->id, $validated['reportReason'], $validated['reportDetails']);

        $this->reset('reportReason', 'reportDetails');
        $this->reportModalOpen = false;
        $this->dispatch('notify', $filed ? 'Report sent to the moderation team.' : 'You already reported this account.');
    }

    public function toggleBlockUser(): mixed
    {
        abort_unless(Auth::check(), 401);
        $targetUser = User::where('username', $this->username)->firstOrFail();
        abort_if(Auth::id() === $targetUser->id, 422);

        $currentUser = Auth::user();
        if ($currentUser->blockedUsers()->whereKey($targetUser->id)->exists()) {
            $currentUser->blockedUsers()->detach($targetUser->id);
            $this->dispatch('notify', 'Artist unblocked.');
        } else {
            $currentUser->blockedUsers()->syncWithoutDetaching([$targetUser->id]);
            $currentUser->following()->detach($targetUser->id);
            $this->dispatch('notify', 'Artist blocked. Their profile and posts are hidden.');
            return redirect()->route('gallery');
        }
    }

    public function submitCommissionInquiry()
    {
        if (! Auth::check()) {
            $this->dispatch('notify', 'Please log in to request commissions');

            return;
        }

        $currentUser = Auth::user();
        if (! \App\Support\SiteSettings::bool('site_global_commissions')) {
            $this->dispatch('notify', 'Commission inquiries are currently paused.');

            return;
        }
        $targetUser = User::where('username', $this->username)->firstOrFail();
        if ($currentUser->id === $targetUser->id) {
            return;
        }

        $this->validate([
            'commissionCategory' => 'required|string',
            'commissionBudget' => 'required|string',
            'commissionDescription' => 'required|string|min:5|max:1000',
        ]);

        $conversation = Conversation::where(function ($q) use ($currentUser, $targetUser) {
            $q->where('user_one_id', $currentUser->id)->where('user_two_id', $targetUser->id);
        })->orWhere(function ($q) use ($currentUser, $targetUser) {
            $q->where('user_one_id', $targetUser->id)->where('user_two_id', $currentUser->id);
        })->first();

        if (! $conversation) {
            $conversation = Conversation::create([
                'user_one_id' => $currentUser->id,
                'user_two_id' => $targetUser->id,
                'last_message_at' => now(),
            ]);
        }

        $formattedText = "🎨 COMMISSION INQUIRY\n\n".
                        "Category: {$this->commissionCategory}\n".
                        "Budget: {$this->commissionBudget}\n".
                        "Deadline: {$this->commissionDeadline}\n\n".
                        "Brief:\n{$this->commissionDescription}";

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $currentUser->id,
            'text' => $formattedText,
            'is_read' => false,
        ]);

        $conversation->update(['last_message_at' => now()]);

        $this->commissionModalOpen = false;
        $this->commissionDescription = '';
        $this->dispatch('notify', "Commission inquiry sent to @{$targetUser->username}! Check your Direct Messages.");
    }

    public function toggleFollow()
    {
        if (! Auth::check()) {
            $this->dispatch('notify', 'Please log in to follow users');

            return;
        }

        $targetUser = User::where('username', $this->username)->firstOrFail();
        $currentUser = Auth::user();

        if ($currentUser->id === $targetUser->id) {
            return;
        }

        if ($currentUser->isFollowing($targetUser)) {
            $currentUser->following()->detach($targetUser->id);
            $this->dispatch('notify', "Unfollowed @{$targetUser->username}");
        } else {
            $currentUser->following()->attach($targetUser->id);
            Notifier::follow($targetUser, $currentUser);
            $this->dispatch('notify', "Followed @{$targetUser->username}");
        }
    }

    public function saveProfile()
    {
        if (! Auth::check()) {
            return;
        }
        $user = Auth::user();
        if ($user->username !== $this->username) {
            return;
        }

        $this->validate([
            'editName' => 'required|string|max:60',
            'editBio' => 'nullable|string|max:500',
            'editWebsite' => 'nullable|url|max:200',
            'editCommissionStatus' => 'required|in:Open,Closed,Waitlist',
            'avatarUpload' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:12288'],
            'bannerUpload' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:12288'],
        ]);

        $avatarUrl = $this->avatarUpload
            ? '/storage/'.$this->avatarUpload->storePublicly('avatars', 'public')
            : $user->avatar_url;
        $bannerUrl = $this->bannerUpload
            ? '/storage/'.$this->bannerUpload->storePublicly('banners', 'public')
            : $user->banner_url;

        $user->update([
            'name' => $this->editName,
            'bio' => $this->editBio,
            'website' => $this->editWebsite,
            'commission_status' => $this->editCommissionStatus,
            'avatar_url' => $avatarUrl,
            'banner_url' => $bannerUrl,
        ]);
        $this->reset('avatarUpload', 'bannerUpload');

        $this->editModalOpen = false;
        $this->dispatch('notify', 'Profile updated successfully!');
    }

    public function render()
    {
        $profileUser = User::where('username', $this->username)->firstOrFail();
        $currentUser = Auth::user();
        $isMe = $currentUser && $currentUser->id === $profileUser->id;
        $isFollowing = $currentUser ? $currentUser->isFollowing($profileUser) : false;

        // Content queries
        $posts = $profileUser->posts()->with(['primaryMedia', 'tags', 'user'])->latest()->get();

        // Media items for Media tab
        $mediaPosts = $profileUser->posts()->with('media')->latest()->get();
        $mediaFlatList = [];
        foreach ($mediaPosts as $mp) {
            foreach ($mp->media as $m) {
                $mediaFlatList[] = [
                    'url' => $m->url,
                    'thumbnail_url' => $m->thumbnail_url ?? $m->url,
                    'title' => $mp->title ?? 'Artwork',
                    'postId' => $mp->id,
                    'type' => $mp->media_type,
                ];
            }
        }

        // Likes
        $likedPosts = $profileUser->likedPosts()->with(['primaryMedia', 'tags', 'user'])->latest()->get();

        // Collections
        $collectionsQuery = $profileUser->collections()->with('items');
        if (! $isMe) {
            $collectionsQuery->where('is_private', false);
        }
        $collections = $collectionsQuery->latest()->get();

        // Pools
        $pools = $profileUser->pools()->with('chapters')->latest()->get();

        // Followers & Following lists for modal
        $followers = $profileUser->followers()->get();
        $following = $profileUser->following()->get();

        return view('components.⚡profile-view', [
            'profileUser' => $profileUser,
            'isMe' => $isMe,
            'isFollowing' => $isFollowing,
            'posts' => $posts,
            'mediaFlatList' => $mediaFlatList,
            'likedPosts' => $likedPosts,
            'collections' => $collections,
            'pools' => $pools,
            'followers' => $followers,
            'following' => $following,
        ]);
    }
};
?>

<div class="max-w-5xl mx-auto pb-12 w-full min-w-0">
    <!-- Header Banner Image (Twitter style) -->
    <div class="relative w-full h-48 sm:h-64 md:h-80 bg-neutral-900 overflow-hidden">
        @if($profileUser->banner_url)
            <img src="{{ $profileUser->banner_url }}"
                 alt="Profile Banner"
                 class="w-full h-full object-cover">
        @else
            <div class="flex h-full items-center justify-center bg-gradient-to-br from-[var(--bg-surface)] via-[var(--bg-page)] to-[var(--bg-surface)] text-[var(--text-dim)]" role="img" aria-label="No banner image">
                <div class="flex flex-col items-center gap-2 text-center">
                    <svg class="h-10 w-10 opacity-50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 3.75-3.75 3 3 4.5-5.25 8.25 8.25M3.75 19.5h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Z" />
                    </svg>
                    <span class="text-xs font-semibold uppercase tracking-[0.2em]">No banner image</span>
                </div>
            </div>
        @endif
        <div class="absolute inset-0 bg-gradient-to-t from-[var(--bg-page)]/80 via-transparent to-black/20"></div>
    </div>

    <!-- Profile Header Info Container -->
    <div class="px-4 sm:px-8 -mt-20 relative z-10 space-y-6">
        <!-- Avatar & Actions Row -->
        <div class="flex items-end justify-between">
            <div class="relative">
                @if($profileUser->avatar_url)
                    <img src="{{ $profileUser->avatar_url }}"
                         alt="{{ $profileUser->name }}"
                         class="w-32 h-32 sm:w-40 sm:h-40 rounded-full object-cover ring-4 ring-[var(--bg-page)] shadow-2xl bg-neutral-900">
                @else
                    <div class="flex w-32 h-32 sm:w-40 sm:h-40 items-center justify-center rounded-full bg-gradient-to-br from-[var(--bg-surface-elevated)] to-[var(--bg-page)] text-4xl sm:text-5xl font-black uppercase text-[var(--text-dim)] ring-4 ring-[var(--bg-page)] shadow-2xl" role="img" aria-label="No avatar image">
                        {{ mb_strtoupper(mb_substr($profileUser->name ?: $profileUser->username, 0, 1)) }}
                    </div>
                @endif
                @if($profileUser->is_artist)
                    <div class="absolute bottom-2 right-2 p-1.5 rounded-full accent-bg text-white ring-2 ring-[var(--bg-page)] shadow" title="Verified Artist">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                        </svg>
                    </div>
                @endif
            </div>

            <!-- Profile Action Buttons -->
            <div class="flex items-center gap-3 pb-2">
                @if($isMe)
                    <button wire:click="$set('editModalOpen', true)" 
                            class="px-5 py-2.5 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-medium)] hover:bg-[var(--bg-surface-elevated)] text-sm font-bold shadow transition">
                        Edit Profile
                    </button>
                @else
                    @if(Auth::check())
                        @if(!$isMe && \App\Support\SiteSettings::bool('site_global_commissions') && in_array($profileUser->commission_status, ['Open', 'Waitlist']))
                            <button wire:click="$set('commissionModalOpen', true)" 
                                    class="px-5 py-2.5 rounded-2xl bg-emerald-500/15 text-emerald-400 hover:bg-emerald-500/25 border border-emerald-500/30 text-sm font-bold shadow transition flex items-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <span>Request Commission</span>
                            </button>
                        @endif
                        <a href="{{ route('lounge.dms') }}" 
                           class="p-2.5 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] hover:bg-[var(--bg-surface-elevated)] transition text-[var(--text-muted)] hover:text-[var(--text-main)]"
                           title="Direct Message">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                            </svg>
                        </a>
                        <button wire:click="toggleFollow" 
                                class="px-6 py-2.5 rounded-2xl font-bold text-sm shadow transition {{ $isFollowing ? 'border border-[var(--border-medium)] hover:border-rose-500 hover:text-rose-400' : 'accent-bg text-white' }}">
                            {{ $isFollowing ? 'Following' : 'Follow' }}
                        </button>
                        <button wire:click="toggleBlockUser" wire:confirm="Block this artist and hide their profile and posts?" class="rounded-2xl border border-rose-500/30 px-4 py-2.5 text-sm font-bold text-rose-400">
                            {{ Auth::user()->blockedUsers()->whereKey($profileUser->id)->exists() ? 'Unblock' : 'Block' }}
                        </button>
                        <button wire:click="$set('reportModalOpen', true)" class="rounded-2xl border border-[var(--border-subtle)] px-4 py-2.5 text-sm font-bold text-[var(--text-muted)] hover:text-[var(--text-main)] transition">
                            Report
                        </button>
                    @endif
                @endif
            </div>
        </div>

        <!-- Bio & User Details -->
        <div class="space-y-3">
            <div class="flex items-center gap-3 flex-wrap">
                <h2 class="text-2xl font-black text-[var(--text-main)] tracking-tight">{{ $profileUser->name }}</h2>
                
                @if($profileUser->is_admin)
                    <span class="px-2.5 py-1 rounded-full text-xs font-black bg-amber-500/15 text-amber-400 border border-amber-500/30 flex items-center gap-1.5 shadow-sm">
                        <span class="w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse"></span>
                        ADMIN
                    </span>
                @endif

                @if($profileUser->commission_status)
                    @php
                        $statusClass = match($profileUser->commission_status) {
                            'Open' => 'text-emerald-400 bg-emerald-500/10 border-emerald-500/20',
                            'Waitlist' => 'text-amber-400 bg-amber-500/10 border-amber-500/20',
                            default => 'text-neutral-400 bg-neutral-500/10 border-neutral-500/20',
                        };
                    @endphp
                    <span class="px-3 py-1 rounded-full text-xs font-bold border {{ $statusClass }}">
                        Commissions: {{ $profileUser->commission_status }}
                    </span>
                @endif
            </div>

            <div class="text-sm text-[var(--text-dim)] font-mono">{{ '@' . $profileUser->username }}</div>

            @if($profileUser->bio)
                <p class="text-sm text-[var(--text-main)] leading-relaxed max-w-2xl">{{ $profileUser->bio }}</p>
            @endif

            <!-- Metadata Row: Website, Joined -->
            <div class="flex flex-wrap items-center gap-6 text-xs text-[var(--text-dim)] pt-1">
                @if($profileUser->website)
                    <a href="{{ $profileUser->website }}" target="_blank" rel="noopener" class="flex items-center gap-1.5 accent-text hover:underline">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
                        </svg>
                        <span>{{ parse_url($profileUser->website, PHP_URL_HOST) ?? $profileUser->website }}</span>
                    </a>
                @endif

                <span class="flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                    <span>Joined {{ $profileUser->created_at->format('F Y') }}</span>
                </span>
            </div>

            <!-- Reputation, Followers & Following Stats (Twitter style with modal triggers) -->
            <div class="flex items-center gap-4 text-sm pt-2 flex-wrap">
                <div class="flex items-center gap-1.5 px-3 py-1 rounded-2xl bg-amber-500/10 border border-amber-500/30 text-amber-400 font-bold text-xs">
                    <span class="text-sm">⚡</span>
                    <span>{{ number_format($profileUser->reputation_score) }} Rep</span>
                    <span class="text-amber-200/80 font-medium ml-1">({{ $profileUser->reputation_title }})</span>
                </div>

                <button wire:click="openFollowModal('following')" type="button" class="group flex items-center gap-1 hover:opacity-80 transition cursor-pointer">
                    <span class="font-extrabold text-[var(--text-main)] group-hover:underline">{{ $profileUser->following()->count() }}</span>
                    <span class="text-[var(--text-dim)] ml-0.5">Following</span>
                </button>
                <button wire:click="openFollowModal('followers')" type="button" class="group flex items-center gap-1 hover:opacity-80 transition cursor-pointer">
                    <span class="font-extrabold text-[var(--text-main)] group-hover:underline">{{ $profileUser->followers()->count() }}</span>
                    <span class="text-[var(--text-dim)] ml-0.5">Followers</span>
                </button>
            </div>
        </div>

        <!-- Profile Tabs (Spec: Posts, Media, Likes, Collections, Pools) -->
        <div class="flex items-center border-b border-[var(--border-subtle)] gap-2 overflow-x-auto w-full min-w-0 scrollbar-none">
            <button wire:click="setTab('posts')" 
                    class="relative py-3.5 px-4 font-bold text-sm transition shrink-0 {{ $activeTab === 'posts' ? 'text-[var(--text-main)]' : 'text-[var(--text-dim)] hover:text-[var(--text-main)]' }}">
                <span>Posts</span>
                <span class="ml-1 text-xs opacity-60">({{ $posts->count() }})</span>
                @if($activeTab === 'posts') <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div> @endif
            </button>

            <!-- Media Tab (Spec: grid of everything user posted; clicking opens lightbox directly) -->
            <button wire:click="setTab('media')" 
                    class="relative py-3.5 px-4 font-bold text-sm transition shrink-0 {{ $activeTab === 'media' ? 'text-[var(--text-main)]' : 'text-[var(--text-dim)] hover:text-[var(--text-main)]' }}">
                <span>Media Grid</span>
                <span class="ml-1 text-xs opacity-60">({{ count($mediaFlatList) }})</span>
                @if($activeTab === 'media') <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div> @endif
            </button>

            <!-- Likes Tab (Spec: likes only — no bookmarks, public and appear on user profile) -->
            <button wire:click="setTab('likes')" 
                    class="relative py-3.5 px-4 font-bold text-sm transition shrink-0 {{ $activeTab === 'likes' ? 'text-[var(--text-main)]' : 'text-[var(--text-dim)] hover:text-[var(--text-main)]' }}">
                <span>Likes</span>
                <span class="ml-1 text-xs opacity-60">({{ $likedPosts->count() }})</span>
                @if($activeTab === 'likes') <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div> @endif
            </button>

            <!-- Collections Tab (Spec: live on profile, not in nav) -->
            <button wire:click="setTab('collections')" 
                    class="relative py-3.5 px-4 font-bold text-sm transition shrink-0 {{ $activeTab === 'collections' ? 'text-[var(--text-main)]' : 'text-[var(--text-dim)] hover:text-[var(--text-main)]' }}">
                <span>Collections</span>
                <span class="ml-1 text-xs opacity-60">({{ $collections->count() }})</span>
                @if($activeTab === 'collections') <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div> @endif
            </button>

            <!-- Pools Tab (Spec: live on profile) -->
            <button wire:click="setTab('pools')" 
                    class="relative py-3.5 px-4 font-bold text-sm transition shrink-0 {{ $activeTab === 'pools' ? 'text-[var(--text-main)]' : 'text-[var(--text-dim)] hover:text-[var(--text-main)]' }}">
                <span>Pools & Manga</span>
                <span class="ml-1 text-xs opacity-60">({{ $pools->count() }})</span>
                @if($activeTab === 'pools') <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div> @endif
            </button>
        </div>

        <!-- Tab 1: Posts -->
        @if($activeTab === 'posts')
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                @forelse($posts as $p)
                    <a href="{{ route('post.detail', $p->id) }}" class="group rounded-3xl overflow-hidden bg-[var(--bg-surface)] border border-[var(--border-subtle)] hover:border-[var(--border-medium)] transition shadow-sm hover:shadow-xl">
                        <div class="relative aspect-video overflow-hidden bg-neutral-900">
                            <img src="{{ $p->primaryMedia->url }}" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105">
                            @if($p->media_count > 1)
                                <div class="absolute top-2 right-2 px-2 py-0.5 rounded-lg bg-black/70 backdrop-blur-md text-white font-mono text-xs font-bold">
                                    {{ $p->media_count }}
                                </div>
                            @endif
                        </div>
                        <div class="p-4 space-y-2">
                            <h4 class="font-bold text-sm truncate text-[var(--text-main)]">{{ $p->title ?? 'Untitled Post' }}</h4>
                            <div class="flex items-center justify-between text-xs text-[var(--text-dim)]">
                                <span>{{ $p->created_at->diffForHumans() }}</span>
                                <span class="flex items-center gap-1 text-rose-400 font-semibold">
                                    ♥ {{ $p->likes_count }}
                                </span>
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="col-span-full py-12 text-center text-sm text-[var(--text-dim)]">No posts shared yet.</div>
                @endforelse
            </div>
        @endif

        <!-- Tab 2: Media Grid (Spec: grid of everything user posted; clicking opens lightbox) -->
        @if($activeTab === 'media')
            <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 gap-3">
                @forelse($mediaFlatList as $idx => $m)
                    <div class="relative aspect-square rounded-2xl overflow-hidden bg-neutral-900 cursor-pointer group shadow border border-[var(--border-subtle)]"
                         @click="$dispatch('open-lightbox', { items: {{ json_encode($mediaFlatList) }}, startIndex: {{ $idx }}, title: '{{ addslashes($m['title']) }}', author: '{{ addslashes($profileUser->name) }}', postId: {{ $m['postId'] }} })">
                        <img src="{{ $m['thumbnail_url'] }}" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-110">
                        <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>
                    </div>
                @empty
                    <div class="col-span-full py-12 text-center text-sm text-[var(--text-dim)]">No media found.</div>
                @endforelse
            </div>
        @endif

        <!-- Tab 3: Likes (Public) -->
        @if($activeTab === 'likes')
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                @forelse($likedPosts as $lp)
                    <a href="{{ route('post.detail', $lp->id) }}" class="group rounded-3xl overflow-hidden bg-[var(--bg-surface)] border border-[var(--border-subtle)] hover:border-[var(--border-medium)] transition shadow-sm hover:shadow-xl">
                        <div class="relative aspect-video overflow-hidden bg-neutral-900">
                            <img src="{{ $lp->primaryMedia->url }}" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105">
                            @if($lp->media_count > 1)
                                <div class="absolute top-2 right-2 px-2 py-0.5 rounded-lg bg-black/70 backdrop-blur-md text-white font-mono text-xs font-bold">
                                    {{ $lp->media_count }}
                                </div>
                            @endif
                        </div>
                        <div class="p-4 space-y-1">
                            <h4 class="font-bold text-sm truncate text-[var(--text-main)]">{{ $lp->title ?? 'Artwork' }}</h4>
                            <p class="text-xs text-[var(--text-dim)]">by {{ $lp->user->name }}</p>
                        </div>
                    </a>
                @empty
                    <div class="col-span-full py-12 text-center text-sm text-[var(--text-dim)]">No public likes yet.</div>
                @endforelse
            </div>
        @endif

        <!-- Tab 4: Collections (Spec: User-ordered sets, public/private, followable, lives on profile) -->
        @if($activeTab === 'collections')
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                @forelse($collections as $coll)
                    <a href="{{ route('collection.detail', $coll->id) }}" class="group rounded-3xl overflow-hidden bg-[var(--bg-surface)] border border-[var(--border-subtle)] hover:border-[var(--border-medium)] transition shadow-sm hover:shadow-xl flex flex-col justify-between">
                        <div class="relative aspect-video bg-neutral-900 overflow-hidden">
                            <img src="{{ $coll->cover_url ?? 'https://images.unsplash.com/photo-1518709268805-4e9042af9f23?auto=format&fit=crop&w=600&q=80' }}" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105">
                            <div class="absolute top-3 left-3 px-2.5 py-1 rounded-xl text-xs font-bold backdrop-blur-md {{ $coll->is_private ? 'bg-amber-500/80 text-white' : 'bg-emerald-500/80 text-white' }}">
                                {{ $coll->is_private ? 'Private' : 'Public' }}
                            </div>
                            <div class="absolute bottom-3 right-3 px-2.5 py-1 rounded-xl bg-black/70 text-white font-mono text-xs font-bold backdrop-blur-md">
                                {{ $coll->items_count }} items
                            </div>
                        </div>
                        <div class="p-4 space-y-1.5">
                            <h4 class="font-bold text-base truncate text-[var(--text-main)]">{{ $coll->title }}</h4>
                            @if($coll->description)
                                <p class="text-xs text-[var(--text-muted)] line-clamp-2">{{ $coll->description }}</p>
                            @endif
                        </div>
                    </a>
                @empty
                    <div class="col-span-full py-12 text-center text-sm text-[var(--text-dim)]">No collections created yet.</div>
                @endforelse
            </div>
        @endif

        <!-- Tab 5: Pools (Series & Manga) -->
        @if($activeTab === 'pools')
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                @forelse($pools as $pool)
                    <a href="{{ route('pools.detail', $pool->id) }}" class="group rounded-3xl p-5 bg-[var(--bg-surface)] border border-[var(--border-subtle)] hover:border-[var(--border-medium)] transition shadow-sm hover:shadow-xl flex gap-4">
                        <img src="{{ $pool->cover_url }}" class="w-24 h-32 rounded-2xl object-cover shrink-0 shadow">
                        <div class="flex-1 min-w-0 flex flex-col justify-between">
                            <div>
                                <div class="flex items-center gap-2">
                                    <h4 class="font-bold text-base truncate text-[var(--text-main)]">{{ $pool->title }}</h4>
                                    @if($pool->is_locked)
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-neutral-800 text-neutral-400">Locked</span>
                                    @endif
                                </div>
                                <p class="text-xs text-[var(--text-muted)] line-clamp-2 mt-1">{{ $pool->description }}</p>
                            </div>
                            <div class="flex items-center justify-between text-xs text-[var(--text-dim)] pt-2 border-t border-[var(--border-subtle)]">
                                <span>{{ $pool->chapters_count }} Chapters</span>
                                <span>{{ $pool->followers_count }} Followers</span>
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="col-span-full py-12 text-center text-sm text-[var(--text-dim)]">No manga or series pools found.</div>
                @endforelse
            </div>
        @endif
    </div>

    <!-- Edit Profile Modal -->
    <div x-show="$wire.editModalOpen" 
         x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-md"
         @click.self="$wire.set('editModalOpen', false)">
        
        <div class="w-full max-w-lg p-6 rounded-3xl glass-panel shadow-2xl border border-[var(--border-medium)] space-y-4 max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between pb-3 border-b border-[var(--border-subtle)]">
                <h3 class="font-bold text-lg">Edit Profile</h3>
                <button wire:click="$set('editModalOpen', false)" class="p-1 rounded-full hover:bg-[var(--bg-surface-elevated)]">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <div class="space-y-3">
                <div>
                    <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Display Name</label>
                    <input type="text" wire:model="editName" class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)]">
                </div>

                <div>
                    <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Bio</label>
                    <textarea wire:model="editBio" rows="3" class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)]"></textarea>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Website URL</label>
                        <input type="url" wire:model="editWebsite" class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)]">
                    </div>

                    <div>
                        <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Commission Status</label>
                        <select wire:model="editCommissionStatus" class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)]">
                            <option value="Open">Open</option>
                            <option value="Closed">Closed</option>
                            <option value="Waitlist">Waitlist</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Avatar Image</label>
                    <label class="mt-1 block text-xs text-[var(--text-muted)]">Choose an image from this device (JPG, PNG, WebP; up to 12 MB)<input type="file" wire:model="avatarUpload" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full"></label>
                    @error('avatarUpload') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Banner Image</label>
                    <label class="mt-1 block text-xs text-[var(--text-muted)]">Choose an image from this device (JPG, PNG, WebP; up to 12 MB)<input type="file" wire:model="bannerUpload" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full"></label>
                    @error('bannerUpload') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-3 border-t border-[var(--border-subtle)]">
                <button wire:click="$set('editModalOpen', false)" class="px-5 py-2 rounded-xl text-xs font-bold hover:bg-[var(--bg-surface-elevated)]">Cancel</button>
                <button wire:click="saveProfile" class="px-6 py-2 rounded-xl accent-bg text-white font-bold text-xs shadow hover:opacity-90 transition">Save Changes</button>
            </div>
        </div>
    </div>

    <!-- Followers & Following Modal -->
    <div x-show="$wire.followModalOpen" 
         x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-md"
         @click.self="$wire.set('followModalOpen', false)">
        
        <div class="w-full max-w-md p-6 rounded-3xl glass-panel shadow-2xl border border-[var(--border-medium)] flex flex-col max-h-[85vh] overflow-hidden">
            <!-- Header & Tabs -->
            <div class="flex items-center justify-between pb-3 border-b border-[var(--border-subtle)]">
                <div class="flex items-center gap-6 text-sm font-bold">
                    <button wire:click="openFollowModal('followers')" 
                            class="relative py-1 transition {{ $followModalTab === 'followers' ? 'text-[var(--text-main)] font-extrabold' : 'text-[var(--text-dim)] hover:text-[var(--text-main)]' }}">
                        <span>Followers</span>
                        <span class="ml-1 text-xs px-2 py-0.5 rounded-full bg-[var(--bg-surface-elevated)]">{{ $profileUser->followers()->count() }}</span>
                        @if($followModalTab === 'followers')
                            <div class="absolute -bottom-3 left-0 right-0 h-0.5 accent-bg rounded-full"></div>
                        @endif
                    </button>
                    <button wire:click="openFollowModal('following')" 
                            class="relative py-1 transition {{ $followModalTab === 'following' ? 'text-[var(--text-main)] font-extrabold' : 'text-[var(--text-dim)] hover:text-[var(--text-main)]' }}">
                        <span>Following</span>
                        <span class="ml-1 text-xs px-2 py-0.5 rounded-full bg-[var(--bg-surface-elevated)]">{{ $profileUser->following()->count() }}</span>
                        @if($followModalTab === 'following')
                            <div class="absolute -bottom-3 left-0 right-0 h-0.5 accent-bg rounded-full"></div>
                        @endif
                    </button>
                </div>
                <button wire:click="closeFollowModal" class="p-1 rounded-full text-[var(--text-dim)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)] transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <!-- List of Users -->
            <div class="overflow-y-auto flex-1 divide-y divide-[var(--border-subtle)] py-2">
                @php
                    $userList = $followModalTab === 'followers' ? $followers : $following;
                @endphp

                @forelse($userList as $u)
                    @php
                        $isFollowingUser = Auth::check() ? Auth::user()->isFollowing($u) : false;
                        $isMeUser = Auth::check() && Auth::id() === $u->id;
                    @endphp
                    <div class="flex items-center justify-between py-3 px-1 gap-3 hover:bg-[var(--bg-surface-elevated)]/30 rounded-xl transition">
                        <a href="{{ route('profile', $u->username) }}" wire:click="closeFollowModal" class="flex items-center gap-3 min-w-0 flex-1 group">
                            <img src="{{ $u->avatar_url }}" alt="{{ $u->name }}" class="w-10 h-10 rounded-full object-cover shadow-sm border border-[var(--border-subtle)] shrink-0">
                            <div class="min-w-0 flex-1">
                                <div class="font-bold text-sm text-[var(--text-main)] group-hover:accent-text transition truncate flex items-center gap-1.5">
                                    <span>{{ $u->name }}</span>
                                    @if($u->is_admin)
                                        <span class="px-1.5 py-0.5 rounded text-[10px] font-black bg-amber-500/15 text-amber-400 border border-amber-500/30">ADMIN</span>
                                    @endif
                                </div>
                                <div class="text-xs text-[var(--text-dim)] truncate">@<span>{{ $u->username }}</span></div>
                                @if($u->bio)
                                    <div class="text-xs text-[var(--text-muted)] truncate mt-0.5">{{ $u->bio }}</div>
                                @endif
                            </div>
                        </a>

                        @if(!$isMeUser && Auth::check())
                            <button wire:click="toggleFollowUser({{ $u->id }})"
                                    class="px-3.5 py-1.5 rounded-full text-xs font-bold transition border shrink-0 {{ $isFollowingUser ? 'bg-[var(--bg-surface)] text-[var(--text-main)] border-[var(--border-medium)] hover:border-red-500/50 hover:text-red-400' : 'accent-bg text-white border-transparent hover:opacity-90' }}">
                                {{ $isFollowingUser ? 'Following' : 'Follow' }}
                            </button>
                        @endif
                    </div>
                @empty
                    <div class="py-12 text-center text-sm text-[var(--text-dim)]">
                        @if($followModalTab === 'followers')
                            No followers yet.
                        @else
                            Not following anyone yet.
                        @endif
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    <!-- Request Commission Modal -->
    <div x-show="$wire.commissionModalOpen" 
         x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-md"
         @click.self="$wire.set('commissionModalOpen', false)">
        
        <div class="w-full max-w-lg p-6 rounded-3xl glass-panel shadow-2xl border border-[var(--border-medium)] space-y-4 max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between pb-3 border-b border-[var(--border-subtle)]">
                <div class="flex items-center gap-2">
                    <div class="p-2 rounded-xl bg-emerald-500/15 text-emerald-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="font-bold text-lg text-[var(--text-main)]">Request Commission</h3>
                        <p class="text-xs text-[var(--text-dim)]">Send a direct inquiry to {{ $profileUser->name }} ({{ '@' . $profileUser->username }})</p>
                    </div>
                </div>
                <button wire:click="$set('commissionModalOpen', false)" class="p-1 rounded-full text-[var(--text-dim)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)] transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Category / Style</label>
                        <select wire:model="commissionCategory" class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)] font-medium">
                            <option value="Illustration">Full Illustration</option>
                            <option value="Character Design">Character Sheet / Concept</option>
                            <option value="Portrait">Portrait / Avatar</option>
                            <option value="Background / Environment">Environment & Background</option>
                            <option value="Custom Project">Custom Commercial Project</option>
                        </select>
                    </div>

                    <div>
                        <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Estimated Budget</label>
                        <select wire:model="commissionBudget" class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)] font-medium">
                            <option value="$50 - $100">$50 - $100</option>
                            <option value="$100 - $250">$100 - $250</option>
                            <option value="$250 - $500">$250 - $500</option>
                            <option value="$500+">$500+</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Target Deadline</label>
                    <select wire:model="commissionDeadline" class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)] font-medium">
                        <option value="1 Week">Flexible (1-2 Weeks)</option>
                        <option value="2 Weeks">2 Weeks</option>
                        <option value="1 Month">1 Month</option>
                        <option value="Rush (< 7 days)">Rush (< 7 Days)</option>
                    </select>
                </div>

                <div>
                    <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Project Description & References</label>
                    <textarea wire:model="commissionDescription" rows="4" placeholder="Describe your character, scene idea, pose, lighting, or reference links..." class="w-full mt-1 p-3.5 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)]"></textarea>
                    @error('commissionDescription') <span class="text-xs text-rose-400 mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-3 border-t border-[var(--border-subtle)]">
                <button wire:click="$set('commissionModalOpen', false)" class="px-5 py-2.5 rounded-xl text-xs font-bold hover:bg-[var(--bg-surface-elevated)]">Cancel</button>
                <button wire:click="submitCommissionInquiry" class="px-6 py-2.5 rounded-xl bg-emerald-500 text-white font-bold text-xs shadow hover:bg-emerald-600 transition flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                    </svg>
                    <span>Send Inquiry</span>
                </button>
            </div>
        </div>
    </div>

    <div x-show="$wire.reportModalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70" @click.self="$wire.set('reportModalOpen', false)">
        <form wire:submit="submitUserReport" class="w-full max-w-lg rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] p-6 space-y-4">
            <h2 class="text-lg font-black">Report account</h2>
            <p class="text-xs text-[var(--text-dim)]">Reports go to the site moderation queue. False reports may be reviewed by staff.</p>
            <label class="block text-xs font-bold">Reason
                <select wire:model="reportReason" class="mt-1 w-full rounded-xl bg-[var(--bg-page)] border border-[var(--border-subtle)] p-3 text-sm">
                    <option value="">Choose a reason</option>
                    <option value="spam">Spam</option>
                    <option value="harassment">Harassment or abuse</option>
                    <option value="impersonation">Impersonation</option>
                    <option value="ban_evasion">Ban evasion</option>
                    <option value="other">Other policy concern</option>
                </select>
            </label>
            @error('reportReason') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
            <label class="block text-xs font-bold">Details
                <textarea wire:model="reportDetails" rows="3" class="mt-1 w-full rounded-xl bg-[var(--bg-page)] border border-[var(--border-subtle)] p-3 text-sm"></textarea>
            </label>
            @error('reportDetails') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
            <div class="flex justify-end gap-2">
                <button type="button" wire:click="$set('reportModalOpen', false)" class="px-4 py-2 text-xs font-bold">Cancel</button>
                <button class="rounded-xl accent-bg px-5 py-2 text-xs font-bold text-white">Send report</button>
            </div>
        </form>
    </div>
</div>
