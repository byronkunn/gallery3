<?php

use App\Models\Pool;
use App\Models\PoolChapter;
use App\Models\PoolHistory;
use App\Models\PoolProgress;
use App\Models\Post;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public int $poolId;

    public bool $addChapterModalOpen = false;

    public string $chapterTitle = '';

    public float $chapterNumber = 1.0;

    public ?int $selectedPostId = null;

    public function mount(int $poolId)
    {
        $this->poolId = $poolId;
    }

    public function toggleFollow()
    {
        if (! Auth::check()) {
            $this->dispatch('notify', 'Please log in to follow pools');

            return;
        }

        $user = Auth::user();
        $pool = Pool::findOrFail($this->poolId);

        if ($pool->isFollowedBy($user)) {
            $user->followingPools()->detach($pool->id);
            $pool->decrement('followers_count');
            $this->dispatch('notify', "Unfollowed '{$pool->title}'");
        } else {
            $user->followingPools()->attach($pool->id);
            $pool->increment('followers_count');
            $this->dispatch('notify', "Following '{$pool->title}'!");
        }
    }

    public function toggleLock()
    {
        $user = Auth::user();
        $pool = Pool::findOrFail($this->poolId);
        if (! $user || $user->id !== $pool->user_id) {
            return;
        }

        $pool->update(['is_locked' => ! $pool->is_locked]);
        $this->dispatch('notify', $pool->is_locked ? 'Pool locked to creator only' : 'Pool unlocked for community contributions');
    }

    public function readChapter(int $chapterId, int $startPage = 1)
    {
        $chapter = PoolChapter::with('pool', 'post.media')
            ->where('pool_id', $this->poolId)
            ->findOrFail($chapterId);
        $user = Auth::user();

        // Remember reader's place (Spec: Remembers reader's place — "Continue reading")
        if ($user) {
            PoolProgress::updateOrCreate(
                ['user_id' => $user->id, 'pool_id' => $this->poolId],
                ['last_chapter_id' => $chapter->id, 'last_page' => $startPage]
            );
        }

        $mediaList = $chapter->post->media->map(fn ($m) => [
            'url' => $m->url,
            'thumbnail_url' => $m->thumbnail_url ?? $m->url,
            'type' => 'image',
            'width' => $m->width,
            'height' => $m->height,
        ])->toArray();

        $this->dispatch('open-lightbox',
            items: $mediaList,
            startIndex: max(0, $startPage - 1),
            title: $chapter->title,
            author: $chapter->pool->title." — Chapter {$chapter->chapter_number}",
            postId: $chapter->post_id
        );
    }

    public function addChapter()
    {
        if (! Auth::check()) {
            return;
        }

        $pool = Pool::findOrFail($this->poolId);
        $user = Auth::user();

        if ($pool->is_locked && $pool->user_id !== $user->id) {
            $this->dispatch('notify', 'This pool has been locked by its creator');

            return;
        }

        $this->validate([
            'chapterTitle' => 'required|string|max:100',
            'chapterNumber' => 'required|numeric',
            'selectedPostId' => ['required', 'integer', 'exists:posts,id'],
        ]);

        abort_unless($user->posts()->whereKey($this->selectedPostId)->exists(), 403);

        $order = $pool->chapters()->count() + 1;

        $ch = PoolChapter::create([
            'pool_id' => $pool->id,
            'post_id' => $this->selectedPostId,
            'chapter_number' => $this->chapterNumber,
            'title' => $this->chapterTitle,
            'order' => $order,
        ]);

        $pool->increment('chapters_count');

        PoolHistory::create([
            'pool_id' => $pool->id,
            'user_id' => $user->id,
            'action' => 'added_chapter',
            'details' => "Added {$this->chapterTitle} (Chapter {$this->chapterNumber})",
        ]);

        $this->addChapterModalOpen = false;
        $this->chapterTitle = '';
        $this->dispatch('notify', 'Chapter successfully added to pool!');
    }

    public function render()
    {
        $pool = Pool::with(['user', 'chapters.post.media', 'history.user'])->findOrFail($this->poolId);
        $user = Auth::user();
        $isFollowed = $pool->isFollowedBy($user);
        $progress = $user ? $pool->getUserProgress($user) : null;
        $myEligiblePosts = $user ? $user->posts()->where('media_type', 'image')->latest()->get() : collect();

        return view('components.⚡pool-detail', [
            'pool' => $pool,
            'isFollowed' => $isFollowed,
            'progress' => $progress,
            'myEligiblePosts' => $myEligiblePosts,
            'currentUser' => $user,
        ]);
    }
};
?>

