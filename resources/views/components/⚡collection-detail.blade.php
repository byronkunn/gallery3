<?php

use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Tag;
use App\Support\Notifier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

new class extends Component
{
    public int $collectionId;

    public bool $editModalOpen = false;

    public string $editTitle = '';

    public string $editDescription = '';

    public string $editVisibility = 'public'; // 'public', 'unlisted', 'private'

    public bool $editIsPrivate = false;

    public string $collectionSearch = '';

    public string $activeTagFilter = '';

    public string $sortMode = 'manual'; // 'manual', 'newest_added', 'oldest_added', 'newest_post', 'most_liked'

    public function mount(int $collectionId)
    {
        $this->collectionId = $collectionId;
        $coll = Collection::findOrFail($collectionId);
        $this->editTitle = $coll->title;
        $this->editDescription = $coll->description ?? '';
        $this->editVisibility = $coll->visibility ?: ($coll->is_private ? 'private' : 'public');
        $this->editIsPrivate = $coll->is_private;
    }

    public function filterByTag(string $tagName): void
    {
        $this->activeTagFilter = $this->activeTagFilter === $tagName ? '' : $tagName;
    }

    public function toggleFollow()
    {
        if (! Auth::check()) {
            $this->dispatch('notify', 'Please log in to follow collections');

            return;
        }

        $user = Auth::user();
        $coll = Collection::findOrFail($this->collectionId);
        abort_if($coll->isPrivate() && $user->id !== $coll->user_id, 404);

        if ($coll->isFollowedBy($user)) {
            $user->followingCollections()->detach($coll->id);
            $coll->decrement('followers_count');
            $this->dispatch('notify', "Unfollowed collection '{$coll->title}'");
        } else {
            $user->followingCollections()->attach($coll->id);
            $coll->increment('followers_count');
            Notifier::collectionFollow($coll, $user);
            $this->dispatch('notify', "Following collection '{$coll->title}'!");
        }
    }

    public function moveItem(int $itemId, string $direction)
    {
        $user = Auth::user();
        $coll = Collection::findOrFail($this->collectionId);
        if (! $user || $user->id !== $coll->user_id) {
            return;
        }

        $items = $coll->items()->orderBy('order', 'asc')->get();
        $currentIndex = $items->search(fn ($i) => $i->id === $itemId);

        if ($currentIndex === false) {
            return;
        }

        $targetIndex = $direction === 'up' ? $currentIndex - 1 : $currentIndex + 1;
        if ($targetIndex < 0 || $targetIndex >= $items->count()) {
            return;
        }

        $currentItem = $items[$currentIndex];
        $targetItem = $items[$targetIndex];

        $tempOrder = $currentItem->order;
        $currentItem->update(['order' => $targetItem->order]);
        $targetItem->update(['order' => $tempOrder]);

        $this->dispatch('notify', 'Item reordered');
    }

    public function removeItem(int $itemId)
    {
        $user = Auth::user();
        $coll = Collection::findOrFail($this->collectionId);
        if (! $user || $user->id !== $coll->user_id) {
            return;
        }

        $item = CollectionItem::where('collection_id', $coll->id)->findOrFail($itemId);
        $item->delete();
        $coll->decrement('items_count');
        $this->dispatch('notify', 'Removed from collection');
    }

    public function saveSettings()
    {
        $user = Auth::user();
        $coll = Collection::findOrFail($this->collectionId);
        if (! $user || $user->id !== $coll->user_id) {
            return;
        }

        if ($this->editIsPrivate && $this->editVisibility !== 'private') {
            $this->editVisibility = 'private';
        }

        $isPrivate = $this->editVisibility === 'private' || $this->editIsPrivate;
        $visibility = $this->editVisibility ?: ($isPrivate ? 'private' : 'public');

        $coll->update([
            'title' => $this->editTitle,
            'description' => $this->editDescription,
            'visibility' => $visibility,
            'is_private' => $isPrivate,
        ]);

        $this->editModalOpen = false;
        $this->dispatch('notify', 'Collection updated!');
    }

    public function render()
    {
        $user = Auth::user();
        $collection = Collection::with(['user', 'items.post.primaryMedia', 'items.post.user', 'items.post.tags'])->findOrFail($this->collectionId);
        abort_if($collection->isPrivate() && (! $user || $user->id !== $collection->user_id) && ! $user?->isAdmin(), 404);

        $isMe = $user && $user->id === $collection->user_id;
        $isFollowed = $collection->isFollowedBy($user);

        // Top tag frequency breakdown across all posts in collection
        $allPostIds = $collection->items->pluck('post_id')->filter()->all();
        $topTags = collect();
        if ($allPostIds !== []) {
            $topTags = Tag::join('post_tag', 'tags.id', '=', 'post_tag.tag_id')
                ->whereIn('post_tag.post_id', $allPostIds)
                ->select('tags.name', 'tags.type', DB::raw('count(post_tag.post_id) as collection_posts_count'))
                ->groupBy('tags.id', 'tags.name', 'tags.type')
                ->orderByDesc('collection_posts_count')
                ->take(12)
                ->get();
        }

        // Filter and Sort Items
        $filteredItems = $collection->items->filter(function ($item) {
            $post = $item->post;
            if (! $post) {
                return false;
            }

            if (filled($this->activeTagFilter)) {
                if (! $post->tags->pluck('name')->contains($this->activeTagFilter)) {
                    return false;
                }
            }

            if (filled(trim($this->collectionSearch))) {
                $s = strtolower(trim($this->collectionSearch));
                $titleMatch = str_contains(strtolower((string) $post->title), $s);
                $tagMatch = $post->tags->pluck('name')->contains(fn ($tName) => str_contains(strtolower($tName), $s));
                $authorMatch = str_contains(strtolower((string) $post->user?->username), $s);
                if (! $titleMatch && ! $tagMatch && ! $authorMatch) {
                    return false;
                }
            }

            return true;
        });

        // Sorting
        $sortedItems = match ($this->sortMode) {
            'newest_added' => $filteredItems->sortByDesc('created_at'),
            'oldest_added' => $filteredItems->sortBy('created_at'),
            'newest_post' => $filteredItems->sortByDesc(fn ($i) => $i->post?->created_at),
            'most_liked' => $filteredItems->sortByDesc(fn ($i) => $i->post?->likes_count),
            default => $filteredItems->sortBy('order'),
        };

        $mediaList = $sortedItems->map(fn ($item) => [
            'url' => $item->post->primaryMedia->url,
            'thumbnail_url' => $item->post->primaryMedia->thumbnail_url ?? $item->post->primaryMedia->url,
            'type' => $item->post->media_type,
            'title' => $item->post->title,
            'author' => $item->post->user->name,
            'postId' => $item->post->id,
        ])->values()->toArray();

        return view('components.⚡collection-detail', [
            'collection' => $collection,
            'items' => $sortedItems,
            'topTags' => $topTags,
            'isMe' => $isMe,
            'isFollowed' => $isFollowed,
            'mediaList' => $mediaList,
            'currentUser' => $user,
        ]);
    }
};
?>

