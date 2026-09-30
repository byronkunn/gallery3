<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Post;
use App\Models\User;
use App\Support\ContentReports;
use App\Support\Notifier;
use App\Support\SiteSettings;
use App\Support\SpamControls;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public ?int $activeConversationId = null;

    public string $messageText = '';

    public ?int $replyingToMessageId = null;

    public string $searchChat = '';

    public string $chatFilter = 'all'; // 'all', 'unread'

    public bool $isTyping = false;

    public bool $newChatModalOpen = false;

    // 1. Direct Image Attachment & Artwork Picker
    public ?int $attachedPostId = null;

    public ?string $attachedImageUrl = null;

    public bool $artPickerModalOpen = false;

    public string $customImageUrlInput = '';

    // 2. Pinned Message
    public ?int $pinnedMessageId = null;

    // 3. Shared Media Drawer
    public bool $mediaDrawerOpen = false;

    // 4. Message Editing & Deletion
    public ?int $editingMessageId = null;

    public string $editingText = '';

    // 5. Reporting a DM to site moderators
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
            $first = Conversation::where('user_one_id', $user->id)
                ->orWhere('user_two_id', $user->id)
                ->orderBy('last_message_at', 'desc')
                ->first();
            $this->activeConversationId = $first?->id;
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

    public function attachPost(int $postId)
    {
        $this->attachedPostId = $postId;
        $this->attachedImageUrl = null;
        $this->artPickerModalOpen = false;
        $this->dispatch('notify', 'Artwork attached to message');
    }

    public function attachCustomImageUrl()
    {
        $validated = $this->validate([
            'customImageUrlInput' => ['required', 'url', 'max:2048', 'regex:/^https?:\/\//i'],
        ]);

        $this->attachedImageUrl = trim($validated['customImageUrlInput']);
        $this->attachedPostId = null;
        $this->customImageUrlInput = '';
        $this->artPickerModalOpen = false;
        $this->dispatch('notify', 'Image URL attached');
    }

    public function detachAttachment()
    {
        $this->attachedPostId = null;
        $this->attachedImageUrl = null;
    }

    public function togglePinMessage(int $messageId)
    {
        $conversation = $this->conversationForCurrentUser($this->activeConversationId);
        abort_unless($conversation, 404);
        Message::where('conversation_id', $conversation->id)->findOrFail($messageId);

        if ($this->pinnedMessageId === $messageId) {
            $this->pinnedMessageId = null;
            $this->dispatch('notify', 'Unpinned message');
        } else {
            $this->pinnedMessageId = $messageId;
            $this->dispatch('notify', 'Message pinned to header 📌');
        }
    }

    public function toggleMediaDrawer()
    {
        $this->mediaDrawerOpen = ! $this->mediaDrawerOpen;
    }

    public function startEditing(int $messageId)
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        $this->conversationForCurrentUser($this->activeConversationId) ?? abort(404);

        $msg = Message::where('conversation_id', $this->activeConversationId)
            ->where('sender_id', $user->id)
            ->findOrFail($messageId);

        $this->editingMessageId = $msg->id;
        $this->editingText = $msg->text;
    }

    public function cancelEditing()
    {
        $this->editingMessageId = null;
        $this->editingText = '';
    }

    public function updateMessage()
    {
        $user = Auth::user();
        if (! $user || ! $this->editingMessageId) {
            return;
        }

        $this->conversationForCurrentUser($this->activeConversationId) ?? abort(404);
        $this->validate(['editingText' => ['required', 'string', 'max:5000']]);

        $text = trim($this->editingText);
        if (empty($text)) {
            return;
        }

        $msg = Message::where('conversation_id', $this->activeConversationId)
            ->where('sender_id', $user->id)
            ->findOrFail($this->editingMessageId);

        $msg->update(['text' => $text]);

        broadcast(new \App\Events\MessageUpdated($msg, 'update'))->toOthers();

        $this->editingMessageId = null;
        $this->editingText = '';
        $this->dispatch('notify', 'Message updated');
    }

    public function deleteMessage(int $messageId)
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        $this->conversationForCurrentUser($this->activeConversationId) ?? abort(404);
        $msg = Message::where('conversation_id', $this->activeConversationId)->findOrFail($messageId);

        if ($msg->sender_id === $user->id) {
            if ($this->pinnedMessageId === $msg->id) {
                $this->pinnedMessageId = null;
            }
            broadcast(new \App\Events\MessageUpdated($msg, 'delete'))->toOthers();
            $msg->delete();
            $this->dispatch('notify', 'Message deleted for everyone');
        }
    }

    public function startConversationWith(int $userId)
    {
        abort_unless(SiteSettings::bool('site_dms_enabled'), 403, 'Direct messages are currently disabled.');

        $me = Auth::user();
        if (! $me || $me->id === $userId) {
            return;
        }
        abort_if($me->blockedUsers()->whereKey($userId)->exists() || $me->blockedByUsers()->whereKey($userId)->exists(), 403);

        $user1 = min($me->id, $userId);
        $user2 = max($me->id, $userId);

        $conv = Conversation::firstOrCreate(
            ['user_one_id' => $user1, 'user_two_id' => $user2],
            ['last_message_at' => now()]
        );

        $this->newChatModalOpen = false;
        $this->selectConversation($conv->id);
    }

    public function markActiveAsRead()
    {
        $user = Auth::user();
        if (! $user || ! $this->activeConversationId) {
            return;
        }

        $conversation = $this->conversationForCurrentUser($this->activeConversationId);
        if (! $conversation) {
            return;
        }

        Message::where('conversation_id', $conversation->id)
            ->where('sender_id', '!=', $user->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
    }

    public function setReply(int $messageId)
    {
        $this->conversationForCurrentUser($this->activeConversationId) ?? abort(404);
        Message::where('conversation_id', $this->activeConversationId)->findOrFail($messageId);
        $this->replyingToMessageId = $messageId;
    }

    public function cancelReply()
    {
        $this->replyingToMessageId = null;
    }

    public function openReportModal(int $messageId): void
    {
        $conversation = $this->conversationForCurrentUser($this->activeConversationId);
        abort_unless($conversation, 404);
        $message = Message::where('conversation_id', $conversation->id)->findOrFail($messageId);
        abort_if($message->sender_id === Auth::id(), 422, 'You cannot report your own message.');

        $this->reportMessageId = $message->id;
        $this->reset('reportReason', 'reportDetails');
        $this->reportModalOpen = true;
    }

    public function submitReport(): void
    {
        abort_unless(Auth::check(), 401);

        $validated = $this->validate([
            'reportReason' => ['required', 'string', 'max:80'],
            'reportDetails' => ['nullable', 'string', 'max:1000'],
        ]);

        $conversation = $this->conversationForCurrentUser($this->activeConversationId);
        abort_unless($conversation, 404);
        $message = Message::where('conversation_id', $conversation->id)->findOrFail((int) $this->reportMessageId);

        $filed = ContentReports::file(Auth::user(), 'message', $message->id, $validated['reportReason'], $validated['reportDetails']);

        $this->reset('reportReason', 'reportDetails');
        $this->reportMessageId = null;
        $this->reportModalOpen = false;
        $this->dispatch('notify', $filed ? 'Report sent to the moderation team.' : 'You already reported this message.');
    }

    public function sendMessage()
    {
        abort_unless(SiteSettings::bool('site_dms_enabled'), 403, 'Direct messages are currently disabled.');

        $user = Auth::user();
        if (! $user || ! $this->activeConversationId) {
            return;
        }

        $conversation = $this->conversationForCurrentUser($this->activeConversationId);
        abort_unless($conversation, 404);

        $text = trim($this->messageText);
        if (empty($text) && ! $this->attachedPostId && ! $this->attachedImageUrl) {
            return;
        }

        if ($this->attachedImageUrl && ! empty($this->attachedImageUrl)) {
            abort_unless(filter_var($this->attachedImageUrl, FILTER_VALIDATE_URL)
                && in_array(parse_url($this->attachedImageUrl, PHP_URL_SCHEME), ['http', 'https'], true), 422);
            $text = (! empty($text) ? $text."\n" : '').$this->attachedImageUrl;
        }

        $this->validate(['messageText' => ['nullable', 'string', 'max:5000']]);
        SpamControls::enforce('messages', 20, 60, $text ?: (string) $this->attachedPostId);
        if ($this->replyingToMessageId) {
            Message::where('conversation_id', $conversation->id)->findOrFail($this->replyingToMessageId);
        }

        $msg = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'text' => $text ?: 'Attached Artwork',
            'reply_to_id' => $this->replyingToMessageId,
            'shared_post_id' => $this->attachedPostId,
            'is_read' => false,
        ]);

        $conversation->update(['last_message_at' => now()]);

        Notifier::directMessage($msg, $user);

        // Dispatch WebSocket Broadcast Event
        broadcast(new \App\Events\MessageSent($msg))->toOthers();

        $this->messageText = '';
        $this->replyingToMessageId = null;
        $this->attachedPostId = null;
        $this->attachedImageUrl = null;

        $this->dispatch('message-sent');
    }

    public function addReaction(int $messageId, string $emoji)
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        $conversation = $this->conversationForCurrentUser($this->activeConversationId);
        abort_unless($conversation, 404);
        $message = Message::where('conversation_id', $conversation->id)->findOrFail($messageId);
        $reactions = $message->reactions ?? [];

        if (! isset($reactions[$emoji])) {
            $reactions[$emoji] = [];
        }

        if (in_array($user->id, $reactions[$emoji])) {
            $reactions[$emoji] = array_values(array_filter($reactions[$emoji], fn ($id) => $id !== $user->id));
            if (empty($reactions[$emoji])) {
                unset($reactions[$emoji]);
            }
        } else {
            $reactions[$emoji][] = $user->id;
        }

        $message->reactions = $reactions;
        $message->save();

        // Dispatch WebSocket Broadcast Event
        broadcast(new \App\Events\MessageUpdated($message, 'reaction'))->toOthers();
    }

    public function render()
    {
        $user = Auth::user();
        if (! $user) {
            return view('components.⚡messages-view', [
                'conversations' => collect(),
                'activeConversation' => null,
                'messages' => collect(),
                'otherUser' => null,
                'replyMessage' => null,
                'availableUsers' => collect(),
                'pinnedMessage' => null,
                'sharedPosts' => collect(),
                'myArtworks' => collect(),
                'attachedPostModel' => null,
            ]);
        }

        $convQuery = Conversation::where(function ($q) use ($user) {
            $q->where('user_one_id', $user->id)
                ->orWhere('user_two_id', $user->id);
        })->with(['userOne', 'userTwo', 'visibleLatestMessage'])
            ->orderBy('last_message_at', 'desc');

        $blockedUserIds = $user->blockedUsers()->pluck('users.id')
            ->merge($user->blockedByUsers()->pluck('users.id'))->unique()->all();
        if ($blockedUserIds !== []) {
            $convQuery->whereNotIn('user_one_id', $blockedUserIds)
                ->whereNotIn('user_two_id', $blockedUserIds);
        }

        if (! empty($this->searchChat)) {
            $search = trim($this->searchChat);
            $convQuery->where(function ($q) use ($search) {
                $q->whereHas('userOne', function ($uq) use ($search) {
                    $uq->where('name', 'like', "%{$search}%")->orWhere('username', 'like', "%{$search}%");
                })->orWhereHas('userTwo', function ($uq) use ($search) {
                    $uq->where('name', 'like', "%{$search}%")->orWhere('username', 'like', "%{$search}%");
                });
            });
        }

        $conversations = $convQuery->get();

        if ($this->chatFilter === 'unread') {
            $conversations = $conversations->filter(fn ($c) => $c->unreadCountFor($user) > 0);
        }

        $activeConversation = $this->activeConversationId
            ? $this->conversationForCurrentUser($this->activeConversationId)?->load(['userOne', 'userTwo'])
            : null;
        if ($this->activeConversationId && ! $activeConversation) {
            abort(404);
        }
        $otherUser = $activeConversation ? $activeConversation->getOtherUser($user) : null;

        $messages = $activeConversation ? Message::where('conversation_id', $activeConversation->id)
            ->where('is_hidden', false)
            ->with(['sender', 'replyTo.sender', 'sharedPost.primaryMedia', 'sharedPost.user'])
            ->oldest()
            ->get() : collect();

        $replyMessage = $this->replyingToMessageId && $activeConversation
            ? Message::where('conversation_id', $activeConversation->id)->where('is_hidden', false)->with('sender')->find($this->replyingToMessageId)
            : null;
        $pinnedMessage = $this->pinnedMessageId && $activeConversation
            ? Message::where('conversation_id', $activeConversation->id)->where('is_hidden', false)->with(['sender', 'sharedPost.primaryMedia'])->find($this->pinnedMessageId)
            : null;

        $sharedPosts = $messages->filter(fn ($m) => $m->shared_post_id !== null)->pluck('sharedPost')->filter();
        $myArtworks = Post::with('primaryMedia')->latest()->take(16)->get();
        $attachedPostModel = $this->attachedPostId ? Post::with('primaryMedia')->find($this->attachedPostId) : null;

        $availableUsers = User::where('id', '!=', $user->id)->get();

        return view('components.⚡messages-view', [
            'conversations' => $conversations,
            'activeConversation' => $activeConversation,
            'messages' => $messages,
            'otherUser' => $otherUser,
            'replyMessage' => $replyMessage,
            'pinnedMessage' => $pinnedMessage,
            'sharedPosts' => $sharedPosts,
            'myArtworks' => $myArtworks,
            'attachedPostModel' => $attachedPostModel,
            'availableUsers' => $availableUsers,
            'currentUser' => $user,
        ]);
    }

    private function conversationForCurrentUser(?int $conversationId): ?Conversation
    {
        $user = Auth::user();
        if (! $user || ! $conversationId) {
            return null;
        }

        $conversation = Conversation::whereKey($conversationId)
            ->where(function ($query) use ($user): void {
                $query->where('user_one_id', $user->id)->orWhere('user_two_id', $user->id);
            })
            ->first();
        if (! $conversation) {
            return null;
        }

        $otherUserId = $conversation->user_one_id === $user->id ? $conversation->user_two_id : $conversation->user_one_id;

        return $user->blockedUsers()->whereKey($otherUserId)->exists() || $user->blockedByUsers()->whereKey($otherUserId)->exists()
            ? null
            : $conversation;
    }
};
?>

