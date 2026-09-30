<?php

use App\Models\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public string $activeTab = 'your'; // 'your', 'following', 'discover'

    public string $discoverSort = 'popular'; // 'popular', 'new', 'trending'

    public string $searchQuery = '';

    public bool $createModalOpen = false;

    public string $newTitle = '';

    public string $newDescription = '';

    public string $newVisibility = 'public'; // 'public', 'unlisted', 'private'

    public function setTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['your', 'following', 'discover'], true) ? $tab : 'your';
    }

    public function setDiscoverSort(string $sort): void
    {
        $this->discoverSort = in_array($sort, ['popular', 'new', 'trending'], true) ? $sort : 'popular';
    }

    public function createCollection(): void
    {
        abort_unless(Auth::check(), 401);
        $this->validate([
            'newTitle' => ['required', 'string', 'max:120'],
            'newDescription' => ['nullable', 'string', 'max:1000'],
            'newVisibility' => ['required', 'in:public,unlisted,private'],
        ]);

        $user = Auth::user();
        $collection = Collection::create([
            'user_id' => $user->id,
            'title' => trim($this->newTitle),
            'description' => trim($this->newDescription),
            'visibility' => $this->newVisibility,
            'is_private' => $this->newVisibility === 'private',
            'items_count' => 0,
            'followers_count' => 0,
        ]);

        $this->reset('newTitle', 'newDescription', 'createModalOpen');
        $this->newVisibility = 'public';
        $this->dispatch('notify', 'Collection created successfully!');

        $this->redirect(route('collection.detail', $collection->id));
    }

    public function toggleFollowCollection(int $collectionId): void
    {
        abort_unless(Auth::check(), 401);
        $user = Auth::user();
        $collection = Collection::findOrFail($collectionId);

        if ($collection->isFollowedBy($user)) {
            $user->followingCollections()->detach($collection->id);
            $collection->decrement('followers_count');
            $this->dispatch('notify', 'Unfollowed collection.');
        } else {
            $user->followingCollections()->attach($collection->id);
            $collection->increment('followers_count');
            $this->dispatch('notify', 'Followed collection!');
        }
    }

    public function render()
    {
        $user = Auth::user();

        // 1. User's own collections
        $myCollections = $user ? $user->collections()->with('items')->latest()->get() : collect();

        // 2. Followed collections
        $followedCollections = $user ? $user->followingCollections()->with(['user', 'items'])->get() : collect();

        // 3. Discover Public Collections
        $discoverQuery = Collection::where('visibility', 'public')->where('is_private', false)->with(['user', 'items']);
        if (! empty($this->searchQuery)) {
            $s = trim($this->searchQuery);
            $discoverQuery->where(function ($q) use ($s) {
                $q->where('title', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%");
            });
        }

        if ($this->discoverSort === 'new') {
            $discoverQuery->latest();
        } else {
            $discoverQuery->orderBy('followers_count', 'desc');
        }
        $discoverCollections = $discoverQuery->take(24)->get();

        return view('components.⚡collections-hub', [
            'myCollections' => $myCollections,
            'followedCollections' => $followedCollections,
            'discoverCollections' => $discoverCollections,
            'currentUser' => $user,
        ]);
    }
};
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8 w-full min-w-0">
    <!-- Top Header Bar -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-black text-[var(--text-main)] tracking-tight">Community Collections Hub</h1>
            <p class="text-sm text-[var(--text-muted)] mt-1">Curated galleries, character reference boards, and community art collections.</p>
        </div>

        @if(Auth::check())
            <button wire:click="$set('createModalOpen', true)" class="px-5 py-2.5 rounded-2xl accent-bg text-white font-bold text-xs shadow-lg hover:opacity-90 transition flex items-center gap-2">
                <span>+ Create Collection</span>
            </button>
        @endif
    </div>

    <!-- Sub-tabs: Your Collections | Following | Discover -->
    <div class="flex items-center justify-between border-b border-[var(--border-subtle)] gap-4 overflow-x-auto scrollbar-none">
        <div class="flex items-center gap-2">
            @if(Auth::check())
                <button wire:click="setTab('your')" class="relative py-3.5 px-4 font-bold text-sm transition {{ $activeTab === 'your' ? 'text-[var(--text-main)] font-black' : 'text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">
                    <span>Your Collections</span>
                    <span class="ml-1 text-xs opacity-60">({{ $myCollections->count() }})</span>
                    @if($activeTab === 'your') <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div> @endif
                </button>

                <button wire:click="setTab('following')" class="relative py-3.5 px-4 font-bold text-sm transition {{ $activeTab === 'following' ? 'text-[var(--text-main)] font-black' : 'text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">
                    <span>Following</span>
                    <span class="ml-1 text-xs opacity-60">({{ $followedCollections->count() }})</span>
                    @if($activeTab === 'following') <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div> @endif
                </button>
            @endif

            <button wire:click="setTab('discover')" class="relative py-3.5 px-4 font-bold text-sm transition {{ $activeTab === 'discover' || !Auth::check() ? 'text-[var(--text-main)] font-black' : 'text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">
                <span>Discover Public Collections</span>
                @if($activeTab === 'discover' || !Auth::check()) <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div> @endif
            </button>
        </div>

        @if($activeTab === 'discover' || !Auth::check())
            <div class="flex items-center gap-2 pb-2">
                <input type="text" wire:model.live.debounce.250ms="searchQuery" placeholder="Search collections..." class="px-3.5 py-1.5 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs outline-none focus:border-[var(--accent-primary)] w-48">
            </div>
        @endif
    </div>

    <!-- Collections Grid Display -->
    <div>
        @if($activeTab === 'your' && Auth::check())
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @forelse($myCollections as $c)
                    <div class="group relative rounded-3xl overflow-hidden bg-[var(--bg-surface)] border border-[var(--border-subtle)] hover:border-[var(--border-medium)] transition-all duration-300 shadow-md hover:shadow-xl flex flex-col">
                        <!-- Mosaic Cover Grid -->
                        <a href="{{ route('collection.detail', $c->id) }}" class="block w-full h-44 bg-neutral-900 overflow-hidden relative">
                            @php $mosaic = $c->mosaicThumbnails(4); @endphp
                            @if(count($mosaic) >= 4)
                                <div class="grid grid-cols-2 grid-rows-2 w-full h-full gap-0.5">
                                    <img src="{{ $mosaic[0] }}" class="w-full h-full object-cover">
                                    <img src="{{ $mosaic[1] }}" class="w-full h-full object-cover">
                                    <img src="{{ $mosaic[2] }}" class="w-full h-full object-cover">
                                    <img src="{{ $mosaic[3] }}" class="w-full h-full object-cover">
                                </div>
                            @elseif(count($mosaic) >= 1)
                                <img src="{{ $mosaic[0] }}" class="w-full h-full object-cover">
                            @else
                                <div class="w-full h-full flex items-center justify-center text-4xl text-[var(--text-dim)]">📁</div>
                            @endif

                            <!-- Visibility Badge Overlay -->
                            <div class="absolute top-3 right-3 px-2.5 py-1 rounded-xl bg-black/75 backdrop-blur-md text-white text-[10px] font-bold uppercase tracking-wider">
                                @if($c->isPrivate()) 🔒 Private @elseif($c->isUnlisted()) 🔗 Unlisted @else 🌐 Public @endif
                            </div>
                        </a>

                        <!-- Collection Details -->
                        <div class="p-5 space-y-2 flex-1 flex flex-col justify-between">
                            <div>
                                <a href="{{ route('collection.detail', $c->id) }}" class="font-extrabold text-base text-[var(--text-main)] hover:underline block truncate">
                                    {{ $c->title }}
                                </a>
                                @if($c->description)
                                    <p class="text-xs text-[var(--text-muted)] line-clamp-2 mt-1">{{ $c->description }}</p>
                                @endif
                            </div>

                            <div class="flex items-center justify-between text-xs text-[var(--text-dim)] pt-2 border-t border-[var(--border-subtle)]/60 font-semibold">
                                <span>{{ $c->items_count }} {{ Str::plural('item', $c->items_count) }}</span>
                                <span>👥 {{ number_format($c->followers_count) }} followers</span>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="col-span-full p-12 text-center rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-3">
                        <p class="font-bold text-base text-[var(--text-main)]">You haven't created any collections yet</p>
                        <p class="text-xs text-[var(--text-muted)] max-w-md mx-auto">Organize your favorite artworks into public curations, unlisted boards, or private inspiration folders.</p>
                        <button wire:click="$set('createModalOpen', true)" class="px-5 py-2.5 rounded-2xl accent-bg text-white font-bold text-xs shadow inline-block mt-2">
                            + Create First Collection
                        </button>
                    </div>
                @endforelse
            </div>
        @elseif($activeTab === 'following' && Auth::check())
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @forelse($followedCollections as $c)
                    <div class="group relative rounded-3xl overflow-hidden bg-[var(--bg-surface)] border border-[var(--border-subtle)] hover:border-[var(--border-medium)] transition-all duration-300 shadow-md hover:shadow-xl flex flex-col">
                        <a href="{{ route('collection.detail', $c->id) }}" class="block w-full h-44 bg-neutral-900 overflow-hidden relative">
                            @php $mosaic = $c->mosaicThumbnails(4); @endphp
                            @if(count($mosaic) >= 4)
                                <div class="grid grid-cols-2 grid-rows-2 w-full h-full gap-0.5">
                                    <img src="{{ $mosaic[0] }}" class="w-full h-full object-cover">
                                    <img src="{{ $mosaic[1] }}" class="w-full h-full object-cover">
                                    <img src="{{ $mosaic[2] }}" class="w-full h-full object-cover">
                                    <img src="{{ $mosaic[3] }}" class="w-full h-full object-cover">
                                </div>
                            @elseif(count($mosaic) >= 1)
                                <img src="{{ $mosaic[0] }}" class="w-full h-full object-cover">
                            @else
                                <div class="w-full h-full flex items-center justify-center text-4xl text-[var(--text-dim)]">📁</div>
                            @endif
                        </a>

                        <div class="p-5 space-y-3 flex-1 flex flex-col justify-between">
                            <div>
                                <a href="{{ route('collection.detail', $c->id) }}" class="font-extrabold text-base text-[var(--text-main)] hover:underline block truncate">
                                    {{ $c->title }}
                                </a>
                                <div class="text-xs text-[var(--text-dim)] mt-0.5">Curated by @<span>{{ $c->user->username }}</span></div>
                            </div>

                            <div class="flex items-center justify-between text-xs text-[var(--text-dim)] pt-2 border-t border-[var(--border-subtle)]/60 font-semibold">
                                <span>{{ $c->items_count }} items</span>
                                <button wire:click="toggleFollowCollection({{ $c->id }})" class="px-3 py-1 rounded-xl border border-rose-500/30 text-rose-400 font-bold hover:bg-rose-500/10 transition">
                                    Unfollow
                                </button>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="col-span-full p-12 text-center rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-muted)] space-y-2">
                        <p class="font-bold text-base text-[var(--text-main)]">Not following any collections yet</p>
                        <p>Explore public collections curated by other users to follow their updates.</p>
                    </div>
                @endforelse
            </div>
        @else
            <!-- Discover Public Collections -->
            <div class="mb-4 flex flex-wrap items-center gap-2">
                <span class="text-[11px] font-bold uppercase tracking-wide text-[var(--text-dim)]">Sort</span>
                @foreach(['popular' => 'Popular', 'new' => 'Newest', 'trending' => 'Trending'] as $sortKey => $sortLabel)
                    <button wire:click="setDiscoverSort('{{ $sortKey }}')"
                            class="rounded-xl border px-3 py-1.5 text-xs font-bold transition {{ $discoverSort === $sortKey ? 'accent-bg text-white border-transparent' : 'border-[var(--border-subtle)] text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">
                        {{ $sortLabel }}
                    </button>
                @endforeach
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @forelse($discoverCollections as $c)
                    <div class="group relative rounded-3xl overflow-hidden bg-[var(--bg-surface)] border border-[var(--border-subtle)] hover:border-[var(--border-medium)] transition-all duration-300 shadow-md hover:shadow-xl flex flex-col">
                        <a href="{{ route('collection.detail', $c->id) }}" class="block w-full h-44 bg-neutral-900 overflow-hidden relative">
                            @php $mosaic = $c->mosaicThumbnails(4); @endphp
                            @if(count($mosaic) >= 4)
                                <div class="grid grid-cols-2 grid-rows-2 w-full h-full gap-0.5">
                                    <img src="{{ $mosaic[0] }}" class="w-full h-full object-cover">
                                    <img src="{{ $mosaic[1] }}" class="w-full h-full object-cover">
                                    <img src="{{ $mosaic[2] }}" class="w-full h-full object-cover">
                                    <img src="{{ $mosaic[3] }}" class="w-full h-full object-cover">
                                </div>
                            @elseif(count($mosaic) >= 1)
                                <img src="{{ $mosaic[0] }}" class="w-full h-full object-cover">
                            @else
                                <div class="w-full h-full flex items-center justify-center text-4xl text-[var(--text-dim)]">📁</div>
                            @endif
                        </a>

                        <div class="p-5 space-y-3 flex-1 flex flex-col justify-between">
                            <div>
                                <a href="{{ route('collection.detail', $c->id) }}" class="font-extrabold text-base text-[var(--text-main)] hover:underline block truncate">
                                    {{ $c->title }}
                                </a>
                                <div class="text-xs text-[var(--text-dim)] mt-0.5">Curated by @<span>{{ $c->user->username }}</span></div>
                            </div>

                            <div class="flex items-center justify-between text-xs text-[var(--text-dim)] pt-2 border-t border-[var(--border-subtle)]/60 font-semibold">
                                <span>{{ $c->items_count }} {{ Str::plural('item', $c->items_count) }}</span>
                                @if(Auth::check() && Auth::id() !== $c->user_id)
                                    <button wire:click="toggleFollowCollection({{ $c->id }})" class="px-3.5 py-1.5 rounded-xl font-bold transition {{ $c->isFollowedBy($currentUser) ? 'border border-[var(--border-medium)] text-[var(--text-muted)]' : 'accent-bg text-white shadow' }}">
                                        {{ $c->isFollowedBy($currentUser) ? 'Following' : 'Follow' }}
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="col-span-full p-12 text-center rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs text-[var(--text-muted)]">
                        No public collections found matching your search.
                    </div>
                @endforelse
            </div>
        @endif
    </div>

    <!-- Create Collection Modal -->
    @if(Auth::check())
        <div x-show="$wire.createModalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70" @click.self="$wire.set('createModalOpen', false)">
            <form wire:submit="createCollection" class="w-full max-w-lg rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] p-6 space-y-4 shadow-2xl">
                <h2 class="text-lg font-black text-[var(--text-main)]">Create New Collection</h2>

                <div>
                    <label class="block text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider mb-1">Collection Name *</label>
                    <input type="text" wire:model="newTitle" placeholder="e.g. Cyberpunk Favorites, Character References..." class="w-full p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)] font-bold">
                    @error('newTitle') <p class="text-xs text-rose-400 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider mb-1">Description (Optional)</label>
                    <textarea wire:model="newDescription" rows="3" placeholder="What is this collection about?" class="w-full p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)]"></textarea>
                </div>

                <!-- 3 Visibility Options: Public, Unlisted, Private -->
                <div>
                    <label class="block text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider mb-2">Visibility Settings</label>
                    <div class="grid grid-cols-3 gap-2">
                        <button type="button" wire:click="$set('newVisibility', 'public')" class="p-2.5 rounded-xl border text-center transition {{ $newVisibility === 'public' ? 'border-[var(--accent-primary)] bg-[var(--accent-primary)]/10 text-[var(--text-main)] font-bold' : 'border-[var(--border-subtle)] bg-[var(--bg-page)] text-[var(--text-muted)]' }}">
                            <div class="text-sm">🌐</div>
                            <div class="text-xs font-bold mt-1">Public</div>
                        </button>
                        <button type="button" wire:click="$set('newVisibility', 'unlisted')" class="p-2.5 rounded-xl border text-center transition {{ $newVisibility === 'unlisted' ? 'border-amber-500/50 bg-amber-500/10 text-[var(--text-main)] font-bold' : 'border-[var(--border-subtle)] bg-[var(--bg-page)] text-[var(--text-muted)]' }}">
                            <div class="text-sm">🔗</div>
                            <div class="text-xs font-bold mt-1">Unlisted</div>
                        </button>
                        <button type="button" wire:click="$set('newVisibility', 'private')" class="p-2.5 rounded-xl border text-center transition {{ $newVisibility === 'private' ? 'border-purple-500/50 bg-purple-500/10 text-[var(--text-main)] font-bold' : 'border-[var(--border-subtle)] bg-[var(--bg-page)] text-[var(--text-muted)]' }}">
                            <div class="text-sm">🔒</div>
                            <div class="text-xs font-bold mt-1">Private</div>
                        </button>
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" wire:click="$set('createModalOpen', false)" class="px-4 py-2.5 text-xs font-bold text-[var(--text-muted)]">Cancel</button>
                    <button type="submit" class="px-6 py-2.5 rounded-2xl accent-bg text-white font-bold text-xs shadow">Create Collection</button>
                </div>
            </form>
        </div>
    @endif
</div>