<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
    <!-- Back Navigation -->
    <div class="flex items-center justify-between">
        <a href="{{ route('collections.hub') }}" class="inline-flex items-center gap-2 text-sm font-semibold text-[var(--text-muted)] hover:text-[var(--text-main)] transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            <span>Back to Collections Hub</span>
        </a>

        <!-- Privacy Status Badge -->
        <span class="px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider {{ $collection->isPrivate() ? 'bg-purple-500/20 text-purple-400 border border-purple-500/30' : ($collection->isUnlisted() ? 'bg-amber-500/20 text-amber-400 border border-amber-500/30' : 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30') }}">
            @if($collection->isPrivate()) 🔒 Private Collection @elseif($collection->isUnlisted()) 🔗 Unlisted Collection @else 🌐 Public Collection @endif
        </span>
    </div>

    <!-- Collection Header Card -->
    <div class="p-6 sm:p-8 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] shadow-xl flex flex-col md:flex-row gap-6 items-start">
        <div class="w-full md:w-56 aspect-video md:aspect-square rounded-2xl overflow-hidden bg-neutral-900 shadow shrink-0">
            @php $mosaic = $collection->mosaicThumbnails(4); @endphp
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
        </div>

        <div class="flex-1 min-w-0 flex flex-col justify-between space-y-4">
            <div>
                <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-[var(--text-main)]">{{ $collection->title }}</h1>

                @if($collection->description)
                    <p class="text-sm text-[var(--text-muted)] mt-2 leading-relaxed">{{ $collection->description }}</p>
                @endif

                <div class="flex items-center gap-2 text-xs text-[var(--text-dim)] mt-3">
                    <span>Curated by:</span>
                    <a href="{{ route('profile', $collection->user->username) }}" class="font-bold text-[var(--text-main)] hover:underline flex items-center gap-1.5">
                        <img src="{{ $collection->user->avatar_url }}" class="w-4 h-4 rounded-full object-cover">
                        <span>{{ $collection->user->name }}</span>
                    </a>
                </div>
            </div>

            <!-- Stats & Action Buttons -->
            <div class="flex flex-wrap items-center justify-between gap-3 pt-4 border-t border-[var(--border-subtle)]">
                <div class="flex items-center gap-6 text-sm text-[var(--text-dim)]">
                    <div><span class="font-extrabold text-[var(--text-main)]">{{ $collection->items_count }}</span> Artworks</div>
                    <div><span class="font-extrabold text-[var(--text-main)]">{{ number_format($collection->followers_count) }}</span> Followers</div>
                </div>

                <div class="flex items-center gap-2">
                    @if(count($mediaList) > 0)
                        <button @click="$dispatch('open-lightbox', { items: {{ json_encode($mediaList) }}, startIndex: 0, title: '{{ addslashes($collection->title) }}', author: '{{ addslashes($collection->user->name) }}' })" 
                                class="px-4 py-2 rounded-xl bg-[var(--bg-surface-elevated)] hover:bg-[var(--bg-surface)] text-xs font-bold border border-[var(--border-subtle)] flex items-center gap-1.5 transition">
                            <svg class="w-4 h-4 accent-text" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <span>Slideshow</span>
                        </button>
                    @endif

                    @if($isMe)
                        <button wire:click="$set('editModalOpen', true)" class="px-4 py-2 rounded-xl text-xs font-bold border border-[var(--border-medium)] hover:bg-[var(--bg-surface-elevated)] transition">
                            Edit Settings
                        </button>
                    @elseif(Auth::check())
                        <button wire:click="toggleFollow" class="px-5 py-2 rounded-xl font-bold text-xs shadow transition {{ $isFollowed ? 'border border-[var(--border-medium)] text-[var(--text-muted)]' : 'accent-bg text-white' }}">
                            {{ $isFollowed ? 'Following' : 'Follow Collection' }}
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Top Tag Breakdown & Internal Search Bar -->
    <div class="p-4 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-3">
        <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
            <!-- Search Inside Collection -->
            <input type="text" wire:model.live.debounce.250ms="collectionSearch" placeholder="Search inside this collection (#tag, title, @artist)..." class="flex-1 px-3.5 py-2 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-xs outline-none focus:border-[var(--accent-primary)] font-mono">

            <!-- Sort Modes -->
            <select wire:model.live="sortMode" class="px-3.5 py-2 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-xs outline-none focus:border-[var(--accent-primary)] font-bold text-[var(--text-main)]">
                <option value="manual">Sort: Manual Order</option>
                <option value="newest_added">Sort: Recently Added</option>
                <option value="oldest_added">Sort: Oldest Added</option>
                <option value="newest_post">Sort: Newest Artwork</option>
                <option value="most_liked">Sort: Most Liked</option>
            </select>
        </div>

        <!-- Top Tag Frequency Bar -->
        @if($topTags->isNotEmpty())
            <div class="flex items-center gap-2 overflow-x-auto pt-1 scrollbar-none">
                <span class="text-[11px] font-bold text-[var(--text-dim)] uppercase tracking-wider shrink-0">Top Tags in Collection:</span>
                @foreach($topTags as $t)
                    <button wire:click="filterByTag('{{ $t->name }}')" class="px-2.5 py-1 rounded-xl text-xs font-medium border shrink-0 transition {{ $activeTagFilter === $t->name ? 'accent-bg text-white border-transparent font-bold' : 'border-[var(--border-subtle)] bg-[var(--bg-page)] text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">
                        #{{ $t->name }}
                        <span class="opacity-60 ml-0.5 text-[10px]">({{ $t->collection_posts_count }})</span>
                    </button>
                @endforeach
                @if($activeTagFilter)
                    <button wire:click="$set('activeTagFilter', '')" class="text-xs text-rose-400 hover:underline shrink-0">Clear filter</button>
                @endif
            </div>
        @endif
    </div>

    <!-- Collection Items Grid -->
    <div class="space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
            @forelse($items as $idx => $item)
                @php
                    $p = $item->post;
                @endphp
                <div class="rounded-3xl overflow-hidden bg-[var(--bg-surface)] border border-[var(--border-subtle)] hover:border-[var(--border-medium)] transition shadow-sm flex flex-col justify-between group">
                    <div class="relative aspect-video bg-neutral-900 overflow-hidden cursor-pointer"
                         @click="$dispatch('open-lightbox', { items: {{ json_encode($mediaList) }}, startIndex: {{ $idx }}, title: '{{ addslashes($p->title ?? 'Post') }}', author: '{{ addslashes($p->user->name) }}', postId: {{ $p->id }} })">
                        <img src="{{ $p->primaryMedia->url }}" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105">
                        <div class="absolute top-2 left-2 px-2.5 py-0.5 rounded-lg bg-black/75 backdrop-blur-md text-white font-mono text-xs font-bold">
                            #{{ $idx + 1 }}
                        </div>
                    </div>

                    <div class="p-4 space-y-3">
                        <div class="flex items-center justify-between gap-2">
                            <a href="{{ route('post.detail', $p->id) }}" class="font-bold text-sm truncate text-[var(--text-main)] hover:underline flex-1">
                                {{ $p->title ?? 'Untitled Post' }}
                            </a>
                            <span class="text-xs text-[var(--text-dim)] shrink-0">by {{ $p->user->name }}</span>
                        </div>

                        <!-- Reorder Controls if Owner & Manual Sort -->
                        @if($isMe && $sortMode === 'manual')
                            <div class="flex items-center justify-between pt-2 border-t border-[var(--border-subtle)]">
                                <div class="flex items-center gap-1">
                                    <button wire:click="moveItem({{ $item->id }}, 'up')" 
                                            {{ $idx === 0 ? 'disabled' : '' }}
                                            class="p-1.5 rounded-lg bg-[var(--bg-surface-elevated)] hover:bg-[var(--accent-primary)] hover:text-white disabled:opacity-30 transition"
                                            title="Move Earlier">
                                        ▲
                                    </button>
                                    <button wire:click="moveItem({{ $item->id }}, 'down')" 
                                            {{ $idx === count($items) - 1 ? 'disabled' : '' }}
                                            class="p-1.5 rounded-lg bg-[var(--bg-surface-elevated)] hover:bg-[var(--accent-primary)] hover:text-white disabled:opacity-30 transition"
                                            title="Move Later">
                                        ▼
                                    </button>
                                </div>

                                <button wire:click="removeItem({{ $item->id }})" class="text-xs text-rose-400 hover:text-rose-200">
                                    Remove
                                </button>
                            </div>
                        @elseif($isMe)
                            <div class="flex justify-end pt-2 border-t border-[var(--border-subtle)]">
                                <button wire:click="removeItem({{ $item->id }})" class="text-xs text-rose-400 hover:text-rose-200">
                                    Remove
                                </button>
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="col-span-full p-12 text-center text-sm text-[var(--text-dim)]">No items found matching your filters.</div>
            @endforelse
        </div>
    </div>

    <!-- Edit Collection Modal -->
    <div x-show="$wire.editModalOpen" 
         x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-md"
         @click.self="$wire.set('editModalOpen', false)">
        
        <div class="w-full max-w-md p-6 rounded-3xl glass-panel shadow-2xl border border-[var(--border-medium)] space-y-4">
            <div class="flex items-center justify-between pb-3 border-b border-[var(--border-subtle)]">
                <h3 class="font-bold text-lg">Edit Collection Settings</h3>
                <button wire:click="$set('editModalOpen', false)" class="p-1 rounded-full hover:bg-[var(--bg-surface-elevated)]">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <div class="space-y-3">
                <div>
                    <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Title</label>
                    <input type="text" wire:model="editTitle" class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)] font-bold">
                </div>

                <div>
                    <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Description</label>
                    <textarea wire:model="editDescription" rows="3" class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)]"></textarea>
                </div>

                <div>
                    <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider block mb-2">Visibility Settings</label>
                    <div class="grid grid-cols-3 gap-2">
                        <button type="button" wire:click="$set('editVisibility', 'public')" class="p-2 rounded-xl border text-center transition {{ $editVisibility === 'public' ? 'border-[var(--accent-primary)] bg-[var(--accent-primary)]/10 text-[var(--text-main)] font-bold' : 'border-[var(--border-subtle)] text-[var(--text-muted)]' }}">Public 🌐</button>
                        <button type="button" wire:click="$set('editVisibility', 'unlisted')" class="p-2 rounded-xl border text-center transition {{ $editVisibility === 'unlisted' ? 'border-amber-500 bg-amber-500/10 text-[var(--text-main)] font-bold' : 'border-[var(--border-subtle)] text-[var(--text-muted)]' }}">Unlisted 🔗</button>
                        <button type="button" wire:click="$set('editVisibility', 'private')" class="p-2 rounded-xl border text-center transition {{ $editVisibility === 'private' ? 'border-purple-500 bg-purple-500/10 text-[var(--text-main)] font-bold' : 'border-[var(--border-subtle)] text-[var(--text-muted)]' }}">Private 🔒</button>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-3 border-t border-[var(--border-subtle)]">
                <button wire:click="$set('editModalOpen', false)" class="px-5 py-2 rounded-xl text-xs font-bold hover:bg-[var(--bg-surface-elevated)]">Cancel</button>
                <button wire:click="saveSettings" class="px-6 py-2 rounded-xl accent-bg text-white font-bold text-xs shadow hover:opacity-90 transition">Save Changes</button>
            </div>
        </div>
    </div>
</div>
