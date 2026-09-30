<?php

use App\Models\Collection;
use App\Models\CollectionItem;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public int $collectionId;

    public bool $editModalOpen = false;

    public string $editTitle = '';

    public string $editDescription = '';

    public bool $editIsPrivate = false;

    public function mount(int $collectionId)
    {
        $this->collectionId = $collectionId;
        $coll = Collection::findOrFail($collectionId);
        $this->editTitle = $coll->title;
        $this->editDescription = $coll->description ?? '';
        $this->editIsPrivate = $coll->is_private;
    }

    public function toggleFollow()
    {
        if (! Auth::check()) {
            $this->dispatch('notify', 'Please log in to follow collections');

            return;
        }

        $user = Auth::user();
        $coll = Collection::findOrFail($this->collectionId);
        abort_if($coll->is_private && $user->id !== $coll->user_id, 404);

        if ($coll->isFollowedBy($user)) {
            $user->followingCollections()->detach($coll->id);
            $coll->decrement('followers_count');
            $this->dispatch('notify', "Unfollowed collection '{$coll->title}'");
        } else {
            $user->followingCollections()->attach($coll->id);
            $coll->increment('followers_count');
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

        $this->validate([
            'editTitle' => 'required|string|max:100',
            'editDescription' => 'nullable|string|max:1000',
        ]);

        $coll->update([
            'title' => $this->editTitle,
            'description' => $this->editDescription,
            'is_private' => $this->editIsPrivate,
        ]);

        $this->editModalOpen = false;
        $this->dispatch('notify', 'Collection updated!');
    }

    public function render()
    {
        $user = Auth::user();
        $collection = Collection::with(['user', 'items.post.primaryMedia', 'items.post.user'])->findOrFail($this->collectionId);
        abort_if($collection->is_private && (! $user || $user->id !== $collection->user_id), 404);
        $isMe = $user && $user->id === $collection->user_id;
        $isFollowed = $collection->isFollowedBy($user);

        $mediaList = $collection->items->map(fn ($item) => [
            'url' => $item->post->primaryMedia->url,
            'thumbnail_url' => $item->post->primaryMedia->thumbnail_url ?? $item->post->primaryMedia->url,
            'type' => $item->post->media_type,
            'title' => $item->post->title,
            'author' => $item->post->user->name,
            'postId' => $item->post->id,
        ])->toArray();

        return view('components.⚡collection-detail', [
            'collection' => $collection,
            'isMe' => $isMe,
            'isFollowed' => $isFollowed,
            'mediaList' => $mediaList,
            'currentUser' => $user,
        ]);
    }
};
?>

<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
    <!-- Back to Profile -->
    <a href="{{ route('profile', $collection->user->username) }}" class="inline-flex items-center gap-2 text-sm font-semibold text-[var(--text-muted)] hover:text-[var(--text-main)] transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
        </svg>
        <span>Back to {{ $collection->user->name }}'s Collections</span>
    </a>

    <!-- Collection Header Card -->
    <div class="p-6 sm:p-8 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] shadow-xl flex flex-col md:flex-row gap-6 items-start">
        <div class="w-full md:w-56 aspect-video md:aspect-square rounded-2xl overflow-hidden bg-neutral-900 shadow shrink-0">
            <img src="{{ $collection->cover_url ?? 'https://images.unsplash.com/photo-1518709268805-4e9042af9f23?auto=format&fit=crop&w=600&q=80' }}" class="w-full h-full object-cover">
        </div>

        <div class="flex-1 min-w-0 flex flex-col justify-between space-y-4">
            <div>
                <div class="flex items-center gap-2.5">
                    <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-[var(--text-main)]">{{ $collection->title }}</h1>
                    <span class="px-2.5 py-0.5 rounded-full text-xs font-bold {{ $collection->is_private ? 'bg-amber-500/20 text-amber-400 border border-amber-500/30' : 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' }}">
                        {{ $collection->is_private ? 'Private Collection' : 'Public Collection' }}
                    </span>
                </div>

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
            <div class="flex items-center justify-between pt-4 border-t border-[var(--border-subtle)]">
                <div class="flex items-center gap-6 text-sm text-[var(--text-dim)]">
                    <div><span class="font-extrabold text-[var(--text-main)]">{{ $collection->items_count }}</span> Artworks</div>
                    <div><span class="font-extrabold text-[var(--text-main)]">{{ $collection->followers_count }}</span> Followers</div>
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
                    @else
                        <button wire:click="toggleFollow" class="px-5 py-2 rounded-xl font-bold text-xs shadow transition {{ $isFollowed ? 'border border-[var(--border-medium)] text-[var(--text-muted)]' : 'accent-bg text-white' }}">
                            {{ $isFollowed ? 'Following' : 'Follow Collection' }}
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Collection Items (Spec: User-ordered sets, drag / buttons to reorder) -->
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="font-extrabold text-xl text-[var(--text-main)]">Collection Items</h2>
            @if($isMe)
                <span class="text-xs text-[var(--text-dim)]">Use ▲ ▼ buttons to reorder items in this collection</span>
            @endif
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
            @forelse($collection->items as $idx => $item)
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

                        <!-- Reorder Controls if Owner -->
                        @if($isMe)
                            <div class="flex items-center justify-between pt-2 border-t border-[var(--border-subtle)]">
                                <div class="flex items-center gap-1">
                                    <button wire:click="moveItem({{ $item->id }}, 'up')" 
                                            {{ $idx === 0 ? 'disabled' : '' }}
                                            class="p-1.5 rounded-lg bg-[var(--bg-surface-elevated)] hover:bg-[var(--accent-primary)] hover:text-white disabled:opacity-30 transition"
                                            title="Move Earlier">
                                        ▲
                                    </button>
                                    <button wire:click="moveItem({{ $item->id }}, 'down')" 
                                            {{ $idx === $collection->items->count() - 1 ? 'disabled' : '' }}
                                            class="p-1.5 rounded-lg bg-[var(--bg-surface-elevated)] hover:bg-[var(--accent-primary)] hover:text-white disabled:opacity-30 transition"
                                            title="Move Later">
                                        ▼
                                    </button>
                                </div>

                                <button wire:click="removeItem({{ $item->id }})" class="text-xs text-rose-400 hover:text-rose-200">
                                    Remove
                                </button>
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <div class="col-span-full p-12 text-center text-sm text-[var(--text-dim)]">This collection has no items yet.</div>
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
                <h3 class="font-bold text-lg">Edit Collection</h3>
                <button wire:click="$set('editModalOpen', false)" class="p-1 rounded-full hover:bg-[var(--bg-surface-elevated)]">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <div class="space-y-3">
                <div>
                    <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Title</label>
                    <input type="text" wire:model="editTitle" class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)]">
                </div>

                <div>
                    <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Description</label>
                    <textarea wire:model="editDescription" rows="3" class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)]"></textarea>
                </div>

                <div class="pt-1">
                    <label class="flex items-center gap-2 text-xs text-[var(--text-main)] cursor-pointer">
                        <input type="checkbox" wire:model="editIsPrivate" class="rounded accent-bg">
                        <span>Private Collection (Only visible to you)</span>
                    </label>
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-3 border-t border-[var(--border-subtle)]">
                <button wire:click="$set('editModalOpen', false)" class="px-5 py-2 rounded-xl text-xs font-bold hover:bg-[var(--bg-surface-elevated)]">Cancel</button>
                <button wire:click="saveSettings" class="px-6 py-2 rounded-xl accent-bg text-white font-bold text-xs shadow hover:opacity-90 transition">Save Changes</button>
            </div>
        </div>
    </div>
</div>
