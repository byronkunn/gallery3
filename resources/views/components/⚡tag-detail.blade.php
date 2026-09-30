<?php

use App\Models\Post;
use App\Models\Tag;
use App\Models\TagAlias;
use App\Models\TagHistory;
use App\Models\TagImplication;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $name = '';

    public string $tab = 'posts'; // 'posts', 'wiki', 'related', 'history'

    public string $postSearch = '';

    public string $sortMode = 'newest'; // 'newest', 'oldest', 'popular', 'likes'

    public string $ratingFilter = 'all'; // 'all', 'safe', 'nsfw'

    public string $mediaTypeFilter = 'all'; // 'all', 'image', 'video'

    // History diff modal
    public bool $showDiffModal = false;

    public ?int $historyId = null;

    // Suggest Alias / Implication modal
    public bool $showAliasModal = false;

    public string $newAlias = '';

    public bool $showImplicationModal = false;

    public string $impliedTagName = '';

    public string $actionMessage = '';

    protected $queryString = [
        'tab' => ['except' => 'posts'],
        'postSearch' => ['except' => ''],
        'sortMode' => ['except' => 'newest'],
        'ratingFilter' => ['except' => 'all'],
    ];

    public function mount(string $name, ?string $tab = 'posts'): void
    {
        $this->name = Tag::normalizeName($name);
        $this->tab = in_array($tab, ['posts', 'wiki', 'related', 'history'], true) ? $tab : 'posts';
    }

    public function setTab(string $t): void
    {
        if (in_array($t, ['posts', 'wiki', 'related', 'history'], true)) {
            $this->tab = $t;
            $this->resetPage();
        }
    }

    public function toggleFollow(): void
    {
        if (! Auth::check()) {
            return;
        }

        $tag = Tag::where('name', $this->name)->first();
        if (! $tag) {
            return;
        }

        $user = Auth::user();
        if ($user->followedTags()->where('tag_id', $tag->id)->exists()) {
            $user->followedTags()->detach($tag->id);
        } else {
            $user->followedTags()->attach($tag->id);
            $user->mutedTags()->detach($tag->id);
        }
    }

    public function toggleMute(): void
    {
        if (! Auth::check()) {
            return;
        }

        $tag = Tag::where('name', $this->name)->first();
        if (! $tag) {
            return;
        }

        $user = Auth::user();
        if ($user->mutedTags()->where('tag_id', $tag->id)->exists()) {
            $user->mutedTags()->detach($tag->id);
        } else {
            $user->mutedTags()->attach($tag->id);
            $user->followedTags()->detach($tag->id);
        }
    }

    public function submitAlias(): void
    {
        if (! Auth::check()) {
            $this->actionMessage = 'Log in to suggest aliases.';

            return;
        }

        $aliasNorm = Tag::normalizeName($this->newAlias);
        if (empty($aliasNorm)) {
            $this->actionMessage = 'Please enter a valid alias.';

            return;
        }

        $tag = Tag::where('name', $this->name)->firstOrFail();

        TagAlias::firstOrCreate([
            'alias' => $aliasNorm,
            'tag_id' => $tag->id,
        ]);

        TagHistory::create([
            'tag_id' => $tag->id,
            'user_id' => Auth::id(),
            'action' => 'alias_added',
            'new_wiki' => ['alias' => $aliasNorm],
            'edit_summary' => "Added alias '{$aliasNorm}'",
        ]);

        $this->showAliasModal = false;
        $this->newAlias = '';
        $this->actionMessage = 'Alias added successfully!';
    }

    public function submitImplication(): void
    {
        if (! Auth::check()) {
            $this->actionMessage = 'Log in to suggest implications.';

            return;
        }

        $impNorm = Tag::normalizeName($this->impliedTagName);
        if (empty($impNorm)) {
            $this->actionMessage = 'Please enter a valid tag to imply.';

            return;
        }

        $tag = Tag::where('name', $this->name)->firstOrFail();
        $targetTag = Tag::firstOrCreate([
            'name' => $impNorm,
            'slug' => $impNorm,
            'type' => 'general',
        ]);

        TagImplication::firstOrCreate([
            'tag_id' => $tag->id,
            'implied_tag_id' => $targetTag->id,
        ]);

        TagHistory::create([
            'tag_id' => $tag->id,
            'user_id' => Auth::id(),
            'action' => 'implication_added',
            'new_wiki' => ['implied_tag' => $impNorm],
            'edit_summary' => "Added implication to #{$impNorm}",
        ]);

        $this->showImplicationModal = false;
        $this->impliedTagName = '';
        $this->actionMessage = 'Tag implication added!';
    }

    public function viewDiff(int $historyId): void
    {
        $this->historyId = $historyId;
        $this->showDiffModal = true;
    }
}; ?>

