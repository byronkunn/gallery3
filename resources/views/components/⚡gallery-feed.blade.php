<?php

use App\Models\Like;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $activeTab = 'latest'; // 'latest', 'following', 'for-you'

    public string $searchInput = '';

    public string $search = '';

    public array $selectedTags = [];

    public array $excludedTags = []; // Negative tags starting with - (e.g. -nsfw, -sketch)

    public string $ratingFilter = 'all'; // 'all', 'safe', 'nsfw'

    public ?int $minScore = null;

    public string $selectedTag = ''; // for backwards compatibility with incoming URLs

    public string $sortMode = 'latest'; // 'latest', 'oldest', 'popular', 'most_viewed', 'random'

    public string $mediaTypeFilter = 'all'; // 'all', 'image', 'gif', 'video', 'albums'

    public string $videoDurationFilter = 'all'; // 'all', 'short', 'medium', 'long'

    public string $followingSubFilter = 'all'; // 'all', 'users', 'artists', 'tags', 'collections'

    public string $followingSort = 'newest'; // 'newest', 'popular', 'most_liked'

    public string $timeRange = 'all'; // 'today', 'week', 'all'

    public string $gridLayout = 'masonry'; // 'masonry', 'square'

    public bool $blurNsfw = true;

    protected $queryString = [
        'activeTab' => ['except' => 'for-you'],
        'followingSubFilter' => ['except' => 'all'],
        'mediaTypeFilter' => ['except' => 'all'],
        'videoDurationFilter' => ['except' => 'all'],
        'search' => ['except' => ''],
        'selectedTags' => ['except' => []],
        'excludedTags' => ['except' => []],
        'ratingFilter' => ['except' => 'all'],
        'sortMode' => ['except' => 'latest'],
        'timeRange' => ['except' => 'all'],
    ];

    // Booru Tag Aliases Mapping
    protected array $tagAliases = [
        'cat_girl' => 'nekomimi',
        'catgirl' => 'nekomimi',
        'landscape' => 'scenery',
        'girl' => '1girl',
        'boy' => '1boy',
        'night' => 'night_sky',
    ];

    // Booru Tag Implications Mapping
    protected array $tagImplications = [
        'nekomimi' => ['cat_ears', 'animal_ears'],
        'night_sky' => ['scenery'],
        'cyberpunk_city' => ['cyberpunk', 'cityscape'],
    ];

    public function mount()
    {
        if (! Auth::check() && $this->activeTab === 'following') {
            $this->activeTab = 'for-you';
        }

        if (Auth::check()) {
            $this->blurNsfw = Auth::user()->blur_nsfw ?? true;
        }

        if (! empty($this->selectedTag)) {
            $clean = strtolower(trim(str_replace('#', '', $this->selectedTag)));
            $clean = str_replace(' ', '_', $clean);
            if (! in_array($clean, $this->selectedTags)) {
                $this->addTag($clean);
            }
            $this->selectedTag = '';
        }
    }

    public function setTab(string $tab)
    {
        if ($tab === 'following' && ! Auth::check()) {
            return;
        }
        $this->activeTab = $tab;
        $this->resetPage();
    }

    public function setFollowingSubFilter(string $filter)
    {
        $this->followingSubFilter = in_array($filter, ['all', 'users', 'tags', 'collections'], true) ? $filter : 'all';
        $this->resetPage();
    }

    public function notInterested(int $postId): void
    {
        $user = Auth::user();
        if ($user) {
            DB::table('user_disliked_posts')->updateOrInsert(
                ['user_id' => $user->id, 'post_id' => $postId],
                ['updated_at' => now(), 'created_at' => now()]
            );
            $this->dispatch('notify', 'Marked artwork as not interested.');
            $this->resetPage();
        }
    }

    public function muteTag(string $tagName): void
    {
        $user = Auth::user();
        if ($user) {
            $tag = Tag::where('name', $tagName)->first();
            if ($tag) {
                $user->mutedTags()->syncWithoutDetaching([$tag->id]);
                $this->dispatch('notify', "Muted tag #{$tagName}.");
                $this->resetPage();
            }
        }
    }

    public function setSort(string $mode, string $range = 'all')
    {
        $this->sortMode = $mode;
        $this->timeRange = $range;
        $this->resetPage();
    }

    public function resolveTagAlias(string $tag): string
    {
        $storedAlias = DB::table('tag_aliases')
            ->join('tags', 'tags.id', '=', 'tag_aliases.tag_id')
            ->where('tag_aliases.alias', $tag)
            ->value('tags.name');

        return $storedAlias ?? $this->tagAliases[$tag] ?? $tag;
    }

    public function addTag(string $tag)
    {
        $isNegative = str_starts_with($tag, '-');
        $clean = strtolower(trim(str_replace(['#', '-'], '', $tag)));
        $clean = str_replace(' ', '_', $clean);
        $clean = $this->resolveTagAlias($clean);

        if (! empty($clean)) {
            if ($isNegative) {
                if (! in_array($clean, $this->excludedTags)) {
                    $this->excludedTags[] = $clean;
                }
            } else {
                if (! in_array($clean, $this->selectedTags)) {
                    $this->selectedTags[] = $clean;
                }
            }
        }
        $this->searchInput = '';
        $this->resetPage();
    }

    public function removeTag(string $tag)
    {
        $this->selectedTags = array_values(array_filter($this->selectedTags, fn ($t) => $t !== $tag));
        $this->excludedTags = array_values(array_filter($this->excludedTags, fn ($t) => $t !== $tag));
        $this->resetPage();
    }

    public function removeExcludedTag(string $tag)
    {
        $this->excludedTags = array_values(array_filter($this->excludedTags, fn ($t) => $t !== $tag));
        $this->resetPage();
    }

    public function clearAllTags()
    {
        $this->selectedTags = [];
        $this->excludedTags = [];
        $this->ratingFilter = 'all';
        $this->minScore = null;
        $this->search = '';
        $this->searchInput = '';
        $this->resetPage();
    }

    public function filterByTag(string $tag)
    {
        $clean = strtolower(trim(str_replace(['#', '-'], '', $tag)));
        $clean = str_replace(' ', '_', $clean);
        $clean = $this->resolveTagAlias($clean);

        if (in_array($clean, $this->selectedTags)) {
            $this->removeTag($clean);
        } else {
            $this->addTag($clean);
        }
    }

    public function performSearch()
    {
        $term = trim($this->searchInput);
        if (! empty($term)) {
            // Support comma or space separated tokens (e.g. "scenery, -sketch, rating:safe, score:>=10")
            $tokens = preg_split('/[\s,]+/', $term, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($tokens as $token) {
                $rawToken = trim($token);
                if (str_starts_with(strtolower($rawToken), 'rating:')) {
                    $val = strtolower(substr($rawToken, 7));
                    if (in_array($val, ['safe', 'nsfw', 'all'])) {
                        $this->ratingFilter = $val;
                    }

                    continue;
                }
                if (str_starts_with(strtolower($rawToken), 'score:>=') || str_starts_with(strtolower($rawToken), 'likes:>=')) {
                    $val = (int) preg_replace('/[^0-9]/', '', $rawToken);
                    $this->minScore = $val;

                    continue;
                }

                $isNegative = str_starts_with($rawToken, '-');
                $clean = strtolower(trim(str_replace(['#', '-'], '', $rawToken)));
                $clean = str_replace(' ', '_', $clean);
                $clean = $this->resolveTagAlias($clean);

                if (! empty($clean)) {
                    if ($isNegative) {
                        if (! in_array($clean, $this->excludedTags)) {
                            $this->excludedTags[] = $clean;
                        }
                    } else {
                        $tagExists = Tag::where('name', $clean)->exists();
                        $isAliased = isset($this->tagAliases[strtolower(trim(str_replace(['#', '-'], '', $rawToken)))]);
                        if ($tagExists || $isAliased || str_starts_with($rawToken, '#') || count($tokens) > 1) {
                            if (! in_array($clean, $this->selectedTags)) {
                                $this->selectedTags[] = $clean;
                            }
                        } else {
                            $partialTag = Tag::where('name', 'like', "%{$clean}%")->first();
                            if ($partialTag) {
                                if (! in_array($partialTag->name, $this->selectedTags)) {
                                    $this->selectedTags[] = $partialTag->name;
                                }
                            } else {
                                if (! in_array($clean, $this->selectedTags)) {
                                    $this->selectedTags[] = $clean;
                                }
                            }
                        }
                    }
                }
            }
            $this->searchInput = '';
        }
        $this->resetPage();
    }

    public function toggleGridLayout()
    {
        $this->gridLayout = $this->gridLayout === 'masonry' ? 'square' : 'masonry';
    }

    public function toggleLike(int $postId)
    {
        if (! Auth::check()) {
            $this->dispatch('notify', 'Please log in to like posts');

            return;
        }

        $user = Auth::user();
        $post = Post::findOrFail($postId);
        $existing = Like::where('user_id', $user->id)->where('post_id', $postId)->first();

        if ($existing) {
            $existing->delete();
            $post->decrement('likes_count');
            $this->dispatch('notify', 'Removed like');
        } else {
            Like::create(['user_id' => $user->id, 'post_id' => $postId]);
            $post->increment('likes_count');
            Notifier::like($post, $user);
            $this->dispatch('notify', 'Post liked!');
        }
    }

    public function toggleFollowTag(int $tagId)
    {
        if (! Auth::check()) {
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

    public function setMediaTypeFilter(string $type): void
    {
        $this->mediaTypeFilter = in_array($type, ['all', 'image', 'gif', 'video', 'albums'], true) ? $type : 'all';
        $this->resetPage();
    }

    public function setVideoDurationFilter(string $duration): void
    {
        $this->videoDurationFilter = in_array($duration, ['all', 'short', 'medium', 'long'], true) ? $duration : 'all';
        $this->resetPage();
    }

    public function render()
    {
        $user = Auth::user();
        $query = Post::with(['user', 'media', 'tags'])->withCount('likes');

        if ($user) {
            $query->whereNotIn('user_id', $user->blockedUsers()->select('users.id'))
                ->whereNotIn('user_id', $user->blockedByUsers()->select('users.id'));

            // Exclude disliked/hidden posts
            $dislikedPostIds = DB::table('user_disliked_posts')->where('user_id', $user->id)->pluck('post_id');
            if ($dislikedPostIds->isNotEmpty()) {
                $query->whereNotIn('id', $dislikedPostIds);
            }

            // Exclude muted tags
            $mutedTagIds = $user->mutedTags()->pluck('tags.id');
            if ($mutedTagIds->isNotEmpty()) {
                $query->whereDoesntHave('tags', function ($q) use ($mutedTagIds) {
                    $q->whereIn('tags.id', $mutedTagIds);
                });
            }
        }

        // Active Tab Logic
        if ($this->activeTab === 'following' && $user) {
            $followingUserIds = $user->following()->pluck('users.id')->all();
            $followedArtistIds = $user->followingArtists()->pluck('artists.id')->all();
            $followedTagIds = $user->followedTags()->pluck('tags.id')->all();
            $followedCollectionPostIds = DB::table('collection_items')
                ->whereIn('collection_id', $user->followingCollections()->pluck('collections.id'))
                ->pluck('post_id')
                ->unique()
                ->all();

            $query->where('user_id', '!=', $user->id);

            if ($this->followingSubFilter === 'users') {
                $query->whereIn('user_id', $followingUserIds ?: [0]);
            } elseif ($this->followingSubFilter === 'artists') {
                $query->whereIn('artist_id', $followedArtistIds ?: [0]);
            } elseif ($this->followingSubFilter === 'tags') {
                $query->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $followedTagIds ?: [0]));
            } elseif ($this->followingSubFilter === 'collections') {
                $query->whereIn('id', $followedCollectionPostIds ?: [0]);
            } else {
                // All: Creators OR Artists OR Tags OR Collections
                $query->where(function ($q) use ($followingUserIds, $followedArtistIds, $followedTagIds, $followedCollectionPostIds) {
                    if ($followingUserIds !== []) {
                        $q->whereIn('user_id', $followingUserIds);
                    }
                    if ($followedArtistIds !== []) {
                        $q->orWhereIn('artist_id', $followedArtistIds);
                    }
                    if ($followedTagIds !== []) {
                        $q->orWhereHas('tags', fn ($tq) => $tq->whereIn('tags.id', $followedTagIds));
                    }
                    if ($followedCollectionPostIds !== []) {
                        $q->orWhereIn('id', $followedCollectionPostIds);
                    }
                });
            }

            // Following is chronological (newest) by default as requested!
            if ($this->followingSort === 'popular' || $this->followingSort === 'most_liked') {
                $query->orderByDesc('likes_count');
            } else {
                $query->latest();
            }
        } elseif ($this->activeTab === 'for-you') {
            $preferredTagIds = collect();

            if ($user) {
                $likedPostIds = $user->likedPosts()->select('posts.id');
                $likedTagIds = Tag::query()
                    ->where('type', '!=', 'artist')
                    ->whereHas('posts', fn ($posts) => $posts->whereIn('posts.id', $likedPostIds))
                    ->pluck('tags.id');
                $followedTagIds = $user->followedTags()
                    ->where('type', '!=', 'artist')
                    ->pluck('tags.id');

                $preferredTagIds = $likedTagIds->merge($followedTagIds)->unique()->values();
                $query->where('user_id', '!=', $user->id);
            }

            if ($preferredTagIds->isNotEmpty()) {
                $query->whereHas('tags', fn ($tags) => $tags->whereIn('tags.id', $preferredTagIds))
                    ->withCount(['tags as recommendation_score' => fn ($tags) => $tags->whereIn('tags.id', $preferredTagIds)])
                    ->orderByDesc('recommendation_score')
                    ->orderByDesc('likes_count');
            } else {
                // Give new users a useful discovery feed while they build preferences.
                $query->orderByDesc('likes_count');
            }
        } else {
            // Latest tab: pure chronological site-wide
            $query->latest();
        }

        // Multi-Tag Filter (Booru standard: artwork must have ALL selected tags)
        if (! empty($this->selectedTags)) {
            foreach ($this->selectedTags as $tag) {
                // Check if tag has implied tags
                $impliedTags = $this->tagImplications[$tag] ?? [];
                $allReqTags = array_merge([$tag], $impliedTags);
                foreach ($allReqTags as $reqTag) {
                    $query->whereHas('tags', function ($q) use ($reqTag) {
                        $q->where('name', $reqTag);
                    });
                }
            }
        }

        // Negative Tag Filter (Booru standard: artwork must NOT have excluded tags)
        if (! empty($this->excludedTags)) {
            foreach ($this->excludedTags as $exTag) {
                if ($exTag === 'nsfw') {
                    $query->where('is_nsfw', false);
                } else {
                    $query->whereDoesntHave('tags', function ($q) use ($exTag) {
                        $q->where('name', $exTag);
                    });
                }
            }
        }

        // Rating Filter (rating:safe / rating:nsfw)
        if ($this->ratingFilter === 'safe') {
            $query->where('is_nsfw', false);
        } elseif ($this->ratingFilter === 'nsfw') {
            $query->where('is_nsfw', true);
        }

        // Media Type Filter (all, image, gif, video, albums)
        if ($this->mediaTypeFilter === 'image') {
            $query->where('media_type', 'image')->where('media_count', 1);
        } elseif ($this->mediaTypeFilter === 'gif') {
            $query->where('media_type', 'gif');
        } elseif ($this->mediaTypeFilter === 'video') {
            $query->where('media_type', 'video');
        } elseif ($this->mediaTypeFilter === 'albums') {
            $query->where('media_count', '>', 1);
        }

        // Video Duration Filter (all, short <30s, medium 30s-180s, long >180s)
        if ($this->videoDurationFilter !== 'all') {
            $query->whereHas('media', function ($mq) {
                $mq->where('media_type', 'video')->whereNotNull('duration');
                if ($this->videoDurationFilter === 'short') {
                    $mq->where('duration', '<', 30);
                } elseif ($this->videoDurationFilter === 'medium') {
                    $mq->whereBetween('duration', [30, 180]);
                } elseif ($this->videoDurationFilter === 'long') {
                    $mq->where('duration', '>', 180);
                }
            });
        }

        // Score / Likes Filter (score:>=10)
        if ($this->minScore !== null && $this->minScore > 0) {
            $query->where('likes_count', '>=', $this->minScore);
        }

        // Search Filter (Free text / title / description / user)
        if (! empty($this->search)) {
            $term = trim($this->search);
            $query->where(function ($q) use ($term) {
                $q->where('title', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%")
                    ->orWhereHas('user', function ($uq) use ($term) {
                        $uq->where('username', 'like', "%{$term}%")
                            ->orWhere('name', 'like', "%{$term}%");
                    });
            });
        }

        // Sort & Time Range
        if ($this->sortMode === 'popular') {
            if ($this->timeRange === 'today') {
                $query->where('created_at', '>=', now()->subDay());
            } elseif ($this->timeRange === 'week') {
                $query->where('created_at', '>=', now()->subWeek());
            }
            $query->orderByDesc('likes_count');
        } elseif ($this->sortMode === 'oldest') {
            $query->oldest();
        } elseif ($this->sortMode === 'most_viewed') {
            $query->orderByDesc('views_count');
        } elseif ($this->sortMode === 'random') {
            $query->inRandomOrder();
        } elseif ($this->sortMode === 'latest') {
            $query->latest();
        }

        $posts = $query->paginate(24);

        // Tag autocomplete suggestions when user is typing
        $tagSuggestions = collect();
        if (strlen(trim($this->searchInput)) >= 1) {
            $cleanInput = strtolower(trim(str_replace('#', '', $this->searchInput)));
            $cleanInput = str_replace(' ', '_', $cleanInput);
            $tagSuggestions = Tag::where('name', 'like', "%{$cleanInput}%")
                ->whereNotIn('name', $this->selectedTags)
                ->orderBy('posts_count', 'desc')
                ->take(8)
                ->get();
        }

        $selectedTagModels = Tag::whereIn('name', $this->selectedTags)->get()->keyBy('name');
        $popularTags = Tag::orderBy('posts_count', 'desc')->take(12)->get();
        $singleActiveTag = count($this->selectedTags) === 1 ? ($selectedTagModels[$this->selectedTags[0]] ?? null) : null;

        return view('components.⚡gallery-feed', [
            'posts' => $posts,
            'popularTags' => $popularTags,
            'selectedTagModels' => $selectedTagModels,
            'singleActiveTag' => $singleActiveTag,
            'tagSuggestions' => $tagSuggestions,
            'currentUser' => $user,
        ]);
    }
};
?>

<div class="max-w-[2400px] mx-auto px-3 sm:px-6 lg:px-8 py-4 sm:py-6 space-y-5 sm:space-y-6 w-full min-w-0">
    <!-- Top Search & Sort Header -->
    <div class="space-y-4 w-full min-w-0">
        <!-- Search & Control Bar (Spec: Sits above all tabs and applies within the current tab) -->
        <div class="flex flex-col md:flex-row items-stretch md:items-center gap-3 w-full min-w-0">
            <!-- Search Container with Multi-tag Chips & Autocomplete -->
            <div class="flex items-center gap-2 flex-1 min-w-0" x-data="{ suggestionsOpen: true }">
                <div class="relative flex-1 min-w-0">
                    <div class="flex flex-wrap items-center gap-1.5 min-h-[50px] p-2 pl-3.5 pr-2.5 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] focus-within:border-[var(--accent-primary)] focus-within:ring-2 focus-within:ring-[var(--accent-primary)]/20 transition cursor-text shadow-sm"
                         @click="$refs.searchInputRef?.focus()">
                        
                        <!-- Search Icon -->
                        <div class="flex items-center text-[var(--text-dim)] shrink-0 mr-1 pointer-events-none">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>

                        <!-- Render Selected Tag Chips -->
                        @foreach($selectedTags as $tag)
                            @php
                                $tagModel = $selectedTagModels[$tag] ?? null;
                                $chipBadge = $tagModel ? $tagModel->getTypeBadgeClasses() : 'text-sky-600 dark:text-sky-400 bg-sky-500/10 border-sky-500/25 hover:bg-sky-500/20';
                                $dotColor = $tagModel ? $tagModel->getDotColorClass() : 'bg-sky-500 dark:bg-sky-400';
                            @endphp
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-xs font-semibold border {{ $chipBadge }} transition shrink-0 select-none shadow-sm">
                                <span class="w-1.5 h-1.5 rounded-full {{ $dotColor }}"></span>
                                <span>#{{ $tag }}</span>
                                <button type="button" 
                                        wire:click.stop="removeTag('{{ $tag }}')" 
                                        class="hover:opacity-75 focus:outline-none ml-0.5 text-xs font-bold leading-none cursor-pointer"
                                        title="Remove tag #{{ $tag }}">
                                    &times;
                                </button>
                            </span>
                        @endforeach

                        <!-- Render Excluded Negative Tag Chips (-tag) -->
                        @foreach($excludedTags as $exTag)
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-xs font-semibold border text-rose-500 dark:text-rose-400 bg-rose-500/10 border-rose-500/25 hover:bg-rose-500/20 transition shrink-0 select-none shadow-sm">
                                <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                                <span>-{{ $exTag }}</span>
                                <button type="button" 
                                        wire:click.stop="removeExcludedTag('{{ $exTag }}')" 
                                        class="hover:opacity-75 focus:outline-none ml-0.5 text-xs font-bold leading-none cursor-pointer"
                                        title="Remove negative filter -{{ $exTag }}">
                                    &times;
                                </button>
                            </span>
                        @endforeach

                        <!-- Render Rating & Score Operator Chips -->
                        @if($ratingFilter !== 'all')
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-xs font-semibold border text-amber-500 dark:text-amber-400 bg-amber-500/10 border-amber-500/25 transition shrink-0 select-none shadow-sm">
                                <span>rating:{{ $ratingFilter }}</span>
                                <button type="button" wire:click.stop="$set('ratingFilter', 'all')" class="hover:opacity-75 focus:outline-none ml-0.5 text-xs font-bold leading-none cursor-pointer">&times;</button>
                            </span>
                        @endif

                        @if($minScore !== null)
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-xs font-semibold border text-emerald-500 dark:text-emerald-400 bg-emerald-500/10 border-emerald-500/25 transition shrink-0 select-none shadow-sm">
                                <span>score:>={{ $minScore }}</span>
                                <button type="button" wire:click.stop="$set('minScore', null)" class="hover:opacity-75 focus:outline-none ml-0.5 text-xs font-bold leading-none cursor-pointer">&times;</button>
                            </span>
                        @endif

                        <!-- Render Free-text Search Term Chip if applied -->
                        @if(!empty($search))
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-xs font-semibold border bg-white/10 text-[var(--text-main)] border-white/20 transition shrink-0 select-none shadow-sm">
                                <svg class="w-3.5 h-3.5 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14"></path>
                                </svg>
                                <span>"{{ $search }}"</span>
                                <button type="button" 
                                        wire:click.stop="$set('search', '')" 
                                        class="hover:opacity-75 focus:outline-none ml-0.5 text-xs font-bold leading-none cursor-pointer"
                                        title="Clear search keyword">
                                    &times;
                                </button>
                            </span>
                        @endif

                        <!-- Text Input inside the chip container -->
                        <input x-ref="searchInputRef"
                               type="text" 
                               wire:model.live.debounce.150ms="searchInput" 
                               wire:keydown.enter.prevent="performSearch"
                               @focus="suggestionsOpen = true"
                               placeholder="{{ count($selectedTags) > 0 ? 'Add another tag or keyword...' : 'Search tags (e.g. scenery, cyberpunk_city) or keywords...' }}"
                               class="flex-1 min-w-[130px] bg-transparent outline-none text-sm placeholder-[var(--text-dim)] text-[var(--text-main)] py-1 px-1">

                        <!-- Clear All Button -->
                        @if(!empty($searchInput) || count($selectedTags) > 0 || !empty($search))
                            <button type="button" 
                                    wire:click="clearAllTags" 
                                    class="p-1 rounded-lg text-[var(--text-dim)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)] transition ml-auto shrink-0 cursor-pointer"
                                    title="Clear all search tags">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        @endif
                    </div>

                    <!-- Autocomplete Suggestions Dropdown -->
                    @if(strlen(trim($searchInput)) >= 1)
                        <div x-show="suggestionsOpen" 
                             @click.outside="suggestionsOpen = false"
                             class="absolute left-0 right-0 top-full mt-2 p-2 rounded-2xl glass-panel shadow-2xl border border-[var(--border-medium)] z-40 space-y-1 backdrop-blur-xl">
                            <div class="px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-[var(--text-dim)] flex items-center justify-between">
                                <span>Tag Suggestions</span>
                                <span class="text-[10px] font-normal opacity-75">Click to add chip</span>
                            </div>

                            @forelse($tagSuggestions as $suggestion)
                                <button type="button" 
                                        wire:click="addTag('{{ $suggestion->name }}')" 
                                        class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs hover:bg-[var(--bg-surface-elevated)] transition group text-left cursor-pointer">
                                    <div class="flex items-center gap-2">
                                        <span class="w-2 h-2 rounded-full {{ $suggestion->getDotColorClass() }}"></span>
                                        <span class="font-bold text-[var(--text-main)] group-hover:text-[var(--accent-primary)]">#{{ $suggestion->name }}</span>
                                        <span class="px-1.5 py-0.5 text-[10px] rounded uppercase font-semibold opacity-85 border {{ $suggestion->getTypeBadgeClasses() }}">
                                            {{ $suggestion->type }}
                                        </span>
                                    </div>
                                    <span class="text-[var(--text-dim)] text-[11px] font-medium">
                                        {{ number_format($suggestion->posts_count) }} {{ Str::plural('post', $suggestion->posts_count) }}
                                    </span>
                                </button>
                            @empty
                                @if(strlen(trim($searchInput)) >= 2)
                                    <div class="px-3 py-2 text-xs text-[var(--text-dim)]">
                                        No existing tags matching "<span class="text-[var(--text-main)]">{{ $searchInput }}</span>"
                                    </div>
                                @endif
                            @endforelse

                            @php
                                $cleanInput = strtolower(trim(str_replace('#', '', $searchInput)));
                                $cleanInput = str_replace(' ', '_', $cleanInput);
                            @endphp
                            @if(!empty($cleanInput) && !$tagSuggestions->contains('name', $cleanInput))
                                <button type="button" 
                                        wire:click="addTag('{{ $cleanInput }}')" 
                                        class="w-full flex items-center gap-2 px-3 py-2 rounded-xl text-xs hover:bg-[var(--bg-surface-elevated)] transition text-left border-t border-[var(--border-subtle)] mt-1 pt-2 cursor-pointer">
                                    <svg class="w-3.5 h-3.5 accent-text" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                                    </svg>
                                    <span class="text-[var(--text-muted)]">Add chip for:</span>
                                    <span class="font-bold accent-text">#{{ $cleanInput }}</span>
                                </button>
                            @endif
                        </div>
                    @endif
                </div>

                <!-- Explicit Search Button -->
                <button type="button" 
                        wire:click="performSearch"
                        class="accent-bg hover:opacity-90 active:scale-95 text-white px-5 py-3 rounded-2xl font-bold text-sm shadow-md flex items-center justify-center gap-2 shrink-0 transition cursor-pointer"
                        title="Search with selected tags and keywords">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    <span>Search</span>
                </button>
            </div>

            <!-- Right Controls: Media Filter Dropdown, Sort Dropdown & Grid Layout Toggle -->
            <div class="flex items-center gap-2 shrink-0 justify-end flex-wrap sm:flex-nowrap">
                <!-- Media Filter Dropdown (Compact & Mobile/Tablet friendly) -->
                <div class="relative" x-data="{ mediaOpen: false }">
                    <button @click="mediaOpen = !mediaOpen" 
                            class="flex items-center gap-2 px-3.5 py-3 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] hover:bg-[var(--bg-surface-elevated)] transition text-xs sm:text-sm font-semibold text-[var(--text-main)]"
                            title="Filter by Media Format and Video Length">
                        <span class="text-sm">
                            @if($mediaTypeFilter === 'image') 🖼️ @elseif($mediaTypeFilter === 'gif') 🎞️ @elseif($mediaTypeFilter === 'video') 🎥 @elseif($mediaTypeFilter === 'albums') 📚 @else 🎬 @endif
                        </span>
                        <span>
                            @if($mediaTypeFilter === 'image') Images @elseif($mediaTypeFilter === 'gif') GIFs @elseif($mediaTypeFilter === 'video') Videos @elseif($mediaTypeFilter === 'albums') Albums @else Media @endif
                        </span>
                        @if($videoDurationFilter !== 'all')
                            <span class="px-1.5 py-0.5 rounded-md text-[10px] bg-sky-500/20 text-sky-400 font-bold border border-sky-500/30">
                                {{ ucfirst($videoDurationFilter) }}
                            </span>
                        @endif
                        <svg class="w-3.5 h-3.5 opacity-60 ml-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>

                    <div x-show="mediaOpen" 
                         @click.outside="mediaOpen = false"
                         x-cloak
                         class="absolute right-0 mt-2 w-56 p-2 rounded-2xl glass-panel shadow-2xl border border-[var(--border-medium)] z-40 space-y-1">
                        <div class="px-3 py-1 text-[10px] font-bold uppercase tracking-wider text-[var(--text-dim)]">Media Format</div>
                        <button wire:click="setMediaTypeFilter('all'); mediaOpen = false;" 
                                class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-semibold {{ $mediaTypeFilter === 'all' ? 'accent-bg text-white' : 'hover:bg-[var(--bg-surface-elevated)]' }}">
                            <span>🎬 All Media Formats</span>
                        </button>
                        <button wire:click="setMediaTypeFilter('image'); mediaOpen = false;" 
                                class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-semibold {{ $mediaTypeFilter === 'image' ? 'accent-bg text-white' : 'hover:bg-[var(--bg-surface-elevated)]' }}">
                            <span>🖼️ Images Only</span>
                        </button>
                        <button wire:click="setMediaTypeFilter('gif'); mediaOpen = false;" 
                                class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-semibold {{ $mediaTypeFilter === 'gif' ? 'accent-bg text-white' : 'hover:bg-[var(--bg-surface-elevated)]' }}">
                            <span>🎞️ Animated GIFs</span>
                        </button>
                        <button wire:click="setMediaTypeFilter('video'); mediaOpen = false;" 
                                class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-semibold {{ $mediaTypeFilter === 'video' ? 'accent-bg text-white' : 'hover:bg-[var(--bg-surface-elevated)]' }}">
                            <span>🎥 Video Clips</span>
                        </button>
                        <button wire:click="setMediaTypeFilter('albums'); mediaOpen = false;" 
                                class="w-full flex items-center justify-between px-3 py-2 rounded-xl text-xs font-semibold {{ $mediaTypeFilter === 'albums' ? 'accent-bg text-white' : 'hover:bg-[var(--bg-surface-elevated)]' }}">
                            <span>📚 Albums / Multi-Image</span>
                        </button>

                        <div class="px-3 py-1 text-[10px] font-bold uppercase tracking-wider text-[var(--text-dim)] pt-2 border-t border-[var(--border-subtle)]">Video Length</div>
                        <button wire:click="setVideoDurationFilter('all'); mediaOpen = false;" 
                                class="w-full text-left px-3 py-1.5 rounded-xl text-xs {{ $videoDurationFilter === 'all' ? 'bg-white/10 text-[var(--text-main)] font-bold' : 'hover:bg-[var(--bg-surface-elevated)]' }}">
                            Any Duration
                        </button>
                        <button wire:click="setVideoDurationFilter('short'); mediaOpen = false;" 
                                class="w-full text-left px-3 py-1.5 rounded-xl text-xs {{ $videoDurationFilter === 'short' ? 'bg-sky-500/20 text-sky-400 font-bold' : 'hover:bg-[var(--bg-surface-elevated)]' }}">
                            ⚡ Shorts (&lt; 30 sec)
                        </button>
                        <button wire:click="setVideoDurationFilter('medium'); mediaOpen = false;" 
                                class="w-full text-left px-3 py-1.5 rounded-xl text-xs {{ $videoDurationFilter === 'medium' ? 'bg-indigo-500/20 text-indigo-400 font-bold' : 'hover:bg-[var(--bg-surface-elevated)]' }}">
                            ⏱️ Medium (30s – 3 min)
                        </button>
                        <button wire:click="setVideoDurationFilter('long'); mediaOpen = false;" 
                                class="w-full text-left px-3 py-1.5 rounded-xl text-xs {{ $videoDurationFilter === 'long' ? 'bg-purple-500/20 text-purple-400 font-bold' : 'hover:bg-[var(--bg-surface-elevated)]' }}">
                            🎬 Extended (&gt; 3 min)
                        </button>
                    </div>
                </div>
                <!-- Sort Dropdown -->
                <div class="relative" x-data="{ sortOpen: false }">
                    <button @click="sortOpen = !sortOpen" 
                            class="flex items-center gap-2 px-4 py-3 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] hover:bg-[var(--bg-surface-elevated)] transition text-sm font-semibold">
                        <svg class="w-4 h-4 accent-text" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h6m4 0l4-4m0 0l4 4m-4-4v12"></path>
                        </svg>
                        <span>
                            @if($sortMode === 'popular')
                                Popular ({{ ucfirst($timeRange) }})
                            @elseif($sortMode === 'oldest')
                                Oldest First
                            @elseif($sortMode === 'most_viewed')
                                Most Viewed
                            @elseif($sortMode === 'random')
                                Random Shuffle
                            @else
                                Newest (Latest)
                            @endif
                        </span>
                        <svg class="w-3.5 h-3.5 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </button>

                    <div x-show="sortOpen" 
                         @click.outside="sortOpen = false"
                         x-cloak
                         class="absolute right-0 mt-2 w-52 p-2 rounded-2xl glass-panel shadow-2xl border border-[var(--border-medium)] z-30 space-y-1">
                        <button wire:click="setSort('latest'); sortOpen = false;" 
                                class="w-full text-left px-3 py-2 rounded-xl text-xs font-semibold {{ $sortMode === 'latest' ? 'accent-bg text-white' : 'hover:bg-[var(--bg-surface-elevated)]' }}">
                            Strict Chronological (Latest)
                        </button>
                        <button wire:click="setSort('oldest'); sortOpen = false;" 
                                class="w-full text-left px-3 py-2 rounded-xl text-xs font-semibold {{ $sortMode === 'oldest' ? 'accent-bg text-white' : 'hover:bg-[var(--bg-surface-elevated)]' }}">
                            Oldest First
                        </button>
                        <button wire:click="setSort('most_viewed'); sortOpen = false;" 
                                class="w-full text-left px-3 py-2 rounded-xl text-xs font-semibold {{ $sortMode === 'most_viewed' ? 'accent-bg text-white' : 'hover:bg-[var(--bg-surface-elevated)]' }}">
                            Most Viewed
                        </button>
                        <button wire:click="setSort('random'); sortOpen = false;" 
                                class="w-full text-left px-3 py-2 rounded-xl text-xs font-semibold {{ $sortMode === 'random' ? 'accent-bg text-white' : 'hover:bg-[var(--bg-surface-elevated)]' }}">
                            🎲 Random Shuffle
                        </button>
                        <div class="px-3 py-1 text-[10px] font-bold uppercase tracking-wider text-[var(--text-dim)] pt-1 border-t border-[var(--border-subtle)]">Popular Range</div>
                        <button wire:click="setSort('popular', 'today'); sortOpen = false;" 
                                class="w-full text-left px-3 py-1.5 rounded-xl text-xs {{ $sortMode === 'popular' && $timeRange === 'today' ? 'accent-bg text-white font-bold' : 'hover:bg-[var(--bg-surface-elevated)]' }}">
                            Popular Today
                        </button>
                        <button wire:click="setSort('popular', 'week'); sortOpen = false;" 
                                class="w-full text-left px-3 py-1.5 rounded-xl text-xs {{ $sortMode === 'popular' && $timeRange === 'week' ? 'accent-bg text-white font-bold' : 'hover:bg-[var(--bg-surface-elevated)]' }}">
                            Popular This Week
                        </button>
                        <button wire:click="setSort('popular', 'all'); sortOpen = false;" 
                                class="w-full text-left px-3 py-1.5 rounded-xl text-xs {{ $sortMode === 'popular' && $timeRange === 'all' ? 'accent-bg text-white font-bold' : 'hover:bg-[var(--bg-surface-elevated)]' }}">
                            Popular All Time
                        </button>
                    </div>
                </div>

                <!-- Switchable Grid Layout Toggle (Spec: switch between dynamic Masonry and uniform square grid) -->
                <button wire:click="toggleGridLayout"
                        class="p-3 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] hover:bg-[var(--bg-surface-elevated)] transition"
                        title="{{ $gridLayout === 'masonry' ? 'Switch to Uniform Square Grid' : 'Switch to Dynamic Masonry Grid' }}">
                    @if($gridLayout === 'masonry')
                        <!-- Masonry Icon -->
                        <svg class="w-5 h-5 accent-text" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h4a1 1 0 011 1v7a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM14 5a1 1 0 011-1h4a1 1 0 011 1v3a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 16a1 1 0 011-1h4a1 1 0 011 1v3a1 1 0 01-1 1H5a1 1 0 01-1-1v-3zM14 12a1 1 0 011-1h4a1 1 0 011 1v7a1 1 0 01-1 1h-4a1 1 0 01-1-1v-7z"></path>
                        </svg>
                    @else
                        <!-- Square Grid Icon -->
                        <svg class="w-5 h-5 accent-text" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
                        </svg>
                    @endif
                </button>
            </div>
        </div>

        <!-- Gallery Tabs: For You (Discovery), Following (User-controlled), Latest (Chronological) -->
        <div class="flex flex-col sm:flex-row items-center justify-center border-b border-[var(--border-subtle)] gap-2 sm:gap-4 pb-1">
            <div class="flex items-center justify-center gap-1 sm:gap-2">
                <!-- For You Tab -->
                <button wire:click="setTab('for-you')" 
                        class="relative px-4 py-3 font-bold text-sm transition-colors {{ $activeTab === 'for-you' ? 'text-[var(--text-main)] font-black' : 'text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">
                    <span>For You</span>
                    @if($activeTab === 'for-you')
                        <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div>
                    @endif
                </button>

                <!-- Following Tab -->
                @if(Auth::check())
                    <button wire:click="setTab('following')" 
                            class="relative px-4 py-3 font-bold text-sm transition-colors {{ $activeTab === 'following' ? 'text-[var(--text-main)] font-black' : 'text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">
                        <span>Following</span>
                        @if($activeTab === 'following')
                            <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div>
                        @endif
                    </button>
                @endif

                <!-- Latest Tab -->
                <button wire:click="setTab('latest')" 
                        class="relative px-4 py-3 font-bold text-sm transition-colors {{ $activeTab === 'latest' ? 'text-[var(--text-main)] font-black' : 'text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">
                    <span>Latest</span>
                    @if($activeTab === 'latest')
                        <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div>
                    @endif
                </button>
            </div>

            <!-- Following Sub-Filters (All | Creators | Artists | Tags | Collections) -->
            @if($activeTab === 'following' && Auth::check())
                <div class="flex items-center gap-1.5 pb-2 sm:pb-0 text-xs overflow-x-auto">
                    <span class="text-[11px] font-bold text-[var(--text-dim)] uppercase mr-1">Filter:</span>
                    <button wire:click="setFollowingSubFilter('all')" class="px-2.5 py-1 rounded-xl font-bold transition {{ $followingSubFilter === 'all' ? 'accent-bg text-white' : 'bg-[var(--bg-surface)] text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">All</button>
                    <button wire:click="setFollowingSubFilter('users')" class="px-2.5 py-1 rounded-xl font-bold transition {{ $followingSubFilter === 'users' ? 'accent-bg text-white' : 'bg-[var(--bg-surface)] text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">Creators</button>
                    <button wire:click="setFollowingSubFilter('artists')" class="px-2.5 py-1 rounded-xl font-bold transition {{ $followingSubFilter === 'artists' ? 'accent-bg text-white' : 'bg-[var(--bg-surface)] text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">Artists</button>
                    <button wire:click="setFollowingSubFilter('tags')" class="px-2.5 py-1 rounded-xl font-bold transition {{ $followingSubFilter === 'tags' ? 'accent-bg text-white' : 'bg-[var(--bg-surface)] text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">Tags</button>
                    <button wire:click="setFollowingSubFilter('collections')" class="px-2.5 py-1 rounded-xl font-bold transition {{ $followingSubFilter === 'collections' ? 'accent-bg text-white' : 'bg-[var(--bg-surface)] text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">Collections</button>
                </div>
            @endif
        </div>

            @if($activeTab === 'following')
                <p class="mt-2 text-xs text-[var(--text-dim)]">Latest work from creators and tags you follow.</p>
            @endif

            <!-- Active Tag Indicator if filtered -->
            @if(count($selectedTags) > 0 || !empty($search))
                <div class="flex items-center gap-2 flex-wrap">
                    @if(count($selectedTags) === 1 && $singleActiveTag)
                        @if(Auth::check())
                            <button wire:click="toggleFollowTag({{ $singleActiveTag->id }})" 
                                    class="px-3 py-1 rounded-full text-xs font-bold border transition {{ Auth::user()->isFollowingTag($singleActiveTag) ? 'accent-bg text-white border-transparent' : 'border-[var(--border-medium)] hover:bg-[var(--bg-surface-elevated)]' }}">
                                {{ Auth::user()->isFollowingTag($singleActiveTag) ? 'Following Tag' : 'Follow Tag' }}
                            </button>
                        @endif
                    @endif

                    <button wire:click="clearAllTags" 
                            class="px-2.5 py-1 rounded-xl text-xs font-medium text-[var(--text-dim)] hover:text-rose-400 hover:bg-rose-500/10 border border-transparent hover:border-rose-500/20 transition cursor-pointer">
                        Clear all ({{ count($selectedTags) + (!empty($search) ? 1 : 0) }})
                    </button>
                </div>
            @endif

        <!-- Popular / Featured Tags Quick Scroll Strip -->
        <div class="flex items-center gap-2 overflow-x-auto py-1 scrollbar-none w-full min-w-0">
            <span class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider shrink-0 mr-1">Trending:</span>
            @foreach($popularTags as $tag)
                <button wire:click="filterByTag('{{ $tag->name }}')" 
                        class="px-2.5 py-1 rounded-xl text-xs font-medium border shrink-0 transition {{ in_array($tag->name, $selectedTags) ? 'accent-bg text-white border-transparent font-bold shadow-sm' : $tag->getTypeBadgeClasses() }}">
                    #{{ $tag->name }}
                    <span class="opacity-60 ml-0.5 text-[10px]">({{ $tag->posts_count }})</span>
                </button>
            @endforeach
        </div>
    </div>

    <!-- Gallery Feed Grid Display -->
    @if($posts->isEmpty())
        <div class="p-12 text-center rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-4">
            <div class="w-16 h-16 mx-auto rounded-3xl bg-neutral-800/50 flex items-center justify-center text-[var(--text-dim)]">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
            </div>
            <div>
                @if($activeTab === 'following')
                    <h3 class="font-bold text-lg">No posts from followed creators or tags yet</h3>
                    <p class="text-sm text-[var(--text-muted)] mt-1">Follow artists or tags to see their new work here.</p>
                @elseif($activeTab === 'for-you')
                    <h3 class="font-bold text-lg">No recommendations found</h3>
                    <p class="text-sm text-[var(--text-muted)] mt-1">Like artwork you enjoy to tune recommendations, or clear filters to explore more.</p>
                @else
                    <h3 class="font-bold text-lg">No artworks found</h3>
                    <p class="text-sm text-[var(--text-muted)] mt-1">Try clearing your filters or exploring another tab.</p>
                @endif
            </div>
            @if(!empty($search) || count($selectedTags) > 0)
                <button wire:click="clearAllTags" class="px-4 py-2 rounded-xl accent-bg text-white font-bold text-xs shadow hover:opacity-90 transition cursor-pointer">
                    Clear all filters
                </button>
            @endif
        </div>
    @else
        <!-- Grid Container: Dynamic Masonry vs Uniform Square -->
        <div class="{{ $gridLayout === 'masonry' ? 'masonry-grid' : 'fluid-square-grid' }}">
            @foreach($posts as $post)
                @php
                    $primaryMedia = $post->primaryMedia;
                    $mediaList = $post->media->map(fn($m) => [
                        'url' => $m->url,
                        'thumbnail_url' => $m->thumbnail_url ?? $m->url,
                        'type' => $post->media_type,
                        'width' => $m->width,
                        'height' => $m->height,
                    ])->toArray();
                    $isLiked = $post->isLikedBy($currentUser);
                @endphp

                <div class="{{ $gridLayout === 'masonry' ? 'masonry-item' : '' }} group relative rounded-3xl overflow-hidden bg-[var(--bg-surface)] border border-[var(--border-subtle)] hover:border-[var(--border-medium)] transition-all duration-300 hover:shadow-2xl flex flex-col"
                     x-data="{
                         hovered: false,
                         videoPlaying: false,
                         playVideo() {
                             const v = this.$refs.previewVideo;
                             if (v) { v.currentTime = 0; v.play().catch(() => {}); this.videoPlaying = true; }
                         },
                         pauseVideo() {
                             const v = this.$refs.previewVideo;
                             if (v) { v.pause(); this.videoPlaying = false; }
                         }
                     }"
                     @mouseenter="hovered = true; if('{{ $post->media_type }}' === 'video') playVideo();"
                     @mouseleave="hovered = false; if('{{ $post->media_type }}' === 'video') pauseVideo();">
                    
                    <!-- Media Area with Click to Lightbox or View -->
                    <div class="relative w-full overflow-hidden bg-neutral-900 {{ $gridLayout === 'square' ? 'aspect-square' : '' }}">
                        <!-- NSFW Blur Overlay -->
                        @if($post->is_nsfw && $blurNsfw)
                            <div x-data="{ unblurred: false }"
                                 x-show="!unblurred" 
                                 class="absolute inset-0 z-20 backdrop-blur-xl bg-black/60 flex flex-col items-center justify-center p-4 text-center cursor-pointer transition"
                                 @click.stop="unblurred = true">
                                <span class="px-2.5 py-1 rounded-full bg-rose-500/80 text-white font-extrabold text-[10px] uppercase tracking-wider mb-2">NSFW / Mature</span>
                                <span class="text-xs text-white/80 font-medium">Click to reveal</span>
                            </div>
                        @endif

                        @if($post->media_type === 'video')
                            <!-- Video Post (Spec: Autoplay muted on hover on desktop; static thumbnail with play icon on mobile) -->
                            <a href="{{ route('post.detail', $post->id) }}" class="block relative w-full {{ $gridLayout === 'square' ? 'h-full' : 'aspect-video' }} cursor-pointer">
                                <video x-ref="previewVideo"
                                       src="{{ $primaryMedia->url }}"
                                       poster="{{ $primaryMedia->thumbnail_url }}"
                                       muted
                                       loop
                                       playsinline
                                       class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-[1.02]"></video>
                                
                                <!-- Video Duration Badge & Play Icon -->
                                <div class="absolute bottom-3 left-3 flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-black/70 backdrop-blur-md text-white text-xs font-bold pointer-events-none">
                                    <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd"></path>
                                    </svg>
                                    <span>{{ $primaryMedia->duration ? gmdate('i:s', $primaryMedia->duration) : 'VIDEO' }}</span>
                                </div>
                            </a>
                        @else
                            <!-- Image Post (Clicking media goes to media view) -->
                            <a href="{{ route('post.detail', $post->id) }}" class="block w-full h-full cursor-pointer">
                                <img src="{{ $primaryMedia->url }}" 
                                     alt="{{ $post->title ?? 'Artwork' }}"
                                     class="w-full {{ $gridLayout === 'square' ? 'h-full object-cover' : 'h-auto block' }} transition-transform duration-500 group-hover:scale-[1.02]">
                            </a>
                        @endif

                        <!-- Multi-Image Badge (Spec: Multi-image posts show a count badge in the corner) -->
                        @if($post->media_count > 1)
                            <div class="absolute top-3 right-3 px-2 py-0.5 rounded-xl bg-black/75 backdrop-blur-md text-white text-xs font-extrabold flex items-center gap-1 shadow-lg ring-1 ring-white/10 pointer-events-none">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                                </svg>
                                <span>{{ $post->media_count }}</span>
                            </div>
                        @endif

                        <!-- Quick Hover Actions Overlay -->
                        <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-200 pointer-events-none flex flex-col justify-between p-3.5">
                            <!-- Top Direct Link to Post -->
                            <div class="flex justify-end pointer-events-auto">
                                <a href="{{ route('post.detail', $post->id) }}" 
                                   class="p-2 rounded-xl bg-white/20 hover:bg-white/30 text-white backdrop-blur-md transition"
                                   title="View full post details & comments">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                    </svg>
                                </a>
                            </div>

                            <!-- Bottom Quick Info on Hover -->
                            <div class="pointer-events-auto">
                                <a href="{{ route('post.detail', $post->id) }}" class="block">
                                    <h4 class="text-white font-bold text-sm truncate drop-shadow hover:underline">{{ $post->title ?? 'Untitled Artwork' }}</h4>
                                </a>
                                <div class="flex items-center gap-2 mt-1">
                                    <a href="{{ route('profile', $post->user->username) }}" class="flex items-center gap-1.5 text-xs text-white/80 hover:text-white truncate">
                                        <img src="{{ $post->user->avatar_url }}" class="w-4 h-4 rounded-full object-cover">
                                        <span class="truncate">{{ $post->user->name }}</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Post Footer Card -->
                    <div class="p-3.5 space-y-2.5 bg-[var(--bg-surface)]">
                        <div class="flex items-center justify-between gap-2">
                            <!-- Author info & Artist attribution -->
                            <div class="min-w-0 flex-1">
                                <a href="{{ route('profile', $post->user->username) }}" class="flex items-center gap-2 min-w-0 group/author">
                                    <img src="{{ $post->user->avatar_url }}" class="w-6 h-6 rounded-full object-cover ring-1 ring-[var(--border-subtle)] shrink-0">
                                    <div class="min-w-0">
                                        <div class="text-xs font-bold truncate group-hover/author:underline text-[var(--text-main)] flex items-center gap-1">
                                            <span>{{ $post->user->name }}</span>
                                            <span class="text-[10px] text-amber-400 font-normal">⚡{{ $post->user->reputation_score }}</span>
                                        </div>
                                    </div>
                                </a>
                                @if(!$post->is_original_creator && $post->artist_name)
                                    <div class="text-[10px] text-amber-400/90 font-semibold truncate pl-8 mt-0.5">
                                        🎨 Art by {{ $post->artist_name }}
                                    </div>
                                @endif
                            </div>

                            <!-- Not Interested (hides this post from the For You feed) -->
                            <button wire:click="notInterested({{ $post->id }})"
                                    title="Not interested — hide posts like this from your feed"
                                    class="flex items-center gap-1 px-2 py-1 rounded-full text-xs font-bold text-[var(--text-muted)] hover:text-amber-400 hover:bg-[var(--bg-surface-elevated)] transition">
                                <span class="text-sm leading-none">🚫</span>
                            </button>

                            <!-- Like Button (Spec: Likes only — no bookmarks, public, heart burst micro-animation) -->
                            <button wire:click="toggleLike({{ $post->id }})" 
                                    class="flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-bold transition {{ $isLiked ? 'text-rose-500 bg-rose-500/10' : 'text-[var(--text-muted)] hover:text-rose-400 hover:bg-[var(--bg-surface-elevated)]' }}">
                                <svg class="w-4 h-4 {{ $isLiked ? 'fill-current animate-heart-burst' : 'fill-none stroke-current' }}" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path>
                                </svg>
                                <span>{{ $post->likes_count }}</span>
                            </button>
                        </div>

                        <!-- Tag Pills (Danbooru Color Coding) -->
                        <div class="flex flex-wrap gap-1.5 pt-1">
                            @foreach($post->tags->take(4) as $tag)
                                <span class="inline-flex items-stretch overflow-hidden rounded-lg border {{ $tag->getTypeBadgeClasses() }}">
                                    <button wire:click="filterByTag('{{ $tag->name }}')"
                                            class="px-2 py-0.5 text-[11px] font-medium transition">
                                        #{{ $tag->name }}
                                    </button>
                                    <button wire:click="muteTag('{{ $tag->name }}')"
                                            title="Mute #{{ $tag->name }} — hide it from your feed"
                                            class="px-1.5 py-0.5 text-[10px] opacity-50 transition hover:bg-black/20 hover:opacity-100">🔇</button>
                                </span>
                            @endforeach
                            @if($post->tags->count() > 4)
                                <a href="{{ route('post.detail', $post->id) }}" class="px-1.5 py-0.5 text-[10px] font-bold text-[var(--text-dim)] hover:text-[var(--text-main)]">
                                    +{{ $post->tags->count() - 4 }}
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <!-- Pagination -->
        <div class="pt-6">
            {{ $posts->links() }}
        </div>
    @endif
</div>
