<?php

use App\Models\Artist;
use App\Models\Collection;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public string $tab = 'artists'; // 'artists', 'users', 'tags', 'collections'

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['artists', 'users', 'tags', 'collections'], true) ? $tab : 'artists';
    }

    public function unfollowArtist(int $artistId): void
    {
        $user = Auth::user();
        if ($user) {
            $user->followingArtists()->detach($artistId);
            $artist = Artist::find($artistId);
            if ($artist) {
                $artist->decrement('followers_count');
            }
            $this->dispatch('notify', 'Unfollowed artist.');
        }
    }

    public function unfollowUser(int $userId): void
    {
        $user = Auth::user();
        if ($user) {
            $user->following()->detach($userId);
            $this->dispatch('notify', 'Unfollowed user.');
        }
    }

    public function unfollowTag(int $tagId): void
    {
        $user = Auth::user();
        if ($user) {
            $user->followedTags()->detach($tagId);
            $this->dispatch('notify', 'Unfollowed tag.');
        }
    }

    public function unfollowCollection(int $collectionId): void
    {
        $user = Auth::user();
        if ($user) {
            $user->followingCollections()->detach($collectionId);
            $this->dispatch('notify', 'Unfollowed collection.');
        }
    }

    public function render()
    {
        $user = Auth::user();
        $followedArtists = $user ? $user->followingArtists()->with('aliases')->get() : collect();
        $followedUsers = $user ? $user->following()->get() : collect();
        $followedTags = $user ? $user->followedTags()->get() : collect();
        $followedCollections = $user ? $user->followingCollections()->with(['user', 'items'])->get() : collect();

        return view('components.⚡following-view', [
            'followedArtists' => $followedArtists,
            'followedUsers' => $followedUsers,
            'followedTags' => $followedTags,
            'followedCollections' => $followedCollections,
        ]);
    }
};
?>

