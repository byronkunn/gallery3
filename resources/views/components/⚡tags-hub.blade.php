<?php

use App\Models\Tag;
use App\Models\TagAlias;
use App\Models\TagHistory;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $categoryFilter = 'all'; // 'all', 'artist', 'character', 'general', 'copyright', 'meta'

    public string $sortMode = 'popular'; // 'popular', 'alphabetical', 'newest'

    public string $viewMode = 'list'; // 'list', 'grid'

    public ?int $selectedTagId = null;

    // Create Modal state
    public bool $showCreateModal = false;

    public string $createTagName = '';

    public string $createTagCategory = 'general';

    public string $createTagShortDescription = '';

    public string $createTagWikiSummary = '';

    public array $createTagAliases = [];

    public string $newAliasInput = '';

    public string $createMessage = '';

    protected $queryString = [
        'search' => ['except' => ''],
        'categoryFilter' => ['except' => 'all'],
        'sortMode' => ['except' => 'popular'],
        'viewMode' => ['except' => 'list'],
    ];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSortMode(): void
    {
        $this->resetPage();
    }

    public function selectTag(int $tagId): void
    {
        $this->selectedTagId = $tagId;
    }

    public function toggleFollowTag(int $tagId): void
    {
        if (! Auth::check()) {
            return;
        }

        $user = Auth::user();
        if ($user->followedTags()->where('tag_id', $tagId)->exists()) {
            $user->followedTags()->detach($tagId);
        } else {
            $user->followedTags()->attach($tagId);
            $user->mutedTags()->detach($tagId);
        }
    }

    public function toggleMuteTag(int $tagId): void
    {
        if (! Auth::check()) {
            return;
        }

        $user = Auth::user();
        if ($user->mutedTags()->where('tag_id', $tagId)->exists()) {
            $user->mutedTags()->detach($tagId);
        } else {
            $user->mutedTags()->attach($tagId);
            $user->followedTags()->detach($tagId);
        }
    }

    public function openCreateModal(?string $initialName = null): void
    {
        $name = $initialName ?? $this->search;
        $this->createTagName = Tag::normalizeName($name);
        $this->createTagCategory = 'general';
        $this->createTagShortDescription = '';
        $this->createTagWikiSummary = '';
        $this->createTagAliases = [];
        $this->newAliasInput = '';
        $this->createMessage = '';
        $this->showCreateModal = true;
    }

    public function addAlias(): void
    {
        $alias = Tag::normalizeName($this->newAliasInput);
        if ($alias !== '' && ! in_array($alias, $this->createTagAliases, true)) {
            $this->createTagAliases[] = $alias;
        }
        $this->newAliasInput = '';
    }

    public function removeAlias(int $index): void
    {
        if (isset($this->createTagAliases[$index])) {
            unset($this->createTagAliases[$index]);
            $this->createTagAliases = array_values($this->createTagAliases);
        }
    }

    public function createTag(): void
    {
        if (! Auth::check()) {
            $this->createMessage = 'You must be logged in to create tags.';

            return;
        }

        $normalized = Tag::normalizeName($this->createTagName);
        if (empty($normalized)) {
            $this->createMessage = 'Please enter a valid tag name.';

            return;
        }

        $existing = Tag::where('name', $normalized)->orWhere('slug', $normalized)->first();
        if ($existing) {
            $this->createMessage = 'Tag #'.$normalized.' already exists.';

            return;
        }

        $tag = Tag::create([
            'name' => $normalized,
            'slug' => $normalized,
            'type' => $this->createTagCategory,
            'short_description' => $this->createTagShortDescription ?: null,
            'wiki_summary' => $this->createTagWikiSummary ?: null,
            'posts_count' => 0,
        ]);

        foreach ($this->createTagAliases as $aliasName) {
            TagAlias::create([
                'alias' => $aliasName,
                'tag_id' => $tag->id,
            ]);
        }

        TagHistory::create([
            'tag_id' => $tag->id,
            'user_id' => Auth::id(),
            'action' => 'created',
            'new_wiki' => [
                'name' => $tag->name,
                'type' => $tag->type,
                'short_description' => $tag->short_description,
                'wiki_summary' => $tag->wiki_summary,
            ],
            'edit_summary' => 'Tag created',
        ]);

        $this->showCreateModal = false;
        $this->selectedTagId = $tag->id;
        $this->search = $tag->name;
    }
}; ?>