<div x-data="{
         activeConversationId: @js($activeConversationId),
         remoteTyping: false,
         typingTimer: null,
         initWebsockets() {
             if (!window.Echo || !this.activeConversationId) return;
             try {
                 window.Echo.private('conversation.' + this.activeConversationId)
                     .listen('.message.sent', (e) => {
                         $wire.$refresh();
                     })
                     .listen('.user.typing', (e) => {
                         if (e.userId !== {{ Auth::id() ?? 0 }}) {
                             this.remoteTyping = e.isTyping;
                             clearTimeout(this.typingTimer);
                             if (e.isTyping) {
                                 this.typingTimer = setTimeout(() => { this.remoteTyping = false; }, 3000);
                             }
                         }
                     })
                     .listen('.message.updated', (e) => {
                         $wire.$refresh();
                     });
             } catch (err) {
                 console.log('Echo WebSocket:', err);
             }
         }
     }"
     x-init="initWebsockets(); $watch('activeConversationId', (val) => { activeConversationId = val; initWebsockets(); })"
     class="h-[calc(100vh-5rem)] max-w-7xl mx-auto px-2 sm:px-6 lg:px-8 py-4 flex flex-col w-full min-w-0">
    @if(!Auth::check())
        <div class="flex-1 flex flex-col items-center justify-center p-8 text-center bg-[var(--bg-surface)] rounded-3xl border border-[var(--border-subtle)] space-y-4">
            <div class="w-16 h-16 rounded-3xl accent-bg/10 text-[var(--accent-primary)] flex items-center justify-center">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                </svg>
            </div>
            <h2 class="text-xl font-bold">Log in to Access Messages</h2>
            <p class="text-sm text-[var(--text-muted)] max-w-sm">Direct messaging features rich post previews, telegram replies, and message reactions.</p>
            <a href="{{ route('login') }}" class="px-6 py-2.5 rounded-2xl accent-bg text-white font-bold text-sm shadow">
                Log in
            </a>
        </div>
    @else
        <!-- Twitter-Style 2-Pane Messaging Container -->
        <div class="flex-1 flex overflow-hidden rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] shadow-xl w-full min-w-0">
            <!-- Left Pane: Conversations List -->
            <div class="{{ $activeConversationId ? 'hidden md:flex' : 'flex' }} w-full md:w-72 lg:w-80 flex-col border-r border-[var(--border-subtle)] bg-[var(--bg-surface)] shrink-0">
                <!-- Header with Back to Site Button -->
                <div class="p-4 border-b border-[var(--border-subtle)] flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <a href="{{ route('gallery') }}" class="p-1.5 rounded-xl text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)] transition" title="Back to Gallery">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                            </svg>
                        </a>
                        <h2 class="font-extrabold text-xl tracking-tight">Messages</h2>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <a href="{{ route('gallery') }}" class="hidden sm:inline-flex items-center gap-1 px-2.5 py-1 rounded-xl bg-[var(--bg-surface-elevated)] hover:bg-[var(--border-medium)] text-[11px] font-bold transition border border-[var(--border-subtle)] text-[var(--text-muted)] hover:text-[var(--text-main)]">
                            <span>Back to Site</span>
                        </a>
                        <button wire:click="$set('newChatModalOpen', true)" 
                                class="p-2 rounded-2xl bg-[var(--bg-surface-elevated)] hover:bg-[var(--accent-glow)] text-[var(--text-main)] hover:accent-text transition"
                                title="New Conversation">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Search Conversations & Filter Tabs -->
                <div class="p-3 border-b border-[var(--border-subtle)] space-y-2">
                    <div class="relative">
                        <svg class="w-4 h-4 absolute left-3 top-3 text-[var(--text-dim)]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        <input type="text" 
                               wire:model.live.debounce.250ms="searchChat"
                               placeholder="Search Direct Messages..." 
                               class="w-full pl-9 pr-4 py-2 rounded-xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-xs outline-none focus:border-[var(--accent-primary)]">
                    </div>

                    <!-- Chat Filter Tabs (All / Unread) -->
                    <div class="flex items-center gap-1">
                        <button wire:click="$set('chatFilter', 'all')" 
                                class="px-3 py-1 rounded-xl text-xs font-bold transition {{ $chatFilter === 'all' ? 'accent-bg text-white shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                            All Chats
                        </button>
                        <button wire:click="$set('chatFilter', 'unread')" 
                                class="px-3 py-1 rounded-xl text-xs font-bold transition {{ $chatFilter === 'unread' ? 'accent-bg text-white shadow-sm' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                            Unread
                        </button>
                    </div>
                </div>

                <!-- Conversation List Items -->
                <div class="flex-1 overflow-y-auto divide-y divide-[var(--border-subtle)]">
                    @forelse($conversations as $c)
                        @php
                            $other = $c->getOtherUser($currentUser);
                            $isActive = $activeConversationId === $c->id;
                            $latestMsg = $c->visibleLatestMessage;
                            $unread = $c->unreadCountFor($currentUser);
                        @endphp
                        <button wire:click="selectConversation({{ $c->id }})" 
                                class="flex items-center gap-3 w-full p-4 text-left transition {{ $isActive ? 'bg-[var(--bg-surface-elevated)] border-l-4 accent-border' : 'hover:bg-[var(--bg-surface-elevated)]/60' }}">
                            <div class="relative shrink-0">
                                <img src="{{ $other->avatar_url }}" class="w-12 h-12 rounded-full object-cover">
                                <span class="absolute bottom-0 right-0 w-3 h-3 rounded-full bg-emerald-500 ring-2 ring-[var(--bg-surface)]"></span>
                            </div>

                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between gap-1">
                                    <span class="font-bold text-sm truncate {{ $unread > 0 ? 'text-[var(--text-main)] font-extrabold' : '' }}">{{ $other->name }}</span>
                                    <span class="text-[10px] text-[var(--text-dim)] shrink-0">{{ $c->last_message_at ? $c->last_message_at->shortAbsoluteDiffForHumans() : '' }}</span>
                                </div>
                                <div class="flex items-center justify-between gap-2 mt-1">
                                    <p class="text-xs truncate {{ $unread > 0 ? 'text-[var(--text-main)] font-semibold' : 'text-[var(--text-muted)]' }}">
                                        @if($latestMsg && $latestMsg->shared_post_id)
                                            🎨 [Shared Artwork]
                                        @else
                                            {{ $latestMsg->text ?? 'No messages yet' }}
                                        @endif
                                    </p>
                                    @if($unread > 0)
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-sky-500 text-white shrink-0">
                                            {{ $unread }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </button>
                    @empty
                        <div class="p-6 text-center text-xs text-[var(--text-dim)]">No conversations found.</div>
                    @endforelse
                </div>
            </div>

            <!-- Right Pane: Telegram-Style One-on-One Chat Area -->
            <div class="{{ $activeConversationId ? 'flex' : 'hidden md:flex' }} flex-1 min-w-0 flex-col bg-[var(--bg-page)]/50 relative">
                @if($activeConversation && $otherUser)
                    <!-- Active Chat Header -->
                    <div class="p-4 border-b border-[var(--border-subtle)] bg-[var(--bg-surface)] flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <!-- Mobile back button to conversations list -->
                            <button wire:click="$set('activeConversationId', null)" class="md:hidden p-1.5 rounded-xl hover:bg-[var(--bg-surface-elevated)]">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                                </svg>
                            </button>

                            <a href="{{ route('profile', $otherUser->username) }}" class="flex items-center gap-3 group">
                                <div class="relative">
                                    <img src="{{ $otherUser->avatar_url }}" class="w-10 h-10 rounded-full object-cover">
                                    <span class="absolute bottom-0 right-0 w-2.5 h-2.5 rounded-full bg-emerald-500 ring-2 ring-[var(--bg-surface)]"></span>
                                </div>
                                <div>
                                    <div class="flex items-center gap-1">
                                        <span class="font-bold text-sm group-hover:underline">{{ $otherUser->name }}</span>
                                        @if($otherUser->is_artist)
                                            <svg class="w-3.5 h-3.5 accent-text" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                            </svg>
                                        @endif
                                    </div>
                                    <div class="text-[11px] text-sky-400 font-bold animate-pulse" x-show="remoteTyping">is typing...</div>
                                    <div class="text-[11px] text-emerald-400 font-medium" x-show="!remoteTyping">online · {{ '@' . $otherUser->username }}</div>
                                </div>
                            </a>
                        </div>

                        <!-- Header Actions (Feature 3: Shared Media Drawer toggle) -->
                        <div class="flex items-center gap-1.5">
                            <button wire:click="toggleMediaDrawer" 
                                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-[var(--bg-surface-elevated)] hover:bg-[var(--border-medium)] text-xs font-bold transition border border-[var(--border-subtle)] text-[var(--text-muted)] hover:text-[var(--text-main)]"
                                    title="Toggle Shared Media Drawer">
                                <span>🖼️ Media ({{ count($sharedPosts) }})</span>
                            </button>
                            <a href="{{ route('profile', $otherUser->username) }}" class="p-2 rounded-xl text-[var(--text-muted)] hover:bg-[var(--bg-surface-elevated)]" title="View Profile">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                </svg>
                            </a>
                        </div>
                    </div>

                    <!-- Feature 2: Telegram-Style Pinned Message Banner -->
                    @if($pinnedMessage)
                        <div class="px-4 py-2 bg-gradient-to-r from-purple-500/10 via-rose-500/10 to-amber-500/10 border-b border-[var(--border-subtle)] flex items-center justify-between text-xs font-semibold shrink-0">
                            <div class="flex items-center gap-2 truncate">
                                <span class="text-rose-400 font-bold flex items-center gap-1 shrink-0">📌 Pinned:</span>
                                <span class="truncate text-[var(--text-main)] font-medium">{{ $pinnedMessage->text ?? ($pinnedMessage->sharedPost->title ?? 'Attached Artwork') }}</span>
                            </div>
                            <button wire:click="togglePinMessage({{ $pinnedMessage->id }})" class="p-1 text-[var(--text-dim)] hover:text-rose-400 font-bold ml-2 shrink-0" title="Unpin message">
                                &times;
                            </button>
                        </div>
                    @endif

                    <div class="flex-1 flex overflow-hidden relative">
                        <!-- Messages Stream (Telegram Bubbles) -->
                        <div class="flex-1 p-4 sm:p-6 overflow-y-auto space-y-4" id="messages-container">
                            @foreach($messages as $msg)
                                @php
                                    $isMine = $msg->sender_id === $currentUser->id;
                                    $isEdited = $msg->updated_at && $msg->updated_at->gt($msg->created_at->addSeconds(2));
                                @endphp
                                <div class="flex flex-col {{ $isMine ? 'items-end' : 'items-start' }} group relative"
                                     x-data="{ showMenu: false }">
                                    
                                    <div class="max-w-md sm:max-w-lg space-y-1">
                                        <!-- Telegram Reply Quote Bubble if replying to a message -->
                                        @if($msg->replyTo)
                                            <div class="text-xs px-3 py-1.5 rounded-t-xl bg-black/20 border-l-4 accent-border opacity-90 truncate max-w-sm">
                                                <span class="font-bold accent-text">{{ $msg->replyTo->sender->name }}:</span>
                                                <span class="text-[var(--text-main)] ml-1">{{ $msg->replyTo->text ?? '[Shared Media]' }}</span>
                                            </div>
                                        @endif

                                        <!-- Feature 4: Editing Input Mode -->
                                        @if($editingMessageId === $msg->id)
                                            <div class="p-3 rounded-2xl bg-[var(--bg-surface-elevated)] border-2 accent-border space-y-2 w-full min-w-[260px]">
                                                <div class="text-xs font-bold accent-text flex items-center gap-1">
                                                    ✏️ Editing Message
                                                </div>
                                                <input type="text" wire:model="editingText" wire:keydown.enter="updateMessage"
                                                       class="w-full px-3 py-1.5 rounded-xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-xs text-[var(--text-main)] outline-none focus:border-[var(--accent-primary)]">
                                                <div class="flex items-center justify-end gap-2">
                                                    <button wire:click="cancelEditing" class="px-2.5 py-1 rounded-lg text-[11px] font-semibold hover:bg-white/10">Cancel</button>
                                                    <button wire:click="updateMessage" class="px-3 py-1 rounded-lg accent-bg text-white text-[11px] font-bold shadow">Save</button>
                                                </div>
                                            </div>
                                        @else
                                            <!-- Main Message Bubble -->
                                            <div class="relative p-3.5 rounded-2xl shadow-sm {{ $isMine ? 'accent-bg text-white rounded-br-none' : 'bg-[var(--bg-surface)] text-[var(--text-main)] border border-[var(--border-subtle)] rounded-bl-none' }} {{ $pinnedMessageId === $msg->id ? 'ring-2 ring-rose-400/60 shadow-lg' : '' }}">
                                                
                                                <!-- Shared Gallery Post Rich Embed (Telegram Card style) -->
                                                @if($msg->sharedPost)
                                                    <div class="mb-2 p-2 rounded-xl bg-black/25 border border-white/10 hover:border-white/30 transition cursor-pointer"
                                                         @click="$dispatch('open-lightbox', { items: [{ url: '{{ $msg->sharedPost->primaryMedia->url }}', type: '{{ $msg->sharedPost->media_type }}' }], startIndex: 0, title: '{{ addslashes($msg->sharedPost->title ?? 'Post') }}', author: '{{ addslashes($msg->sharedPost->user->name) }}', postId: {{ $msg->sharedPost->id }} })">
                                                        <div class="flex items-center gap-3">
                                                            <img src="{{ $msg->sharedPost->primaryMedia->url }}" class="w-16 h-16 rounded-lg object-cover shrink-0">
                                                            <div class="min-w-0">
                                                                <div class="text-xs font-extrabold truncate">{{ $msg->sharedPost->title ?? 'Artwork' }}</div>
                                                                <div class="text-[11px] opacity-80 mt-0.5">by {{ $msg->sharedPost->user->name }}</div>
                                                                <div class="text-[10px] opacity-60 mt-1">Tap to open in Lightbox →</div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                @endif

                                                <!-- Message Text -->
                                                @if($msg->text)
                                                    <p class="text-sm leading-relaxed whitespace-pre-wrap select-text chat-bubble">{{ $msg->text }}</p>
                                                @endif

                                                <!-- Timestamp, Edited Indicator & Read Receipts -->
                                                <div class="flex items-center justify-end gap-1.5 text-[10px] mt-1 opacity-70">
                                                    @if($isEdited)
                                                        <span class="italic font-medium opacity-80">(edited)</span>
                                                    @endif
                                                    <span>{{ $msg->created_at->format('H:i') }}</span>
                                                    @if($isMine)
                                                        @if($msg->is_read)
                                                            <span class="text-sky-300 font-bold" title="Read {{ $msg->read_at?->format('H:i') }}">✓✓</span>
                                                        @else
                                                            <span title="Delivered">✓</span>
                                                        @endif
                                                    @endif
                                                </div>
                                            </div>
                                        @endif

                                        <!-- Reactions Display Pills below bubble -->
                                        @if(!empty($msg->reactions))
                                            <div class="flex flex-wrap gap-1 mt-1">
                                                @foreach($msg->reactions as $emoji => $uids)
                                                    @if(count($uids) > 0)
                                                        <button wire:click="addReaction({{ $msg->id }}, '{{ $emoji }}')" 
                                                                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-bold border transition {{ in_array($currentUser->id, $uids) ? 'accent-border bg-[var(--accent-glow)]' : 'bg-[var(--bg-surface)] border-[var(--border-subtle)]' }}">
                                                            <span>{{ $emoji }}</span>
                                                            <span class="text-[10px]">{{ count($uids) }}</span>
                                                        </button>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>

                                    <!-- Quick Options Menu (Feature 4 & Feature 2: Edit, Delete, Pin, Reply) -->
                                    <div class="opacity-0 group-hover:opacity-100 transition-opacity duration-150 flex items-center gap-1 mt-1 bg-[var(--bg-surface)] border border-[var(--border-subtle)] p-1 rounded-xl shadow-md">
                                        <!-- Emoji Reactions Trigger -->
                                        <div class="relative" x-data="{ popover: false }">
                                            <button @click="popover = !popover" class="p-1 rounded-lg text-xs hover:bg-[var(--bg-surface-elevated)]" title="React with emoji">
                                                😊
                                            </button>
                                            <div x-show="popover" @click.outside="popover = false" x-cloak
                                                 class="absolute bottom-full mb-1 z-30 flex items-center gap-1 p-1.5 rounded-2xl glass-panel shadow-2xl border border-[var(--border-medium)]">
                                                @foreach(['❤️', '🔥', '👍', '😂', '🎨', '🚀'] as $em)
                                                    <button wire:click="addReaction({{ $msg->id }}, '{{ $em }}'); popover = false;" class="p-1 hover:scale-125 transition text-base">
                                                        {{ $em }}
                                                    </button>
                                                @endforeach
                                            </div>
                                        </div>

                                        <!-- Reply button -->
                                        <button wire:click="setReply({{ $msg->id }})" class="p-1 rounded-lg text-xs text-[var(--text-dim)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]" title="Reply to message">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"></path>
                                            </svg>
                                        </button>

                                        <!-- Pin message button -->
                                        <button wire:click="togglePinMessage({{ $msg->id }})" class="p-1 rounded-lg text-xs text-[var(--text-dim)] hover:text-amber-400 hover:bg-[var(--bg-surface-elevated)]" title="Pin message">
                                            📌
                                        </button>

                                        <!-- Edit button (if sender) -->
                                        @if($isMine)
                                            <button wire:click="startEditing({{ $msg->id }})" class="p-1 rounded-lg text-xs text-[var(--text-dim)] hover:text-sky-400 hover:bg-[var(--bg-surface-elevated)]" title="Edit message">
                                                ✏️
                                            </button>

                                            <!-- Delete for everyone button (if sender) -->
                                            <button wire:click="deleteMessage({{ $msg->id }})" class="p-1 rounded-lg text-xs text-[var(--text-dim)] hover:text-rose-400 hover:bg-[var(--bg-surface-elevated)]" title="Delete for everyone">
                                                🗑️
                                            </button>
                                        @else
                                            <button wire:click="openReportModal({{ $msg->id }})" class="p-1 rounded-lg text-xs text-[var(--text-dim)] hover:text-rose-400 hover:bg-[var(--bg-surface-elevated)]" title="Report this message">
                                                🚩
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <!-- Feature 3: Shared Media Drawer Panel -->
                        @if($mediaDrawerOpen)
                            <div class="w-72 border-l border-[var(--border-subtle)] bg-[var(--bg-surface)] p-4 space-y-4 overflow-y-auto shrink-0 animate-fade-in">
                                <div class="flex items-center justify-between pb-2 border-b border-[var(--border-subtle)]">
                                    <h4 class="font-bold text-xs uppercase tracking-wider text-[var(--text-dim)]">Shared Media Gallery</h4>
                                    <button wire:click="toggleMediaDrawer" class="p-1 rounded-lg text-[var(--text-dim)] hover:text-[var(--text-main)]">✕</button>
                                </div>

                                @if($sharedPosts->isNotEmpty())
                                    <div class="space-y-2">
                                        <div class="text-[11px] font-bold text-[var(--text-muted)]">Artworks Shared ({{ $sharedPosts->count() }})</div>
                                        <div class="grid grid-cols-2 gap-2">
                                            @foreach($sharedPosts as $sp)
                                                <div class="group relative aspect-square rounded-xl overflow-hidden bg-neutral-900 border border-[var(--border-subtle)] cursor-pointer"
                                                     @click="$dispatch('open-lightbox', { items: [{ url: '{{ $sp->primaryMedia->url }}', type: '{{ $sp->media_type }}' }], startIndex: 0, title: '{{ addslashes($sp->title ?? 'Post') }}', author: '{{ addslashes($sp->user->name) }}', postId: {{ $sp->id }} })">
                                                    <img src="{{ $sp->primaryMedia->thumbnail_url ?? $sp->primaryMedia->url }}" class="w-full h-full object-cover">
                                                    <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition p-1 flex items-end text-[10px] text-white font-bold truncate">
                                                        {{ $sp->title }}
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @else
                                    <div class="p-6 text-center text-xs text-[var(--text-dim)]">No shared artworks yet in this chat.</div>
                                @endif
                            </div>
                        @endif
                    </div>

                    <!-- Feature 1: Attachment Bar Preview (if post or URL attached) -->
                    @if($attachedPostModel || $attachedImageUrl)
                        <div class="px-4 py-2 bg-[var(--bg-surface-elevated)] border-t border-[var(--border-subtle)] flex items-center justify-between">
                            <div class="flex items-center gap-3 text-xs">
                                @if($attachedPostModel)
                                    <img src="{{ $attachedPostModel->primaryMedia->url }}" class="w-9 h-9 rounded-lg object-cover">
                                    <div>
                                        <span class="font-bold accent-text">Attached Artwork:</span>
                                        <p class="text-[var(--text-main)] truncate max-w-sm">{{ $attachedPostModel->title }} by {{ $attachedPostModel->user->name }}</p>
                                    </div>
                                @else
                                    <img src="{{ $attachedImageUrl }}" class="w-9 h-9 rounded-lg object-cover">
                                    <div>
                                        <span class="font-bold text-sky-400">Attached Image URL:</span>
                                        <p class="text-[var(--text-main)] truncate max-w-sm">{{ $attachedImageUrl }}</p>
                                    </div>
                                @endif
                            </div>
                            <button wire:click="detachAttachment" class="p-1 text-[var(--text-dim)] hover:text-rose-400 font-bold" title="Remove attachment">
                                &times;
                            </button>
                        </div>
                    @endif

                    <!-- Reply Preview Bar (Telegram style quote bar above input) -->
                    @if($replyMessage)
                        <div class="px-4 py-2 bg-[var(--bg-surface-elevated)] border-t border-[var(--border-subtle)] flex items-center justify-between">
                            <div class="flex items-center gap-2 text-xs truncate">
                                <div class="w-1 h-6 accent-bg rounded-full"></div>
                                <div>
                                    <span class="font-bold accent-text">Replying to {{ $replyMessage->sender->name }}</span>
                                    <p class="text-[var(--text-dim)] truncate max-w-sm">{{ $replyMessage->text }}</p>
                                </div>
                            </div>
                            <button wire:click="cancelReply" class="p-1 text-[var(--text-dim)] hover:text-[var(--text-main)]">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </button>
                        </div>
                    @endif

                    <!-- Message Input Bar (Telegram style with Feature 1 Artwork Picker Button) -->
                    <div class="p-3 sm:p-4 bg-[var(--bg-surface)] border-t border-[var(--border-subtle)]">
                        <form wire:submit.prevent="sendMessage" class="flex items-center gap-2">
                            <!-- Feature 1: Attachment Button -->
                            <button type="button" 
                                    wire:click="$set('artPickerModalOpen', true)"
                                    class="p-3 rounded-2xl bg-[var(--bg-page)] hover:bg-[var(--bg-surface-elevated)] text-[var(--text-muted)] hover:text-[var(--text-main)] transition shrink-0 border border-[var(--border-subtle)]"
                                    title="Attach Artwork or Image">
                                🖼️
                            </button>

                            <input type="text" 
                                   wire:model="messageText" 
                                   placeholder="Write a message to {{ $otherUser->name }}..." 
                                   class="flex-1 px-4 py-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] focus:border-[var(--accent-primary)] focus:ring-2 focus:ring-[var(--accent-primary)]/20 outline-none text-sm transition text-[var(--text-main)]">
                            
                            <button type="submit" 
                                    class="p-3 rounded-2xl accent-bg text-white shadow-lg hover:opacity-90 transition shrink-0"
                                    title="Send Message (Enter)">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                                </svg>
                            </button>
                        </form>
                    </div>
                @else
                    <!-- No Active Chat Selected -->
                    <div class="flex-1 flex flex-col items-center justify-center p-8 text-center space-y-3">
                        <div class="w-16 h-16 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] flex items-center justify-center text-[var(--text-dim)]">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                            </svg>
                        </div>
                        <h3 class="font-bold text-lg">Select a message</h3>
                        <p class="text-xs text-[var(--text-dim)] max-w-xs">Choose from your existing conversations, or start a new conversation with an artist.</p>
                        <button wire:click="$set('newChatModalOpen', true)" class="px-5 py-2 rounded-2xl accent-bg text-white font-bold text-xs shadow">
                            Start New Chat
                        </button>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <!-- Feature 1: Artwork / Image Picker Modal -->
    <div x-show="$wire.artPickerModalOpen" 
         x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-md"
         @click.self="$wire.set('artPickerModalOpen', false)">
        
        <div class="w-full max-w-lg p-6 rounded-3xl glass-panel shadow-2xl border border-[var(--border-medium)] space-y-4">
            <div class="flex items-center justify-between pb-3 border-b border-[var(--border-subtle)]">
                <h3 class="font-bold text-lg">Attach Artwork or Image</h3>
                <button wire:click="$set('artPickerModalOpen', false)" class="p-1 rounded-full hover:bg-[var(--bg-surface-elevated)]">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <!-- Custom URL Input Option -->
            <div class="space-y-2">
                <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Paste Image URL</label>
                <div class="flex items-center gap-2">
                    <input type="text" wire:model="customImageUrlInput" placeholder="https://..." class="flex-1 px-3.5 py-2 rounded-xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-xs outline-none">
                    <button wire:click="attachCustomImageUrl" class="px-4 py-2 rounded-xl accent-bg text-white font-bold text-xs shadow">Attach</button>
                </div>
            </div>

            <!-- Select from Gallery Artworks Grid -->
            <div class="space-y-2 pt-2 border-t border-[var(--border-subtle)]">
                <label class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Choose from Gallery Artworks</label>
                <div class="grid grid-cols-4 gap-2 max-h-60 overflow-y-auto">
                    @foreach($myArtworks as $art)
                        <button wire:click="attachPost({{ $art->id }})" class="group relative aspect-square rounded-xl overflow-hidden bg-neutral-900 border border-[var(--border-subtle)] hover:border-[var(--accent-primary)] transition cursor-pointer">
                            <img src="{{ $art->primaryMedia->thumbnail_url ?? $art->primaryMedia->url }}" class="w-full h-full object-cover">
                            <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition p-1 flex items-end text-[9px] text-white font-bold truncate">
                                {{ $art->title }}
                            </div>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <!-- New Chat Modal -->
    <div x-show="$wire.newChatModalOpen" 
         x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/75 backdrop-blur-md"
         @click.self="$wire.set('newChatModalOpen', false)">
        
        <div class="w-full max-w-md p-6 rounded-3xl glass-panel shadow-2xl border border-[var(--border-medium)] space-y-4">
            <div class="flex items-center justify-between pb-3 border-b border-[var(--border-subtle)]">
                <h3 class="font-bold text-lg">New Direct Message</h3>
                <button wire:click="$set('newChatModalOpen', false)" class="p-1 rounded-full hover:bg-[var(--bg-surface-elevated)]">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <div class="space-y-2 max-h-72 overflow-y-auto">
                @foreach($availableUsers ?? [] as $u)
                    <button wire:click="startConversationWith({{ $u->id }})" 
                            class="flex items-center gap-3 w-full p-3 rounded-2xl border border-[var(--border-subtle)] hover:bg-[var(--bg-surface-elevated)] transition text-left">
                        <img src="{{ $u->avatar_url }}" class="w-10 h-10 rounded-full object-cover">
                        <div class="flex-1 min-w-0">
                            <div class="font-bold text-sm truncate">{{ $u->name }}</div>
                            <div class="text-xs text-[var(--text-dim)]">{{ '@' . $u->username }}</div>
                        </div>
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    <!-- Report Direct Message Modal -->
    <div x-show="$wire.reportModalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70" @click.self="$wire.set('reportModalOpen', false)">
        <form wire:submit="submitReport" class="w-full max-w-lg rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] p-6 space-y-4">
            <h2 class="text-lg font-black">Report direct message</h2>
            <p class="text-xs text-[var(--text-dim)]">A site moderator will review this message.</p>
            <label class="block text-xs font-bold">Reason
                <select wire:model="reportReason" class="mt-1 w-full rounded-xl bg-[var(--bg-page)] border border-[var(--border-subtle)] p-3 text-sm">
                    <option value="">Choose a reason</option>
                    <option value="spam">Spam</option>
                    <option value="harassment">Harassment or abuse</option>
                    <option value="sexual_content">Unwanted sexual content</option>
                    <option value="scam">Scam or fraud</option>
                    <option value="other">Other policy concern</option>
                </select>
            </label>
            @error('reportReason') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
            <label class="block text-xs font-bold">Details
                <textarea wire:model="reportDetails" rows="3" class="mt-1 w-full rounded-xl bg-[var(--bg-page)] border border-[var(--border-subtle)] p-3 text-sm"></textarea>
            </label>
            @error('reportDetails') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
            <div class="flex justify-end gap-2">
                <button type="button" wire:click="$set('reportModalOpen', false)" class="px-4 py-2 text-xs font-bold">Cancel</button>
                <button class="rounded-xl accent-bg px-5 py-2 text-xs font-bold text-white">Send report</button>
            </div>
        </form>
    </div>
</div>
