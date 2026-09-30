<?php

use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\ArtistClaim;
use App\Models\ArtistEdit;
use App\Models\ArtistLink;
use App\Models\Pool;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $slug;

    public string $activeTab = 'works'; // works, pools, tags, links, about

    // Works tab filters
    public string $worksSort = 'newest'; // newest, oldest, popular, most_liked, most_viewed, random

    public string $mediaFilter = 'all'; // all, image, gif, video

    public string $insideSearch = '';

    // Suggest Link Modal
    public bool $linkModalOpen = false;

    public string $linkPlatform = 'website';

    public string $linkUrl = '';

    public string $linkTitle = '';

    // Suggest Alias Modal
    public bool $aliasModalOpen = false;

    public string $aliasName = '';

    // Claim Page Modal
    public bool $claimModalOpen = false;

    public string $claimVerificationCode = '';

    public string $claimMethod = 'bio_code';

    public string $claimNotes = '';

    // Merge / Split Request Modal
    public bool $mergeModalOpen = false;

    public string $mergeTargetName = '';

    public string $mergeReason = '';

    public function mount(string $slug)
    {
        $this->slug = $slug;
        $this->claimVerificationCode = 'booru-artist-'.Str::random(8);
    }

    public function setTab(string $tab)
    {
        $this->activeTab = $tab;
        $this->resetPage();
    }

    public function toggleFollowArtist()
    {
        if (! Auth::check()) {
            $this->dispatch('notify', 'Please log in to follow artists');

            return;
        }

        $user = Auth::user();
        $artist = $this->getArtist();

        if ($user->isFollowingArtist($artist)) {
            $user->followingArtists()->detach($artist->id);
            $artist->decrement('followers_count');
            $this->dispatch('notify', 'Unfollowed '.$artist->name);
        } else {
            $user->followingArtists()->attach($artist->id);
            $artist->increment('followers_count');
            $this->dispatch('notify', 'Following '.$artist->name);
        }
    }

    public function toggleFollowUser()
    {
        if (! Auth::check()) {
            $this->dispatch('notify', 'Please log in');

            return;
        }

        $artist = $this->getArtist();
        if (! $artist->is_claimed || ! $artist->claimedByUser) {
            return;
        }

        $user = Auth::user();
        $claimedUser = $artist->claimedByUser;

        if ($user->id === $claimedUser->id) {
            return;
        }

        if ($user->isFollowing($claimedUser)) {
            $user->following()->detach($claimedUser->id);
            $this->dispatch('notify', 'Unfollowed user @'.$claimedUser->username);
        } else {
            $user->following()->attach($claimedUser->id);
            $this->dispatch('notify', 'Following user @'.$claimedUser->username);
        }
    }

    public function followBoth()
    {
        if (! Auth::check()) {
            $this->dispatch('notify', 'Please log in');

            return;
        }

        $artist = $this->getArtist();
        $user = Auth::user();

        if (! $user->isFollowingArtist($artist)) {
            $user->followingArtists()->attach($artist->id);
            $artist->increment('followers_count');
        }

        if ($artist->is_claimed && $artist->claimedByUser && $user->id !== $artist->claimed_by_user_id) {
            if (! $user->isFollowing($artist->claimedByUser)) {
                $user->following()->attach($artist->claimed_by_user_id);
            }
        }

        $this->dispatch('notify', 'Following both Artist & User!');
    }

    public function submitSuggestLink()
    {
        if (! Auth::check()) {
            $this->dispatch('notify', 'Please log in to suggest links');

            return;
        }

        $this->validate([
            'linkUrl' => 'required|url|max:1024',
            'linkPlatform' => 'required|string',
        ]);

        $artist = $this->getArtist();

        ArtistLink::create([
            'artist_id' => $artist->id,
            'platform' => $this->linkPlatform,
            'url' => $this->linkUrl,
            'title' => $this->linkTitle ?: ucfirst($this->linkPlatform),
            'status' => 'unverified',
            'submitted_by_user_id' => Auth::id(),
        ]);

        ArtistEdit::create([
            'artist_id' => $artist->id,
            'user_id' => Auth::id(),
            'action' => 'add_link',
            'details' => ['platform' => $this->linkPlatform, 'url' => $this->linkUrl],
            'status' => 'approved',
        ]);

        $this->linkModalOpen = false;
        $this->linkUrl = '';
        $this->linkTitle = '';
        $this->dispatch('notify', 'Artist link suggested successfully!');
    }

    public function submitSuggestAlias()
    {
        if (! Auth::check()) {
            $this->dispatch('notify', 'Please log in to suggest aliases');

            return;
        }

        $this->validate([
            'aliasName' => 'required|string|max:100',
        ]);

        $artist = $this->getArtist();
        $cleanAlias = trim($this->aliasName);
        $aliasSlug = Str::slug($cleanAlias);

        if (! ArtistAlias::where('artist_id', $artist->id)->where('alias', $cleanAlias)->exists()) {
            ArtistAlias::create([
                'artist_id' => $artist->id,
                'alias' => $cleanAlias,
                'slug' => $aliasSlug ?: 'alias-'.Str::random(4),
            ]);

            ArtistEdit::create([
                'artist_id' => $artist->id,
                'user_id' => Auth::id(),
                'action' => 'add_alias',
                'details' => ['alias' => $cleanAlias],
                'status' => 'approved',
            ]);
        }

        $this->aliasModalOpen = false;
        $this->aliasName = '';
        $this->dispatch('notify', 'Alias suggested and added!');
    }

    public function submitClaimPage()
    {
        if (! Auth::check()) {
            $this->dispatch('notify', 'Please log in to claim an artist page');

            return;
        }

        $artist = $this->getArtist();
        $user = Auth::user();

        if ($artist->is_claimed) {
            $this->dispatch('notify', 'This artist page is already claimed');

            return;
        }

        ArtistClaim::create([
            'artist_id' => $artist->id,
            'user_id' => $user->id,
            'verification_code' => $this->claimVerificationCode,
            'verification_method' => $this->claimMethod,
            'status' => 'pending',
            'notes' => $this->claimNotes,
        ]);

        ArtistEdit::create([
            'artist_id' => $artist->id,
            'user_id' => $user->id,
            'action' => 'claim_request',
            'details' => ['method' => $this->claimMethod, 'code' => $this->claimVerificationCode],
            'status' => 'pending',
        ]);

        $this->claimModalOpen = false;
        $this->dispatch('notify', 'Claim request submitted! Moderators will verify your ownership.');
    }

    public function submitMergeRequest()
    {
        if (! Auth::check()) {
            $this->dispatch('notify', 'Please log in');

            return;
        }

        $this->validate([
            'mergeTargetName' => 'required|string|max:100',
            'mergeReason' => 'required|string|max:500',
        ]);

        $artist = $this->getArtist();

        ArtistEdit::create([
            'artist_id' => $artist->id,
            'user_id' => Auth::id(),
            'action' => 'merge_request',
            'details' => ['target' => $this->mergeTargetName, 'reason' => $this->mergeReason],
            'status' => 'pending',
        ]);

        $this->mergeModalOpen = false;
        $this->mergeTargetName = '';
        $this->mergeReason = '';
        $this->dispatch('notify', 'Merge suggestion submitted for moderator review');
    }

    public function filterInsideTag(string $tagName)
    {
        $this->insideSearch = $tagName;
        $this->activeTab = 'works';
        $this->resetPage();
    }

    private function getArtist(): Artist
    {
        $artist = Artist::where('slug', $this->slug)->first();
        if (! $artist) {
            $alias = ArtistAlias::where('slug', $this->slug)->first();
            if ($alias) {
                $artist = $alias->artist;
            }
        }
        abort_unless($artist, 404);

        return $artist;
    }

    public function render()
    {
        $artist = $this->getArtist();
        $user = Auth::user();
        $isFollowingArtist = $user ? $artist->isFollowedBy($user) : false;
        $isFollowingUser = ($user && $artist->is_claimed && $artist->claimedByUser) ? $user->isFollowing($artist->claimedByUser) : false;

        // Query Works attributed to this artist
        $worksQuery = Post::with(['user', 'primaryMedia', 'tags', 'artist'])
            ->where(function ($q) use ($artist) {
                $q->where('artist_id', $artist->id)
                    ->orWhere('artist_name', $artist->name);
            });

        if ($this->mediaFilter === 'image') {
            $worksQuery->where('media_type', 'image');
        } elseif ($this->mediaFilter === 'gif') {
            $worksQuery->where('media_type', 'gif');
        } elseif ($this->mediaFilter === 'video') {
            $worksQuery->where('media_type', 'video');
        }

        if (! empty(trim($this->insideSearch))) {
            $term = trim($this->insideSearch);
            $worksQuery->whereHas('tags', fn ($tq) => $tq->where('name', 'like', "%{$term}%"));
        }

        match ($this->worksSort) {
            'oldest' => $worksQuery->oldest(),
            'popular', 'most_liked' => $worksQuery->orderByDesc('likes_count'),
            'most_viewed' => $worksQuery->orderByDesc('views_count'),
            'random' => $worksQuery->inRandomOrder(),
            default => $worksQuery->latest(),
        };

        $works = $worksQuery->paginate(20);

        // Fetch Artist Pools
        $artistPostIds = Post::where('artist_id', $artist->id)->orWhere('artist_name', $artist->name)->pluck('id')->all();
        $pools = Pool::with(['user'])
            ->whereIn('id', function ($query) use ($artistPostIds) {
                $query->select('pool_id')->from('pool_chapters')->whereIn('post_id', $artistPostIds);
            })
            ->latest()
            ->get();

        // Calculate Top Tags frequency across artist's works
        $topTags = collect();
        if ($artistPostIds !== []) {
            $topTags = Tag::join('post_tag', 'tags.id', '=', 'post_tag.tag_id')
                ->whereIn('post_tag.post_id', $artistPostIds)
                ->select('tags.name', 'tags.type', DB::raw('count(post_tag.post_id) as tag_frequency'))
                ->groupBy('tags.id', 'tags.name', 'tags.type')
                ->orderByDesc('tag_frequency')
                ->take(20)
                ->get();
        }

        // Fetch Audit Log History & Links
        $links = $artist->links()->latest()->get();
        $aliases = $artist->aliases;
        $edits = $artist->edits()->with('user')->latest()->take(15)->get();

        return view('components.⚡artist-detail', [
            'artist' => $artist,
            'works' => $works,
            'pools' => $pools,
            'topTags' => $topTags,
            'links' => $links,
            'aliases' => $aliases,
            'edits' => $edits,
            'currentUser' => $user,
            'isFollowingArtist' => $isFollowingArtist,
            'isFollowingUser' => $isFollowingUser,
        ]);
    }
};
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6 w-full min-w-0">
    <!-- Header Banner & Catalog Profile Header -->
    <div class="rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] overflow-hidden shadow-xl">
        <!-- Banner Image -->
        <div class="h-44 sm:h-56 w-full bg-gradient-to-r from-purple-950 via-indigo-950 to-slate-950 relative">
            @if($artist->banner_url)
                <img src="{{ $artist->banner_url }}" class="w-full h-full object-cover">
            @endif
            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/30 to-transparent"></div>

            <!-- Top Header Badges -->
            <div class="absolute top-4 right-4 flex items-center gap-2">
                @if($artist->is_claimed)
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-emerald-500/90 text-white font-black text-xs shadow-lg backdrop-blur-md">
                        ✓ Claimed Artist Page
                    </span>
                @else
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-amber-500/80 text-white font-extrabold text-xs shadow-lg backdrop-blur-md">
                        Unclaimed Community Catalog
                    </span>
                @endif
            </div>
        </div>

        <!-- Info Header Row -->
        <div class="px-6 pb-6 pt-0 relative">
            <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 -mt-14 mb-4">
                <!-- Avatar & Identity -->
                <div class="flex flex-col sm:flex-row sm:items-end gap-4">
                    <img src="{{ $artist->avatar }}" alt="{{ $artist->name }}" class="w-24 h-24 sm:w-28 sm:h-28 rounded-3xl object-cover ring-4 ring-[var(--bg-surface)] shadow-2xl shrink-0">
                    <div class="space-y-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h1 class="text-2xl sm:text-3xl font-black text-[var(--text-main)] tracking-tight">
                                {{ $artist->name }}
                            </h1>
                            @if($artist->is_claimed)
                                <span class="text-sky-400 text-xl" title="Verified Artist">✓</span>
                            @endif
                        </div>

                        <!-- Aliases pill strip -->
                        @if($aliases->isNotEmpty())
                            <div class="flex items-center gap-1.5 text-xs text-[var(--text-dim)] flex-wrap">
                                <span class="font-bold">Known Aliases:</span>
                                @foreach($aliases as $alias)
                                    <span class="px-2 py-0.5 rounded-lg bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] font-medium text-[var(--text-muted)]">
                                        {{ $alias->alias }}
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        <!-- Claimed owner status -->
                        @if($artist->is_claimed && $artist->claimedByUser)
                            <div class="text-xs text-[var(--accent-primary)] font-semibold flex items-center gap-1">
                                <span>Managed by account</span>
                                <a href="{{ route('profile', $artist->claimedByUser->username) }}" class="font-bold underline hover:opacity-80">
                                    @<span>{{ $artist->claimedByUser->username }}</span>
                                </a>
                                <span class="px-1.5 py-0.5 rounded text-[10px] bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 font-extrabold ml-1">✓ Verified Owner</span>
                            </div>
                        @else
                            <p class="text-xs text-[var(--text-muted)]">
                                This is an unclaimed community catalog index. The artist has not yet claimed control of this page.
                            </p>
                        @endif
                    </div>
                </div>

                <!-- Action Controls: Follow Artist / Follow User / Claim Page -->
                <div class="flex items-center gap-2 flex-wrap shrink-0">
                    <button wire:click="toggleFollowArtist" 
                            class="px-4 py-2.5 rounded-xl font-bold text-xs transition flex items-center gap-2 cursor-pointer shadow-sm {{ $isFollowingArtist ? 'bg-[var(--bg-surface-elevated)] border border-[var(--border-medium)] text-[var(--text-main)] hover:bg-rose-500/10 hover:text-rose-400' : 'accent-bg text-white hover:opacity-90' }}">
                        @if($isFollowingArtist)
                            <span>Following Artist</span>
                        @else
                            <span>Follow Artist</span>
                        @endif
                    </button>

                    @if($artist->is_claimed && $artist->claimedByUser && Auth::id() !== $artist->claimed_by_user_id)
                        <button wire:click="toggleFollowUser" 
                                class="px-4 py-2.5 rounded-xl font-bold text-xs transition border border-[var(--border-medium)] bg-[var(--bg-surface)] hover:bg-[var(--bg-surface-elevated)] text-[var(--text-main)] cursor-pointer">
                            {{ $isFollowingUser ? 'Following User @' . $artist->claimedByUser->username : 'Follow User @' . $artist->claimedByUser->username }}
                        </button>

                        <button wire:click="followBoth" 
                                class="px-3.5 py-2.5 rounded-xl font-bold text-xs transition bg-indigo-600 hover:bg-indigo-500 text-white cursor-pointer shadow-sm"
                                title="Follow both the Artist catalog updates and the User social profile">
                            Follow Both
                        </button>
                    @endif

                    @if(!$artist->is_claimed && Auth::check())
                        <button wire:click="$set('claimModalOpen', true)" 
                                class="px-4 py-2.5 rounded-xl font-bold text-xs transition border border-amber-500/40 bg-amber-500/10 hover:bg-amber-500/20 text-amber-400 cursor-pointer">
                            Claim Artist Page
                        </button>
                    @endif
                </div>
            </div>

            <!-- Stats Bar & Links Header Strip -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pt-4 border-t border-[var(--border-subtle)]">
                <div class="flex items-center gap-6 text-xs text-[var(--text-muted)] font-medium">
                    <div>
                        <span class="font-black text-sm text-[var(--text-main)]">{{ number_format($artist->works_count) }}</span> Works
                    </div>
                    <div>
                        <span class="font-black text-sm text-[var(--text-main)]">{{ number_format($artist->followers_count) }}</span> Followers
                    </div>
                    <div>
                        <span class="font-black text-sm text-[var(--text-main)]">{{ $pools->count() }}</span> Pools
                    </div>
                </div>

                <!-- Top Important Links Icons Strip -->
                @if($links->isNotEmpty())
                    <div class="flex items-center gap-2 overflow-x-auto">
                        @foreach($links->take(5) as $link)
                            <a href="{{ $link->url }}" target="_blank" rel="noopener" class="px-3 py-1.5 rounded-xl bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] text-xs font-semibold text-[var(--text-muted)] hover:text-[var(--text-main)] transition flex items-center gap-1.5">
                                <span>{{ $link->platform_icon }}</span>
                                <span class="capitalize">{{ $link->platform }}</span>
                                @if($link->status === 'verified')
                                    <span class="text-emerald-400 text-[10px]">✓</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Catalog Main Navigation Tabs -->
    <div class="flex items-center justify-between border-b border-[var(--border-subtle)] pb-1 overflow-x-auto">
        <div class="flex items-center gap-2">
            <button wire:click="setTab('works')" class="relative px-4 py-3 font-bold text-sm transition-colors {{ $activeTab === 'works' ? 'text-[var(--text-main)] font-black' : 'text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">
                <span>Works ({{ number_format($artist->works_count) }})</span>
                @if($activeTab === 'works')
                    <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div>
                @endif
            </button>

            <button wire:click="setTab('pools')" class="relative px-4 py-3 font-bold text-sm transition-colors {{ $activeTab === 'pools' ? 'text-[var(--text-main)] font-black' : 'text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">
                <span>Pools ({{ $pools->count() }})</span>
                @if($activeTab === 'pools')
                    <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div>
                @endif
            </button>

            <button wire:click="setTab('tags')" class="relative px-4 py-3 font-bold text-sm transition-colors {{ $activeTab === 'tags' ? 'text-[var(--text-main)] font-black' : 'text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">
                <span>Artist Tags</span>
                @if($activeTab === 'tags')
                    <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div>
                @endif
            </button>

            <button wire:click="setTab('links')" class="relative px-4 py-3 font-bold text-sm transition-colors {{ $activeTab === 'links' ? 'text-[var(--text-main)] font-black' : 'text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">
                <span>Links Directory ({{ $links->count() }})</span>
                @if($activeTab === 'links')
                    <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div>
                @endif
            </button>

            <button wire:click="setTab('about')" class="relative px-4 py-3 font-bold text-sm transition-colors {{ $activeTab === 'about' ? 'text-[var(--text-main)] font-black' : 'text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">
                <span>About & History</span>
                @if($activeTab === 'about')
                    <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div>
                @endif
            </button>
        </div>
    </div>

    <!-- TAB 1: WORKS (BOORU GALLERY) -->
    @if($activeTab === 'works')
        <!-- Works Filter & Search Controls -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 bg-[var(--bg-surface)] p-3.5 rounded-2xl border border-[var(--border-subtle)]">
            <!-- Search inside artist's works -->
            <div class="relative flex-1 max-w-md">
                <input type="text" 
                       wire:model.live.debounce.250ms="insideSearch" 
                       placeholder="Search tags inside {{ $artist->name }}'s works..." 
                       class="w-full pl-9 pr-3 py-2 rounded-xl bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] text-xs outline-none text-[var(--text-main)]">
                <div class="absolute left-3 top-2.5 text-[var(--text-dim)]">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </div>
                @if(!empty($insideSearch))
                    <button wire:click="$set('insideSearch', '')" class="absolute right-2.5 top-2 text-[var(--text-dim)] text-xs font-bold">&times;</button>
                @endif
            </div>

            <!-- Media Filter & Sort -->
            <div class="flex items-center gap-2 overflow-x-auto text-xs">
                <!-- Media Type -->
                <div class="flex items-center gap-1 bg-[var(--bg-surface-elevated)] p-1 rounded-xl">
                    <button wire:click="$set('mediaFilter', 'all')" class="px-2.5 py-1 rounded-lg font-bold {{ $mediaFilter === 'all' ? 'accent-bg text-white' : 'text-[var(--text-muted)]' }}">All</button>
                    <button wire:click="$set('mediaFilter', 'image')" class="px-2.5 py-1 rounded-lg font-bold {{ $mediaFilter === 'image' ? 'accent-bg text-white' : 'text-[var(--text-muted)]' }}">Images</button>
                    <button wire:click="$set('mediaFilter', 'gif')" class="px-2.5 py-1 rounded-lg font-bold {{ $mediaFilter === 'gif' ? 'accent-bg text-white' : 'text-[var(--text-muted)]' }}">GIFs</button>
                    <button wire:click="$set('mediaFilter', 'video')" class="px-2.5 py-1 rounded-lg font-bold {{ $mediaFilter === 'video' ? 'accent-bg text-white' : 'text-[var(--text-muted)]' }}">Videos</button>
                </div>

                <!-- Sort Mode -->
                <select wire:model.live="worksSort" class="bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] text-[var(--text-main)] rounded-xl px-3 py-1.5 text-xs outline-none">
                    <option value="newest">Newest First</option>
                    <option value="oldest">Oldest First</option>
                    <option value="popular">Most Liked</option>
                    <option value="most_viewed">Most Viewed</option>
                    <option value="random">Random</option>
                </select>
            </div>
        </div>

        <!-- Works Grid -->
        @if($works->isEmpty())
            <div class="p-12 text-center rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)]">
                <h3 class="font-bold text-lg text-[var(--text-main)]">No attributed works found</h3>
                <p class="text-sm text-[var(--text-muted)] mt-1">No uploads currently match your filters for {{ $artist->name }}.</p>
            </div>
        @else
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                @foreach($works as $post)
                    @php $primaryMedia = $post->primaryMedia; @endphp
                    <div class="group relative rounded-3xl overflow-hidden bg-[var(--bg-surface)] border border-[var(--border-subtle)] hover:shadow-xl transition">
                        <a href="{{ route('post.detail', $post->id) }}" class="block aspect-square w-full overflow-hidden bg-neutral-900">
                            <img src="{{ $primaryMedia->url ?? '/sfw/avatar/sample_07acb23be6e4bea15d091117693849b2.jpg' }}" alt="{{ $post->title }}" class="w-full h-full object-cover group-hover:scale-105 transition duration-300">
                        </a>

                        <div class="p-3 space-y-1 text-xs">
                            <a href="{{ route('post.detail', $post->id) }}" class="font-bold text-[var(--text-main)] truncate block hover:underline">
                                {{ $post->title ?: 'Untitled Work' }}
                            </a>
                            <div class="flex items-center justify-between text-[11px] text-[var(--text-dim)] pt-1 border-t border-[var(--border-subtle)]">
                                <span>Uploaded by @<span>{{ $post->user->username }}</span></span>
                                <span>❤️ {{ number_format($post->likes_count) }}</span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="pt-4">
                {{ $works->links() }}
            </div>
        @endif

    <!-- TAB 2: POOLS -->
    @elseif($activeTab === 'pools')
        @if($pools->isEmpty())
            <div class="p-12 text-center rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)]">
                <h3 class="font-bold text-lg text-[var(--text-main)]">No artist pools found</h3>
                <p class="text-sm text-[var(--text-muted)] mt-1">No manga, series, or comic pools created for {{ $artist->name }} yet.</p>
            </div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                @foreach($pools as $pool)
                    <div class="p-4 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="px-2.5 py-1 rounded-full bg-indigo-500/10 text-indigo-400 font-extrabold text-[10px] uppercase">Pool</span>
                            <span class="text-xs text-[var(--text-dim)]">{{ $pool->chapters_count ?? 0 }} chapters</span>
                        </div>
                        <a href="{{ route('pools.detail', $pool->id) }}" class="font-black text-base text-[var(--text-main)] hover:underline block">
                            {{ $pool->title }}
                        </a>
                        <p class="text-xs text-[var(--text-muted)] line-clamp-2">
                            {{ $pool->description ?: 'Organized series pool for this artist.' }}
                        </p>
                    </div>
                @endforeach
            </div>
        @endif

    <!-- TAB 3: ARTIST TAGS -->
    @elseif($activeTab === 'tags')
        <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-4">
            <h3 class="font-extrabold text-base text-[var(--text-main)]">Top Tags across {{ $artist->name }}'s Works</h3>
            <p class="text-xs text-[var(--text-muted)]">Clicking a tag filters works inside this artist catalog.</p>

            <div class="flex flex-wrap gap-2 pt-2">
                @foreach($topTags as $tag)
                    <button wire:click="filterInsideTag('{{ $tag->name }}')" 
                            class="px-3 py-1.5 rounded-xl border text-xs font-semibold hover:opacity-80 transition flex items-center gap-1.5 bg-[var(--bg-surface-elevated)] border-[var(--border-subtle)] text-[var(--text-main)]">
                        <span>#{{ $tag->name }}</span>
                        <span class="px-1.5 py-0.5 rounded-full bg-white/10 text-[10px] font-bold">{{ number_format($tag->tag_frequency) }}</span>
                    </button>
                @endforeach
            </div>
        </div>

    <!-- TAB 4: LINKS DIRECTORY -->
    @elseif($activeTab === 'links')
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="font-extrabold text-base text-[var(--text-main)]">Structured External Accounts & Links</h3>
                <button wire:click="$set('linkModalOpen', true)" class="px-3.5 py-2 rounded-xl accent-bg text-white font-bold text-xs transition cursor-pointer">
                    + Suggest Official Link
                </button>
            </div>

            @if($links->isEmpty())
                <div class="p-8 text-center rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)]">
                    <p class="text-xs text-[var(--text-muted)]">No external links registered for this artist yet.</p>
                </div>
            @else
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach($links as $link)
                        <div class="p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-2 flex flex-col justify-between">
                            <div class="flex items-center justify-between">
                                <span class="text-lg">{{ $link->platform_icon }}</span>
                                <span class="px-2 py-0.5 rounded-md text-[10px] uppercase font-bold {{ $link->status === 'verified' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-amber-500/10 text-amber-400 border border-amber-500/20' }}">
                                    {{ $link->status }}
                                </span>
                            </div>
                            <div>
                                <h4 class="font-bold text-sm text-[var(--text-main)] capitalize">{{ $link->platform }}</h4>
                                <a href="{{ $link->url }}" target="_blank" rel="noopener" class="text-xs accent-text hover:underline truncate block">
                                    {{ $link->url }}
                                </a>
                            </div>
                            @if($link->submittedByUser)
                                <div class="text-[10px] text-[var(--text-dim)] border-t border-[var(--border-subtle)] pt-2">
                                    Submitted by @<span>{{ $link->submittedByUser->username }}</span>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

    <!-- TAB 5: ABOUT & AUDIT HISTORY -->
    @elseif($activeTab === 'about')
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Left Info -->
            <div class="lg:col-span-2 space-y-6">
                <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-3">
                    <h3 class="font-extrabold text-base text-[var(--text-main)]">Artist Biography</h3>
                    <p class="text-sm text-[var(--text-muted)] leading-relaxed">
                        {{ $artist->bio ?: 'No description or biography provided for this artist catalog.' }}
                    </p>
                </div>

                <!-- Community Suggestion Actions -->
                <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-3">
                    <h3 class="font-extrabold text-base text-[var(--text-main)]">Community Wiki Actions</h3>
                    <p class="text-xs text-[var(--text-muted)]">Suggest edits, aliases, or report duplicate artists for moderator review.</p>
                    
                    <div class="flex flex-wrap gap-2 pt-1">
                        <button wire:click="$set('aliasModalOpen', true)" class="px-3 py-2 rounded-xl bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] text-xs font-bold text-[var(--text-main)] hover:bg-white/10 transition">
                            + Suggest Alias
                        </button>
                        <button wire:click="$set('linkModalOpen', true)" class="px-3 py-2 rounded-xl bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] text-xs font-bold text-[var(--text-main)] hover:bg-white/10 transition">
                            + Suggest Official Link
                        </button>
                        <button wire:click="$set('mergeModalOpen', true)" class="px-3 py-2 rounded-xl bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] text-xs font-bold text-[var(--text-main)] hover:bg-white/10 transition">
                            🔗 Request Artist Merge/Split
                        </button>
                    </div>
                </div>
            </div>

            <!-- Right Audit Log -->
            <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-4">
                <h3 class="font-extrabold text-base text-[var(--text-main)]">Edit History & Audit Log</h3>
                @if($edits->isEmpty())
                    <p class="text-xs text-[var(--text-dim)]">No audit entries recorded yet.</p>
                @else
                    <div class="space-y-3 text-xs">
                        @foreach($edits as $edit)
                            <div class="p-3 rounded-2xl bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] space-y-1">
                                <div class="flex items-center justify-between text-[11px] font-bold">
                                    <span class="capitalize text-[var(--text-main)]">{{ str_replace('_', ' ', $edit->action) }}</span>
                                    <span class="text-[10px] text-emerald-400">{{ $edit->status }}</span>
                                </div>
                                <div class="text-[var(--text-dim)]">
                                    By @<span>{{ $edit->user->username }}</span> • {{ $edit->created_at->diffForHumans() }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif

    <!-- MODAL 1: SUGGEST LINK -->
    @if($linkModalOpen)
        <div class="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-[var(--bg-surface)] border border-[var(--border-medium)] p-6 rounded-3xl max-w-md w-full space-y-4 shadow-2xl">
                <h3 class="font-extrabold text-lg text-[var(--text-main)]">Suggest External Artist Link</h3>

                <div class="space-y-3 text-xs">
                    <div>
                        <label class="font-bold text-[var(--text-muted)] block mb-1">Platform</label>
                        <select wire:model="linkPlatform" class="w-full bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] p-2.5 rounded-xl text-[var(--text-main)] outline-none">
                            <option value="website">Official Website</option>
                            <option value="x">X / Twitter</option>
                            <option value="pixiv">Pixiv</option>
                            <option value="bluesky">Bluesky</option>
                            <option value="patreon">Patreon</option>
                            <option value="fanbox">Fanbox</option>
                            <option value="instagram">Instagram</option>
                            <option value="deviantart">DeviantArt</option>
                            <option value="tumblr">Tumblr</option>
                            <option value="youtube">YouTube</option>
                            <option value="store">Store / BOOTH</option>
                        </select>
                    </div>

                    <div>
                        <label class="font-bold text-[var(--text-muted)] block mb-1">Full URL</label>
                        <input type="url" wire:model="linkUrl" placeholder="https://pixiv.net/users/12345" class="w-full bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] p-2.5 rounded-xl text-[var(--text-main)] outline-none">
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-2">
                    <button wire:click="$set('linkModalOpen', false)" class="px-4 py-2 text-xs font-bold text-[var(--text-dim)] hover:text-[var(--text-main)]">Cancel</button>
                    <button wire:click="submitSuggestLink" class="px-4 py-2 rounded-xl accent-bg text-white text-xs font-bold shadow">Submit Link</button>
                </div>
            </div>
        </div>
    @endif

    <!-- MODAL 2: CLAIM ARTIST PAGE -->
    @if($claimModalOpen)
        <div class="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-[var(--bg-surface)] border border-[var(--border-medium)] p-6 rounded-3xl max-w-lg w-full space-y-4 shadow-2xl">
                <h3 class="font-extrabold text-lg text-[var(--text-main)]">Claim {{ $artist->name }} Artist Page</h3>
                <p class="text-xs text-[var(--text-muted)]">
                    Verify your identity to claim management of this artist catalog.
                </p>

                <div class="p-3 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-xs text-amber-300 space-y-1">
                    <div class="font-bold">Verification Code:</div>
                    <code class="px-2 py-1 rounded bg-black/40 font-mono text-white select-all block text-center">{{ $claimVerificationCode }}</code>
                    <p class="text-[11px] opacity-80 pt-1">Place this code temporarily in your X bio, Pixiv bio, or website header.</p>
                </div>

                <div class="space-y-3 text-xs">
                    <div>
                        <label class="font-bold text-[var(--text-muted)] block mb-1">Verification Notes</label>
                        <textarea wire:model="claimNotes" rows="3" placeholder="Provide link to where you placed the code, or details for moderator review..." class="w-full bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] p-2.5 rounded-xl text-[var(--text-main)] outline-none"></textarea>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-2">
                    <button wire:click="$set('claimModalOpen', false)" class="px-4 py-2 text-xs font-bold text-[var(--text-dim)] hover:text-[var(--text-main)]">Cancel</button>
                    <button wire:click="submitClaimPage" class="px-4 py-2 rounded-xl accent-bg text-white text-xs font-bold shadow">Submit Claim Request</button>
                </div>
            </div>
        </div>
    @endif

    <!-- MODAL 3: SUGGEST ALIAS -->
    @if($aliasModalOpen)
        <div class="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-[var(--bg-surface)] border border-[var(--border-medium)] p-6 rounded-3xl max-w-md w-full space-y-4 shadow-2xl">
                <h3 class="font-extrabold text-lg text-[var(--text-main)]">Suggest an Alias</h3>
                <p class="text-xs text-[var(--text-muted)]">Aliases make searches for this artist's other names resolve to this page.</p>

                <div class="space-y-3 text-xs">
                    <div>
                        <label class="font-bold text-[var(--text-muted)] block mb-1">Alias name</label>
                        <input type="text" wire:model="aliasName" maxlength="100" placeholder="e.g. J.Staub"
                               class="w-full bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] p-2.5 rounded-xl text-[var(--text-main)] outline-none">
                        @error('aliasName') <span class="text-[11px] text-rose-400">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-2">
                    <button wire:click="$set('aliasModalOpen', false)" class="px-4 py-2 text-xs font-bold text-[var(--text-dim)] hover:text-[var(--text-main)]">Cancel</button>
                    <button wire:click="submitSuggestAlias" class="px-4 py-2 rounded-xl accent-bg text-white text-xs font-bold shadow">Submit Alias</button>
                </div>
            </div>
        </div>
    @endif

    <!-- MODAL 4: REQUEST MERGE -->
    @if($mergeModalOpen)
        <div class="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-[var(--bg-surface)] border border-[var(--border-medium)] p-6 rounded-3xl max-w-md w-full space-y-4 shadow-2xl">
                <h3 class="font-extrabold text-lg text-[var(--text-main)]">Request a Merge</h3>
                <p class="text-xs text-[var(--text-muted)]">Report a duplicate artist page so moderators can merge it into this one.</p>

                <div class="space-y-3 text-xs">
                    <div>
                        <label class="font-bold text-[var(--text-muted)] block mb-1">Duplicate artist name or URL</label>
                        <input type="text" wire:model="mergeTargetName" maxlength="100" placeholder="Name or slug of the duplicate page"
                               class="w-full bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] p-2.5 rounded-xl text-[var(--text-main)] outline-none">
                        @error('mergeTargetName') <span class="text-[11px] text-rose-400">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="font-bold text-[var(--text-muted)] block mb-1">Reason</label>
                        <textarea wire:model="mergeReason" rows="3" maxlength="500" placeholder="Why are these the same artist?"
                                  class="w-full bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] p-2.5 rounded-xl text-[var(--text-main)] outline-none"></textarea>
                        @error('mergeReason') <span class="text-[11px] text-rose-400">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 pt-2">
                    <button wire:click="$set('mergeModalOpen', false)" class="px-4 py-2 text-xs font-bold text-[var(--text-dim)] hover:text-[var(--text-main)]">Cancel</button>
                    <button wire:click="submitMergeRequest" class="px-4 py-2 rounded-xl accent-bg text-white text-xs font-bold shadow">Submit Merge Request</button>
                </div>
            </div>
        </div>
    @endif
</div>
