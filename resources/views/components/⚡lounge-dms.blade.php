<?php

use App\Models\Community;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Post;
use App\Models\User;
use App\Support\ContentReports;
use App\Support\Notifier;
use App\Support\SiteSettings;
use App\Support\SpamControls;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

new class extends Component
{
    public ?int $activeConversationId = null;

    public string $messageText = '';

    public ?int $replyingToMessageId = null;

    public string $searchChat = '';

    public string $chatFilter = 'all'; // 'all', 'unread', 'friends'

    public bool $newChatModalOpen = false;

    public string $userSearch = '';

    // Attachments
    public ?int $attachedPostId = null;

    public ?string $attachedImageUrl = null;

    public bool $artPickerModalOpen = false;

    // Pinned Message & Media Drawer
    public ?int $pinnedMessageId = null;

    public bool $mediaDrawerOpen = false;

    // Message Editing & Deletion
    public ?int $editingMessageId = null;

    public string $editingText = '';

    // Reporting
    public bool $reportModalOpen = false;

    public ?int $reportMessageId = null;

    public string $reportReason = '';

    public string $reportDetails = '';

    public function mount(?int $conversationId = null)
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        if ($conversationId) {
            $this->activeConversationId = $conversationId;
        } else {
            $this->activeConversationId = Conversation::query()
                ->visibleFor($user)
                ->orderByDesc('last_message_at')
                ->value('id');
        }

        $this->markActiveAsRead();
    }

    public function selectConversation(int $id)
    {
        $conversation = $this->conversationForCurrentUser($id);
        abort_unless($conversation, 404);
        $this->activeConversationId = $conversation->id;
        $this->replyingToMessageId = null;
        $this->attachedPostId = null;
        $this->attachedImageUrl = null;
        $this->editingMessageId = null;
        $this->mediaDrawerOpen = false;
        $this->markActiveAsRead();
    }

    public function startConversationWithUser(int $userId)
    {
        $me = Auth::user();
        abort_unless($me, 401);
        if ($userId === $me->id) {
            return;
        }

        $partner = User::findOrFail($userId);
        abort_if($me->isBlockedBy($partner) || $me->isBlocking($partner), 403, 'Cannot message this user.');

        $existing = Conversation::where(function ($q) use ($me, $partner) {
            $q->where('user_one_id', $me->id)->where('user_two_id', $partner->id);
        })->orWhere(function ($q) use ($me, $partner) {
            $q->where('user_one_id', $partner->id)->where('user_two_id', $me->id);
        })->first();

        if (! $existing) {
            $existing = Conversation::create([
                'user_one_id' => $me->id,
                'user_two_id' => $partner->id,
                'last_message_at' => now(),
            ]);
        } else {
            $existing->unhideFor($me);
        }

        $this->activeConversationId = $existing->id;
        $this->newChatModalOpen = false;
        $this->userSearch = '';
        $this->dispatch('notify', 'Chat opened with @'.$partner->username);
    }

    public function attachPost(int $postId)
    {
        $this->attachedPostId = $postId;
        $this->attachedImageUrl = null;
        $this->artPickerModalOpen = false;
        $this->dispatch('notify', 'Artwork attached to message');
    }

    public function sendMessage()
    {
        abort_unless(Auth::check(), 401);
        $me = Auth::user();

        if (! $this->activeConversationId) {
            return;
        }

        $conversation = $this->conversationForCurrentUser($this->activeConversationId);
        abort_unless($conversation, 404);

        $partner = $conversation->getOtherUser($me);
        abort_if($me->isBlockedBy($partner) || $me->isBlocking($partner), 403, 'Cannot message blocked user.');

        $text = trim($this->messageText);
        if ($text === '' && ! $this->attachedPostId && ! $this->attachedImageUrl) {
            return;
        }

        SpamControls::enforce('messages', 10, 60, $text);

        $msg = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $me->id,
            'text' => $text,
            'shared_post_id' => $this->attachedPostId,
            'image_url' => $this->attachedImageUrl,
            'reply_to_id' => $this->replyingToMessageId,
            'is_read' => false,
        ]);

        $conversation->update(['last_message_at' => now()]);
        Notifier::directMessage($msg, $me);

        $this->reset('messageText', 'replyingToMessageId', 'attachedPostId', 'attachedImageUrl');
        $this->dispatch('lounge-message-sent');
    }

    public function toggleReaction(int $messageId, string $emoji)
    {
        $me = Auth::user();
        if (! $me) {
            return;
        }

        $msg = Message::findOrFail($messageId);
        $reactions = $msg->reactions ?? [];

        $userReactions = $reactions[$emoji] ?? [];
        if (in_array($me->id, $userReactions)) {
            $userReactions = array_values(array_diff($userReactions, [$me->id]));
        } else {
            $userReactions[] = $me->id;
        }

        if (empty($userReactions)) {
            unset($reactions[$emoji]);
        } else {
            $reactions[$emoji] = $userReactions;
        }

        $msg->update(['reactions' => $reactions]);
    }

    public function deleteMessage(int $messageId)
    {
        $me = Auth::user();
        $msg = Message::findOrFail($messageId);
        abort_unless($me && ($msg->sender_id === $me->id || $me->isAdmin()), 403);

        $msg->delete();
        $this->dispatch('notify', 'Message deleted.');
    }

    public function deleteConversation(?int $conversationId = null): void
    {
        $me = Auth::user();
        abort_unless($me, 401);

        $conversation = $this->conversationForCurrentUser($conversationId ?? (int) $this->activeConversationId);
        abort_unless($conversation, 404);

        $conversation->hideFor($me);

        if ($this->activeConversationId === $conversation->id) {
            $this->activeConversationId = Conversation::query()
                ->visibleFor($me)
                ->orderByDesc('last_message_at')
                ->value('id');
            $this->markActiveAsRead();
        }

        $this->dispatch('notify', 'Conversation removed from your inbox.');
    }

    public function setReplyingTo(?int $messageId)
    {
        $this->replyingToMessageId = $messageId;
    }

    public function cancelReply()
    {
        $this->replyingToMessageId = null;
    }

    public function clearAttachments()
    {
        $this->attachedPostId = null;
        $this->attachedImageUrl = null;
    }

    public function togglePinMessage(int $messageId)
    {
        $this->pinnedMessageId = $this->pinnedMessageId === $messageId ? null : $messageId;
        $this->dispatch('notify', $this->pinnedMessageId ? 'Message pinned to top' : 'Message unpinned');
    }

    private function conversationForCurrentUser(int $id): ?Conversation
    {
        $user = Auth::user();
        if (! $user) {
            return null;
        }

        return Conversation::where('id', $id)
            ->where(function ($q) use ($user) {
                $q->where('user_one_id', $user->id)
                    ->orWhere('user_two_id', $user->id);
            })->first();
    }

    private function markActiveAsRead()
    {
        $user = Auth::user();
        if (! $user || ! $this->activeConversationId) {
            return;
        }

        Message::where('conversation_id', $this->activeConversationId)
            ->where('sender_id', '!=', $user->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);
    }

    public function render()
    {
        $user = Auth::user();

        // Server Rail communities & DMs
        $myCommunities = $user
            ? Community::query()
                ->join('community_members', 'community_members.community_id', '=', 'communities.id')
                ->where('community_members.user_id', $user->id)
                ->whereIn('community_members.status', ['active', 'pending'])
                ->select('communities.*', 'community_members.status as membership_status')
                ->orderBy('communities.name')->get()
            : collect();

        $conversations = collect();
        if ($user) {
            $conversations = Conversation::with(['userOne', 'userTwo', 'latestMessage'])
                ->visibleFor($user)
                ->orderByDesc('last_message_at')
                ->get();
        }

        $activeConversation = $this->activeConversationId ? $this->conversationForCurrentUser($this->activeConversationId) : null;
        $partnerUser = ($user && $activeConversation) ? $activeConversation->getOtherUser($user) : null;

        $messages = collect();
        if ($activeConversation) {
            $messages = Message::with(['sender', 'sharedPost.primaryMedia', 'replyTo'])
                ->where('conversation_id', $activeConversation->id)
                ->orderBy('created_at', 'asc')
                ->get();
        }

        // Search Users for New DM Modal
        $userSearchResults = collect();
        if ($user && strlen(trim($this->userSearch)) >= 1) {
            $term = trim(str_replace('@', '', $this->userSearch));
            $userSearchResults = User::where('id', '!=', $user->id)
                ->where(function ($uq) use ($term) {
                    $uq->where('username', 'like', "%{$term}%")
                        ->orWhere('name', 'like', "%{$term}%");
                })
                ->take(8)
                ->get();
        }

        // Shared Media Items in current conversation
        $sharedMedia = collect();
        if ($activeConversation) {
            $sharedMedia = Message::where('conversation_id', $activeConversation->id)
                ->where(fn ($mq) => $mq->whereNotNull('shared_post_id')->orWhereNotNull('image_url'))
                ->with('sharedPost.primaryMedia')
                ->latest()
                ->get();
        }

        $userPosts = $user ? Post::where('user_id', $user->id)->with('primaryMedia')->latest()->take(12)->get() : collect();

        return view('components.⚡lounge-dms', [
            'myCommunities' => $myCommunities,
            'recentConversations' => $conversations,
            'conversations' => $conversations,
            'activeConversation' => $activeConversation,
            'partnerUser' => $partnerUser,
            'messages' => $messages,
            'userSearchResults' => $userSearchResults,
            'sharedMedia' => $sharedMedia,
            'userPosts' => $userPosts,
            'currentUser' => $user,
        ]);
    }
};
?>

