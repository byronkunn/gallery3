<?php

use App\Models\Artist;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $filterTab = 'popular'; // popular, trending, recently_added, most_works, alpha

    public string $claimFilter = 'all'; // all, claimed, unclaimed

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function setFilterTab(string $tab)
    {
        $this->filterTab = $tab;
        $this->resetPage();
    }

    public function setClaimFilter(string $claim)
    {
        $this->claimFilter = $claim;
        $this->resetPage();
    }

    public function toggleFollowArtist(int $artistId)
    {
        if (! Auth::check()) {
            $this->dispatch('notify', 'Please log in to follow artists');

            return;
        }

        $user = Auth::user();
        $artist = Artist::findOrFail($artistId);

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

    public function render()
    {
        $query = Artist::with(['aliases', 'links', 'claimedByUser']);

        if (! empty(trim($this->search))) {
            $term = trim($this->search);
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('bio', 'like', "%{$term}%")
                    ->orWhereHas('aliases', fn ($aq) => $aq->where('alias', 'like', "%{$term}%"));
            });
        }

        if ($this->claimFilter === 'claimed') {
            $query->where('is_claimed', true);
        } elseif ($this->claimFilter === 'unclaimed') {
            $query->where('is_claimed', false);
        }

        match ($this->filterTab) {
            'trending', 'popular' => $query->orderByDesc('followers_count')->orderByDesc('works_count'),
            'recently_added' => $query->latest(),
            'most_works' => $query->orderByDesc('works_count'),
            'alpha' => $query->orderBy('name', 'asc'),
            default => $query->orderByDesc('followers_count'),
        };

        $artists = $query->paginate(16);
        $user = Auth::user();

        return view('components.⚡artists-index', [
            'artists' => $artists,
            'currentUser' => $user,
        ]);
    }
};
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6 w-full min-w-0">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-[var(--border-subtle)] pb-5">
        <div>
            <h1 class="text-2xl font-black tracking-tight text-[var(--text-main)] flex items-center gap-2">
                <span>🎨 Artist Directory</span>
                <span class="text-xs px-2.5 py-1 rounded-full accent-bg text-white font-bold">Catalog</span>
            </h1>
            <p class="text-sm text-[var(--text-muted)] mt-1">
                Explore creator catalogs, verified artist pages, and community-attributed artwork index.
            </p>
        </div>

        @if(Auth::check())
            <a href="{{ route('settings') }}" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-medium)] hover:bg-[var(--bg-surface-elevated)] transition text-xs font-bold text-[var(--text-main)] shadow-sm">
                <svg class="w-4 h-4 accent-text" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                </svg>
                <span>Are you an Artist? Claim Page</span>
            </a>
        @endif
    </div>

    <!-- Search & Control Filter Bar -->
    <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-4">
        <!-- Search Input -->
        <div class="relative flex-1 max-w-md">
            <input type="text" 
                   wire:model.live.debounce.250ms="search" 
                   placeholder="Search artists by name or alias (e.g. JaneArt, Pixiv ID)..." 
                   class="w-full pl-10 pr-4 py-2.5 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] focus:border-[var(--accent-primary)] text-sm outline-none transition text-[var(--text-main)] placeholder-[var(--text-dim)] shadow-sm">
            <div class="absolute left-3.5 top-3 text-[var(--text-dim)]">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
            </div>
            @if(!empty($search))
                <button wire:click="$set('search', '')" class="absolute right-3 top-2.5 text-[var(--text-dim)] hover:text-[var(--text-main)] text-sm font-bold">&times;</button>
            @endif
        </div>

        <!-- Filter Sub-Tabs -->
        <div class="flex items-center gap-1 overflow-x-auto text-xs font-semibold">
            <button wire:click="setFilterTab('popular')" class="px-3 py-2 rounded-xl transition {{ $filterTab === 'popular' ? 'accent-bg text-white font-bold' : 'bg-[var(--bg-surface)] text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">Popular</button>
            <button wire:click="setFilterTab('trending')" class="px-3 py-2 rounded-xl transition {{ $filterTab === 'trending' ? 'accent-bg text-white font-bold' : 'bg-[var(--bg-surface)] text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">Trending</button>
            <button wire:click="setFilterTab('most_works')" class="px-3 py-2 rounded-xl transition {{ $filterTab === 'most_works' ? 'accent-bg text-white font-bold' : 'bg-[var(--bg-surface)] text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">Most Works</button>
            <button wire:click="setFilterTab('recently_added')" class="px-3 py-2 rounded-xl transition {{ $filterTab === 'recently_added' ? 'accent-bg text-white font-bold' : 'bg-[var(--bg-surface)] text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">Recently Added</button>
            <button wire:click="setFilterTab('alpha')" class="px-3 py-2 rounded-xl transition {{ $filterTab === 'alpha' ? 'accent-bg text-white font-bold' : 'bg-[var(--bg-surface)] text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">A–Z</button>
        </div>
    </div>

    <!-- Claim Filter Status Toggle -->
    <div class="flex items-center gap-2 text-xs">
        <span class="text-[var(--text-dim)] font-bold uppercase tracking-wider text-[10px]">Filter Status:</span>
        <button wire:click="setClaimFilter('all')" class="px-2.5 py-1 rounded-lg transition {{ $claimFilter === 'all' ? 'bg-white/10 text-[var(--text-main)] font-bold' : 'text-[var(--text-dim)] hover:text-[var(--text-main)]' }}">All Artists</button>
        <button wire:click="setClaimFilter('claimed')" class="px-2.5 py-1 rounded-lg transition {{ $claimFilter === 'claimed' ? 'bg-emerald-500/20 text-emerald-400 font-bold border border-emerald-500/30' : 'text-[var(--text-dim)] hover:text-[var(--text-main)]' }}">✓ Verified Claimed</button>
        <button wire:click="setClaimFilter('unclaimed')" class="px-2.5 py-1 rounded-lg transition {{ $claimFilter === 'unclaimed' ? 'bg-amber-500/20 text-amber-400 font-bold border border-amber-500/30' : 'text-[var(--text-dim)] hover:text-[var(--text-main)]' }}">Unclaimed Catalog</button>
    </div>

    <!-- Artist Cards Grid -->
    @if($artists->isEmpty())
        <div class="p-12 text-center rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-3">
            <div class="w-16 h-16 mx-auto rounded-3xl bg-neutral-800/50 flex items-center justify-center text-[var(--text-dim)] text-2xl">🎨</div>
            <h3 class="font-bold text-lg text-[var(--text-main)]">No artists found</h3>
            <p class="text-sm text-[var(--text-muted)]">Try adjusting your search criteria or filters.</p>
        </div>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
            @foreach($artists as $artist)
                @php
                    $isFollowing = $currentUser ? $artist->isFollowedBy($currentUser) : false;
                @endphp
                <div class="rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] hover:border-[var(--border-medium)] transition-all duration-300 shadow-md hover:shadow-xl overflow-hidden flex flex-col justify-between group">
                    <div>
                        <!-- Artist Banner / Header Header -->
                        <div class="h-24 w-full bg-gradient-to-r from-purple-900/40 via-indigo-900/40 to-slate-900/40 relative">
                            @if($artist->banner_url)
                                <img src="{{ $artist->banner_url }}" class="w-full h-full object-cover">
                            @endif
                            <div class="absolute inset-0 bg-black/20"></div>

                            <!-- Claim Badge -->
                            <div class="absolute top-3 right-3">
                                @if($artist->is_claimed)
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-emerald-500/90 backdrop-blur-md text-white font-extrabold text-[10px] uppercase shadow">
                                        ✓ Claimed Artist
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-black/60 backdrop-blur-md text-white/80 font-semibold text-[10px]">
                                        Unclaimed Catalog
                                    </span>
                                @endif
                            </div>
                        </div>

                        <!-- Info Area -->
                        <div class="px-5 pt-0 pb-4 relative">
                            <!-- Avatar -->
                            <div class="-mt-10 mb-3 flex items-end justify-between">
                                <a href="{{ route('artist.detail', $artist->slug) }}">
                                    <img src="{{ $artist->avatar }}" alt="{{ $artist->name }}" class="w-16 h-16 rounded-2xl object-cover ring-4 ring-[var(--bg-surface)] shadow-lg group-hover:scale-105 transition">
                                </a>
                                @if($artist->is_claimed && $artist->claimedByUser)
                                    <a href="{{ route('profile', $artist->claimedByUser->username) }}" class="text-[11px] font-semibold text-[var(--accent-primary)] hover:underline flex items-center gap-1">
                                        Managed by @<span>{{ $artist->claimedByUser->username }}</span>
                                    </a>
                                @endif
                            </div>

                            <!-- Name & Aliases -->
                            <a href="{{ route('artist.detail', $artist->slug) }}" class="block">
                                <h3 class="font-extrabold text-base text-[var(--text-main)] hover:underline flex items-center gap-1.5 truncate">
                                    <span>{{ $artist->name }}</span>
                                    @if($artist->is_claimed)
                                        <span class="text-sky-400" title="Verified Claimed Artist">✓</span>
                                    @endif
                                </h3>
                            </a>

                            @if($artist->aliases->isNotEmpty())
                                <p class="text-xs text-[var(--text-dim)] truncate mt-0.5">
                                    aka {{ $artist->aliases->pluck('alias')->take(3)->implode(', ') }}
                                </p>
                            @endif

                            @if($artist->bio)
                                <p class="text-xs text-[var(--text-muted)] line-clamp-2 mt-2 leading-relaxed">
                                    {{ $artist->bio }}
                                </p>
                            @endif

                            <!-- Stats -->
                            <div class="flex items-center gap-4 mt-4 pt-3 border-t border-[var(--border-subtle)] text-xs text-[var(--text-dim)] font-medium">
                                <div>
                                    <span class="font-black text-[var(--text-main)]">{{ number_format($artist->works_count) }}</span> works
                                </div>
                                <div>
                                    <span class="font-black text-[var(--text-main)]">{{ number_format($artist->followers_count) }}</span> followers
                                </div>
                            </div>

                            <!-- Platform Links Pills -->
                            @if($artist->links->isNotEmpty())
                                <div class="flex items-center gap-1.5 mt-3 flex-wrap">
                                    @foreach($artist->links->take(4) as $link)
                                        <a href="{{ $link->url }}" target="_blank" rel="noopener" class="px-2 py-0.5 rounded-lg bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] text-[10px] font-semibold text-[var(--text-muted)] hover:text-[var(--text-main)] transition flex items-center gap-1">
                                            <span>{{ $link->platform_icon }}</span>
                                            <span>{{ ucfirst($link->platform) }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>

                    <!-- Footer Action -->
                    <div class="p-4 pt-0">
                        <button wire:click="toggleFollowArtist({{ $artist->id }})" 
                                class="w-full py-2.5 rounded-xl font-bold text-xs transition flex items-center justify-center gap-2 cursor-pointer {{ $isFollowing ? 'bg-[var(--bg-surface-elevated)] border border-[var(--border-medium)] text-[var(--text-main)] hover:bg-rose-500/10 hover:text-rose-400 hover:border-rose-500/30' : 'accent-bg text-white hover:opacity-90 shadow-sm' }}">
                            @if($isFollowing)
                                <span>Following Artist</span>
                            @else
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path>
                                </svg>
                                <span>Follow Artist</span>
                            @endif
                        </button>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="pt-4">
            {{ $artists->links() }}
        </div>
    @endif
</div>
