<?php

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Notification;
use Illuminate\Support\Facades\Auth;

new class extends Component
{
    use WithPagination;

    public string $filter = 'all'; // 'all', 'pools_collections', 'likes', 'follows'

    public function setFilter(string $f)
    {
        $this->filter = $f;
        $this->resetPage();
    }

    public function markAllAsRead()
    {
        $user = Auth::user();
        if (!$user) return;

        $user->notifications()->whereNull('read_at')->update(['read_at' => now()]);
        $this->dispatch('notify', 'All notifications marked as read');
    }

    public function markAsRead(int $id)
    {
        $notif = Notification::where('user_id', Auth::id())->findOrFail($id);
        $notif->markAsRead();
    }

    public function deleteNotification(int $id)
    {
        $notif = Notification::where('user_id', Auth::id())->find($id);
        if ($notif) {
            $notif->delete();
            $this->dispatch('notify', 'Notification deleted');
        }
    }

    public function clearAllNotifications()
    {
        $user = Auth::user();
        if (!$user) return;

        $user->notifications()->delete();
        $this->resetPage();
        $this->dispatch('notify', 'All notifications cleared');
    }

    public function render()
    {
        $user = Auth::user();
        if (!$user) {
            return view('components.⚡notifications-view', [
                'notifications' => collect(),
                'unreadCount' => 0,
            ]);
        }

        $query = $user->notifications()->with('actor');

        if ($this->filter === 'unread') {
            $query->whereNull('read_at');
        } elseif ($this->filter === 'pools_collections') {
            $query->whereIn('type', ['pool_chapter', 'collection_update']);
        } elseif ($this->filter === 'likes') {
            $query->where('type', 'like');
        } elseif ($this->filter === 'follows') {
            $query->where('type', 'follow');
        }

        $notifications = $query->paginate(20);
        $unreadCount = $user->notifications()->whereNull('read_at')->count();

        return view('components.⚡notifications-view', [
            'notifications' => $notifications,
            'unreadCount' => $unreadCount,
            'currentUser' => $user,
        ]);
    }
};
?>

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-black tracking-tight text-[var(--text-main)]">Notifications</h1>
            <p class="text-xs text-[var(--text-dim)]">Activity updates for your creations, followed pools, and collections.</p>
        </div>

        <div class="flex items-center gap-2">
            @if($unreadCount > 0)
                <button wire:click="markAllAsRead" class="px-3.5 py-2 rounded-2xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] hover:bg-[var(--bg-surface-elevated)] text-xs font-bold transition">
                    Mark all as read
                </button>
            @endif
            @if(isset($notifications) && $notifications->count() > 0)
                <button wire:click="clearAllNotifications"
                        wire:confirm="Are you sure you want to delete all notifications?"
                        class="px-3.5 py-2 rounded-2xl bg-rose-500/10 border border-rose-500/20 hover:bg-rose-500/20 text-rose-400 text-xs font-bold transition flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                    Clear all
                </button>
            @endif
        </div>
    </div>

    <!-- Twitter-Style Notification Filter Tabs -->
    <div class="flex items-center border-b border-[var(--border-subtle)] gap-2 overflow-x-auto">
        <button wire:click="setFilter('all')" 
                class="relative py-3.5 px-4 font-bold text-sm transition {{ $filter === 'all' ? 'text-[var(--text-main)]' : 'text-[var(--text-dim)] hover:text-[var(--text-main)]' }}">
            <span>All</span>
            @if($unreadCount > 0)
                <span class="ml-1 text-xs px-1.5 py-0.5 rounded-full bg-rose-500/20 text-rose-400 font-extrabold">{{ $unreadCount }}</span>
            @endif
            @if($filter === 'all') <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div> @endif
        </button>

        <button wire:click="setFilter('unread')" 
                class="relative py-3.5 px-4 font-bold text-sm transition {{ $filter === 'unread' ? 'text-[var(--text-main)]' : 'text-[var(--text-dim)] hover:text-[var(--text-main)]' }}">
            <span>Unread</span>
            @if($unreadCount > 0)
                <span class="ml-1 text-xs px-1.5 py-0.5 rounded-full bg-rose-500 text-white font-extrabold">{{ $unreadCount }}</span>
            @endif
            @if($filter === 'unread') <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div> @endif
        </button>

        <button wire:click="setFilter('pools_collections')" 
                class="relative py-3.5 px-4 font-bold text-sm transition {{ $filter === 'pools_collections' ? 'text-[var(--text-main)]' : 'text-[var(--text-dim)] hover:text-[var(--text-main)]' }}">
            <span>Pools & Collections</span>
            @if($filter === 'pools_collections') <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div> @endif
        </button>

        <button wire:click="setFilter('likes')" 
                class="relative py-3.5 px-4 font-bold text-sm transition {{ $filter === 'likes' ? 'text-[var(--text-main)]' : 'text-[var(--text-dim)] hover:text-[var(--text-main)]' }}">
            <span>Likes</span>
            @if($filter === 'likes') <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div> @endif
        </button>

        <button wire:click="setFilter('follows')" 
                class="relative py-3.5 px-4 font-bold text-sm transition {{ $filter === 'follows' ? 'text-[var(--text-main)]' : 'text-[var(--text-dim)] hover:text-[var(--text-main)]' }}">
            <span>Follows</span>
            @if($filter === 'follows') <div class="absolute bottom-0 left-0 right-0 h-1 accent-bg rounded-t-full"></div> @endif
        </button>
    </div>

    @if(!Auth::check())
        <div class="p-8 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] text-center space-y-3">
            <h3 class="font-bold text-lg">Please Log In</h3>
            <p class="text-xs text-[var(--text-dim)]">Notifications are delivered in real-time to authenticated accounts.</p>
            <a href="{{ route('login') }}" class="inline-block px-6 py-2.5 rounded-2xl accent-bg text-white font-bold text-sm shadow">
                Log In
            </a>
        </div>
    @else
        <!-- Notifications Stream -->
        <div class="rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] shadow-xl overflow-hidden divide-y divide-[var(--border-subtle)]">
            @forelse($notifications as $notif)
                @php
                    $isUnread = is_null($notif->read_at);
                    $iconClass = match($notif->type) {
                        'like' => 'text-rose-500 bg-rose-500/10',
                        'follow' => 'text-cyan-400 bg-cyan-500/10',
                        'pool_chapter' => 'text-violet-400 bg-violet-500/10',
                        'collection_update' => 'text-emerald-400 bg-emerald-500/10',
                        default => 'text-amber-400 bg-amber-500/10',
                    };
                @endphp
                <div class="group p-4 sm:p-5 flex items-start gap-4 transition relative {{ $isUnread ? 'bg-[var(--bg-surface-elevated)]/70' : 'hover:bg-[var(--bg-surface-elevated)]/40' }}"
                     wire:click="markAsRead({{ $notif->id }})">
                    
                    <!-- Icon Indicator -->
                    <div class="w-10 h-10 rounded-2xl flex items-center justify-center shrink-0 {{ $iconClass }}">
                        @if($notif->type === 'like')
                            <svg class="w-5 h-5 fill-current" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M3.172 5.172a4 4 0 015.656 0L10 6.343l1.172-1.171a4 4 0 115.656 5.656L10 17.657l-6.828-6.829a4 4 0 010-5.656z" clip-rule="evenodd"></path>
                            </svg>
                        @elseif($notif->type === 'follow')
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
                            </svg>
                        @elseif($notif->type === 'pool_chapter')
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                            </svg>
                        @else
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                            </svg>
                        @endif
                    </div>

                    <!-- Actor Avatar & Content -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <a href="{{ route('profile', $notif->actor->username) }}">
                                <img src="{{ $notif->actor->avatar_url }}" class="w-6 h-6 rounded-full object-cover">
                            </a>
                            <a href="{{ route('profile', $notif->actor->username) }}" class="font-bold text-sm text-[var(--text-main)] hover:underline">
                                {{ $notif->actor->name }}
                            </a>
                            <span class="text-xs text-[var(--text-dim)]">· {{ $notif->created_at->diffForHumans() }}</span>
                            @if($isUnread)
                                <span class="w-2 h-2 rounded-full accent-bg animate-pulse ml-1"></span>
                            @endif
                        </div>

                        <p class="text-sm text-[var(--text-main)] mt-1.5 leading-relaxed">
                            {{ $notif->message }}
                        </p>
                    </div>

                    <!-- Delete Single Notification Button -->
                    <button wire:click.stop="deleteNotification({{ $notif->id }})"
                            title="Delete notification"
                            class="p-2 rounded-xl text-[var(--text-dim)] hover:text-rose-400 hover:bg-rose-500/10 transition shrink-0 opacity-70 hover:opacity-100">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                        </svg>
                    </button>
                </div>
            @empty
                <div class="p-12 text-center text-sm text-[var(--text-dim)]">No notifications in this category.</div>
            @endforelse
        </div>

        <div class="pt-4">
            {{ $notifications->links() }}
        </div>
    @endif
</div>