<div class="min-h-screen pb-16">
    <!-- Top Header Banner -->
    <div class="border-b border-[var(--border-subtle)] bg-[var(--bg-surface)]/80 backdrop-blur-xl sticky top-0 z-20 px-4 py-5 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <h1 class="text-xl sm:text-2xl lg:text-3xl font-black tracking-tight text-[var(--text-main)] flex items-center gap-2.5">
                        <span class="flex size-9 sm:size-10 items-center justify-center rounded-2xl accent-bg text-white shadow-md text-base sm:text-lg font-bold">#</span>
                        <span>Unified Tags & Wiki Hub</span>
                    </h1>
                    <p class="mt-1 text-xs sm:text-sm text-[var(--text-muted)]">
                        Search, discover, edit wikis, manage aliases, and explore community tags in one workspace.
                    </p>
                </div>

                <div class="flex items-center gap-2 shrink-0">
                    <button wire:click="openCreateModal()" 
                            class="inline-flex items-center gap-2 rounded-2xl accent-bg px-4 py-2.5 text-xs sm:text-sm font-bold text-white shadow-lg hover:brightness-110 transition active:scale-95">
                        <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                        <span>Create Tag</span>
                    </button>
                </div>
            </div>

            <!-- Search Bar & Filters -->
            <div class="mt-4 sm:mt-6 space-y-3 sm:space-y-4">
                <div class="relative">
                    <input type="text" 
                           wire:model.live.debounce.300ms="search" 
                           placeholder="Search tags, aliases, or wiki definitions..." 
                           class="w-full rounded-2xl border border-[var(--border-medium)] bg-[var(--bg-page)] py-3 pl-11 pr-4 text-sm sm:text-base text-[var(--text-main)] placeholder-[var(--text-muted)] shadow-inner focus:border-[var(--accent-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--accent-glow)] transition">
                    <svg class="absolute left-4 top-3.5 size-5 text-[var(--text-muted)]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </div>

                <!-- Category Filters Horizontal Scroll Bar -->
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pt-1">
                    <div class="flex items-center gap-1.5 sm:gap-2 overflow-x-auto pb-1 sm:pb-0 scrollbar-none">
                        @foreach([
                            'all' => 'All Tags',
                            'general' => 'General',
                            'artist' => 'Artists',
                            'character' => 'Characters',
                            'copyright' => 'Copyright',
                            'meta' => 'Meta'
                        ] as $catKey => $catLabel)
                            <button wire:click="$set('categoryFilter', '{{ $catKey }}')" 
                                    class="shrink-0 rounded-xl px-3 py-1.5 text-xs font-bold transition-all border {{ $categoryFilter === $catKey ? 'accent-bg text-white border-transparent shadow-md' : 'border-[var(--border-subtle)] bg-[var(--bg-surface-elevated)] text-[var(--text-muted)] hover:text-[var(--text-main)] hover:border-[var(--border-medium)]' }}">
                                {{ $catLabel }}
                            </button>
                        @endforeach
                    </div>

                    <!-- Sort Mode & View Mode Switcher -->
                    <div class="flex items-center justify-between sm:justify-end gap-3 shrink-0 pt-1 sm:pt-0">
                        <div class="flex items-center gap-1.5">
                            <span class="text-xs font-semibold text-[var(--text-muted)] hidden sm:inline">Sort:</span>
                            <select wire:model.live="sortMode" 
                                    class="rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface-elevated)] px-3 py-1.5 text-xs font-bold text-[var(--text-main)] focus:outline-none cursor-pointer">
                                <option value="popular">Popular (Post Count)</option>
                                <option value="alphabetical">Alphabetical (A-Z)</option>
                                <option value="newest">Newest Created</option>
                            </select>
                        </div>

                        <div class="flex items-center rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface-elevated)] p-1 shrink-0">
                            <button wire:click="$set('viewMode', 'list')" 
                                    class="rounded-lg p-1.5 text-xs transition {{ $viewMode === 'list' ? 'accent-bg text-white shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-main)]' }}"
                                    title="List View">
                                <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                            </button>
                            <button wire:click="$set('viewMode', 'grid')" 
                                    class="rounded-lg p-1.5 text-xs transition {{ $viewMode === 'grid' ? 'accent-bg text-white shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-main)]' }}"
                                    title="Grid View">
                                <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Workspace Container -->
    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        @php
            $normalizedSearch = Tag::normalizeName($search);
            $query = Tag::query();

            if (!empty($search)) {
                $query->where(function($q) use ($search, $normalizedSearch) {
                    $q->where('name', 'like', "%{$normalizedSearch}%")
                      ->orWhere('short_description', 'like', "%{$search}%")
                      ->orWhere('wiki_summary', 'like', "%{$search}%")
                      ->orWhereHas('aliases', function($aq) use ($normalizedSearch) {
                          $aq->where('alias', 'like', "%{$normalizedSearch}%");
                      });
                });
            }

            if ($categoryFilter !== 'all') {
                if ($categoryFilter === 'copyright') {
                    $query->whereIn('type', ['copyright', 'series']);
                } else {
                    $query->where('type', $categoryFilter);
                }
            }

            match ($sortMode) {
                'alphabetical' => $query->orderBy('name', 'asc'),
                'newest' => $query->latest(),
                default => $query->orderBy('posts_count', 'desc'),
            };

            $tags = $query->paginate(24);

            $exactMatchExists = !empty($normalizedSearch) && Tag::where('name', $normalizedSearch)->exists();
            $similarTags = !empty($normalizedSearch) && !$exactMatchExists
                ? Tag::where('name', 'like', "%{$normalizedSearch}%")->take(4)->get()
                : collect();

            $activeTag = $selectedTagId ? Tag::find($selectedTagId) : ($tags->first() ?? null);
        @endphp

        <!-- Search-First Tag Creation Notice -->
        @if(!empty($normalizedSearch) && !$exactMatchExists)
            <div class="mb-6 rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4 sm:p-5 backdrop-blur-md">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h3 class="text-sm sm:text-base font-bold text-amber-500 flex items-center gap-2">
                            <span>🔍 No exact tag named "#{{ $normalizedSearch }}" exists</span>
                        </h3>
                        @if($similarTags->isNotEmpty())
                            <p class="mt-1 text-xs text-[var(--text-muted)]">
                                Did you mean one of these existing tags? Check before creating a new tag to prevent duplicate tags:
                            </p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach($similarTags as $simTag)
                                    <button wire:click="selectTag({{ $simTag->id }})" 
                                            class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-2.5 py-1 text-xs font-bold text-[var(--text-main)] hover:border-[var(--accent-primary)]">
                                        <span class="size-2 rounded-full {{ $simTag->getDotColorClass() }}"></span>
                                        <span>#{{ $simTag->name }}</span>
                                        <span class="text-[10px] text-[var(--text-muted)]">({{ number_format($simTag->posts_count) }})</span>
                                    </button>
                                @endforeach
                            </div>
                        @else
                            <p class="mt-1 text-xs text-[var(--text-muted)]">
                                You can create the tag <strong class="text-[var(--text-main)]">#{{ $normalizedSearch }}</strong> now and add wiki documentation later.
                            </p>
                        @endif
                    </div>

                    <button wire:click="openCreateModal('{{ $normalizedSearch }}')" 
                            class="shrink-0 rounded-xl accent-bg px-4 py-2 text-xs font-bold text-white shadow hover:brightness-110">
                        Create "#{{ $normalizedSearch }}"
                    </button>
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <!-- Left Column: Tag Directory List/Grid (col-span-7 or 8) -->
            <div class="lg:col-span-7 xl:col-span-8 space-y-4">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-[var(--text-muted)] uppercase tracking-wider">
                        Showing {{ number_format($tags->total()) }} Tags
                    </span>
                </div>

                @if($viewMode === 'list')
                    <div class="overflow-hidden rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] shadow-sm">
                        <div class="divide-y divide-[var(--border-subtle)]">
                            @forelse($tags as $t)
                                <div wire:click="selectTag({{ $t->id }})" 
                                     class="flex items-center justify-between p-3.5 sm:p-4 transition cursor-pointer hover:bg-[var(--bg-surface-elevated)] {{ $activeTag && $activeTag->id === $t->id ? 'bg-[var(--bg-surface-elevated)] ring-1 ring-inset ring-[var(--accent-primary)]' : '' }}">
                                    <div class="min-w-0 flex-1 pr-3">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="size-2 rounded-full shrink-0 {{ $t->getDotColorClass() }}"></span>
                                            <a href="{{ route('tags.show', $t->name) }}" class="font-black text-sm sm:text-base text-[var(--text-main)] hover:accent-text">
                                                #{{ $t->name }}
                                            </a>
                                            <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[10px] font-extrabold border {{ $t->getTypeBadgeClasses() }}">
                                                {{ $t->getCategoryLabel() }}
                                            </span>
                                            @if($t->is_locked)
                                                <span class="text-xs text-amber-500" title="Locked Tag">🔒</span>
                                            @endif
                                        </div>

                                        @if($t->short_description)
                                            <p class="mt-1 text-xs text-[var(--text-muted)] line-clamp-1 pl-4">
                                                {{ $t->short_description }}
                                            </p>
                                        @endif
                                    </div>

                                    <div class="flex items-center gap-3 shrink-0">
                                        <div class="text-right">
                                            <span class="block text-xs font-black text-[var(--text-main)]">
                                                {{ number_format($t->posts_count) }}
                                            </span>
                                            <span class="block text-[10px] text-[var(--text-muted)]">posts</span>
                                        </div>

                                        <a href="{{ route('tags.show', $t->name) }}" 
                                           class="rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] p-2 text-[var(--text-muted)] hover:accent-text hover:border-[var(--accent-primary)] transition">
                                            <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                                        </a>
                                    </div>
                                </div>
                            @empty
                                <div class="p-8 text-center">
                                    <span class="text-3xl">🏷️</span>
                                    <h4 class="mt-2 text-sm font-bold text-[var(--text-main)]">No tags found</h4>
                                    <p class="mt-1 text-xs text-[var(--text-muted)]">Try adjusting your search query or category filter.</p>
                                </div>
                            @endforelse
                        </div>
                    </div>
                @else
                    <!-- Grid View -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @forelse($tags as $t)
                            <div wire:click="selectTag({{ $t->id }})" 
                                 class="rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-4 transition cursor-pointer hover:border-[var(--accent-primary)] {{ $activeTag && $activeTag->id === $t->id ? 'ring-2 ring-[var(--accent-primary)]' : '' }}">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[10px] font-extrabold border {{ $t->getTypeBadgeClasses() }}">
                                        <span class="size-1.5 rounded-full {{ $t->getDotColorClass() }}"></span>
                                        <span>{{ $t->getCategoryLabel() }}</span>
                                    </span>
                                    <span class="text-xs font-bold text-[var(--text-muted)]">
                                        {{ number_format($t->posts_count) }} posts
                                    </span>
                                </div>

                                <a href="{{ route('tags.show', $t->name) }}" class="mt-2 block font-black text-base text-[var(--text-main)] hover:accent-text truncate">
                                    #{{ $t->name }}
                                </a>

                                <p class="mt-1 text-xs text-[var(--text-muted)] line-clamp-2 min-h-8 leading-relaxed">
                                    {{ $t->short_description ?: 'No wiki summary registered.' }}
                                </p>
                            </div>
                        @empty
                            <div class="col-span-full p-8 text-center border border-[var(--border-subtle)] rounded-2xl bg-[var(--bg-surface)]">
                                <span class="text-3xl">🏷️</span>
                                <h4 class="mt-2 text-sm font-bold text-[var(--text-main)]">No tags found</h4>
                            </div>
                        @endforelse
                    </div>
                @endif

                <div class="mt-4">
                    {{ $tags->links() }}
                </div>
            </div>

            <!-- Right Column: Tag Details Preview Box (Workspace Desktop View) -->
            <div class="lg:col-span-5 xl:col-span-4">
                <div class="sticky top-24 rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5 sm:p-6 shadow-xl space-y-4">
                    @if($activeTag)
                        <div class="space-y-4">
                            <!-- Header -->
                            <div class="border-b border-[var(--border-subtle)] pb-4">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="inline-flex items-center rounded-lg px-2.5 py-1 text-xs font-extrabold border {{ $activeTag->getTypeBadgeClasses() }}">
                                        {{ $activeTag->getCategoryLabel() }} Tag
                                    </span>
                                    <span class="text-xs font-black text-[var(--text-main)] bg-[var(--bg-surface-elevated)] px-2.5 py-1 rounded-md border border-[var(--border-subtle)]">
                                        {{ number_format($activeTag->posts_count) }} posts
                                    </span>
                                </div>

                                <h2 class="mt-3 text-2xl font-black text-[var(--text-main)] flex items-center gap-2">
                                    <span>#{{ $activeTag->name }}</span>
                                    @if($activeTag->is_locked)
                                        <span class="text-base text-amber-500" title="Locked Tag">🔒</span>
                                    @endif
                                </h2>

                                <p class="mt-2 text-xs text-[var(--text-muted)] leading-relaxed">
                                    {{ $activeTag->short_description ?: ($activeTag->wiki_summary ?: 'No detailed wiki summary added yet.') }}
                                </p>

                                <div class="mt-4 flex flex-wrap gap-2">
                                    <a href="{{ route('tags.show', $activeTag->name) }}" 
                                       class="inline-flex items-center gap-1.5 rounded-xl accent-bg px-4 py-2 text-xs font-bold text-white shadow hover:brightness-110 active:scale-95 transition">
                                        <span>Open Tag Page</span>
                                        <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                                    </a>

                                    @auth
                                        @php
                                            $isFollowing = Auth::user()->isFollowingTag($activeTag);
                                            $isMuted = Auth::user()->mutedTags()->where('tag_id', $activeTag->id)->exists();
                                        @endphp

                                        <button wire:click="toggleFollowTag({{ $activeTag->id }})" 
                                                class="rounded-xl px-3 py-2 text-xs font-bold transition border {{ $isFollowing ? 'bg-emerald-500 text-white border-transparent shadow-sm' : 'border-[var(--border-subtle)] bg-[var(--bg-surface-elevated)] text-[var(--text-main)] hover:border-[var(--accent-primary)]' }}">
                                            {{ $isFollowing ? '✓ Following' : '+ Follow' }}
                                        </button>

                                        <button wire:click="toggleMuteTag({{ $activeTag->id }})" 
                                                class="rounded-xl px-3 py-2 text-xs font-bold transition border {{ $isMuted ? 'bg-rose-500 text-white border-transparent shadow-sm' : 'border-[var(--border-subtle)] bg-[var(--bg-surface-elevated)] text-[var(--text-muted)] hover:text-rose-500' }}">
                                            {{ $isMuted ? '🔇 Muted' : 'Mute' }}
                                        </button>

                                        <a href="{{ route('tags.edit-wiki', $activeTag->name) }}" 
                                           class="rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface-elevated)] px-3 py-2 text-xs font-bold text-[var(--text-main)] hover:border-[var(--accent-primary)] transition">
                                            ✏️ Edit Wiki
                                        </a>
                                    @endauth
                                </div>
                            </div>

                            <!-- Wiki Quick Preview Sections -->
                            @if($activeTag->wiki_usage)
                                <div>
                                    <h4 class="text-[11px] font-bold text-[var(--text-muted)] uppercase tracking-wider">Usage Guidelines</h4>
                                    <p class="mt-1 text-xs text-[var(--text-main)] leading-relaxed whitespace-pre-line bg-[var(--bg-page)] p-3 rounded-xl border border-[var(--border-subtle)] font-sans">
                                        {{ Str::limit($activeTag->wiki_usage, 220) }}
                                    </p>
                                </div>
                            @endif

                            <!-- Aliases -->
                            <div>
                                <h4 class="text-[11px] font-bold text-[var(--text-muted)] uppercase tracking-wider">Aliases</h4>
                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                    @forelse($activeTag->aliases as $alias)
                                        <span class="rounded-lg bg-[var(--bg-page)] border border-[var(--border-subtle)] px-2.5 py-1 text-[11px] font-mono text-[var(--text-main)]">
                                            {{ $alias->alias }} → #{{ $activeTag->name }}
                                        </span>
                                    @empty
                                        <span class="text-xs text-[var(--text-muted)] italic">No aliases defined</span>
                                    @endforelse
                                </div>
                            </div>

                            <!-- Implications -->
                            @if($activeTag->implications->isNotEmpty())
                                <div>
                                    <h4 class="text-[11px] font-bold text-[var(--text-muted)] uppercase tracking-wider">Implied Tags</h4>
                                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                                        @foreach($activeTag->implications as $imp)
                                            <a href="{{ route('tags.show', $imp->impliedTag->name) }}" class="rounded-lg bg-[var(--bg-page)] border border-[var(--border-subtle)] px-2.5 py-1 text-[11px] font-bold accent-text hover:underline">
                                                #{{ $activeTag->name }} → #{{ $imp->impliedTag->name }}
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    @else
                        <div class="p-8 text-center text-[var(--text-muted)]">
                            <span class="text-2xl">🏷️</span>
                            <p class="mt-2 text-xs font-semibold">Select a tag to preview its details & wiki</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Create Tag Modal -->
    @if($showCreateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-md">
            <div class="w-full max-w-lg rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 shadow-2xl space-y-4">
                <div class="flex items-center justify-between border-b border-[var(--border-subtle)] pb-3">
                    <h3 class="text-lg font-black text-[var(--text-main)] flex items-center gap-2">
                        <span>🏷️ Create New Tag</span>
                    </h3>
                    <button wire:click="$set('showCreateModal', false)" class="text-[var(--text-muted)] hover:text-[var(--text-main)] text-xl font-bold">×</button>
                </div>

                @if($createMessage)
                    <div class="rounded-xl bg-rose-500/10 border border-rose-500/20 p-3 text-xs text-rose-500 font-bold">
                        {{ $createMessage }}
                    </div>
                @endif

                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-bold text-[var(--text-muted)] uppercase mb-1">Tag Name *</label>
                        <input type="text" 
                               wire:model="createTagName" 
                               placeholder="e.g. huge_thighs" 
                               class="w-full rounded-xl border border-[var(--border-medium)] bg-[var(--bg-page)] p-2.5 text-sm text-[var(--text-main)] font-mono focus:border-[var(--accent-primary)] focus:outline-none">
                        <span class="mt-1 block text-[11px] text-[var(--text-muted)]">
                            Normalized Tag Name: <strong class="font-mono text-[var(--accent-primary)]">#{{ Tag::normalizeName($createTagName) }}</strong>
                        </span>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-[var(--text-muted)] uppercase mb-1">Category *</label>
                        <select wire:model="createTagCategory" 
                                class="w-full rounded-xl border border-[var(--border-medium)] bg-[var(--bg-page)] p-2.5 text-sm text-[var(--text-main)] focus:outline-none">
                            <option value="general">General</option>
                            <option value="artist">Artist</option>
                            <option value="character">Character</option>
                            <option value="copyright">Copyright / Series</option>
                            <option value="meta">Meta</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-[var(--text-muted)] uppercase mb-1">Short Description</label>
                        <input type="text" 
                               wire:model="createTagShortDescription" 
                               placeholder="Brief description..." 
                               class="w-full rounded-xl border border-[var(--border-medium)] bg-[var(--bg-page)] p-2.5 text-sm text-[var(--text-main)] focus:outline-none">
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-[var(--text-muted)] uppercase mb-1">Wiki Summary (Optional)</label>
                        <textarea wire:model="createTagWikiSummary" 
                                  rows="3" 
                                  placeholder="Full documentation summary..." 
                                  class="w-full rounded-xl border border-[var(--border-medium)] bg-[var(--bg-page)] p-2.5 text-sm text-[var(--text-main)] focus:outline-none"></textarea>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-[var(--text-muted)] uppercase mb-1">Aliases</label>
                        <div class="flex gap-2">
                            <input type="text" 
                                   wire:model="newAliasInput" 
                                   wire:keydown.enter.prevent="addAlias"
                                   placeholder="Add alias..." 
                                   class="flex-1 rounded-xl border border-[var(--border-medium)] bg-[var(--bg-page)] p-2 text-xs text-[var(--text-main)] font-mono">
                            <button type="button" wire:click="addAlias" class="rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface-elevated)] px-3 text-xs font-bold text-[var(--text-main)]">
                                + Add
                            </button>
                        </div>

                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach($createTagAliases as $idx => $aliasItem)
                                <span class="inline-flex items-center gap-1 rounded-lg bg-[var(--bg-page)] border border-[var(--border-subtle)] px-2 py-0.5 text-xs font-mono text-[var(--text-main)]">
                                    {{ $aliasItem }}
                                    <button type="button" wire:click="removeAlias({{ $idx }})" class="text-rose-400 font-bold hover:text-rose-600">×</button>
                                </span>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-[var(--border-subtle)] pt-4">
                    <button wire:click="$set('showCreateModal', false)" class="rounded-xl border border-[var(--border-subtle)] px-4 py-2 text-xs font-bold text-[var(--text-muted)] hover:text-[var(--text-main)]">
                        Cancel
                    </button>
                    <button wire:click="createTag" class="rounded-xl accent-bg px-5 py-2 text-xs font-bold text-white shadow hover:brightness-110">
                        Create Tag
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