<div class="min-h-screen pb-16">
    @php
        $tag = Tag::where('name', $name)->orWhere('slug', $name)->firstOrFail();
        $isFollowing = Auth::check() && Auth::user()->isFollowingTag($tag);
        $isMuted = Auth::check() && Auth::user()->mutedTags()->where('tag_id', $tag->id)->exists();
    @endphp

    <!-- Tag Header Section -->
    <div class="border-b border-[var(--border-subtle)] bg-[var(--bg-surface)] px-4 py-8 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl">
            @if($actionMessage)
                <div class="mb-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 p-3 text-xs font-bold text-emerald-500 flex justify-between items-center">
                    <span>{{ $actionMessage }}</span>
                    <button wire:click="$set('actionMessage', '')" class="text-emerald-500">×</button>
                </div>
            @endif

            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-6">
                <div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="inline-flex items-center rounded-lg px-2.5 py-1 text-xs font-extrabold border {{ $tag->getTypeBadgeClasses() }}">
                            {{ $tag->getCategoryLabel() }} Tag
                        </span>
                        <span class="text-xs font-bold text-[var(--text-muted)]">
                            {{ number_format($tag->posts_count) }} posts
                        </span>
                        @if($tag->is_locked)
                            <span class="inline-flex items-center gap-1 text-xs text-amber-500 font-bold bg-amber-500/10 px-2 py-0.5 rounded-md border border-amber-500/20">
                                🔒 {{ ucfirst($tag->lock_level) }}
                            </span>
                        @endif
                    </div>

                    <h1 class="mt-2 text-3xl sm:text-4xl font-black tracking-tight text-[var(--text-main)]">
                        #{{ $tag->name }}
                    </h1>

                    <p class="mt-2 text-sm text-[var(--text-muted)] max-w-3xl leading-relaxed">
                        {{ $tag->short_description ?: ($tag->wiki_summary ?: 'Natural or artificial scenery where the environment is the primary subject of the work.') }}
                    </p>
                </div>

                <!-- Action Controls -->
                <div class="flex flex-wrap items-center gap-2 shrink-0">
                    @auth
                        <button wire:click="toggleFollow" 
                                class="inline-flex items-center gap-1.5 rounded-2xl px-4 py-2.5 text-xs font-bold transition border {{ $isFollowing ? 'bg-emerald-500 text-white border-transparent shadow' : 'border-[var(--border-subtle)] bg-[var(--bg-surface-elevated)] text-[var(--text-main)] hover:border-[var(--accent-primary)]' }}">
                            <span>{{ $isFollowing ? '✓ Following' : '+ Follow Tag' }}</span>
                        </button>

                        <button wire:click="toggleMute" 
                                class="inline-flex items-center gap-1.5 rounded-2xl px-3.5 py-2.5 text-xs font-bold transition border {{ $isMuted ? 'bg-rose-500 text-white border-transparent shadow' : 'border-[var(--border-subtle)] bg-[var(--bg-surface-elevated)] text-[var(--text-muted)] hover:text-rose-500' }}">
                            <span>{{ $isMuted ? '🔇 Muted' : 'Mute' }}</span>
                        </button>

                        <a href="{{ route('tags.edit-wiki', $tag->name) }}" 
                           class="inline-flex items-center gap-1.5 rounded-2xl accent-bg px-4 py-2.5 text-xs font-bold text-white shadow hover:brightness-110">
                            <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                            <span>Edit Wiki</span>
                        </a>
                    @endauth

                    <!-- More Options Dropdown -->
                    <div x-data="{ open: false }" class="relative">
                        <button @click="open = !open" 
                                class="rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-surface-elevated)] p-2.5 text-[var(--text-muted)] hover:text-[var(--text-main)]">
                            <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h.01M12 12h.01M19 12h.01M6 12a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0z"/></svg>
                        </button>

                        <div x-show="open" 
                             @click.away="open = false" 
                             x-cloak 
                             class="absolute right-0 top-12 z-30 w-48 rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-2 shadow-xl space-y-1">
                            <button wire:click="$set('showAliasModal', true)" @click="open = false" class="w-full text-left rounded-xl px-3 py-2 text-xs font-bold text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]">
                                ➕ Suggest Alias
                            </button>
                            <button wire:click="$set('showImplicationModal', true)" @click="open = false" class="w-full text-left rounded-xl px-3 py-2 text-xs font-bold text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]">
                                🔗 Suggest Implication
                            </button>
                            <a href="{{ route('tags.show', ['name' => $tag->name, 'tab' => 'history']) }}" class="block w-full text-left rounded-xl px-3 py-2 text-xs font-bold text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]">
                                📜 View Edit History
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab Navigation Bar -->
            <div class="mt-6 flex border-b border-[var(--border-subtle)] space-x-1 sm:space-x-4">
                @foreach([
                    'posts' => 'Posts',
                    'wiki' => 'Wiki Documentation',
                    'related' => 'Related & Aliases',
                    'history' => 'Revision History'
                ] as $tabKey => $tabLabel)
                    <button wire:click="setTab('{{ $tabKey }}')" 
                            class="relative py-3 px-3 sm:px-4 text-xs sm:text-sm font-bold transition-colors border-b-2 {{ $tab === $tabKey ? 'accent-text border-[var(--accent-primary)] font-black' : 'border-transparent text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">
                        {{ $tabLabel }}
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    <!-- Main Content Container -->
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        @if($tab === 'posts')
            <!-- Posts Tab (Gallery View Filtered by Tag) -->
            <div class="space-y-6">
                <!-- Search & Filters -->
                <div class="rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-4 sm:p-5 shadow-sm space-y-4">
                    <div class="flex flex-col sm:flex-row gap-3">
                        <div class="relative flex-1">
                            <input type="text" 
                                   wire:model.live.debounce.300ms="postSearch" 
                                   placeholder="Search within #{{ $tag->name }}..." 
                                   class="w-full rounded-xl border border-[var(--border-medium)] bg-[var(--bg-page)] py-2.5 pl-10 pr-4 text-sm text-[var(--text-main)] placeholder-[var(--text-muted)] focus:border-[var(--accent-primary)] focus:outline-none">
                            <svg class="absolute left-3.5 top-3 size-4 text-[var(--text-muted)]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <select wire:model.live="sortMode" 
                                    class="rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface-elevated)] px-3 py-2 text-xs font-bold text-[var(--text-main)] focus:outline-none">
                                <option value="newest">Newest First</option>
                                <option value="oldest">Oldest First</option>
                                <option value="popular">Most Liked</option>
                            </select>

                            <select wire:model.live="ratingFilter" 
                                    class="rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface-elevated)] px-3 py-2 text-xs font-bold text-[var(--text-main)] focus:outline-none">
                                <option value="all">All Content</option>
                                <option value="safe">SFW Only</option>
                                <option value="nsfw">NSFW Only</option>
                            </select>
                        </div>
                    </div>
                </div>

                @php
                    $postsQuery = $tag->posts()->with(['user', 'media']);

                    if (!empty($postSearch)) {
                        $postsQuery->where('title', 'like', "%{$postSearch}%");
                    }

                    if ($ratingFilter === 'safe') {
                        $postsQuery->where('is_nsfw', false);
                    } elseif ($ratingFilter === 'nsfw') {
                        $postsQuery->where('is_nsfw', true);
                    }

                    match ($sortMode) {
                        'oldest' => $postsQuery->orderBy('posts.created_at', 'asc'),
                        'popular' => $postsQuery->orderBy('likes_count', 'desc'),
                        default => $postsQuery->orderBy('posts.created_at', 'desc'),
                    };

                    $posts = $postsQuery->paginate(20);
                @endphp

                <!-- Booru Gallery Grid -->
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3 sm:gap-4">
                    @forelse($posts as $p)
                        @php $firstMedia = $p->media->first(); @endphp
                        <a href="{{ route('post.detail', $p->id) }}" class="group relative aspect-square overflow-hidden rounded-2xl bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] shadow-sm transition hover:shadow-md hover:scale-[1.02]">
                            @if($firstMedia)
                                <img src="{{ $firstMedia->thumbnail_url ?: $firstMedia->url }}" alt="{{ $p->title }}" class="size-full object-cover transition duration-300 group-hover:scale-105">
                            @else
                                <div class="flex size-full items-center justify-center text-xs text-[var(--text-muted)]">No Media</div>
                            @endif

                            <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition p-3 flex flex-col justify-end">
                                <span class="text-xs font-bold text-white truncate">{{ $p->title ?: 'Post #'.$p->id }}</span>
                                <span class="text-[10px] text-gray-300">❤️ {{ number_format($p->likes_count) }} likes</span>
                            </div>
                        </a>
                    @empty
                        <div class="col-span-full py-12 text-center rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-surface)]">
                            <span class="text-3xl">🖼️</span>
                            <h4 class="mt-2 text-sm font-bold text-[var(--text-main)]">No posts tagged with #{{ $tag->name }} yet</h4>
                        </div>
                    @endforelse
                </div>

                <div>
                    {{ $posts->links() }}
                </div>
            </div>

        @elseif($tab === 'wiki')
            <!-- Wiki Tab -->
            <div class="max-w-4xl space-y-8">
                <!-- Summary Card -->
                <div class="rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 sm:p-8 shadow-sm space-y-4">
                    <div class="flex items-center justify-between border-b border-[var(--border-subtle)] pb-4">
                        <h2 class="text-xl font-black text-[var(--text-main)] flex items-center gap-2">
                            <span>📖 #{{ $tag->name }} Documentation</span>
                        </h2>
                        @auth
                            <a href="{{ route('tags.edit-wiki', $tag->name) }}" class="rounded-xl accent-bg px-4 py-2 text-xs font-bold text-white hover:brightness-110">
                                ✏️ Edit Wiki
                            </a>
                        @endauth
                    </div>

                    <div class="prose dark:prose-invert max-w-none text-sm leading-relaxed text-[var(--text-main)]">
                        <p class="text-base font-medium leading-relaxed">
                            {{ $tag->wiki_summary ?: 'No detailed wiki summary has been added yet. Feel free to edit this wiki to contribute documentation!' }}
                        </p>
                    </div>
                </div>

                <!-- Usage Section -->
                @if($tag->wiki_usage)
                    <div class="rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 sm:p-8 shadow-sm space-y-3">
                        <h3 class="text-base font-extrabold text-[var(--text-main)] flex items-center gap-2">
                            <span>✅ Usage Guidelines</span>
                        </h3>
                        <div class="text-sm text-[var(--text-main)] leading-relaxed whitespace-pre-line bg-[var(--bg-page)] p-4 rounded-2xl border border-[var(--border-subtle)] font-sans">
                            {{ $tag->wiki_usage }}
                        </div>
                    </div>
                @endif

                <!-- Do Not Use When -->
                @if($tag->wiki_do_not_use)
                    <div class="rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 sm:p-8 shadow-sm space-y-3">
                        <h3 class="text-base font-extrabold text-rose-500 flex items-center gap-2">
                            <span>🚫 When NOT to Use</span>
                        </h3>
                        <div class="text-sm text-[var(--text-main)] leading-relaxed whitespace-pre-line bg-[var(--bg-page)] p-4 rounded-2xl border border-rose-500/20 font-sans">
                            {{ $tag->wiki_do_not_use }}
                        </div>
                    </div>
                @endif

                <!-- Examples & Notes -->
                @if($tag->wiki_examples || $tag->wiki_notes)
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        @if($tag->wiki_examples)
                            <div class="rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 shadow-sm space-y-2">
                                <h4 class="text-xs font-bold uppercase tracking-wider text-[var(--text-muted)]">Examples</h4>
                                <p class="text-xs text-[var(--text-main)] whitespace-pre-line leading-relaxed">
                                    {{ $tag->wiki_examples }}
                                </p>
                            </div>
                        @endif

                        @if($tag->wiki_notes)
                            <div class="rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 shadow-sm space-y-2">
                                <h4 class="text-xs font-bold uppercase tracking-wider text-[var(--text-muted)]">Notes & Edge Cases</h4>
                                <p class="text-xs text-[var(--text-main)] whitespace-pre-line leading-relaxed">
                                    {{ $tag->wiki_notes }}
                                </p>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

        @elseif($tab === 'related')
            <!-- Related & Aliases Tab -->
            <div class="max-w-4xl space-y-6">
                <!-- Aliases Card -->
                <div class="rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 shadow-sm space-y-3">
                    <h3 class="text-sm font-extrabold text-[var(--text-main)] uppercase tracking-wider">
                        Tag Aliases
                    </h3>
                    <p class="text-xs text-[var(--text-muted)]">
                        Searching any of these aliases automatically maps to the canonical tag <strong class="text-[var(--text-main)]">#{{ $tag->name }}</strong>.
                    </p>
                    <div class="flex flex-wrap gap-2 pt-2">
                        @forelse($tag->aliases as $alias)
                            <span class="rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-1.5 text-xs font-mono font-bold text-[var(--text-main)]">
                                {{ $alias->alias }} → #{{ $tag->name }}
                            </span>
                        @empty
                            <span class="text-xs text-[var(--text-muted)] italic">No aliases registered for this tag.</span>
                        @endforelse
                    </div>
                </div>

                <!-- Implication Hierarchy Tree -->
                <div class="rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 shadow-sm space-y-3">
                    <h3 class="text-sm font-extrabold text-[var(--text-main)] uppercase tracking-wider">
                        Tag Implications
                    </h3>
                    <p class="text-xs text-[var(--text-muted)]">
                        Tagging content with <strong class="text-[var(--text-main)]">#{{ $tag->name }}</strong> automatically implies and includes these parent tags:
                    </p>
                    <div class="flex flex-wrap gap-2 pt-2">
                        @forelse($tag->implications as $imp)
                            <a href="{{ route('tags.show', $imp->impliedTag->name) }}" class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-1.5 text-xs font-bold accent-text hover:underline">
                                <span>#{{ $tag->name }}</span>
                                <span>→</span>
                                <span>#{{ $imp->impliedTag->name }}</span>
                            </a>
                        @empty
                            <span class="text-xs text-[var(--text-muted)] italic">No tag implications defined.</span>
                        @endforelse
                    </div>
                </div>

                <!-- Related Tags -->
                <div class="rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 shadow-sm space-y-3">
                    <h3 class="text-sm font-extrabold text-[var(--text-main)] uppercase tracking-wider">
                        Related Tags
                    </h3>
                    <p class="text-xs text-[var(--text-muted)]">
                        Tags that curators have linked to <strong class="text-[var(--text-main)]">#{{ $tag->name }}</strong>.
                    </p>
                    <div class="flex flex-wrap gap-2 pt-2">
                        @forelse($tag->relatedTags as $related)
                            <a href="{{ route('tags.show', $related->name) }}" class="rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-1.5 text-xs font-bold accent-text hover:underline">
                                #{{ $related->name }}
                            </a>
                        @empty
                            <span class="text-xs text-[var(--text-muted)] italic">No related tags yet.</span>
                        @endforelse
                    </div>
                </div>
            </div>

        @elseif($tab === 'history')
            <!-- Revision History Tab with Visual Diff -->
            <div class="max-w-4xl space-y-4">
                <div class="rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 shadow-sm space-y-4">
                    <h3 class="text-base font-black text-[var(--text-main)] flex items-center gap-2">
                        <span>📜 Edit History for #{{ $tag->name }}</span>
                    </h3>

                    <div class="divide-y divide-[var(--border-subtle)] border-t border-[var(--border-subtle)]">
                        @forelse($tag->histories as $history)
                            <div class="py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs font-extrabold text-[var(--text-main)]">
                                            @ {{ $history->user->username ?? 'System' }}
                                        </span>
                                        <span class="inline-flex items-center rounded-md bg-[var(--bg-page)] px-2 py-0.5 text-[10px] font-bold uppercase text-[var(--text-muted)] border border-[var(--border-subtle)]">
                                            {{ $history->action }}
                                        </span>
                                        <span class="text-[11px] text-[var(--text-muted)]">
                                            {{ $history->created_at->diffForHumans() }}
                                        </span>
                                    </div>

                                    @if($history->edit_summary)
                                        <p class="mt-1 text-xs text-[var(--text-muted)] italic">
                                            "{{ $history->edit_summary }}"
                                        </p>
                                    @endif
                                </div>

                                <button wire:click="viewDiff({{ $history->id }})" 
                                        class="shrink-0 rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-1.5 text-xs font-bold text-[var(--text-main)] hover:border-[var(--accent-primary)]">
                                    View Diff
                                </button>
                            </div>
                        @empty
                            <div class="py-8 text-center text-xs text-[var(--text-muted)]">
                                No edit history recorded for this tag yet.
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif
    </div>

    <!-- Suggest Alias Modal -->
    @if($showAliasModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
            <div class="w-full max-w-md rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 shadow-2xl space-y-4">
                <h3 class="text-lg font-black text-[var(--text-main)]">Suggest Alias for #{{ $tag->name }}</h3>
                <div>
                    <label class="block text-xs font-bold text-[var(--text-muted)] uppercase mb-1">Alias Name</label>
                    <input type="text" wire:model="newAlias" placeholder="e.g. large_butt" class="w-full rounded-xl border border-[var(--border-medium)] bg-[var(--bg-page)] p-2.5 text-sm text-[var(--text-main)] font-mono focus:outline-none">
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button wire:click="$set('showAliasModal', false)" class="rounded-xl border border-[var(--border-subtle)] px-4 py-2 text-xs font-bold text-[var(--text-muted)]">Cancel</button>
                    <button wire:click="submitAlias" class="rounded-xl accent-bg px-4 py-2 text-xs font-bold text-white">Save Alias</button>
                </div>
            </div>
        </div>
    @endif

    <!-- Suggest Implication Modal -->
    @if($showImplicationModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
            <div class="w-full max-w-md rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 shadow-2xl space-y-4">
                <h3 class="text-lg font-black text-[var(--text-main)]">Suggest Implication</h3>
                <p class="text-xs text-[var(--text-muted)]">Tagging #{{ $tag->name }} will automatically imply this target tag:</p>
                <div>
                    <label class="block text-xs font-bold text-[var(--text-muted)] uppercase mb-1">Implied Tag Name</label>
                    <input type="text" wire:model="impliedTagName" placeholder="e.g. hair" class="w-full rounded-xl border border-[var(--border-medium)] bg-[var(--bg-page)] p-2.5 text-sm text-[var(--text-main)] font-mono focus:outline-none">
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button wire:click="$set('showImplicationModal', false)" class="rounded-xl border border-[var(--border-subtle)] px-4 py-2 text-xs font-bold text-[var(--text-muted)]">Cancel</button>
                    <button wire:click="submitImplication" class="rounded-xl accent-bg px-4 py-2 text-xs font-bold text-white">Save Implication</button>
                </div>
            </div>
        </div>
    @endif

    <!-- Diff Viewer Modal -->
    @if($showDiffModal && $historyId)
        @php $targetHist = TagHistory::find($historyId); @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
            <div class="w-full max-w-2xl rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 shadow-2xl space-y-4">
                <div class="flex items-center justify-between border-b border-[var(--border-subtle)] pb-3">
                    <h3 class="text-lg font-black text-[var(--text-main)]">Revision Diff Viewer</h3>
                    <button wire:click="$set('showDiffModal', false)" class="text-[var(--text-muted)] text-xl font-bold">×</button>
                </div>

                @if($targetHist)
                    <div class="space-y-3">
                        <div class="text-xs text-[var(--text-muted)]">
                            Action: <strong class="text-[var(--text-main)] uppercase">{{ $targetHist->action }}</strong> by <strong class="text-[var(--text-main)]">@ {{ $targetHist->user->username ?? 'System' }}</strong>
                        </div>

                        <div class="rounded-2xl bg-black/90 p-4 text-xs font-mono space-y-2 border border-gray-800 text-gray-200">
                            @if(!empty($targetHist->old_wiki))
                                <div class="text-rose-400 border-l-2 border-rose-500 pl-2">
                                    - OLD: {{ json_encode($targetHist->old_wiki, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}
                                </div>
                            @endif

                            @if(!empty($targetHist->new_wiki))
                                <div class="text-emerald-400 border-l-2 border-emerald-500 pl-2">
                                    + NEW: {{ json_encode($targetHist->new_wiki, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}
                                </div>
                            @endif
                        </div>
                    </div>
                @endif

                <div class="flex justify-end pt-2">
                    <button wire:click="$set('showDiffModal', false)" class="rounded-xl border border-[var(--border-subtle)] px-4 py-2 text-xs font-bold text-[var(--text-main)]">Close</button>
                </div>
            </div>
        </div>
    @endif
</div>
