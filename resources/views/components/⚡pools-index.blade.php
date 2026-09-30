<?php

use Livewire\Component;
use App\Models\Pool;
use Illuminate\Support\Facades\Auth;

new class extends Component
{
    public string $activeTab = 'all'; // 'all', 'following', 'my_pools', 'popular'
    public string $search = '';
    public bool $createModalOpen = false;
    public string $newTitle = '';
    public string $newDescription = '';
    public string $newCoverUrl = '';
    public bool $newIsLocked = false;

    protected $queryString = [
        'activeTab' => ['except' => 'all'],
        'search' => ['except' => ''],
    ];

    public function setTab(string $tab)
    {
        if (in_array($tab, ['following', 'my_pools']) && !Auth::check()) {
            $this->dispatch('notify', 'Please log in to view personal pools');
            return;
        }
        $this->activeTab = $tab;
    }

    public function toggleFollow(int $poolId)
    {
        if (!Auth::check()) {
            $this->dispatch('notify', 'Please log in to follow pools');
            return;
        }

        $user = Auth::user();
        $pool = Pool::findOrFail($poolId);

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

    public function createPool()
    {
        if (!Auth::check()) return;

        $this->validate([
            'newTitle' => 'required|string|max:150',
            'newDescription' => 'nullable|string|max:1000',
            'newCoverUrl' => 'nullable|url',
        ]);

        $pool = Pool::create([
            'user_id' => Auth::id(),
            'title' => $this->newTitle,
            'description' => $this->newDescription,
            'cover_url' => $this->newCoverUrl ?: 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?auto=format&fit=crop&w=1200&q=80',
            'is_locked' => $this->newIsLocked,
            'chapters_count' => 0,
            'followers_count' => 1,
        ]);

        Auth::user()->followingPools()->attach($pool->id);

        $this->createModalOpen = false;
        $this->newTitle = '';
        $this->newDescription = '';
        $this->newCoverUrl = '';
        $this->dispatch('notify', "Series '{$pool->title}' created!");
    }

    public function render()
    {
        $query = Pool::with(['user', 'chapters'])->withCount('followers');

        if ($this->activeTab === 'following' && Auth::check()) {
            $query->whereHas('followers', function($q) {
                $q->where('users.id', Auth::id());
            });
        } elseif ($this->activeTab === 'my_pools' && Auth::check()) {
            $query->where('user_id', Auth::id());
        } elseif ($this->activeTab === 'popular') {
            $query->orderBy('followers_count', 'desc');
        }

        if (!empty($this->search)) {
            $s = trim($this->search);
            $query->where(function($q) use ($s) {
                $q->where('title', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%");
            });
        }

        if ($this->activeTab !== 'popular') {
            $query->latest();
        }

        $pools = $query->paginate(12);
        $user = Auth::user();

        return view('components.⚡pools-index', [
            'pools' => $pools,
            'currentUser' => $user,
        ]);
    }
};
?>

<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black tracking-tight text-[var(--text-main)]">Pools & Manga Series</h1>
            <p class="text-xs text-[var(--text-dim)]">Read episodic manga, comic series, and thematic art pools with American LTR pagination.</p>
        </div>

        @if(Auth::check())
            <button wire:click="$set('createModalOpen', true)" class="px-5 py-2.5 rounded-2xl accent-bg text-white font-bold text-xs shadow hover:opacity-90 transition shrink-0">
                + Create New Pool
            </button>
        @endif
    </div>

    <!-- Tab Bar & Live Search Row -->
    <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between border-b border-[var(--border-subtle)] gap-4 pb-1">
        <div class="flex items-center gap-1 overflow-x-auto scrollbar-none">
            <button wire:click="setTab('all')" 
                    class="relative py-3 px-4 font-bold text-sm transition shrink-0 {{ $activeTab === 'all' ? 'text-[var(--text-main)]' : 'text-[var(--text-dim)] hover:text-[var(--text-main)]' }}">
                <span>All Series</span>
                @if($activeTab === 'all') <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div> @endif
            </button>

            @if(Auth::check())
                <button wire:click="setTab('following')" 
                        class="relative py-3 px-4 font-bold text-sm transition shrink-0 {{ $activeTab === 'following' ? 'text-[var(--text-main)]' : 'text-[var(--text-dim)] hover:text-[var(--text-main)]' }}">
                    <span>Following</span>
                    @if($activeTab === 'following') <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div> @endif
                </button>

                <button wire:click="setTab('my_pools')" 
                        class="relative py-3 px-4 font-bold text-sm transition shrink-0 {{ $activeTab === 'my_pools' ? 'text-[var(--text-main)]' : 'text-[var(--text-dim)] hover:text-[var(--text-main)]' }}">
                    <span>My Pools</span>
                    @if($activeTab === 'my_pools') <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div> @endif
                </button>
            @endif

            <button wire:click="setTab('popular')" 
                    class="relative py-3 px-4 font-bold text-sm transition shrink-0 {{ $activeTab === 'popular' ? 'text-[var(--text-main)]' : 'text-[var(--text-dim)] hover:text-[var(--text-main)]' }}">
                <span>Popular</span>
                @if($activeTab === 'popular') <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div> @endif
            </button>
        </div>

        <div class="pb-2 sm:pb-0">
            <input type="text" 
                   wire:model.live.debounce.250ms="search" 
                   placeholder="Search series by title or description..." 
                   class="w-full sm:w-64 px-3.5 py-1.5 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-xs outline-none focus:border-[var(--accent-primary)]">
        </div>
    </div>

    <!-- Pools Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        @forelse($pools as $pool)
            @php
                $isFollowed = $pool->isFollowedBy($currentUser);
                $progress = $currentUser ? $pool->getUserProgress($currentUser) : null;
            @endphp
            <div class="p-5 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] hover:border-[var(--border-medium)] transition shadow-sm hover:shadow-xl flex flex-col justify-between space-y-4">
                <div class="flex gap-4">
                    <a href="{{ route('pools.detail', $pool->id) }}" class="shrink-0 w-28 h-36 rounded-2xl overflow-hidden bg-neutral-900 shadow">
                        <img src="{{ $pool->cover_url }}" class="w-full h-full object-cover">
                    </a>

                    <div class="flex-1 min-w-0 flex flex-col justify-between">
                        <div>
                            <div class="flex items-center gap-2">
                                <a href="{{ route('pools.detail', $pool->id) }}" class="font-extrabold text-lg text-[var(--text-main)] hover:underline truncate">
                                    {{ $pool->title }}
                                </a>
                                @if($pool->is_locked)
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-neutral-800 text-neutral-400">Locked</span>
                                @endif
                            </div>

                            <p class="text-xs text-[var(--text-muted)] line-clamp-3 mt-1.5 leading-relaxed">
                                {{ $pool->description }}
                            </p>
                        </div>

                        <div class="text-xs text-[var(--text-dim)] pt-2">
                            <span>Author: </span>
                            <a href="{{ route('profile', $pool->user->username) }}" class="font-bold text-[var(--text-main)] hover:underline">
                                {{ $pool->user->name }}
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Footer Stats & Actions -->
                <div class="flex items-center justify-between pt-3 border-t border-[var(--border-subtle)] text-xs">
                    <div class="flex items-center gap-4 text-[var(--text-dim)]">
                        <span class="font-semibold">{{ $pool->chapters_count }} Chapters</span>
                        <span>{{ $pool->followers_count }} Followers</span>
                    </div>

                    <div class="flex items-center gap-2">
                        @if($progress)
                            <a href="{{ route('pools.detail', $pool->id) }}" class="px-3 py-1.5 rounded-xl bg-violet-500/20 text-violet-400 font-bold hover:bg-violet-500/30">
                                Continue (Ch {{ $progress->lastChapter?->chapter_number ?? 1 }})
                            </a>
                        @endif

                        <button wire:click="toggleFollow({{ $pool->id }})" 
                                class="px-3 py-1.5 rounded-xl font-bold border transition {{ $isFollowed ? 'border-[var(--border-medium)] text-[var(--text-muted)]' : 'accent-bg text-white' }}">
                            {{ $isFollowed ? 'Following' : 'Follow' }}
                        </button>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-span-full p-12 text-center text-sm text-[var(--text-dim)]">No pools found.</div>
        @endforelse
    </div>

    <!-- Create Pool Modal -->
    <div x-show="$wire.createModalOpen" 
         x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-md"
         @click.self="$wire.set('createModalOpen', false)">
        
        <div class="w-full max-w-lg p-6 rounded-3xl glass-panel shadow-2xl border border-[var(--border-medium)] space-y-4">
            <div class="flex items-center justify-between pb-3 border-b border-[var(--border-subtle)]">
                <h3 class="font-bold text-lg">Create New Series / Pool</h3>
                <button wire:click="$set('createModalOpen', false)" class="p-1 rounded-full hover:bg-[var(--bg-surface-elevated)]">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <div class="space-y-3">
                <div>
                    <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Series Title</label>
                    <input type="text" wire:model="newTitle" placeholder="e.g. Chronicles of the Astral Blade" class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)] font-bold">
                </div>

                <div>
                    <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Synopsis / Description</label>
                    <textarea wire:model="newDescription" rows="3" placeholder="Plot synopsis, premise, reading direction notes..." class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)]"></textarea>
                </div>

                <div>
                    <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Cover Image URL</label>
                    <input type="url" wire:model="newCoverUrl" placeholder="https://..." class="w-full mt-1 p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-sm outline-none focus:border-[var(--accent-primary)]">
                </div>

                <div class="pt-1">
                    <label class="flex items-center gap-2 text-xs text-[var(--text-main)] cursor-pointer">
                        <input type="checkbox" wire:model="newIsLocked" class="rounded accent-bg">
                        <span>Lock Pool (only you can add chapters; leave unchecked for community pools)</span>
                    </label>
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-3 border-t border-[var(--border-subtle)]">
                <button wire:click="$set('createModalOpen', false)" class="px-5 py-2 rounded-xl text-xs font-bold hover:bg-[var(--bg-surface-elevated)]">Cancel</button>
                <button wire:click="createPool" class="px-6 py-2 rounded-xl accent-bg text-white font-bold text-xs shadow hover:opacity-90 transition">Create Series</button>
            </div>
        </div>
    </div>
</div>