<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
    <!-- Breadcrumb -->
    <a href="{{ route('pools.index') }}" class="inline-flex items-center gap-2 text-sm font-semibold text-[var(--text-muted)] hover:text-[var(--text-main)] transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
        </svg>
        <span>All Manga & Series Pools</span>
    </a>

    <!-- Pool Header Card -->
    <div class="p-6 sm:p-8 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] shadow-xl flex flex-col md:flex-row gap-6 items-start">
        <img src="{{ $pool->cover_url }}" class="w-full md:w-48 h-64 rounded-2xl object-cover shadow-2xl shrink-0">

        <div class="flex-1 min-w-0 flex flex-col justify-between h-full space-y-4">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-[var(--text-main)]">{{ $pool->title }}</h1>
                    @if($pool->is_locked)
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-neutral-800 text-neutral-300 border border-neutral-700">Locked</span>
                    @else
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">Open Community Pool</span>
                    @endif
                </div>

                <p class="text-sm text-[var(--text-muted)] mt-2 leading-relaxed">{{ $pool->description }}</p>

                <div class="flex items-center gap-2 text-xs text-[var(--text-dim)] mt-3">
                    <span>Created by:</span>
                    <a href="{{ route('profile', $pool->user->username) }}" class="font-bold text-[var(--text-main)] hover:underline flex items-center gap-1.5">
                        <img src="{{ $pool->user->avatar_url }}" class="w-4 h-4 rounded-full object-cover">
                        <span>{{ $pool->user->name }}</span>
                    </a>
                    <span>·</span>
                    <span>American LTR Reading Direction</span>
                </div>
            </div>

            <!-- Stats & Actions Row -->
            <div class="flex flex-wrap items-center justify-between gap-4 pt-4 border-t border-[var(--border-subtle)]">
                <div class="flex items-center gap-6 text-sm text-[var(--text-dim)]">
                    <div>
                        <span class="font-extrabold text-[var(--text-main)]">{{ $pool->chapters_count }}</span> Chapters
                    </div>
                    <div>
                        <span class="font-extrabold text-[var(--text-main)]">{{ $pool->followers_count }}</span> Followers
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    @if(Auth::check() && Auth::id() === $pool->user_id)
                        <button wire:click="toggleLock" class="px-3.5 py-2 rounded-xl text-xs font-bold border border-[var(--border-medium)] hover:bg-[var(--bg-surface-elevated)] transition">
                            {{ $pool->is_locked ? 'Unlock Pool' : 'Lock Pool' }}
                        </button>
                    @endif

                    @if(!$pool->is_locked || (Auth::check() && Auth::id() === $pool->user_id))
                        <button wire:click="$set('addChapterModalOpen', true)" class="px-4 py-2 rounded-xl bg-[var(--bg-surface-elevated)] hover:bg-[var(--bg-surface)] text-xs font-bold border border-[var(--border-subtle)] transition">
                            + Add Chapter
                        </button>
                    @endif

                    <button wire:click="toggleFollow" 
                            class="px-5 py-2 rounded-xl font-bold text-xs shadow transition {{ $isFollowed ? 'border border-[var(--border-medium)] text-[var(--text-muted)]' : 'accent-bg text-white' }}">
                        {{ $isFollowed ? 'Following Pool' : 'Follow Pool' }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- "Continue Reading" Alert Banner if Progress exists -->
    @if($progress && $progress->lastChapter)
        <div class="p-5 rounded-2xl bg-gradient-to-r from-violet-950/40 via-purple-900/30 to-black border border-violet-500/30 flex items-center justify-between shadow-xl">
            <div class="flex items-center gap-3">
                <div class="p-2.5 rounded-xl bg-violet-500 text-white shadow-lg">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                    </svg>
                </div>
                <div>
                    <div class="font-extrabold text-sm text-white">Continue Reading: {{ $progress->lastChapter->title }}</div>
                    <div class="text-xs text-violet-300">You stopped at Page {{ $progress->last_page }} of {{ $progress->lastChapter->post->media_count }}</div>
                </div>
            </div>

            <button wire:click="readChapter({{ $progress->last_chapter_id }}, {{ $progress->last_page }})" 
                    class="px-5 py-2.5 rounded-xl bg-violet-500 hover:bg-violet-600 text-white font-extrabold text-xs shadow-lg transition">
                Resume Chapter →
            </button>
        </div>
    @endif

    <!-- Chapters Stream -->
    <div class="space-y-4">
        <h2 class="font-black text-xl text-[var(--text-main)]">Chapters ({{ $pool->chapters->count() }})</h2>

        <div class="space-y-3">
            @forelse($pool->chapters as $chapter)
                <div class="p-4 sm:p-5 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] hover:border-[var(--border-medium)] transition flex items-center justify-between gap-4">
                    <div class="flex items-center gap-4 min-w-0">
                        <span class="w-8 h-8 rounded-xl bg-[var(--bg-surface-elevated)] flex items-center justify-center font-bold text-xs shrink-0 font-mono">
                            {{ $chapter->chapter_number }}
                        </span>

                        <img src="{{ $chapter->post->primaryMedia->url ?? $pool->cover_url }}" class="w-16 h-16 rounded-xl object-cover shrink-0 shadow">

                        <div class="min-w-0">
                            <h3 class="font-bold text-base truncate text-[var(--text-main)]">{{ $chapter->title }}</h3>
                            <div class="text-xs text-[var(--text-dim)] flex items-center gap-3 mt-1">
                                <span>{{ $chapter->post->media_count }} pages</span>
                                <span>·</span>
                                <span>Published {{ $chapter->created_at->format('M d, Y') }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <button wire:click="readChapter({{ $chapter->id }}, 1)" 
                                class="px-5 py-2.5 rounded-xl accent-bg text-white font-bold text-xs shadow hover:opacity-90 transition flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <span>Read Lightbox</span>
                        </button>
                    </div>
                </div>
            @empty
                <div class="p-8 text-center text-xs text-[var(--text-dim)] rounded-2xl bg-[var(--bg-surface)]">
                    No chapters added to this pool yet.
                </div>
            @endforelse
        </div>
    </div>

    <!-- History / Community Contributions Log (Spec: Anyone can add with edit history and revert) -->
    <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-4">
        <h3 class="font-extrabold text-sm uppercase tracking-wider text-[var(--text-dim)]">Pool Edit History & Contributions</h3>
        <div class="space-y-3">
            @forelse($pool->history as $h)
                <div class="flex items-center justify-between text-xs p-3 rounded-xl bg-[var(--bg-page)] border border-[var(--border-subtle)]">
                    <div class="flex items-center gap-2">
                        <span class="font-bold text-[var(--text-main)]">{{ $h->user->name }}</span>
                        <span class="text-[var(--text-muted)]">{{ $h->details }}</span>
                    </div>
                    <span class="text-[10px] text-[var(--text-dim)]">{{ $h->created_at->diffForHumans() }}</span>
                </div>
            @empty
                <div class="text-xs text-[var(--text-dim)]">No history logs recorded.</div>
            @endforelse
        </div>
    </div>

    <!-- Add Chapter Modal -->
    <div x-show="$wire.addChapterModalOpen" 
         x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-md"
         @click.self="$wire.set('addChapterModalOpen', false)">
        
        <div class="w-full max-w-lg p-6 rounded-3xl glass-panel shadow-2xl border border-[var(--border-medium)] space-y-4">
            <div class="flex items-center justify-between pb-3 border-b border-[var(--border-subtle)]">
                <h3 class="font-bold text-lg">Add Chapter to Pool</h3>
                <button wire:click="$set('addChapterModalOpen', false)" class="p-1 rounded-full hover:bg-[var(--bg-surface-elevated)]">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <div class="space-y-3">
                <div class="grid grid-cols-3 gap-3">
                    <div class="col-span-1">
                        <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Ch. Number</label>
                        <input type="number" step="0.5" wire:model="chapterNumber" class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)] font-mono">
                    </div>
                    <div class="col-span-2">
                        <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Chapter Title</label>
                        <input type="text" wire:model="chapterTitle" placeholder="e.g. Chapter 3: The Astral Awakening" class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)] font-bold">
                    </div>
                </div>

                <div>
                    <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Select Post Media (up to 100 pages)</label>
                    <select wire:model="selectedPostId" class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)]">
                        <option value="">-- Choose your uploaded post --</option>
                        @foreach($myEligiblePosts as $ep)
                            <option value="{{ $ep->id }}">{{ $ep->title }} ({{ $ep->media_count }} pages)</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-3 border-t border-[var(--border-subtle)]">
                <button wire:click="$set('addChapterModalOpen', false)" class="px-5 py-2 rounded-xl text-xs font-bold hover:bg-[var(--bg-surface-elevated)]">Cancel</button>
                <button wire:click="addChapter" class="px-6 py-2 rounded-xl accent-bg text-white font-bold text-xs shadow hover:opacity-90 transition">Save Chapter</button>
            </div>
        </div>
    </div>
</div>