<div class="max-w-5xl mx-auto px-4 sm:px-6 py-8 space-y-6 w-full min-w-0">
    <!-- Header Title -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-[var(--text-main)] tracking-tight">Following Management</h1>
            <p class="text-xs text-[var(--text-muted)] mt-1">Manage your explicitly followed artist catalogs, site creators, Danbooru tags, and collections.</p>
        </div>
        <a href="{{ route('gallery', ['tab' => 'following']) }}" class="px-4 py-2 rounded-xl accent-bg text-white font-bold text-xs shadow hover:opacity-90 transition flex items-center gap-1.5">
            <span>View Following Feed →</span>
        </a>
    </div>

    <!-- Navigation Sub-tabs -->
    <div class="flex items-center border-b border-[var(--border-subtle)] gap-2 overflow-x-auto">
        <button wire:click="setTab('artists')" class="relative py-3 px-4 font-bold text-sm transition {{ $tab === 'artists' ? 'text-[var(--text-main)] font-black' : 'text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">
            <span>Artists ({{ $followedArtists->count() }})</span>
            @if($tab === 'artists') <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div> @endif
        </button>

        <button wire:click="setTab('users')" class="relative py-3 px-4 font-bold text-sm transition {{ $tab === 'users' ? 'text-[var(--text-main)] font-black' : 'text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">
            <span>Creators / Accounts ({{ $followedUsers->count() }})</span>
            @if($tab === 'users') <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div> @endif
        </button>

        <button wire:click="setTab('tags')" class="relative py-3 px-4 font-bold text-sm transition {{ $tab === 'tags' ? 'text-[var(--text-main)] font-black' : 'text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">
            <span>Tags ({{ $followedTags->count() }})</span>
            @if($tab === 'tags') <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div> @endif
        </button>

        <button wire:click="setTab('collections')" class="relative py-3 px-4 font-bold text-sm transition {{ $tab === 'collections' ? 'text-[var(--text-main)] font-black' : 'text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">
            <span>Collections ({{ $followedCollections->count() }})</span>
            @if($tab === 'collections') <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div> @endif
        </button>
    </div>

    <!-- Tab Content -->
    <div>
        @if($tab === 'artists')
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @forelse($followedArtists as $artist)
                    <div class="p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] flex items-center justify-between gap-3">
                        <a href="{{ route('artist.detail', $artist->slug) }}" class="flex items-center gap-3 min-w-0">
                            <img src="{{ $artist->avatar }}" class="w-10 h-10 rounded-xl object-cover shrink-0">
                            <div class="min-w-0">
                                <div class="font-bold text-sm text-[var(--text-main)] truncate flex items-center gap-1">
                                    <span>{{ $artist->name }}</span>
                                    @if($artist->is_claimed)
                                        <span class="text-sky-400 text-xs">✓</span>
                                    @endif
                                </div>
                                <div class="text-xs text-[var(--text-dim)] truncate">{{ number_format($artist->works_count) }} works</div>
                            </div>
                        </a>
                        <button wire:click="unfollowArtist({{ $artist->id }})" class="px-3 py-1.5 rounded-xl border border-rose-500/30 text-rose-400 text-xs font-bold hover:bg-rose-500/10 transition shrink-0 cursor-pointer">
                            Unfollow
                        </button>
                    </div>
                @empty
                    <div class="col-span-full p-8 text-center rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-muted)] space-y-2">
                        <p class="font-bold text-sm text-[var(--text-main)]">Not following any artists yet</p>
                        <p>Follow artist catalogs to receive all newly attributed artwork directly in your Following feed.</p>
                    </div>
                @endforelse
            </div>
        @elseif($tab === 'users')
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @forelse($followedUsers as $u)
                    <div class="p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] flex items-center justify-between gap-3">
                        <a href="{{ route('profile', $u->username) }}" class="flex items-center gap-3 min-w-0">
                            <img src="{{ $u->avatar_url }}" class="w-10 h-10 rounded-full object-cover shrink-0">
                            <div class="min-w-0">
                                <div class="font-bold text-sm text-[var(--text-main)] truncate">{{ $u->name }}</div>
                                <div class="text-xs text-[var(--text-dim)] truncate">@<span>{{ $u->username }}</span></div>
                            </div>
                        </a>
                        <button wire:click="unfollowUser({{ $u->id }})" class="px-3 py-1.5 rounded-xl border border-rose-500/30 text-rose-400 text-xs font-bold hover:bg-rose-500/10 transition shrink-0 cursor-pointer">
                            Unfollow
                        </button>
                    </div>
                @empty
                    <div class="col-span-full p-8 text-center rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-muted)] space-y-2">
                        <p class="font-bold text-sm text-[var(--text-main)]">Not following any creators yet</p>
                        <p>Explore the gallery or search for users to follow their site activity directly in your Following feed.</p>
                    </div>
                @endforelse
            </div>
        @elseif($tab === 'tags')
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                @forelse($followedTags as $t)
                    <div class="p-3.5 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-extrabold text-xs accent-text truncate">#{{ $t->name }}</div>
                            <div class="text-[10px] text-[var(--text-dim)]">{{ number_format($t->posts_count) }} posts</div>
                        </div>
                        <button wire:click="unfollowTag({{ $t->id }})" class="px-3 py-1 rounded-xl border border-rose-500/30 text-rose-400 text-xs font-bold hover:bg-rose-500/10 transition shrink-0 cursor-pointer">
                            Unfollow
                        </button>
                    </div>
                @empty
                    <div class="col-span-full p-8 text-center rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-muted)] space-y-2">
                        <p class="font-bold text-sm text-[var(--text-main)]">Not following any tags yet</p>
                        <p>Follow Danbooru tags like #cyberpunk, #concept_art, or #character_design to customize your feed.</p>
                    </div>
                @endforelse
            </div>
        @elseif($tab === 'collections')
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @forelse($followedCollections as $c)
                    <div class="p-4 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-12 h-12 rounded-xl bg-neutral-900 border border-[var(--border-subtle)] overflow-hidden shrink-0 flex items-center justify-center">
                                @php $mosaic = $c->mosaicThumbnails(1); @endphp
                                @if(!empty($mosaic))
                                    <img src="{{ $mosaic[0] }}" class="w-full h-full object-cover">
                                @else
                                    <span class="text-xs">📁</span>
                                @endif
                            </div>
                            <div class="min-w-0">
                                <a href="{{ route('collection.detail', $c->id) }}" class="font-bold text-sm text-[var(--text-main)] hover:underline truncate block">
                                    {{ $c->title }}
                                </a>
                                <div class="text-xs text-[var(--text-dim)] truncate">By @<span>{{ $c->user->username }}</span> • {{ $c->items_count }} posts</div>
                            </div>
                        </div>
                        <button wire:click="unfollowCollection({{ $c->id }})" class="px-3 py-1.5 rounded-xl border border-rose-500/30 text-rose-400 text-xs font-bold hover:bg-rose-500/10 transition shrink-0 cursor-pointer">
                            Unfollow
                        </button>
                    </div>
                @empty
                    <div class="col-span-full p-8 text-center rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-muted)] space-y-2">
                        <p class="font-bold text-sm text-[var(--text-main)]">Not following any collections yet</p>
                        <p>Follow community-curated galleries to receive their updates directly in your Following feed.</p>
                    </div>
                @endforelse
            </div>
        @endif
    </div>
</div>
