<?php

use Livewire\Component;
use App\Models\Post;
use App\Models\Comment;
use App\Models\Like;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tag;
use App\Support\ContentReports;
use App\Support\Notifier;
use App\Support\SpamControls;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

new class extends Component
{
    public int $postId;
    public string $commentText = '';
    public bool $collectionModalOpen = false;
    public bool $shareModalOpen = false;
    public string $newCollectionTitle = '';
    public bool $newCollectionPrivate = false;
    public ?int $selectedConversationId = null;
    public string $shareMessage = '';
    public string $tagToAdd = '';
    public ?int $tagToRemove = null;
    public string $tagRequestReason = '';
    public bool $editPostModalOpen = false;
    public bool $reportModalOpen = false;
    public string $editTitle = '';
    public string $editDescription = '';
    public string $editSourceUrl = '';
    public bool $editIsOriginalCreator = true;
    public string $editArtistName = '';
    public string $editArtistUrl = '';
    public string $reportReason = '';
    public string $reportDetails = '';
    public string $reportTargetType = 'post';
    public ?int $reportTargetId = null;

    public function mount(int $postId)
    {
        $this->postId = $postId;
        $post = Post::findOrFail($postId);
        if (Auth::check() && ($post->user->blockedByUsers()->whereKey(Auth::id())->exists() || Auth::user()->blockedUsers()->whereKey($post->user_id)->exists())) {
            abort(404);
        }
        $this->editTitle = $post->title ?? '';
        $this->editDescription = $post->description ?? '';
        $this->editSourceUrl = $post->source_url ?? '';
        $this->editIsOriginalCreator = (bool) $post->is_original_creator;
        $this->editArtistName = $post->artist_name ?? '';
        $this->editArtistUrl = $post->artist_url ?? '';
        $post->increment('views_count');
    }

    public function savePost(): void
    {
        $post = Post::findOrFail($this->postId);
        abort_unless(Auth::id() === $post->user_id, 403);

        $validated = $this->validate([
            'editTitle' => ['required', 'string', 'max:150'],
            'editDescription' => ['nullable', 'string', 'max:2000'],
            'editSourceUrl' => ['nullable', 'url', 'max:2048'],
            'editArtistName' => ['nullable', 'string', 'max:100'],
            'editArtistUrl' => ['nullable', 'url', 'max:2048'],
        ]);

        $post->update([
            'title' => $validated['editTitle'],
            'description' => $validated['editDescription'],
            'source_url' => $validated['editSourceUrl'] ?: null,
            'is_original_creator' => $this->editIsOriginalCreator,
            'artist_name' => ! $this->editIsOriginalCreator && filled(trim($this->editArtistName)) ? trim($this->editArtistName) : null,
            'artist_url' => ! $this->editIsOriginalCreator && filled(trim($this->editArtistUrl)) ? trim($this->editArtistUrl) : null,
        ]);

        $this->editPostModalOpen = false;
        $this->dispatch('notify', 'Post updated.');
    }

    public function deletePost(): mixed
    {
        $post = Post::with(['media', 'tags', 'poolChapters'])->findOrFail($this->postId);
        abort_unless(Auth::id() === $post->user_id, 403);

        $uploadedPaths = $post->media->map(function ($media): ?string {
            $path = parse_url($media->url, PHP_URL_PATH) ?: '';
            if (! str_starts_with($path, '/storage/')) {
                return null;
            }

            $storagePath = substr($path, strlen('/storage/'));

            return str_starts_with($storagePath, 'posts/') || str_starts_with($storagePath, 'videos/')
                ? $storagePath
                : null;
        })->filter()->values();

        DB::transaction(function () use ($post): void {
            foreach ($post->tags as $tag) {
                Tag::whereKey($tag->id)->where('posts_count', '>', 0)->decrement('posts_count');
            }

            foreach ($post->poolChapters as $chapter) {
                $chapter->pool()->where('chapters_count', '>', 0)->decrement('chapters_count');
            }
            foreach (CollectionItem::where('post_id', $post->id)->get() as $item) {
                Collection::whereKey($item->collection_id)->where('items_count', '>', 0)->decrement('items_count');
            }

            // An owner deleting their own upload deletes it for good; admin
            // removals stay reversible (see the admin moderation history).
            $post->forceDelete();
        });

        Storage::disk('public')->delete($uploadedPaths->all());

        return redirect()->route('gallery');
    }

    public function toggleBlockAuthor(): mixed
    {
        abort_unless(Auth::check(), 401);
        $post = Post::findOrFail($this->postId);
        abort_if($post->user_id === Auth::id(), 422);

        $user = Auth::user();
        if ($user->blockedUsers()->whereKey($post->user_id)->exists()) {
            $user->blockedUsers()->detach($post->user_id);
            $this->dispatch('notify', 'Artist unblocked.');
        } else {
            $user->blockedUsers()->syncWithoutDetaching([$post->user_id]);
            $user->following()->detach($post->user_id);
            $this->dispatch('notify', 'Artist blocked. Their posts are hidden from your gallery.');
            return redirect()->route('gallery');
        }
    }

    public function openReportModal(string $targetType = 'post', ?int $targetId = null): void
    {
        $this->reportTargetType = in_array($targetType, ContentReports::TARGET_TYPES, true) ? $targetType : 'post';
        $this->reportTargetId = $targetId;
        $this->reset('reportReason', 'reportDetails');
        $this->reportModalOpen = true;
    }

    public function reportLabel(): string
    {
        return ContentReports::labelFor($this->reportTargetType);
    }

    public function submitReport(): void
    {
        abort_unless(Auth::check(), 401);
        $validated = $this->validate([
            'reportReason' => ['required', 'string', 'max:80'],
            'reportDetails' => ['nullable', 'string', 'max:1000'],
        ]);

        $targetId = $this->reportTargetId ?? $this->postId;
        $targetLabel = strtolower($this->reportLabel());
        $filed = ContentReports::file(Auth::user(), $this->reportTargetType, $targetId, $validated['reportReason'], $validated['reportDetails']);

        $this->reset('reportReason', 'reportDetails');
        $this->reportTargetId = null;
        $this->reportTargetType = 'post';
        $this->reportModalOpen = false;
        $this->dispatch('notify', $filed
            ? 'Report sent to the moderation team.'
            : 'You already reported this '.$targetLabel.'.');
    }

    public function toggleLike()
    {
        if (!Auth::check()) {
            $this->dispatch('notify', 'Please log in to like posts');
            return;
        }

        $user = Auth::user();
        $post = Post::findOrFail($this->postId);
        $existing = Like::where('user_id', $user->id)->where('post_id', $this->postId)->first();

        if ($existing) {
            $existing->delete();
            $post->decrement('likes_count');
            $post->user?->decrement('reputation_score', 2);
            $this->dispatch('notify', 'Removed like');
        } else {
            Like::create(['user_id' => $user->id, 'post_id' => $this->postId]);
            $post->increment('likes_count');
            $post->user?->increment('reputation_score', 2);
            Notifier::like($post, $user);
            $this->dispatch('notify', 'Liked post!');
        }
    }

    public function toggleFollowAuthor(int $authorId)
    {
        if (!Auth::check()) {
            $this->dispatch('notify', 'Please log in to follow artists');
            return;
        }

        $user = Auth::user();
        if ($user->id === $authorId) return;

        if ($user->following()->where('following_id', $authorId)->exists()) {
            $user->following()->detach($authorId);
            $this->dispatch('notify', 'Unfollowed artist');
        } else {
            $user->following()->attach($authorId);
            Notifier::follow(User::findOrFail($authorId), $user);
            $this->dispatch('notify', 'Followed artist!');
        }
    }

    public function toggleFollowTag(int $tagId)
    {
        if (!Auth::check()) {
            $this->dispatch('notify', 'Please log in to follow tags');
            return;
        }

        $user = Auth::user();
        $tag = Tag::findOrFail($tagId);

        if ($user->isFollowingTag($tag)) {
            $user->followedTags()->detach($tagId);
            $this->dispatch('notify', "Unfollowed #{$tag->name}");
        } else {
            $user->followedTags()->attach($tagId);
            $this->dispatch('notify', "Followed #{$tag->name}");
        }
    }

    public function requestTagChange(string $action): void
    {
        abort_unless(Auth::check(), 401);
        abort_unless(in_array($action, ['add', 'remove'], true), 422);
        $post = Post::with('tags')->findOrFail($this->postId);
        $isOwner = $post->user_id === Auth::id();
        if ($action === 'add') {
            $this->validate(['tagToAdd' => ['required', 'string', 'max:80', 'regex:/^[a-zA-Z0-9 _-]+$/']]);
            $name = Str::of($this->tagToAdd)->trim()->lower()->replace(' ', '_')->toString();
            $tag = Tag::where('name', $name)->orWhere('slug', Str::slug($name))->first();
            if ($isOwner) {
                $tag ??= Tag::create(['name' => $name, 'slug' => Str::slug($name), 'type' => 'general']);
                if (! $post->tags()->whereKey($tag->id)->exists()) { $post->tags()->attach($tag->id); $tag->increment('posts_count'); }
            } else {
                DB::table('post_tag_proposals')->insert(['post_id' => $post->id, 'requester_id' => Auth::id(), 'tag_id' => $tag?->id, 'tag_name' => $name, 'action' => 'add', 'reason' => $this->tagRequestReason ?: null, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->reset('tagToAdd', 'tagRequestReason');
        } else {
            $this->validate(['tagToRemove' => ['required', 'integer']]);
            $tag = $post->tags()->whereKey($this->tagToRemove)->firstOrFail();
            if ($isOwner) { $post->tags()->detach($tag->id); Tag::whereKey($tag->id)->where('posts_count', '>', 0)->decrement('posts_count'); }
            else DB::table('post_tag_proposals')->insert(['post_id' => $post->id, 'requester_id' => Auth::id(), 'tag_id' => $tag->id, 'tag_name' => $tag->name, 'action' => 'remove', 'reason' => $this->tagRequestReason ?: null, 'created_at' => now(), 'updated_at' => now()]);
            $this->reset('tagToRemove', 'tagRequestReason');
        }
        $this->dispatch('notify', $isOwner ? 'Artwork tags updated.' : 'Tag change sent for moderator review.');
    }

    public function addComment()
    {
        if (!Auth::check()) {
            $this->dispatch('notify', 'Please log in to comment');
            return;
        }

        $this->validate(['commentText' => 'required|string|min:2|max:1000']);

        $post = Post::findOrFail($this->postId);
        abort_if($post->comments_locked, 423, 'Comments are locked on this post.');

        SpamControls::enforce('comments', 8, 60, $this->commentText);

        $comment = Comment::create([
            'post_id' => $this->postId,
            'user_id' => Auth::id(),
            'content' => trim($this->commentText),
        ]);

        Notifier::comment($comment, Auth::user());

        $this->commentText = '';
        $this->dispatch('notify', 'Comment posted!');
    }

    public function addToCollection(int $collectionId)
    {
        if (!Auth::check()) return;

        $coll = Collection::where('user_id', Auth::id())->findOrFail($collectionId);
        $exists = CollectionItem::where('collection_id', $coll->id)->where('post_id', $this->postId)->exists();

        if ($exists) {
            CollectionItem::where('collection_id', $coll->id)->where('post_id', $this->postId)->delete();
            $coll->decrement('items_count');
            $this->dispatch('notify', "Removed from collection '{$coll->title}'");
        } else {
            $maxOrder = CollectionItem::where('collection_id', $coll->id)->max('order') ?? 0;
            CollectionItem::create([
                'collection_id' => $coll->id,
                'post_id' => $this->postId,
                'order' => $maxOrder + 1,
            ]);
            $coll->increment('items_count');
            Notifier::collectionItemAdded($coll, Post::findOrFail($this->postId), Auth::user());
            $this->dispatch('notify', "Added to collection '{$coll->title}'");
        }
    }

    public function createAndAddToCollection()
    {
        if (!Auth::check()) return;

        $this->validate(['newCollectionTitle' => 'required|string|max:100']);

        $post = Post::findOrFail($this->postId);
        $coll = Collection::create([
            'user_id' => Auth::id(),
            'title' => $this->newCollectionTitle,
            'is_private' => $this->newCollectionPrivate,
            'cover_url' => $post->primaryMedia->url ?? null,
            'items_count' => 1,
        ]);

        CollectionItem::create([
            'collection_id' => $coll->id,
            'post_id' => $this->postId,
            'order' => 1,
        ]);

        $this->newCollectionTitle = '';
        $this->newCollectionPrivate = false;
        $this->dispatch('notify', "Created collection '{$coll->title}' and saved artwork!");
    }

    public function shareToConversation()
    {
        if (!Auth::check() || !$this->selectedConversationId) return;

        $conv = Conversation::where('id', $this->selectedConversationId)
            ->where(function ($query): void {
                $query->where('user_one_id', Auth::id())->orWhere('user_two_id', Auth::id());
            })
            ->first();
        abort_unless($conv, 404);

        Message::create([
            'conversation_id' => $conv->id,
            'sender_id' => Auth::id(),
            'text' => !empty($this->shareMessage) ? $this->shareMessage : 'Shared artwork:',
            'shared_post_id' => $this->postId,
            'is_read' => false,
        ]);

        $conv->update(['last_message_at' => now()]);
        $this->shareModalOpen = false;
        $this->shareMessage = '';
        $this->dispatch('notify', 'Shared artwork to chat!');
    }

    public function render()
    {
        $post = Post::with(['user', 'media', 'tags', 'comments' => fn ($query) => $query->where('is_hidden', false)->with('user')])->findOrFail($this->postId);
        $user = Auth::user();
        $isLiked = $post->isLikedBy($user);
        $isFollowingAuthor = $user ? $user->isFollowing($post->user) : false;

        $myCollections = $user ? Collection::where('user_id', $user->id)->with('items')->get() : collect();
        $myConversations = $user ? Conversation::query()->visibleFor($user)->with(['userOne', 'userTwo'])->get() : collect();

        $tagsByType = $post->tags->groupBy('type');

        $mediaList = $post->media->map(fn($m) => [
            'url' => $m->url,
            'thumbnail_url' => $m->thumbnail_url ?? $m->url,
            'type' => $post->media_type,
            'width' => $m->width,
            'height' => $m->height,
        ])->toArray();

        $prevPost = Post::where('id', '<', $this->postId)->latest('id')->first();
        $nextPost = Post::where('id', '>', $this->postId)->oldest('id')->first();

        return view('components.⚡post-detail', [
            'post' => $post,
            'mediaList' => $mediaList,
            'isLiked' => $isLiked,
            'isFollowingAuthor' => $isFollowingAuthor,
            'myCollections' => $myCollections,
            'myConversations' => $myConversations,
            'tagsByType' => $tagsByType,
            'currentUser' => $user,
            'prevPost' => $prevPost,
            'nextPost' => $nextPost,
        ]);
    }
};
?>

<div x-data="{ 
    mediaList: {{ json_encode($mediaList) }}, 
    postTitle: {{ json_encode($post->title ?? 'Post #' . $post->id) }}, 
    authorName: {{ json_encode($post->user->name) }}, 
    postId: {{ $post->id }} 
}" class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-8 w-full min-w-0">
    @if(request()->has('from_admin'))
        <div class="mb-4 p-4 rounded-2xl bg-gradient-to-r from-purple-900/90 to-rose-900/90 border border-purple-500/40 text-white flex items-center justify-between shadow-xl">
            <div class="flex items-center gap-3">
                <span class="p-2 rounded-xl bg-purple-600/50 text-lg">🛡️</span>
                <div>
                    <div class="font-extrabold text-sm">Admin Moderation Mode</div>
                    <div class="text-xs text-purple-200">Inspecting artwork details directly from Admin Dashboard</div>
                </div>
            </div>
            <a href="{{ route('admin') }}" class="px-4 py-2 rounded-xl bg-white text-purple-950 font-black text-xs hover:bg-purple-100 transition shadow">
                ← Return to Admin Panel
            </a>
        </div>
    @endif

    <!-- Breadcrumb & Top Bar -->
    <div class="flex flex-wrap items-center justify-between gap-3 w-full min-w-0">
        <a href="{{ request()->has('from_admin') ? route('admin') : route('gallery') }}" class="flex items-center gap-2 text-sm font-semibold text-[var(--text-muted)] hover:text-[var(--text-main)] transition shrink-0">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            <span>{{ request()->has('from_admin') ? 'Return to Admin Panel' : 'Back to Gallery' }}</span>
        </a>

        <!-- Quick Actions: Lightbox Launch, Add to Collection, Share -->
        <div class="flex items-center gap-2 shrink-0">
            <!-- Fullscreen Lightbox Button -->
            <button @click="$dispatch('open-lightbox', { items: mediaList, startIndex: 0, title: postTitle, author: authorName, postId: postId })" 
                    class="flex items-center gap-1.5 px-3 py-2 rounded-xl bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] hover:bg-[var(--bg-surface)] text-xs font-bold transition">
                <svg class="w-4 h-4 accent-text" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"></path>
                </svg>
                <span>Lightbox</span>
            </button>

            <!-- Save to Collection -->
            <button wire:click="$set('collectionModalOpen', true)" 
                    class="flex items-center gap-1.5 px-3 py-2 rounded-xl bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] hover:bg-[var(--bg-surface)] text-xs font-bold transition">
                <svg class="w-4 h-4 accent-text" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                </svg>
                <span class="hidden sm:inline">Save</span>
            </button>

            <!-- Share to Chat -->
            <button wire:click="$set('shareModalOpen', true)" 
                    class="flex items-center gap-1.5 px-3 py-2 rounded-xl bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] hover:bg-[var(--bg-surface)] text-xs font-bold transition">
                <svg class="w-4 h-4 accent-text" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"></path>
                </svg>
                <span class="hidden sm:inline">Share</span>
            </button>

            @if(auth()->check() && auth()->id() === $post->user_id)
                <button wire:click="$set('editPostModalOpen', true)" class="px-3 py-2 rounded-xl bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] text-xs font-bold">Edit post</button>
                <button wire:click="deletePost" wire:confirm="Delete this post and its uploaded media?" class="px-3 py-2 rounded-xl border border-rose-500/30 text-rose-400 text-xs font-bold">Delete</button>
            @elseif(auth()->check())
                <button wire:click="openReportModal('post')" class="px-3 py-2 rounded-xl bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] text-xs font-bold">Report</button>
                <button wire:click="toggleBlockAuthor" wire:confirm="Block this artist and hide their posts?" class="px-3 py-2 rounded-xl border border-rose-500/30 text-rose-400 text-xs font-bold">
                    {{ auth()->user()->blockedUsers()->whereKey($post->user_id)->exists() ? 'Unblock artist' : 'Block artist' }}
                </button>
            @endif

            <!-- Previous / Next Post Navigation Buttons -->
            <div class="flex items-center gap-1 border-l border-[var(--border-subtle)] pl-2">
                @if($prevPost)
                    <a href="{{ route('post.detail', $prevPost->id) }}" title="Previous Post" class="p-2 rounded-xl bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] hover:bg-[var(--bg-surface)] text-[var(--text-muted)] hover:text-[var(--text-main)] transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"></path>
                        </svg>
                    </a>
                @endif
                @if($nextPost)
                    <a href="{{ route('post.detail', $nextPost->id) }}" title="Next Post" class="p-2 rounded-xl bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] hover:bg-[var(--bg-surface)] text-[var(--text-muted)] hover:text-[var(--text-main)] transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </a>
                @endif
            </div>
        </div>
    </div>

    <!-- Main Two-Column Layout -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
        <!-- Left Column: Vertically Stacked Media View (Spec: Post page shows images stacked vertically. Clicking opens lightbox) -->
        <div class="lg:col-span-8 space-y-6">
            @foreach($post->media as $idx => $m)
                <div class="relative rounded-3xl overflow-hidden bg-neutral-900 border border-[var(--border-subtle)] shadow-xl group cursor-pointer"
                     @click="$dispatch('open-lightbox', { items: mediaList, startIndex: {{ $idx }}, title: postTitle, author: authorName, postId: postId })">
                    
                    @if($post->media_type === 'video')
                        <div class="relative">
                            <video src="{{ $m->url }}" controls class="w-full h-auto max-h-[85vh] object-contain"></video>
                            <button type="button"
                                    @click.stop="$dispatch('open-lightbox', { items: mediaList, startIndex: {{ $idx }}, title: postTitle, author: authorName, postId: postId })"
                                    class="absolute top-4 right-4 z-10 px-3 py-1.5 rounded-xl bg-black/75 hover:bg-black/90 backdrop-blur-md text-white text-xs font-bold flex items-center gap-1.5 transition shadow-lg ring-1 ring-white/10">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"></path>
                                </svg>
                                <span>Expand Lightbox</span>
                            </button>
                        </div>
                    @else
                        <img src="{{ $m->url }}" 
                             alt="{{ $post->title ?? 'Artwork page ' . ($idx + 1) }}"
                             class="w-full h-auto max-h-[85vh] object-contain transition-transform duration-300 group-hover:scale-[1.01]">
                    @endif

                    <!-- Overlay hint on hover -->
                    <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity duration-200 pointer-events-none flex items-center justify-center">
                        <span class="px-4 py-2 rounded-2xl bg-black/70 backdrop-blur-md text-white text-xs font-bold flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7"></path>
                            </svg>
                            <span>Click to expand in Lightbox (Page {{ $idx + 1 }} of {{ $post->media_count }})</span>
                        </span>
                    </div>

                    @if($post->media_count > 1)
                        <div class="absolute top-4 left-4 px-3 py-1 rounded-xl bg-black/75 backdrop-blur-md text-white font-mono text-xs font-bold">
                            {{ $idx + 1 }} / {{ $post->media_count }}
                        </div>
                    @endif
                </div>
            @endforeach

            <!-- Comments Section -->
            <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-6">
                <h3 class="font-bold text-lg flex items-center gap-2">
                    <svg class="w-5 h-5 accent-text" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                    </svg>
                    <span>Comments ({{ $post->comments->count() }})</span>
                </h3>

                <!-- Add Comment Input -->
                @if(Auth::check() && !$post->comments_locked)
                    <div class="flex items-start gap-3">
                        <img src="{{ Auth::user()->avatar_url }}" class="w-10 h-10 rounded-full object-cover ring-2 ring-[var(--border-subtle)] shrink-0">
                        <div class="flex-1 space-y-2">
                            <textarea wire:model="commentText" 
                                      placeholder="Write a constructive comment or reaction..."
                                      rows="3"
                                      class="w-full p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] focus:border-[var(--accent-primary)] focus:ring-2 focus:ring-[var(--accent-primary)]/20 outline-none text-sm transition"></textarea>
                            <div class="flex justify-end">
                                <button wire:click="addComment" 
                                        class="px-5 py-2 rounded-xl accent-bg text-white font-bold text-xs shadow hover:opacity-90 transition">
                                    Post Comment
                                </button>
                            </div>
                        </div>
                    </div>
                @elseif(!Auth::check())
                    <div class="p-4 rounded-2xl bg-[var(--bg-surface-elevated)] text-center text-sm text-[var(--text-muted)]">
                        <a href="{{ route('login') }}" class="accent-text font-bold hover:underline">Log in</a> to join the discussion.
                    </div>
                @endif

                @if($post->comments_locked)
                    <div class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4 text-center text-sm font-semibold text-amber-300">
                        🔒 Comments are locked on this post by a moderator.
                    </div>
                @endif

                <!-- Comments List -->
                <div class="space-y-4 pt-2">
                    @forelse($post->comments as $comment)
                        <div class="flex items-start gap-3 p-4 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)]">
                            <a href="{{ route('profile', $comment->user->username) }}" class="shrink-0">
                                <img src="{{ $comment->user->avatar_url }}" class="w-8 h-8 rounded-full object-cover">
                            </a>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between gap-2">
                                    <a href="{{ route('profile', $comment->user->username) }}" class="font-bold text-xs hover:underline truncate">
                                        {{ $comment->user->name }}
                                        <span class="text-[var(--text-dim)] font-normal ml-1">{{ '@' . $comment->user->username }}</span>
                                    </a>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <span class="text-[10px] text-[var(--text-dim)]">{{ $comment->created_at->diffForHumans() }}</span>
                                        @if(auth()->check() && auth()->id() !== $comment->user_id)
                                            <button wire:click="openReportModal('comment', {{ $comment->id }})"
                                                    title="Report this comment"
                                                    class="text-[10px] font-bold text-[var(--text-dim)] hover:text-rose-400 transition">Report</button>
                                        @endif
                                    </div>
                                </div>
                                <p class="text-sm text-[var(--text-main)] mt-1.5 leading-relaxed">{{ $comment->content }}</p>
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-[var(--text-dim)] text-center py-4">No comments yet. Be the first to share your thoughts!</p>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- Right Column: Metadata, Categorized Danbooru Tags, Artist Card -->
        <div class="lg:col-span-4 space-y-6">
            <!-- Post Info Card -->
            <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-5">
                <div>
                    <h1 class="text-2xl font-black tracking-tight text-[var(--text-main)]">{{ $post->title ?? 'Untitled Artwork' }}</h1>
                    @if($post->description)
                        <p class="text-sm text-[var(--text-muted)] mt-2 leading-relaxed">{{ $post->description }}</p>
                    @endif
                </div>

                <!-- Stats & Like button -->
                <div class="flex items-center justify-between py-3 border-y border-[var(--border-subtle)]">
                    <button wire:click="toggleLike" 
                            class="flex items-center gap-2 px-4 py-2 rounded-2xl font-bold text-sm transition {{ $isLiked ? 'text-white bg-rose-500 shadow-lg shadow-rose-500/30' : 'bg-[var(--bg-surface-elevated)] text-[var(--text-main)] hover:bg-rose-500/10 hover:text-rose-400' }}">
                        <svg class="w-5 h-5 {{ $isLiked ? 'fill-current animate-heart-burst' : 'fill-none stroke-current' }}" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                        </svg>
                        <span>{{ $post->likes_count }} {{ Str::plural('Like', $post->likes_count) }}</span>
                    </button>

                    <div class="flex items-center gap-4 text-xs font-semibold text-[var(--text-dim)]">
                        <span class="flex items-center gap-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                            </svg>
                            <span>{{ number_format($post->views_count) }} views</span>
                        </span>
                        <span>{{ $post->created_at->format('M d, Y') }}</span>
                    </div>
                </div>

                <!-- Artist Attribution / Source -->
                @if(!$post->is_original_creator && $post->artist_name)
                    <div class="p-3.5 rounded-2xl bg-amber-500/10 border border-amber-500/30 text-amber-300 space-y-1">
                        <div class="text-[10px] uppercase font-black tracking-wider text-amber-400">🎨 Original Artist Attribution</div>
                        <div class="text-xs font-bold flex items-center justify-between gap-2">
                            <span>Created by <strong>{{ $post->artist_name }}</strong></span>
                            @if($post->artist_url)
                                <a href="{{ $post->artist_url }}" target="_blank" rel="noopener" class="px-2.5 py-1 rounded-lg bg-amber-500/20 hover:bg-amber-500/30 text-amber-200 text-[11px] font-bold inline-flex items-center gap-1 transition shrink-0">
                                    <span>Artist Page ↗</span>
                                </a>
                            @endif
                        </div>
                        <div class="text-[11px] opacity-75">Uploaded to gallery archive by @<span>{{ $post->user->username }}</span></div>
                    </div>
                @elseif($post->source_url)
                    <div class="flex items-center gap-2 text-xs">
                        <span class="text-[var(--text-dim)]">Source:</span>
                        <a href="{{ $post->source_url }}" target="_blank" rel="noopener" class="accent-text hover:underline truncate max-w-xs flex items-center gap-1">
                            <span>{{ parse_url($post->source_url, PHP_URL_HOST) }}</span>
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                            </svg>
                        </a>
                    </div>
                @endif

                <!-- Artist / Uploader Card (Twitter style) -->
                <div class="p-4 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] space-y-3">
                    <div class="flex items-center justify-between gap-3">
                        <a href="{{ route('profile', $post->user->username) }}" class="flex items-center gap-3 min-w-0">
                            <img src="{{ $post->user->avatar_url }}" class="w-12 h-12 rounded-full object-cover ring-2 ring-[var(--border-subtle)] shrink-0">
                            <div class="min-w-0">
                                <div class="flex items-center gap-1">
                                    <span class="font-bold text-sm truncate">{{ $post->user->name }}</span>
                                    @if($post->user->is_artist)
                                        <svg class="w-4 h-4 accent-text shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                        </svg>
                                    @endif
                                </div>
                                <div class="text-xs text-[var(--text-dim)] truncate">{{ '@' . $post->user->username }}</div>
                                <div class="flex items-center gap-1.5 mt-0.5">
                                    <span class="px-2 py-0.2 rounded-full text-[10px] font-bold bg-amber-500/15 text-amber-400 border border-amber-500/30">
                                        ⚡ {{ number_format($post->user->reputation_score) }} Rep
                                    </span>
                                    <span class="text-[10px] text-[var(--text-muted)] font-medium">{{ $post->user->reputation_title }}</span>
                                </div>
                            </div>
                        </a>

                        @if(Auth::check() && Auth::id() !== $post->user_id)
                            <button wire:click="toggleFollowAuthor({{ $post->user_id }})" 
                                    class="px-4 py-1.5 rounded-full text-xs font-bold transition {{ $isFollowingAuthor ? 'border border-[var(--border-medium)] text-[var(--text-muted)] hover:text-rose-400' : 'accent-bg text-white shadow' }}">
                                {{ $isFollowingAuthor ? 'Following' : 'Follow' }}
                            </button>
                        @endif
                    </div>

                    @if($post->user->bio)
                        <p class="text-xs text-[var(--text-muted)] leading-relaxed line-clamp-2">{{ $post->user->bio }}</p>
                    @endif
                </div>
            </div>

            <!-- Categorized Danbooru Tags Card -->
            <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-4">
                <h3 class="font-extrabold text-sm uppercase tracking-wider text-[var(--text-dim)]">Tags & Classification</h3>

                @php
                    $categories = [
                        'artist' => ['label' => 'Artists', 'color' => 'text-red-400'],
                        'character' => ['label' => 'Characters', 'color' => 'text-emerald-400'],
                        'series' => ['label' => 'Series / Copyright', 'color' => 'text-purple-400'],
                        'general' => ['label' => 'General', 'color' => 'text-sky-400'],
                        'meta' => ['label' => 'Meta', 'color' => 'text-amber-400'],
                    ];
                @endphp

                <div class="space-y-4">
                    @foreach($categories as $typeKey => $meta)
                        @if(isset($tagsByType[$typeKey]) && $tagsByType[$typeKey]->isNotEmpty())
                            <div>
                                <div class="text-xs font-bold {{ $meta['color'] }} mb-2">{{ $meta['label'] }}</div>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach($tagsByType[$typeKey] as $tag)
                                        <div class="inline-flex items-center gap-1 px-2.5 py-1 rounded-xl text-xs font-medium border {{ $tag->getTypeBadgeClasses() }}">
                                            <a href="{{ route('gallery', ['selectedTag' => $tag->name]) }}" class="hover:underline">#{{ $tag->name }}</a>
                                            @if(Auth::check())
                                                <button wire:click="toggleFollowTag({{ $tag->id }})" 
                                                        title="{{ Auth::user()->isFollowingTag($tag) ? 'Unfollow Tag' : 'Follow Tag' }}"
                                                        class="opacity-70 hover:opacity-100 ml-1">
                                                    {{ Auth::user()->isFollowingTag($tag) ? '★' : '☆' }}
                                                </button>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
                @auth
                    <details class="mt-5 rounded-2xl border border-[var(--border-subtle)] p-4">
                        <summary class="cursor-pointer text-sm font-bold">Suggest an artwork tag change</summary>
                        <p class="mt-2 text-xs text-[var(--text-dim)]">Artwork owners update tags immediately. Other requests go to moderators for review.</p>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <form wire:submit="requestTagChange('add')" class="space-y-2"><input wire:model="tagToAdd" placeholder="Tag to add" class="w-full rounded-xl border bg-[var(--bg-page)] px-3 py-2 text-sm"><input wire:model="tagRequestReason" placeholder="Optional reason" class="w-full rounded-xl border bg-[var(--bg-page)] px-3 py-2 text-sm"><button class="rounded-xl accent-bg px-3 py-2 text-xs font-bold text-white">Add tag</button></form>
                            <form wire:submit="requestTagChange('remove')" class="space-y-2"><select wire:model="tagToRemove" class="w-full rounded-xl border bg-[var(--bg-page)] px-3 py-2 text-sm"><option value="">Choose a tag to remove</option>@foreach($post->tags as $tag)<option value="{{ $tag->id }}">#{{ $tag->name }}</option>@endforeach</select><input wire:model="tagRequestReason" placeholder="Optional reason" class="w-full rounded-xl border bg-[var(--bg-page)] px-3 py-2 text-sm"><button class="rounded-xl border px-3 py-2 text-xs font-bold">Request removal</button></form>
                        </div>
                    </details>
                @endauth
            </div>
        </div>
    </div>

    <!-- Add to Collection Modal -->
    <div x-show="$wire.collectionModalOpen" 
         x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-md"
         @click.self="$wire.set('collectionModalOpen', false)">
        
        <div class="w-full max-w-md p-6 rounded-3xl glass-panel shadow-2xl border border-[var(--border-medium)] space-y-5">
            <div class="flex items-center justify-between pb-3 border-b border-[var(--border-subtle)]">
                <h3 class="font-bold text-lg">Save to Collection</h3>
                <button wire:click="$set('collectionModalOpen', false)" class="p-1 rounded-full hover:bg-[var(--bg-surface-elevated)]">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <!-- Existing Collections List -->
            <div class="space-y-2 max-h-56 overflow-y-auto">
                @forelse($myCollections as $coll)
                    @php
                        $inColl = $coll->items->contains('post_id', $post->id);
                    @endphp
                    <button wire:click="addToCollection({{ $coll->id }})" 
                            class="flex items-center justify-between w-full p-3 rounded-2xl border transition {{ $inColl ? 'accent-border bg-[var(--accent-glow)]' : 'border-[var(--border-subtle)] hover:bg-[var(--bg-surface-elevated)]' }}">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="w-2.5 h-2.5 rounded-full {{ $coll->is_private ? 'bg-amber-400' : 'bg-emerald-400' }}"></span>
                            <div class="text-left min-w-0">
                                <div class="text-sm font-bold truncate">{{ $coll->title }}</div>
                                <div class="text-[11px] text-[var(--text-dim)]">{{ $coll->is_private ? 'Private' : 'Public' }} · {{ $coll->items_count }} items</div>
                            </div>
                        </div>

                        <span class="text-xs font-bold {{ $inColl ? 'accent-text' : 'text-[var(--text-dim)]' }}">
                            {{ $inColl ? 'Saved ✓' : '+ Add' }}
                        </span>
                    </button>
                @empty
                    <p class="text-xs text-[var(--text-dim)] text-center py-2">No collections yet. Create your first one below!</p>
                @endforelse
            </div>

            <!-- Create New Collection Inline -->
            <div class="pt-4 border-t border-[var(--border-subtle)] space-y-3">
                <div class="text-xs font-bold uppercase tracking-wider text-[var(--text-dim)]">Create New Collection</div>
                <input type="text" 
                       wire:model="newCollectionTitle"
                       placeholder="e.g. Cyberpunk Environments, Character Sheets"
                       class="w-full p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)]">
                
                <label class="flex items-center gap-2 text-xs text-[var(--text-muted)] cursor-pointer">
                    <input type="checkbox" wire:model="newCollectionPrivate" class="rounded accent-bg">
                    <span>Keep this collection private (only you can see it)</span>
                </label>

                <button wire:click="createAndAddToCollection" 
                        class="w-full py-2.5 rounded-xl accent-bg text-white font-bold text-xs shadow hover:opacity-90 transition">
                    Create & Save Artwork
                </button>
            </div>
        </div>
    </div>

    <!-- Share to Telegram-Style Chat Modal -->
    <div x-show="$wire.shareModalOpen" 
         x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-md"
         @click.self="$wire.set('shareModalOpen', false)">
        
        <div class="w-full max-w-md p-6 rounded-3xl glass-panel shadow-2xl border border-[var(--border-medium)] space-y-5">
            <div class="flex items-center justify-between pb-3 border-b border-[var(--border-subtle)]">
                <h3 class="font-bold text-lg">Share Artwork to Chat</h3>
                <button wire:click="$set('shareModalOpen', false)" class="p-1 rounded-full hover:bg-[var(--bg-surface-elevated)]">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <!-- Select Conversation -->
            <div class="space-y-3">
                <div class="text-xs font-bold uppercase tracking-wider text-[var(--text-dim)]">Select Conversation</div>
                <div class="space-y-2 max-h-48 overflow-y-auto">
                    @forelse($myConversations as $conv)
                        @php
                            $other = $conv->getOtherUser($currentUser);
                        @endphp
                        <button wire:click="$set('selectedConversationId', {{ $conv->id }})" 
                                class="flex items-center gap-3 w-full p-3 rounded-2xl border transition {{ $selectedConversationId === $conv->id ? 'accent-border bg-[var(--accent-glow)]' : 'border-[var(--border-subtle)] hover:bg-[var(--bg-surface-elevated)]' }}">
                            <img src="{{ $other->avatar_url }}" class="w-8 h-8 rounded-full object-cover">
                            <div class="text-left flex-1 min-w-0">
                                <div class="text-sm font-bold truncate">{{ $other->name }}</div>
                                <div class="text-xs text-[var(--text-dim)]">{{ '@' . $other->username }}</div>
                            </div>
                        </button>
                    @empty
                        <p class="text-xs text-[var(--text-dim)] text-center py-2">No active conversations found.</p>
                    @endforelse
                </div>

                <input type="text" 
                       wire:model="shareMessage" 
                       placeholder="Add a message note (optional)..."
                       class="w-full p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)]">

                <button wire:click="shareToConversation" 
                        {{ empty($selectedConversationId) ? 'disabled' : '' }}
                        class="w-full py-2.5 rounded-xl accent-bg text-white font-bold text-xs shadow hover:opacity-90 disabled:opacity-50 transition">
                    Send to Chat (Rich Embed)
                </button>
            </div>
        </div>
    </div>

    <div x-show="$wire.editPostModalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70" @click.self="$wire.set('editPostModalOpen', false)">
        <form wire:submit="savePost" class="w-full max-w-lg rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] p-6 space-y-4">
            <h2 class="text-lg font-black">Edit post</h2>
            <label class="block text-xs font-bold">Title<input wire:model="editTitle" class="mt-1 w-full rounded-xl bg-[var(--bg-page)] border border-[var(--border-subtle)] p-3 text-sm"></label>
            @error('editTitle') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
            <label class="block text-xs font-bold">Description<textarea wire:model="editDescription" rows="4" class="mt-1 w-full rounded-xl bg-[var(--bg-page)] border border-[var(--border-subtle)] p-3 text-sm"></textarea></label>
            @error('editDescription') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
            <label class="block text-xs font-bold">Source URL<input type="url" wire:model="editSourceUrl" class="mt-1 w-full rounded-xl bg-[var(--bg-page)] border border-[var(--border-subtle)] p-3 text-sm"></label>
            @error('editSourceUrl') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror

            <!-- Artist Attribution Edit -->
            <div class="p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] space-y-2">
                <span class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider block">Creator Attribution</span>
                <div class="flex items-center gap-2">
                    <button type="button" wire:click="$set('editIsOriginalCreator', true)" class="px-3 py-1.5 rounded-xl text-xs font-bold border transition {{ $editIsOriginalCreator ? 'accent-bg text-white border-transparent' : 'border-[var(--border-subtle)] text-[var(--text-muted)]' }}">Original Creator</button>
                    <button type="button" wire:click="$set('editIsOriginalCreator', false)" class="px-3 py-1.5 rounded-xl text-xs font-bold border transition {{ !$editIsOriginalCreator ? 'bg-amber-500 text-white border-transparent' : 'border-[var(--border-subtle)] text-[var(--text-muted)]' }}">Third-Party Artist</button>
                </div>

                @if(!$editIsOriginalCreator)
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 pt-2">
                        <div>
                            <label class="text-[10px] font-bold text-[var(--text-dim)] uppercase">Artist Name</label>
                            <input type="text" wire:model="editArtistName" placeholder="Artist Name / Handle" class="w-full mt-0.5 p-2 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs">
                        </div>
                        <div>
                            <label class="text-[10px] font-bold text-[var(--text-dim)] uppercase">Artist Website URL</label>
                            <input type="url" wire:model="editArtistUrl" placeholder="https://..." class="w-full mt-0.5 p-2 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs">
                        </div>
                    </div>
                @endif
            </div>

            <div class="flex justify-end gap-2"><button type="button" wire:click="$set('editPostModalOpen', false)" class="px-4 py-2 text-xs font-bold">Cancel</button><button class="rounded-xl accent-bg px-5 py-2 text-xs font-bold text-white">Save changes</button></div>
        </form>
    </div>

    <div x-show="$wire.reportModalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70" @click.self="$wire.set('reportModalOpen', false)">
        <form wire:submit="submitReport" class="w-full max-w-lg rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] p-6 space-y-4">
            <h2 class="text-lg font-black">Report {{ strtolower(\App\Support\ContentReports::labelFor($reportTargetType)) }}</h2>
            <label class="block text-xs font-bold">Reason<select wire:model="reportReason" class="mt-1 w-full rounded-xl bg-[var(--bg-page)] border border-[var(--border-subtle)] p-3 text-sm"><option value="">Choose a reason</option><option value="spam">Spam</option><option value="copyright">Copyright concern</option><option value="harassment">Harassment</option><option value="wrong_rating">Incorrect content rating</option><option value="other">Other policy concern</option></select></label>
            @error('reportReason') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
            <label class="block text-xs font-bold">Details<textarea wire:model="reportDetails" rows="3" class="mt-1 w-full rounded-xl bg-[var(--bg-page)] border border-[var(--border-subtle)] p-3 text-sm"></textarea></label>
            @error('reportDetails') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
            <div class="flex justify-end gap-2"><button type="button" wire:click="$set('reportModalOpen', false)" class="px-4 py-2 text-xs font-bold">Cancel</button><button class="rounded-xl accent-bg px-5 py-2 text-xs font-bold text-white">Send report</button></div>
        </form>
    </div>
</div>