<div class="flex h-full min-h-0 w-full min-w-0 overflow-hidden bg-[var(--bg-surface-elevated)] text-[var(--text-main)]"
     x-data="{ mediaDrawerOpen: false, searchOpen: false, sidebarOpen: false }">

    <!-- 1. Discord-Style Server Rail (Leftmost) -->
    @include('lounge.server-rail')

    <!-- Mobile Backdrop for DM Sidebar -->
    <div x-show="sidebarOpen" x-cloak @click="sidebarOpen = false" class="fixed inset-0 z-40 bg-black/60 backdrop-blur-sm md:hidden"></div>

    <!-- 2. Direct Messages & Friends Sidebar (Middle Column) -->
    <div class="fixed inset-y-0 left-0 z-50 flex w-64 sm:w-72 shrink-0 flex-col border-r border-black/20 bg-[var(--bg-surface)] min-h-0 transition-transform duration-200 md:static md:z-auto md:translate-x-0"
         :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0'">
        <!-- Sidebar Top Header -->
        <div class="flex items-center justify-between p-4 border-b border-[var(--border-subtle)]">
            <h2 class="font-black text-sm text-[var(--text-main)] flex items-center gap-2">
                <span>💬 Direct Messages</span>
            </h2>
            <button wire:click="$set('newChatModalOpen', true)" 
                    class="p-1.5 rounded-xl bg-[var(--bg-surface-elevated)] hover:bg-white/10 text-[var(--accent-primary)] transition cursor-pointer"
                    title="Start New Direct Message">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path>
                </svg>
            </button>
        </div>

        <!-- DM Search & Filter -->
        <div class="p-3 border-b border-[var(--border-subtle)]">
            <input type="text" 
                   wire:model.live.debounce.150ms="searchChat" 
                   placeholder="Find or start a conversation..." 
                   class="w-full px-3 py-1.5 rounded-xl bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] text-xs outline-none text-[var(--text-main)] placeholder-[var(--text-dim)]">
        </div>

        <!-- Conversation List -->
        <div class="flex-1 overflow-y-auto p-2 space-y-1">
            @forelse($conversations as $conv)
                @php
                    $partner = $conv->getOtherUser($currentUser);
                    $isActive = $activeConversationId === $conv->id;
                    $unreadCount = $conv->unreadCountFor($currentUser);
                @endphp

                <button wire:click="selectConversation({{ $conv->id }}); sidebarOpen = false;" 
                        class="w-full flex items-center gap-3 p-2.5 rounded-2xl transition group text-left cursor-pointer {{ $isActive ? 'accent-bg text-white shadow-md' : 'hover:bg-[var(--bg-surface-elevated)] text-[var(--text-main)]' }}">
                    <div class="relative shrink-0">
                        <img src="{{ $partner->avatar_url }}" class="w-10 h-10 rounded-full object-cover">
                        <span class="absolute bottom-0 right-0 w-3 h-3 rounded-full border-2 border-[var(--bg-surface)] bg-emerald-500"></span>
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex items-center justify-between gap-1">
                            <h4 class="font-extrabold text-xs truncate group-hover:underline">{{ $partner->name }}</h4>
                            <span class="text-[10px] opacity-60">{{ $conv->last_message_at ? $conv->last_message_at->diffForHumans(null, true, true) : '' }}</span>
                        </div>
                        <p class="text-[11px] truncate opacity-75 mt-0.5">
                            {{ $conv->latestMessage ? $conv->latestMessage->text : 'No messages yet' }}
                        </p>
                    </div>

                    @if($unreadCount > 0)
                        <span class="px-2 py-0.5 rounded-full bg-rose-500 text-white font-extrabold text-[10px] shrink-0">
                            {{ $unreadCount }}
                        </span>
                    @endif
                </button>
            @empty
                <div class="p-6 text-center text-xs text-[var(--text-dim)] space-y-3">
                    <p>No active conversations yet.</p>
                    <button wire:click="$set('newChatModalOpen', true)" class="px-3 py-1.5 rounded-xl accent-bg text-white font-bold text-xs">
                        Start First Chat
                    </button>
                </div>
            @endforelse
        </div>

        <!-- Current User Status Footer -->
        @if($currentUser)
            <div class="p-3 border-t border-[var(--border-subtle)] bg-[var(--bg-surface)] flex items-center justify-between gap-2">
                <div class="flex items-center gap-2 min-w-0">
                    <img src="{{ $currentUser->avatar_url }}" class="w-8 h-8 rounded-full object-cover">
                    <div class="min-w-0">
                        <div class="font-bold text-xs truncate">{{ $currentUser->name }}</div>
                        <div class="text-[10px] text-[var(--text-dim)] truncate">@<span>{{ $currentUser->username }}</span></div>
                    </div>
                </div>
                <a href="{{ route('settings') }}" class="p-1.5 rounded-lg hover:bg-white/10 text-[var(--text-dim)] hover:text-[var(--text-main)] transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                    </svg>
                </a>
            </div>
        @endif
    </div>

    <!-- 3. Direct Message Active Chat Area (Right Pane) -->
    <div class="flex-1 flex flex-col min-w-0 bg-[var(--bg-surface-elevated)] min-h-0 relative">
        @if($activeConversation && $partnerUser)
            <!-- Chat Top Header -->
            <div class="flex items-center justify-between p-4 border-b border-[var(--border-subtle)] bg-[var(--bg-surface)] shrink-0">
                <div class="flex items-center gap-3 min-w-0">
                    <button @click="sidebarOpen = !sidebarOpen" class="p-2 rounded-xl bg-[var(--bg-surface-elevated)] hover:bg-white/10 text-[var(--text-muted)] hover:text-[var(--text-main)] transition md:hidden cursor-pointer" aria-label="Toggle direct messages sidebar">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>

                    <a href="{{ route('profile', $partnerUser->username) }}" class="relative shrink-0">
                        <img src="{{ $partnerUser->avatar_url }}" class="w-9 h-9 rounded-full object-cover">
                        <span class="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full border-2 border-[var(--bg-surface)] bg-emerald-500"></span>
                    </a>
                    <div class="min-w-0">
                        <a href="{{ route('profile', $partnerUser->username) }}" class="font-extrabold text-sm text-[var(--text-main)] hover:underline block truncate">
                            {{ $partnerUser->name }}
                        </a>
                        <div class="text-[11px] text-[var(--text-dim)] truncate">
                            @<span>{{ $partnerUser->username }}</span> • Active in DM
                        </div>
                    </div>
                </div>

                <!-- Header Actions -->
                <div class="flex items-center gap-2 shrink-0">
                    <button @click="mediaDrawerOpen = !mediaDrawerOpen" class="p-2 rounded-xl bg-[var(--bg-surface-elevated)] hover:bg-white/10 text-[var(--text-muted)] hover:text-[var(--text-main)] transition text-xs font-semibold flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                        <span class="hidden sm:inline">Shared Media</span>
                    </button>
                    <button wire:click="deleteConversation({{ $activeConversation->id }})"
                            wire:confirm="Delete this conversation? It will be removed from your inbox only. {{ $partnerUser->name }} keeps the thread and can message you again."
                            class="p-2 rounded-xl bg-[var(--bg-surface-elevated)] hover:bg-rose-500/10 text-[var(--text-muted)] hover:text-rose-400 transition text-xs font-semibold flex items-center gap-1"
                            title="Delete conversation">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16"></path>
                        </svg>
                        <span class="hidden sm:inline">Delete</span>
                    </button>
                </div>
            </div>

            <!-- Messages Scroll Stream -->
            <div class="flex-1 overflow-y-auto p-4 space-y-4 max-w-5xl mx-auto w-full" x-ref="dmScroll">
                @forelse($messages as $msg)
                    @php
                        $isMine = $msg->sender_id === $currentUser->id;
                    @endphp

                    <div class="flex items-start gap-3 group {{ $isMine ? 'flex-row-reverse' : '' }}">
                        <!-- Sender Avatar -->
                        <a href="{{ route('profile', $msg->sender->username) }}" class="shrink-0">
                            <img src="{{ $msg->sender->avatar_url }}" class="w-8 h-8 rounded-full object-cover">
                        </a>

                        <!-- Message Body Container -->
                        <div class="space-y-1 max-w-[80%] sm:max-w-[70%]">
                            <div class="flex items-center gap-2 text-[10px] text-[var(--text-dim)] {{ $isMine ? 'flex-row-reverse' : '' }}">
                                <span class="font-bold text-[var(--text-muted)]">{{ $msg->sender->name }}</span>
                                <span>{{ $msg->created_at->format('g:i A') }}</span>
                            </div>

                            <!-- Attached Artwork Card if present -->
                            @if($msg->sharedPost)
                                <div class="rounded-2xl overflow-hidden bg-[var(--bg-surface)] border border-[var(--border-subtle)] p-2 space-y-1 mb-1 shadow-sm">
                                    <a href="{{ route('post.detail', $msg->sharedPost->id) }}" class="block aspect-video w-full overflow-hidden rounded-xl bg-neutral-900">
                                        <img src="{{ $msg->sharedPost->primaryMedia->url ?? '' }}" class="w-full h-full object-cover">
                                    </a>
                                    <a href="{{ route('post.detail', $msg->sharedPost->id) }}" class="font-bold text-xs text-[var(--text-main)] truncate block hover:underline">
                                        {{ $msg->sharedPost->title ?: 'Shared Artwork' }}
                                    </a>
                                </div>
                            @endif

                            <!-- Message Text Bubble -->
                            @if($msg->text)
                                <div class="p-3 rounded-2xl text-xs leading-relaxed chat-bubble {{ $isMine ? 'accent-bg text-white font-medium rounded-tr-none' : 'bg-[var(--bg-surface)] text-[var(--text-main)] border border-[var(--border-subtle)] rounded-tl-none' }}">
                                    {{ $msg->text }}
                                </div>
                            @endif

                            <!-- Reactions Bar -->
                            <div class="flex items-center gap-1 pt-0.5 {{ $isMine ? 'justify-end' : '' }}">
                                @if(!empty($msg->reactions))
                                    @foreach($msg->reactions as $emoji => $users)
                                        <button wire:click="toggleReaction({{ $msg->id }}, '{{ $emoji }}')" 
                                                class="px-2 py-0.5 rounded-full text-[10px] font-bold border flex items-center gap-1 transition {{ in_array($currentUser->id, $users) ? 'bg-white/20 border-white/40 text-white' : 'bg-[var(--bg-surface)] border-[var(--border-subtle)] text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">
                                            <span>{{ $emoji }}</span>
                                            <span>{{ count($users) }}</span>
                                        </button>
                                    @endforeach
                                @endif

                                <!-- Quick Reaction Picker Trigger -->
                                <div class="opacity-0 group-hover:opacity-100 transition flex items-center gap-1">
                                    <button wire:click="toggleReaction({{ $msg->id }}, '❤️')" class="p-1 text-xs hover:scale-125 transition">❤️</button>
                                    <button wire:click="toggleReaction({{ $msg->id }}, '🔥')" class="p-1 text-xs hover:scale-125 transition">🔥</button>
                                    <button wire:click="toggleReaction({{ $msg->id }}, '👍')" class="p-1 text-xs hover:scale-125 transition">👍</button>
                                    <button wire:click="setReplyingTo({{ $msg->id }})" class="p-1 text-[10px] text-[var(--text-muted)] hover:text-[var(--text-main)] hover:underline" title="Reply to this message">Reply</button>
                                    <button wire:click="togglePinMessage({{ $msg->id }})" class="p-1 text-[10px] {{ $pinnedMessageId === $msg->id ? 'text-amber-400' : 'text-[var(--text-muted)] hover:text-[var(--text-main)]' }} hover:underline" title="Pin this message">{{ $pinnedMessageId === $msg->id ? '📌 Pinned' : '📌 Pin' }}</button>
                                    @if($isMine || $currentUser->isAdmin())
                                        <button wire:click="deleteMessage({{ $msg->id }})" class="p-1 text-[10px] text-rose-400 hover:underline">Delete</button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="p-12 text-center text-xs text-[var(--text-dim)]">
                        No messages in this chat yet. Say hello to @<span>{{ $partnerUser->username }}</span>!
                    </div>
                @endforelse
            </div>

            <!-- Attached Artwork Preview Bar before sending -->
            @if($attachedPostId)
                <div class="px-4 py-2 bg-amber-500/10 border-t border-amber-500/20 text-xs text-amber-300 flex items-center justify-between">
                    <span class="font-bold">🎨 Artwork Attached to message</span>
                    <button wire:click="clearAttachments" class="text-xs font-bold hover:underline">&times; Remove</button>
                </div>
            @endif

            @if($pinnedMessageId)
                @php($pinnedMessage = $messages->firstWhere('id', $pinnedMessageId))
                @if($pinnedMessage)
                    <div class="px-4 py-2 bg-amber-500/10 border-t border-amber-500/20 text-xs text-amber-300 flex items-center justify-between gap-2">
                        <span class="min-w-0 truncate">📌 Pinned: <span class="font-bold">{{ $pinnedMessage->sender?->username }}</span> — {{ \Illuminate\Support\Str::limit($pinnedMessage->text, 70) }}</span>
                        <button wire:click="togglePinMessage({{ $pinnedMessage->id }})" class="shrink-0 font-bold hover:underline">&times; Unpin</button>
                    </div>
                @endif
            @endif

            @if($replyingToMessageId)
                @php($replyTarget = $messages->firstWhere('id', $replyingToMessageId))
                <div class="px-4 py-2 bg-[var(--bg-surface-elevated)] border-t border-[var(--border-subtle)] text-xs text-[var(--text-muted)] flex items-center justify-between gap-2">
                    <span class="min-w-0 truncate">Replying to <span class="font-bold text-[var(--text-main)]">{{ $replyTarget?->sender?->username ?? 'a message' }}</span>@if($replyTarget): {{ \Illuminate\Support\Str::limit($replyTarget->text, 70) }}@endif</span>
                    <button wire:click="cancelReply" class="shrink-0 font-bold hover:text-[var(--text-main)]">&times; Cancel</button>
                </div>
            @endif

            <!-- Bottom Message Input Composer -->
            <div class="p-3.5 bg-[var(--bg-surface)] border-t border-[var(--border-subtle)] shrink-0">
                <form wire:submit.prevent="sendMessage" class="flex items-center gap-2">
                    <button type="button" 
                            wire:click="$set('artPickerModalOpen', true)" 
                            class="p-2.5 rounded-xl bg-[var(--bg-surface-elevated)] hover:bg-white/10 text-[var(--accent-primary)] transition cursor-pointer"
                            title="Attach Artwork from Booru">
                        🎨
                    </button>

                    <input type="text" 
                           wire:model="messageText" 
                           placeholder="Message @{{ $partnerUser->username }}..." 
                           class="flex-1 px-4 py-3 rounded-2xl bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] text-xs outline-none text-[var(--text-main)] placeholder-[var(--text-dim)] focus:border-[var(--accent-primary)] transition">

                    <button type="submit" 
                            class="accent-bg hover:opacity-90 active:scale-95 text-white px-5 py-3 rounded-2xl font-bold text-xs shadow-md transition cursor-pointer">
                        Send
                    </button>
                </form>
            </div>
        @else
            <!-- Empty State when no conversation selected -->
            <div class="flex-1 flex flex-col items-center justify-center p-8 text-center space-y-4">
                <div class="w-16 h-16 rounded-3xl bg-[var(--bg-surface)] flex items-center justify-center text-2xl text-[var(--text-dim)]">
                    💬
                </div>
                <div>
                    <h3 class="font-extrabold text-lg text-[var(--text-main)]">Your Direct Messages</h3>
                    <p class="text-xs text-[var(--text-muted)] mt-1 max-w-sm">
                        Private 1:1 messaging integrated seamlessly inside the Lounge Area.
                    </p>
                </div>
                <button wire:click="$set('newChatModalOpen', true)" class="px-4 py-2.5 rounded-xl accent-bg text-white text-xs font-bold shadow-md cursor-pointer">
                    Start a Direct Message
                </button>
            </div>
        @endif
    </div>

    <!-- MODAL: START NEW DIRECT MESSAGE -->
    @if($newChatModalOpen)
        <div class="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-[var(--bg-surface)] border border-[var(--border-medium)] p-6 rounded-3xl max-w-md w-full space-y-4 shadow-2xl">
                <h3 class="font-extrabold text-base text-[var(--text-main)]">Start Direct Message</h3>

                <input type="text" 
                       wire:model.live.debounce.200ms="userSearch" 
                       placeholder="Search user by name or @username..." 
                       class="w-full px-3.5 py-2.5 rounded-xl bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] text-xs text-[var(--text-main)] outline-none">

                <div class="space-y-1.5 max-h-60 overflow-y-auto">
                    @forelse($userSearchResults as $searchedUser)
                        <button wire:click="startConversationWithUser({{ $searchedUser->id }})" 
                                class="w-full flex items-center justify-between p-2 rounded-xl hover:bg-[var(--bg-surface-elevated)] transition text-left cursor-pointer">
                            <div class="flex items-center gap-2.5">
                                <img src="{{ $searchedUser->avatar_url }}" class="w-8 h-8 rounded-full object-cover">
                                <div>
                                    <div class="font-bold text-xs text-[var(--text-main)]">{{ $searchedUser->name }}</div>
                                    <div class="text-[10px] text-[var(--text-dim)]">@<span>{{ $searchedUser->username }}</span></div>
                                </div>
                            </div>
                            <span class="text-xs accent-text font-bold">Message →</span>
                        </button>
                    @empty
                        @if(strlen(trim($userSearch)) >= 2)
                            <div class="p-4 text-center text-xs text-[var(--text-dim)]">No users found matching "{{ $userSearch }}"</div>
                        @else
                            <div class="p-4 text-center text-xs text-[var(--text-dim)]">Type a username to find members...</div>
                        @endif
                    @endforelse
                </div>

                <div class="flex items-center justify-end pt-2">
                    <button wire:click="$set('newChatModalOpen', false)" class="px-4 py-2 text-xs font-bold text-[var(--text-dim)] hover:text-[var(--text-main)]">Close</button>
                </div>
            </div>
        </div>
    @endif
</div>
