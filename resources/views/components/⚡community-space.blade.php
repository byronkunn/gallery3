<?php

use App\Events\CommunityMessageSent;
use App\Events\CommunityMessageUpdated;
use App\Events\CommunityPresenceChanged;
use App\Events\CommunityTyping;
use App\Models\Community;
use App\Models\CommunityChannel;
use App\Models\CommunityChannelCategory;
use App\Models\CommunityChannelRead;
use App\Models\CommunityEmoji;
use App\Models\CommunityMember;
use App\Models\CommunityMessage;
use App\Models\CommunityMessageReaction;
use App\Models\CommunityRole;
use App\Models\Conversation;
use App\Support\ContentReports;
use App\Support\LoungeFormatter;
use App\Support\SpamControls;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

new class extends Component
{
    // ---------------------------------------------------------------- shell
    public string $slug;

    public ?string $channel = null;

    public ?int $activeChannelId = null;

    public bool $memberListVisible = true;

    // ------------------------------------------------------------- composer
    public string $body = '';

    public string $attachmentUrl = '';

    public string $attachmentType = 'image';

    public ?int $replyingTo = null;

    public ?int $editingMessageId = null;

    public string $editingBody = '';

    public bool $emojiPickerOpen = false;

    public string $emojiSearch = '';

    // --------------------------------------------------------------- search
    public string $search = '';

    public bool $searchOpen = false;

    public bool $pinsOpen = false;

    public ?int $profileUserId = null;

    // ---------------------------------------------------- channel management
    public bool $createChannelOpen = false;

    public string $channelName = '';

    public string $channelType = 'text';

    public string $channelTopic = '';

    public ?int $channelCategoryId = null;

    public bool $createCategoryOpen = false;

    public string $categoryName = '';

    public bool $channelSettingsOpen = false;

    public ?int $settingsChannelId = null;

    public string $settingsChannelName = '';

    public string $settingsChannelTopic = '';

    public int $settingsSlowmode = 0;

    public bool $settingsReadOnly = false;

    public bool $settingsNsfw = false;

    public ?int $settingsCategoryId = null;

    // ------------------------------------------------------ server management
    public bool $serverSettingsOpen = false;

    public string $serverTab = 'overview';

    public string $serverName = '';

    public string $serverDescription = '';

    public string $serverIconUrl = '';

    public string $serverBannerUrl = '';

    public string $serverRules = '';

    public string $serverTopics = '';

    public string $serverVisibility = 'public';

    public bool $auditOpen = false;

    public string $inviteUrl = '';

    public string $newEmojiName = '';

    public string $newEmojiUrl = '';

    // ---------------------------------------------------------------- roles
    public string $newRoleName = '';

    public string $newRoleColor = '#8b5cf6';

    public bool $newRoleHoist = false;

    public bool $newRoleMentionable = false;

    public array $rolePermissions = [];

    public ?int $assignUserId = null;

    public ?int $assignRoleId = null;

    // -------------------------------------------------------------- members
    public string $memberSearch = '';

    public ?int $timeoutUserId = null;

    public int $timeoutMinutes = 10;

    public string $timeoutReason = '';

    // ---------------------------------------------------------------- forum
    public string $forumTitle = '';

    public string $forumBody = '';

    public string $forumTags = '';

    public string $forumTagName = '';

    public string $forumSort = 'latest';

    public ?string $forumTagFilter = null;

    public string $replyBody = '';

    public ?int $replyingToForumPost = null;

    // ---------------------------------------------------------------- polls
    public string $pollQuestion = '';

    public string $pollOptions = '';

    // --------------------------------------------------------------- events
    public string $eventTitle = '';

    public string $eventDescription = '';

    public string $eventStartsAt = '';

    // -------------------------------------------------------- user settings
    public bool $userSettingsOpen = false;

    public string $nickname = '';

    public string $statusEmoji = '';

    public string $statusText = '';

    public string $presence = CommunityMember::PRESENCE_ONLINE;

    public string $notifyLevel = 'all';

    // -------------------------------------------------------------- reports
    public string $reportReason = '';

    public string $reportDetails = '';

    public ?int $reportTargetId = null;

    public string $reportTargetType = 'message';

    // Reporting the whole community to site admins (not the in-server queue).
    public bool $siteReportOpen = false;

    // ----------------------------------------------------------------- join
    public string $joinAnswer = '';

    public function mount(string $slug, ?string $channel = null): void
    {
        $this->slug = $slug;
        $this->channel = $channel;

        $community = $this->community();
        $this->activeChannelId = $this->currentChannel($community)->id;

        if (Auth::check() && $member = $community->memberFor(Auth::id())) {
            $this->nickname = (string) $member->nickname;
            $this->statusEmoji = (string) $member->status_emoji;
            $this->statusText = (string) $member->status_text;
            $this->presence = $member->presence ?: CommunityMember::PRESENCE_ONLINE;
            $this->notifyLevel = $member->notify_level ?: 'all';
            $this->markOnline($community);
            $this->markChannelRead();
        }
    }

    // =========================================================== resolution

    private function community(): Community
    {
        return Community::where('slug', $this->slug)->firstOrFail();
    }

    private function currentChannel(Community $community): CommunityChannel
    {
        $query = CommunityChannel::where('community_id', $community->id);

        if ($this->channel) {
            return (clone $query)->where('slug', $this->channel)->firstOrFail();
        }

        return $query->whereNull('parent_id')->orderBy('position')->firstOrFail();
    }

    private function requireMember(Community $community): void
    {
        abort_unless(Auth::check() && $community->isMember(Auth::id()), 403);
    }

    private function requirePermission(Community $community, string $permission): void
    {
        abort_unless(Auth::check() && $community->hasPermission(Auth::user(), $permission), 403);
    }

    private function requireChannelManager(Community $community): void
    {
        abort_unless(
            Auth::check() && (Auth::id() === $community->owner_id || Auth::user()?->isAdmin() || $community->hasPermission(Auth::user(), 'manage_channels')),
            403
        );
    }

    private function messageInCommunity(Community $community, int $messageId): CommunityMessage
    {
        return CommunityMessage::query()
            ->where('community_messages.id', $messageId)
            ->join('community_channels', 'community_channels.id', '=', 'community_messages.community_channel_id')
            ->where('community_channels.community_id', $community->id)
            ->select('community_messages.*')
            ->firstOrFail();
    }

    private function formatterFor(Community $community): LoungeFormatter
    {
        return new LoungeFormatter(
            $community->members()->where('status', 'active')->with('user')->get(),
            $community->roles()->get(),
            $community->emoji()->get(),
            Auth::id(),
        );
    }

    // ============================================================ audit log

    private function logAction(Community $community, string $action, ?int $targetUserId = null, array $details = [], ?int $channelId = null, ?string $targetName = null): void
    {
        DB::table('admin_audit_logs')->insert([
            'actor_id' => Auth::id(), 'action' => 'community.'.$action, 'target_type' => 'community', 'target_id' => $community->id,
            'reason' => $targetUserId ? 'Target user #'.$targetUserId : null,
            'details' => json_encode(['target_user_id' => $targetUserId] + $details), 'ip_address' => request()->ip(),
            'user_agent' => Str::limit((string) request()->userAgent(), 500, ''), 'created_at' => now(),
        ]);
        DB::table('community_action_logs')->insert([
            'community_id' => $community->id, 'actor_id' => Auth::id(), 'target_user_id' => $targetUserId,
            'community_channel_id' => $channelId, 'target_name' => $targetName,
            'action' => $action, 'details' => json_encode($details), 'created_at' => now(),
        ]);
    }

    // =========================================================== presence etc

    private function markOnline(Community $community): void
    {
        if (! Auth::check() || ! ($member = $community->memberFor(Auth::id()))) {
            return;
        }

        if (in_array($member->presence, [null, '', CommunityMember::PRESENCE_OFFLINE], true)) {
            $member->presence = CommunityMember::PRESENCE_ONLINE;
        }
        $member->last_seen_at = now();
        $member->save();
    }

    public function heartbeat(): void
    {
        if (! Auth::check()) {
            return;
        }

        $community = $this->community();
        if ($member = $community->memberFor(Auth::id())) {
            $member->last_seen_at = now();
            $member->save();
        }

        $this->markChannelRead();
    }

    public function markChannelRead(): void
    {
        if (! Auth::check()) {
            return;
        }

        $community = $this->community();
        $channel = $this->currentChannel($community);
        $latestId = CommunityMessage::where('community_channel_id', $channel->id)->where('is_hidden', false)->max('id');

        CommunityChannelRead::updateOrCreate(
            ['community_channel_id' => $channel->id, 'user_id' => Auth::id()],
            ['community_id' => $community->id, 'last_read_message_id' => $latestId ?? 0, 'mention_count' => 0],
        );
    }

    public function setPresence(string $presence): void
    {
        abort_unless(in_array($presence, CommunityMember::PRESENCE_STATES, true), 422);
        $community = $this->community();
        $this->requireMember($community);

        $member = $community->memberFor(Auth::id());
        $member->presence = $presence;
        $member->last_seen_at = now();
        $member->save();

        $this->presence = $presence;
        CommunityPresenceChanged::dispatch($community->id, Auth::id(), $member->presenceState());
    }

    public function typing(): void
    {
        if (! Auth::check()) {
            return;
        }

        $community = $this->community();
        $member = $community->memberFor(Auth::id());
        if (! $member || ! $community->isMember(Auth::id())) {
            return;
        }

        CommunityTyping::dispatch($community->id, $this->activeChannelId ?? 0, null, Auth::id(), $member->displayName());
    }

    public function toggleMemberList(): void
    {
        $this->memberListVisible = ! $this->memberListVisible;
    }

    public function toggleMute(): void
    {
        $community = $this->community();
        $this->requireMember($community);

        $member = $community->memberFor(Auth::id());
        $member->is_muted = ! $member->is_muted;
        $member->save();

        $this->dispatch('notify', $member->is_muted ? 'Server muted.' : 'Server unmuted.');
    }

    // ============================================================ messaging

    private function slowmodeRemaining(CommunityChannel $channel): int
    {
        if ($channel->slowmode_seconds <= 0) {
            return 0;
        }

        $last = CommunityMessage::where('community_channel_id', $channel->id)
            ->where('user_id', Auth::id())
            ->latest('id')
            ->value('created_at');

        if (! $last) {
            return 0;
        }

        $next = Carbon::parse($last)->addSeconds($channel->slowmode_seconds);
        if ($next->isFuture()) {
            return max(1, (int) ceil(now()->diffInSeconds($next)));
        }

        return 0;
    }

    private function assertNotTimedOut(Community $community): void
    {
        abort_if($community->memberFor(Auth::id())?->isTimedOut(), 403, 'You are timed out in this community.');
    }

    /**
     * @return array<string, mixed>
     */
    private function broadcastPayload(Community $community, CommunityChannel $channel, CommunityMessage $message): array
    {
        $member = $community->memberFor($message->user_id);

        return [
            'id' => $message->id,
            'channel_id' => $channel->id,
            'thread_id' => $message->thread_id,
            'user_id' => $message->user_id,
            'display_name' => $member?->displayName() ?? 'Someone',
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    private function afterMessageCreated(Community $community, CommunityChannel $channel, CommunityMessage $message): void
    {
        CommunityChannel::whereKey($channel->id)->increment('message_count');
        CommunityChannel::whereKey($channel->id)->update(['last_message_at' => now()]);

        if ($member = $community->memberFor(Auth::id())) {
            $member->increment('message_count');
        }

        $this->markChannelRead();
        CommunityMessageSent::dispatch($community->id, $channel->id, $message->thread_id, $this->broadcastPayload($community, $channel, $message));
    }

    public function sendMessage(): void
    {
        $community = $this->community();
        $this->requireMember($community);
        $channel = $this->currentChannel($community);

        abort_unless($channel->isPostable(), 403);
        abort_if($channel->isForum() || $channel->isEvents(), 403);
        $this->assertNotTimedOut($community);

        $raw = trim($this->body);
        if ($raw !== '' && str_starts_with($raw, '/')) {
            if ($this->handleSlashCommand($raw, $community)) {
                $this->reset('body');

                return;
            }
            $raw = trim($this->body);
        }

        if ($remaining = $this->slowmodeRemaining($channel)) {
            $this->addError('body', 'Slowmode is enabled. Try again in '.$remaining.'s.');

            return;
        }

        $data = $this->validate([
            'body' => ['required_without:attachmentUrl', 'nullable', 'string', 'max:4000'],
            'attachmentUrl' => ['nullable', 'url', 'max:2048', 'regex:/^https:\/\//i'],
            'attachmentType' => ['required', Rule::in(['image', 'gif'])],
        ]);

        SpamControls::enforce('messages', 20, 60, ($data['body'] ?? '').' '.($data['attachmentUrl'] ?? ''));

        $body = trim($data['body'] ?? '');
        $mentions = $this->formatterFor($community)->extractMentions($body);

        $replyTo = null;
        if ($this->replyingTo) {
            $replyTo = CommunityMessage::whereKey($this->replyingTo)
                ->where('community_channel_id', $channel->id)
                ->exists() ? $this->replyingTo : null;
        }

        $message = CommunityMessage::create([
            'community_channel_id' => $channel->id,
            'thread_id' => $channel->isThread() ? $channel->id : null,
            'user_id' => Auth::id(),
            'body' => $body,
            'attachment_url' => $data['attachmentUrl'] ?: null,
            'attachment_type' => filled($data['attachmentUrl']) ? $data['attachmentType'] : null,
            'reply_to_id' => $replyTo,
            'mention_user_ids' => $mentions['user_ids'],
            'mention_role_ids' => $mentions['role_ids'],
            'mention_everyone' => $mentions['everyone'],
        ]);

        $this->afterMessageCreated($community, $channel, $message);
        $this->reset('body', 'attachmentUrl', 'replyingTo', 'attachmentType');
        $this->attachmentType = 'image';
        $this->dispatch('lounge-message-sent');
    }

    private function handleSlashCommand(string $raw, Community $community): bool
    {
        [$command, $rest] = array_pad(preg_split('/\s+/', $raw, 2) ?: [], 2, '');
        $rest = trim((string) $rest);

        switch (strtolower((string) $command)) {
            case '/shrug':
                $this->body = trim($rest.' ¯\_(ツ)_/¯');

                return false;
            case '/tableflip':
                $this->body = trim($rest.' (╯°□°)╯︵ ┻━┻');

                return false;
            case '/unflip':
                $this->body = trim($rest.' ┬─┬ノ( º _ ºノ)');

                return false;
            case '/me':
                $this->body = '*'.$rest.'*';

                return false;
            case '/spoiler':
                $this->body = '||'.$rest.'||';

                return false;
            case '/nick':
                if ($rest === '') {
                    $this->addError('body', 'Usage: /nick <name>');

                    return true;
                }
                $this->nickname = mb_substr($rest, 0, 40);
                $this->saveUserSettings();

                return true;
            case '/status':
                $this->statusText = mb_substr($rest, 0, 140);
                $this->saveUserSettings();

                return true;
            case '/invite':
                $this->openInvites();

                return true;
            case '/help':
                $this->dispatch('notify', 'Commands: /shrug /tableflip /unflip /me /spoiler /nick /status /invite');

                return true;
            default:
                $this->addError('body', 'Unknown command. Try /help.');

                return true;
        }
    }

    public function setReply(int $messageId): void
    {
        $this->replyingTo = $messageId;
        $this->editingMessageId = null;
    }

    public function cancelReply(): void
    {
        $this->replyingTo = null;
    }

    public function startEditing(int $messageId): void
    {
        $community = $this->community();
        $message = $this->messageInCommunity($community, $messageId);
        abort_unless($message->user_id === Auth::id(), 403);

        $this->editingMessageId = $message->id;
        $this->editingBody = (string) $message->body;
        $this->replyingTo = null;
    }

    public function cancelEditing(): void
    {
        $this->editingMessageId = null;
        $this->editingBody = '';
    }

    public function saveEdit(): void
    {
        $community = $this->community();
        $this->requireMember($community);
        abort_unless($this->editingMessageId, 404);

        $message = $this->messageInCommunity($community, $this->editingMessageId);
        abort_unless($message->user_id === Auth::id(), 403);

        $data = $this->validate(['editingBody' => ['required', 'string', 'max:4000']]);
        $mentions = $this->formatterFor($community)->extractMentions($data['editingBody']);

        $message->update([
            'body' => trim($data['editingBody']),
            'edited_at' => now(),
            'mention_user_ids' => $mentions['user_ids'],
            'mention_role_ids' => $mentions['role_ids'],
            'mention_everyone' => $mentions['everyone'],
        ]);

        $this->cancelEditing();
        CommunityMessageUpdated::dispatch($community->id, $message->community_channel_id, $message->thread_id, $message->id, 'edit');
    }

    public function deleteMessage(int $messageId): void
    {
        $community = $this->community();
        $this->requireMember($community);
        $message = $this->messageInCommunity($community, $messageId);

        $canDeleteOthers = $community->hasPermission(Auth::user(), 'delete_messages');
        abort_unless($message->user_id === Auth::id() || $canDeleteOthers, 403);

        $message->update(['is_hidden' => true]);
        $this->logAction($community, 'message_deleted', $message->user_id, ['message_id' => $message->id], $message->community_channel_id);
        CommunityMessageUpdated::dispatch($community->id, $message->community_channel_id, $message->thread_id, $message->id, 'delete');
    }

    public function togglePin(int $messageId): void
    {
        $community = $this->community();
        $this->requirePermission($community, 'delete_messages');
        $message = $this->messageInCommunity($community, $messageId);

        $pinned = ! $message->is_pinned;
        $message->update([
            'is_pinned' => $pinned,
            'pinned_at' => $pinned ? now() : null,
            'pinned_by' => $pinned ? Auth::id() : null,
        ]);

        $this->logAction($community, $pinned ? 'message_pinned' : 'message_unpinned', null, ['message_id' => $message->id], $message->community_channel_id);
        CommunityMessageUpdated::dispatch($community->id, $message->community_channel_id, $message->thread_id, $message->id, $pinned ? 'pin' : 'unpin');
    }

    public function toggleReaction(int $messageId, string $emoji): void
    {
        $community = $this->community();
        $this->requireMember($community);

        $emoji = trim($emoji);
        abort_if($emoji === '' || mb_strlen($emoji) > 32, 422);

        $message = $this->messageInCommunity($community, $messageId);

        $existing = CommunityMessageReaction::where('community_message_id', $message->id)
            ->where('user_id', Auth::id())
            ->where('emoji', $emoji)
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            CommunityMessageReaction::create([
                'community_message_id' => $message->id,
                'user_id' => Auth::id(),
                'emoji' => $emoji,
            ]);
        }

        CommunityMessageUpdated::dispatch($community->id, $message->community_channel_id, $message->thread_id, $message->id, 'reaction');
    }

    // ================================================================ threads

    public function createThread(int $messageId): void
    {
        $community = $this->community();
        $this->requireMember($community);
        $channel = $this->currentChannel($community);

        abort_unless($channel->isText() && ! $channel->isThread() && $channel->isPostable(), 403);

        $message = $this->messageInCommunity($community, $messageId);
        abort_unless($message->community_channel_id === $channel->id, 404);

        $existing = CommunityChannel::where('parent_message_id', $message->id)->first();
        if ($existing) {
            $this->redirect(route('lounge.community', ['slug' => $community->slug, 'channel' => $existing->slug]));

            return;
        }

        $base = Str::slug(Str::limit($message->body ?: 'thread', 60, ''));
        $base = $base !== '' ? $base : 'thread';
        $slug = $base;
        $suffix = 2;
        while (CommunityChannel::where('community_id', $community->id)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        $thread = CommunityChannel::create([
            'community_id' => $community->id,
            'parent_id' => $channel->id,
            'parent_message_id' => $message->id,
            'owner_id' => Auth::id(),
            'category_id' => $channel->category_id,
            'name' => Str::limit($message->body ?: 'Thread', 60, ''),
            'slug' => $slug,
            'type' => CommunityChannel::TYPE_THREAD,
            'position' => 0,
            'last_message_at' => now(),
        ]);

        $this->logAction($community, 'thread_created', null, ['thread' => $thread->slug], $channel->id, $thread->name);
        $this->redirect(route('lounge.community', ['slug' => $community->slug, 'channel' => $thread->slug]));
    }

    public function archiveThread(int $threadId): void
    {
        $community = $this->community();
        $this->requireChannelManager($community);
        $thread = CommunityChannel::where('community_id', $community->id)->whereNotNull('parent_id')->findOrFail($threadId);
        $thread->update(['thread_archived' => ! $thread->thread_archived]);
        $this->logAction($community, $thread->thread_archived ? 'thread_archived' : 'thread_unarchived', null, [], $thread->id, $thread->name);
    }

    public function lockThread(int $threadId): void
    {
        $community = $this->community();
        $this->requireChannelManager($community);
        $thread = CommunityChannel::where('community_id', $community->id)->whereNotNull('parent_id')->findOrFail($threadId);
        $thread->update(['thread_locked' => ! $thread->thread_locked]);
        $this->logAction($community, $thread->thread_locked ? 'thread_locked' : 'thread_unlocked', null, [], $thread->id, $thread->name);
    }

    // ============================================================== channels

    public function createChannel(): void
    {
        $community = $this->community();
        $this->requirePermission($community, 'manage_channels');

        $data = $this->validate([
            'channelName' => ['required', 'string', 'max:80'],
            'channelType' => ['required', Rule::in(['text', 'forum', 'events'])],
            'channelTopic' => ['nullable', 'string', 'max:300'],
            'channelCategoryId' => ['nullable', 'integer'],
        ]);

        $base = Str::slug($data['channelName']);
        if ($base === '') {
            $this->addError('channelName', 'Use letters or numbers in the channel name.');

            return;
        }

        $slug = $base;
        $suffix = 2;
        while (CommunityChannel::where('community_id', $community->id)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        $categoryId = $this->validCategoryId($community, $data['channelCategoryId']);

        $channel = CommunityChannel::create([
            'community_id' => $community->id,
            'name' => $data['channelName'],
            'slug' => $slug,
            'type' => $data['channelType'],
            'description' => $data['channelTopic'] ?? null,
            'position' => (int) CommunityChannel::where('community_id', $community->id)->whereNull('parent_id')->max('position') + 1,
            'category_id' => $categoryId,
        ]);

        $this->logAction($community, 'channel_created', null, ['name' => $channel->name, 'type' => $channel->type], $channel->id, $channel->name);
        $this->reset('channelName', 'channelTopic', 'channelCategoryId', 'createChannelOpen');
        $this->redirect(route('lounge.community', ['slug' => $community->slug, 'channel' => $channel->slug]));
    }

    public function createCategory(): void
    {
        $community = $this->community();
        $this->requirePermission($community, 'manage_channels');
        $data = $this->validate(['categoryName' => ['required', 'string', 'max:60']]);

        $category = CommunityChannelCategory::create([
            'community_id' => $community->id,
            'name' => $data['categoryName'],
            'position' => (int) $community->categoryGroups()->max('position') + 1,
        ]);

        $this->logAction($community, 'category_created', null, ['name' => $category->name]);
        $this->reset('categoryName', 'createCategoryOpen');
    }

    public function deleteCategory(int $categoryId): void
    {
        $community = $this->community();
        $this->requirePermission($community, 'manage_channels');
        $category = CommunityChannelCategory::where('community_id', $community->id)->findOrFail($categoryId);

        CommunityChannel::where('category_id', $category->id)->update(['category_id' => null]);
        $this->logAction($community, 'category_deleted', null, ['name' => $category->name]);
        $category->delete();
    }

    public function openChannelSettings(int $channelId): void
    {
        $community = $this->community();
        $this->requirePermission($community, 'manage_channels');
        $channel = CommunityChannel::where('community_id', $community->id)->findOrFail($channelId);

        $this->settingsChannelId = $channel->id;
        $this->settingsChannelName = $channel->name;
        $this->settingsChannelTopic = (string) $channel->description;
        $this->settingsSlowmode = (int) $channel->slowmode_seconds;
        $this->settingsReadOnly = (bool) $channel->is_read_only;
        $this->settingsNsfw = (bool) $channel->is_nsfw;
        $this->settingsCategoryId = $channel->category_id;
        $this->channelSettingsOpen = true;
    }

    public function saveChannelSettings(): void
    {
        $community = $this->community();
        $this->requirePermission($community, 'manage_channels');
        abort_unless($this->settingsChannelId, 404);

        $channel = CommunityChannel::where('community_id', $community->id)->findOrFail($this->settingsChannelId);

        $data = $this->validate([
            'settingsChannelName' => ['required', 'string', 'max:80'],
            'settingsChannelTopic' => ['nullable', 'string', 'max:300'],
            'settingsSlowmode' => ['required', 'integer', 'min:0', 'max:21600'],
            'settingsReadOnly' => ['boolean'],
            'settingsNsfw' => ['boolean'],
            'settingsCategoryId' => ['nullable', 'integer'],
        ]);

        $slug = $channel->slug;
        if ($data['settingsChannelName'] !== $channel->name && ! $channel->isThread()) {
            $base = Str::slug($data['settingsChannelName']) ?: $channel->slug;
            $slug = $base;
            $suffix = 2;
            while (CommunityChannel::where('community_id', $community->id)->where('slug', $slug)->where('id', '!=', $channel->id)->exists()) {
                $slug = $base.'-'.$suffix++;
            }
        }

        $channel->update([
            'name' => $data['settingsChannelName'],
            'slug' => $slug,
            'description' => $data['settingsChannelTopic'] ?? null,
            'slowmode_seconds' => $data['settingsSlowmode'],
            'is_read_only' => $data['settingsReadOnly'],
            'is_nsfw' => $data['settingsNsfw'],
            'category_id' => $this->validCategoryId($community, $data['settingsCategoryId']),
        ]);

        $this->logAction($community, 'channel_updated', null, ['name' => $channel->name], $channel->id, $channel->name);
        $this->channelSettingsOpen = false;
        $this->redirect(route('lounge.community', ['slug' => $community->slug, 'channel' => $slug]), navigate: false);
    }

    public function deleteChannel(int $channelId): void
    {
        $community = $this->community();
        $this->requirePermission($community, 'manage_channels');

        $channel = CommunityChannel::where('community_id', $community->id)->findOrFail($channelId);
        $remaining = CommunityChannel::where('community_id', $community->id)
            ->whereNull('parent_id')
            ->where('id', '!=', $channel->id)
            ->orderBy('position')
            ->first();

        abort_if(! $remaining, 422, 'A community needs at least one channel.');

        $this->logAction($community, 'channel_deleted', null, ['name' => $channel->name]);
        $channel->delete();
        $this->channelSettingsOpen = false;
        $this->redirect(route('lounge.community', ['slug' => $community->slug, 'channel' => $remaining->slug]));
    }

    private function validCategoryId(Community $community, ?int $categoryId): ?int
    {
        if (! $categoryId) {
            return null;
        }

        return CommunityChannelCategory::where('community_id', $community->id)->whereKey($categoryId)->exists() ? $categoryId : null;
    }

    // ============================================================ membership

    public function joinCommunity(): void
    {
        abort_unless(Auth::check(), 401);
        $community = $this->community();
        abort_if($community->visibility === 'invite', 403);

        $this->validate(['joinAnswer' => ['nullable', 'string', 'max:1000']]);

        $existing = DB::table('community_members')->where('community_id', $community->id)->where('user_id', Auth::id())->first();
        abort_if($existing?->status === 'banned', 403);

        $status = $community->visibility === 'approval' ? 'pending' : 'active';

        if (! $existing) {
            DB::table('community_members')->insert([
                'community_id' => $community->id, 'user_id' => Auth::id(), 'status' => $status,
                'onboarding_answers' => json_encode(array_filter([$this->joinAnswer])),
                'joined_at' => $status === 'active' ? now() : null,
                'presence' => $status === 'active' ? CommunityMember::PRESENCE_ONLINE : CommunityMember::PRESENCE_OFFLINE,
                'last_seen_at' => $status === 'active' ? now() : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($status === 'active') {
                $community->increment('member_count');
            }
        } elseif ($existing->status !== 'active') {
            DB::table('community_members')->where('id', $existing->id)->update([
                'status' => $status,
                'onboarding_answers' => json_encode(array_filter([$this->joinAnswer])),
                'joined_at' => $status === 'active' ? now() : null,
                'updated_at' => now(),
            ]);
            if ($status === 'active') {
                $community->increment('member_count');
            }
        } else {
            $status = 'active';
        }

        $this->dispatch('notify', $status === 'pending' ? 'Your request is waiting for approval.' : 'You joined the community.');
    }

    public function leaveCommunity(): void
    {
        $community = $this->community();
        abort_if(Auth::id() === $community->owner_id, 403, 'Transfer ownership before leaving.');

        $member = DB::table('community_members')->where('community_id', $community->id)->where('user_id', Auth::id())->first();
        abort_unless($member, 404);

        DB::table('community_members')->where('id', $member->id)->delete();
        if ($member->status === 'active') {
            $community->decrement('member_count');
        }

        $this->redirect(route('lounge.explore'));
    }

    /**
     * Owners may permanently remove a community they created. Every related
     * table (channels, messages, members, roles, invites, reports, logs, ...)
     * cascades from the communities row.
     */
    public function deleteCommunity(): void
    {
        $community = $this->community();
        abort_unless(Auth::check() && Auth::id() === $community->owner_id, 403);

        $name = $community->name;

        DB::transaction(function () use ($community): void {
            $community->delete();
        });

        session()->flash('success', $name.' has been deleted.');

        $this->redirect(route('lounge.explore'));
    }

    public function reviewMember(int $userId, string $decision): void
    {
        $community = $this->community();
        $this->requirePermission($community, 'manage_members');
        abort_unless(in_array($decision, ['approve', 'remove', 'ban', 'unban'], true), 422);

        $member = DB::table('community_members')->where('community_id', $community->id)->where('user_id', $userId)->first();
        abort_unless($member, 404);
        abort_if($userId === $community->owner_id, 403);

        if ($decision === 'remove') {
            DB::table('community_members')->where('id', $member->id)->delete();
            if ($member->status === 'active') {
                $community->decrement('member_count');
            }
        } else {
            $status = ['approve' => 'active', 'ban' => 'banned', 'unban' => 'pending'][$decision];
            DB::table('community_members')->where('id', $member->id)->update([
                'status' => $status,
                'joined_at' => $status === 'active' ? now() : null,
                'presence' => $status === 'active' ? CommunityMember::PRESENCE_ONLINE : CommunityMember::PRESENCE_OFFLINE,
                'updated_at' => now(),
            ]);
            if ($member->status !== 'active' && $status === 'active') {
                $community->increment('member_count');
            }
            if ($member->status === 'active' && $status !== 'active') {
                $community->decrement('member_count');
            }
        }

        $this->logAction($community, 'member_'.$decision, $userId);
    }

    public function timeoutMember(int $userId): void
    {
        $community = $this->community();
        $this->requirePermission($community, 'manage_members');
        abort_if($userId === $community->owner_id, 403);

        $data = $this->validate([
            'timeoutMinutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'timeoutReason' => ['nullable', 'string', 'max:160'],
        ]);

        $member = DB::table('community_members')->where('community_id', $community->id)->where('user_id', $userId)->first();
        abort_unless($member, 404);

        DB::table('community_members')->where('id', $member->id)->update([
            'timeout_until' => now()->addMinutes($data['timeoutMinutes']),
            'timeout_reason' => $data['timeoutReason'] ?: null,
            'updated_at' => now(),
        ]);

        $this->logAction($community, 'member_timeout', $userId, ['minutes' => $data['timeoutMinutes']]);
        $this->reset('timeoutUserId', 'timeoutReason');
        $this->dispatch('notify', 'Member timed out.');
    }

    // ============================================================ server mgmt

    public function openServerSettings(): void
    {
        $community = $this->community();
        $canOpenAny = $this->canManageServer($community)
            || $community->hasPermission(Auth::user(), 'manage_members')
            || $community->hasPermission(Auth::user(), 'manage_channels')
            || $community->hasPermission(Auth::user(), 'manage_tags')
            || $community->hasPermission(Auth::user(), 'review_reports');
        abort_unless(Auth::check() && $canOpenAny, 403);

        $this->serverName = $community->name;
        $this->serverDescription = (string) $community->description;
        $this->serverIconUrl = (string) $community->icon_url;
        $this->serverBannerUrl = (string) $community->banner_url;
        $this->serverRules = (string) $community->rules;
        $this->serverTopics = implode(', ', $community->topics ?? []);
        $this->serverVisibility = $community->visibility;
        $this->serverTab = $this->firstAvailableServerTab($community);
        $this->serverSettingsOpen = true;
    }

    private function canManageServer(Community $community): bool
    {
        return Auth::check() && (Auth::id() === $community->owner_id || (bool) Auth::user()?->isAdmin());
    }

    private function firstAvailableServerTab(Community $community): string
    {
        $tabs = ['overview', 'roles', 'emoji', 'members', 'invites', 'audit', 'moderation', 'danger'];

        foreach ($tabs as $tab) {
            $allowed = match ($tab) {
                'overview', 'roles' => $this->canManageServer($community),
                'emoji', 'audit' => $this->canManageServer($community) || $community->hasPermission(Auth::user(), 'manage_channels'),
                'members', 'invites' => $community->hasPermission(Auth::user(), 'manage_members'),
                'moderation' => $community->hasPermission(Auth::user(), 'review_reports') || $community->hasPermission(Auth::user(), 'resolve_reports'),
                default => true,
            };

            if ($allowed) {
                return $tab;
            }
        }

        return 'danger';
    }

    public function saveServerSettings(): void
    {
        $community = $this->community();
        abort_unless($this->canManageServer($community), 403);

        $data = $this->validate([
            'serverName' => ['required', 'string', 'max:80'],
            'serverDescription' => ['nullable', 'string', 'max:1200'],
            'serverIconUrl' => ['nullable', 'url', 'max:2048'],
            'serverBannerUrl' => ['nullable', 'url', 'max:2048'],
            'serverRules' => ['nullable', 'string', 'max:3000'],
            'serverTopics' => ['nullable', 'string', 'max:300'],
            'serverVisibility' => ['required', Rule::in(['public', 'approval', 'invite'])],
        ]);

        $topics = collect(explode(',', $data['serverTopics'] ?? ''))
            ->map(fn (string $topic): string => Str::of($topic)->trim()->lower()->replace(' ', '-')->toString())
            ->filter()->unique()->take(8)->values()->all();

        $community->update([
            'name' => $data['serverName'],
            'description' => $data['serverDescription'] ?? null,
            'icon_url' => $data['serverIconUrl'] ?: null,
            'banner_url' => $data['serverBannerUrl'] ?: null,
            'rules' => $data['serverRules'] ?? null,
            'topics' => $topics,
            'visibility' => $data['serverVisibility'],
        ]);

        $this->logAction($community, 'server_updated', null, ['name' => $community->name]);
        $this->serverSettingsOpen = false;
    }

    public function openInvites(): void
    {
        $community = $this->community();
        $this->requirePermission($community, 'manage_members');
        $this->serverTab = 'invites';
        $this->serverSettingsOpen = true;
    }

    public function createInvite(): void
    {
        $community = $this->community();
        $this->requirePermission($community, 'manage_members');

        $code = Str::random(40);
        DB::table('community_invites')->insert([
            'community_id' => $community->id, 'created_by' => Auth::id(), 'code' => $code,
            'expires_at' => now()->addDays(30), 'max_uses' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->logAction($community, 'invite_created', null, ['code' => Str::limit($code, 8, '').'…']);
        $this->inviteUrl = route('lounge.invite', $code);
        $this->serverTab = 'invites';
        $this->serverSettingsOpen = true;
    }

    public function revokeInvite(int $inviteId): void
    {
        $community = $this->community();
        $this->requirePermission($community, 'manage_members');

        DB::table('community_invites')->where('community_id', $community->id)->where('id', $inviteId)->delete();
        $this->logAction($community, 'invite_revoked', null, ['invite_id' => $inviteId]);
    }

    public function createEmoji(): void
    {
        $community = $this->community();
        $this->requireChannelManager($community);

        $data = $this->validate([
            'newEmojiName' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_]+$/'],
            'newEmojiUrl' => ['required', 'url', 'max:2048'],
        ]);

        CommunityEmoji::updateOrCreate(
            ['community_id' => $community->id, 'name' => $data['newEmojiName']],
            ['image_url' => $data['newEmojiUrl'], 'created_by' => Auth::id()],
        );

        $this->logAction($community, 'emoji_created', null, ['name' => $data['newEmojiName']]);
        $this->reset('newEmojiName', 'newEmojiUrl');
    }

    public function deleteEmoji(int $emojiId): void
    {
        $community = $this->community();
        $this->requireChannelManager($community);

        $emoji = CommunityEmoji::where('community_id', $community->id)->findOrFail($emojiId);
        $this->logAction($community, 'emoji_deleted', null, ['name' => $emoji->name]);
        $emoji->delete();
    }

    public function createModeratorRole(): void
    {
        $community = $this->community();
        abort_unless(Auth::id() === $community->owner_id || Auth::user()?->isAdmin(), 403);

        $data = $this->validate([
            'newRoleName' => ['required', 'string', 'max:60'],
            'newRoleColor' => ['nullable', 'string', 'max:7'],
            'newRoleHoist' => ['boolean'],
            'newRoleMentionable' => ['boolean'],
        ]);

        $permissions = array_values(array_intersect($this->rolePermissions, Community::PERMISSIONS));

        $role = CommunityRole::create([
            'community_id' => $community->id,
            'name' => trim($data['newRoleName']),
            'permissions' => $permissions,
            'color' => $data['newRoleColor'] ?: null,
            'position' => (int) $community->roles()->max('position') + 1,
            'hoist' => $data['newRoleHoist'],
            'mentionable' => $data['newRoleMentionable'],
        ]);

        $this->logAction($community, 'moderator_role_created', null, ['name' => $role->name, 'permissions' => $permissions]);
        $this->reset('newRoleName', 'rolePermissions', 'newRoleColor', 'newRoleHoist', 'newRoleMentionable');
        $this->newRoleColor = '#8b5cf6';
    }

    public function deleteRole(int $roleId): void
    {
        $community = $this->community();
        abort_unless(Auth::id() === $community->owner_id || Auth::user()?->isAdmin(), 403);

        $role = CommunityRole::where('community_id', $community->id)->findOrFail($roleId);
        DB::table('community_members')->where('community_role_id', $role->id)->update(['community_role_id' => null]);
        $this->logAction($community, 'moderator_role_deleted', null, ['name' => $role->name]);
        $role->delete();
    }

    public function assignModeratorRole(): void
    {
        $community = $this->community();
        abort_unless(Auth::id() === $community->owner_id || Auth::user()?->isAdmin(), 403);

        $data = $this->validate(['assignUserId' => ['required', 'integer'], 'assignRoleId' => ['required', 'integer']]);

        $member = DB::table('community_members')->where('community_id', $community->id)->where('user_id', $data['assignUserId'])->where('status', 'active')->first();
        abort_unless($member && CommunityRole::where('community_id', $community->id)->whereKey($data['assignRoleId'])->exists(), 404);

        DB::table('community_members')->where('id', $member->id)->update(['community_role_id' => $data['assignRoleId'], 'updated_at' => now()]);
        $this->logAction($community, 'moderator_role_assigned', (int) $data['assignUserId']);
        $this->dispatch('notify', 'Role assigned.');
    }

    // ========================================================= user settings

    public function openUserSettings(): void
    {
        $community = $this->community();
        $member = $community->memberFor(Auth::id());
        if ($member) {
            $this->nickname = (string) $member->nickname;
            $this->statusEmoji = (string) $member->status_emoji;
            $this->statusText = (string) $member->status_text;
            $this->presence = $member->presence ?: CommunityMember::PRESENCE_ONLINE;
            $this->notifyLevel = $member->notify_level ?: 'all';
        }
        $this->userSettingsOpen = true;
    }

    public function saveUserSettings(): void
    {
        $community = $this->community();
        $this->requireMember($community);

        $data = $this->validate([
            'nickname' => ['nullable', 'string', 'max:40'],
            'statusEmoji' => ['nullable', 'string', 'max:16'],
            'statusText' => ['nullable', 'string', 'max:140'],
            'presence' => ['required', Rule::in(CommunityMember::PRESENCE_STATES)],
            'notifyLevel' => ['required', Rule::in(['all', 'mentions', 'none'])],
        ]);

        $member = $community->memberFor(Auth::id());
        $member->update([
            'nickname' => $data['nickname'] ?: null,
            'status_emoji' => $data['statusEmoji'] ?: null,
            'status_text' => $data['statusText'] ?: null,
            'presence' => $data['presence'],
            'notify_level' => $data['notifyLevel'],
            'last_seen_at' => now(),
        ]);

        $this->userSettingsOpen = false;
        CommunityPresenceChanged::dispatch($community->id, Auth::id(), $member->presenceState());
        $this->dispatch('notify', 'Profile updated.');
    }

    // ================================================================== forum

    public function createForumPost(): void
    {
        $community = $this->community();
        $this->requireMember($community);
        $channel = $this->currentChannel($community);

        abort_unless($channel->isForum() && $channel->isPostable(), 403);
        $this->assertNotTimedOut($community);

        $data = $this->validate([
            'forumTitle' => ['required', 'string', 'max:160'],
            'forumBody' => ['nullable', 'string', 'max:5000'],
            'forumTags' => ['nullable', 'string', 'max:300'],
        ]);
        SpamControls::enforce('posts', 12, 3600, $data['forumTitle'].' '.($data['forumBody'] ?? ''));

        $postId = DB::table('community_forum_posts')->insertGetId([
            'community_channel_id' => $channel->id, 'user_id' => Auth::id(),
            'title' => $data['forumTitle'], 'body' => $data['forumBody'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (collect(explode(',', $data['forumTags'] ?? ''))->map(fn ($tag) => Str::slug(trim($tag)))->filter()->unique()->take(5) as $tagSlug) {
            $tagId = DB::table('community_forum_tags')->where('community_id', $community->id)->where('slug', $tagSlug)->value('id');
            if ($tagId) {
                DB::table('community_forum_post_tag')->insertOrIgnore([
                    'community_forum_post_id' => $postId, 'community_forum_tag_id' => $tagId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        CommunityChannel::whereKey($channel->id)->increment('message_count');
        $this->reset('forumTitle', 'forumBody', 'forumTags');
    }

    public function createForumTag(): void
    {
        $community = $this->community();
        $this->requirePermission($community, 'manage_tags');
        $this->validate(['forumTagName' => ['required', 'string', 'max:40']]);

        $slug = Str::slug($this->forumTagName);
        abort_if($slug === '', 422);

        DB::table('community_forum_tags')->insertOrIgnore([
            'community_id' => $community->id, 'name' => trim($this->forumTagName), 'slug' => $slug,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->logAction($community, 'forum_tag_created', null, ['name' => trim($this->forumTagName), 'slug' => $slug]);
        $this->reset('forumTagName');
    }

    public function moderateForumPost(int $postId, string $action): void
    {
        $community = $this->community();
        $this->requirePermission($community, 'manage_forums');
        abort_unless(in_array($action, ['hide', 'reopen', 'lock', 'unlock'], true), 422);

        $channel = $this->currentChannel($community);
        abort_unless($channel->isForum(), 404);

        $status = match ($action) {
            'hide' => 'hidden',
            'lock' => 'locked',
            default => 'open',
        };

        DB::table('community_forum_posts')->where('community_channel_id', $channel->id)->where('id', $postId)
            ->update(['status' => $status, 'updated_at' => now()]);
        $this->logAction($community, 'forum_post_'.$action, null, ['post_id' => $postId], $channel->id);
    }

    public function replyToForumPost(int $postId): void
    {
        $community = $this->community();
        $this->requireMember($community);
        $channel = $this->currentChannel($community);

        abort_if($channel->is_read_only, 403);
        $this->assertNotTimedOut($community);

        $post = DB::table('community_forum_posts')->where('id', $postId)->where('community_channel_id', $channel->id)->first();
        abort_unless($channel->isForum() && $post && $post->status === 'open', 404);

        $this->validate(['replyBody' => ['required', 'string', 'max:3000']]);
        SpamControls::enforce('comments', 8, 60, $this->replyBody);

        DB::table('community_forum_replies')->insert([
            'community_forum_post_id' => $postId, 'user_id' => Auth::id(),
            'body' => trim($this->replyBody), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('community_forum_posts')->where('id', $postId)->update(['updated_at' => now()]);

        $this->reset('replyBody', 'replyingToForumPost');
    }

    // ================================================================== polls

    public function createPoll(): void
    {
        $community = $this->community();
        $this->requireMember($community);
        $channel = $this->currentChannel($community);

        abort_unless($channel->isText() && ! $channel->isThread() && $channel->isPostable(), 403);

        $data = $this->validate([
            'pollQuestion' => ['required', 'string', 'max:200'],
            'pollOptions' => ['required', 'string', 'max:1200'],
        ]);
        SpamControls::enforce('posts', 12, 3600, $data['pollQuestion'].' '.$data['pollOptions']);

        $options = collect(preg_split('/\r\n|\r|\n/', $data['pollOptions']))
            ->map(fn ($option) => trim((string) $option))->filter()->unique()->take(10)->values();
        abort_if($options->count() < 2, 422, 'Add at least two different poll options.');

        $pollId = DB::table('community_polls')->insertGetId([
            'community_channel_id' => $channel->id, 'user_id' => Auth::id(),
            'question' => $data['pollQuestion'], 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($options as $position => $label) {
            DB::table('community_poll_options')->insert([
                'community_poll_id' => $pollId, 'label' => $label, 'position' => $position,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->reset('pollQuestion', 'pollOptions');
    }

    public function vote(int $pollId, int $optionId): void
    {
        $community = $this->community();
        $this->requireMember($community);
        $channel = $this->currentChannel($community);

        $poll = DB::table('community_polls')->where('id', $pollId)->where('community_channel_id', $channel->id)->first();
        abort_unless($poll && ! $poll->is_closed && (! $poll->ends_at || Carbon::parse($poll->ends_at)->isFuture()), 404);
        abort_unless(DB::table('community_poll_options')->where('id', $optionId)->where('community_poll_id', $pollId)->exists(), 404);

        DB::table('community_poll_votes')->updateOrInsert(
            ['community_poll_id' => $pollId, 'user_id' => Auth::id()],
            ['community_poll_option_id' => $optionId, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    // ================================================================= events

    public function createEvent(): void
    {
        $community = $this->community();
        $this->requireMember($community);
        $channel = $this->currentChannel($community);

        abort_unless($channel->isEvents() && $channel->isPostable(), 403);
        $this->assertNotTimedOut($community);

        $data = $this->validate([
            'eventTitle' => ['required', 'string', 'max:160'],
            'eventDescription' => ['nullable', 'string', 'max:3000'],
            'eventStartsAt' => ['required', 'date', 'after:now'],
        ]);
        SpamControls::enforce('posts', 12, 3600, $data['eventTitle'].' '.($data['eventDescription'] ?? ''));

        DB::table('community_events')->insert([
            'community_channel_id' => $channel->id, 'user_id' => Auth::id(),
            'title' => $data['eventTitle'], 'description' => $data['eventDescription'] ?? null,
            'starts_at' => Carbon::parse($data['eventStartsAt']), 'event_type' => 'discussion',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->reset('eventTitle', 'eventDescription', 'eventStartsAt');
    }

    public function toggleRsvp(int $eventId): void
    {
        $community = $this->community();
        $this->requireMember($community);
        $channel = $this->currentChannel($community);

        abort_unless($channel->isEvents() && DB::table('community_events')->where('id', $eventId)->where('community_channel_id', $channel->id)->exists(), 404);

        $rsvp = DB::table('community_event_rsvps')->where('community_event_id', $eventId)->where('user_id', Auth::id());
        if ($rsvp->exists()) {
            $rsvp->delete();
        } else {
            DB::table('community_event_rsvps')->insert([
                'community_event_id' => $eventId, 'user_id' => Auth::id(),
                'status' => 'interested', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function cancelEvent(int $eventId): void
    {
        $community = $this->community();
        $this->requirePermission($community, 'manage_events');
        $channel = $this->currentChannel($community);
        abort_unless($channel->isEvents(), 404);

        DB::table('community_events')->where('community_channel_id', $channel->id)->where('id', $eventId)->delete();
        $this->logAction($community, 'event_cancelled', null, ['event_id' => $eventId], $channel->id);
    }

    // ================================================================ reports

    public function reportContent(string $type, int $id): void
    {
        $community = $this->community();
        $this->requireMember($community);
        abort_unless(in_array($type, ['message', 'forum_post', 'forum_reply'], true), 422);

        $belongs = match ($type) {
            'message' => DB::table('community_messages')->join('community_channels', 'community_channels.id', '=', 'community_messages.community_channel_id')->where('community_channels.community_id', $community->id)->where('community_messages.id', $id)->exists(),
            'forum_post' => DB::table('community_forum_posts')->join('community_channels', 'community_channels.id', '=', 'community_forum_posts.community_channel_id')->where('community_channels.community_id', $community->id)->where('community_forum_posts.id', $id)->exists(),
            'forum_reply' => DB::table('community_forum_replies')->join('community_forum_posts', 'community_forum_posts.id', '=', 'community_forum_replies.community_forum_post_id')->join('community_channels', 'community_channels.id', '=', 'community_forum_posts.community_channel_id')->where('community_channels.community_id', $community->id)->where('community_forum_replies.id', $id)->exists(),
        };
        abort_unless($belongs, 404);

        $this->validate(['reportReason' => ['required', 'string', 'max:80'], 'reportDetails' => ['nullable', 'string', 'max:1500']]);

        DB::table('community_reports')->insert([
            'community_id' => $community->id, 'reporter_id' => Auth::id(),
            'target_type' => $type, 'target_id' => $id,
            'reason' => $this->reportReason, 'details' => $this->reportDetails ?: null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->reset('reportReason', 'reportDetails', 'reportTargetId');
        $this->dispatch('notify', 'Report sent to community moderators.');
    }

    /**
     * Escalate the whole community to the site-wide moderation queue.
     */
    public function reportCommunityToSite(): void
    {
        $community = $this->community();
        $this->requireMember($community);
        abort_if($community->owner_id === Auth::id(), 422, 'You own this community.');

        $validated = $this->validate([
            'reportReason' => ['required', 'string', 'max:80'],
            'reportDetails' => ['nullable', 'string', 'max:1000'],
        ]);

        $filed = ContentReports::file(Auth::user(), 'community', $community->id, $validated['reportReason'], $validated['reportDetails']);

        $this->reset('reportReason', 'reportDetails');
        $this->siteReportOpen = false;
        $this->dispatch('notify', $filed
            ? 'Community reported to site admins.'
            : 'A report about this community is already pending review.');
    }

    public function assignReport(int $reportId): void
    {
        $community = $this->community();
        $this->requirePermission($community, 'review_reports');

        DB::table('community_reports')->where('community_id', $community->id)->where('id', $reportId)->where('status', 'pending')
            ->update(['assignee_id' => Auth::id(), 'status' => 'reviewing', 'updated_at' => now()]);
    }

    public function resolveReport(int $reportId, string $resolution): void
    {
        $community = $this->community();
        $this->requirePermission($community, 'resolve_reports');
        abort_unless(in_array($resolution, ['hide', 'dismiss'], true), 422);

        $report = DB::table('community_reports')->where('community_id', $community->id)->where('id', $reportId)->whereIn('status', ['pending', 'reviewing'])->first();
        abort_unless($report, 404);

        if ($resolution === 'hide') {
            $table = ['message' => 'community_messages', 'forum_post' => 'community_forum_posts', 'forum_reply' => 'community_forum_replies'][$report->target_type] ?? null;
            if ($table) {
                DB::table($table)->where('id', $report->target_id)->update(
                    $report->target_type === 'forum_post' ? ['status' => 'hidden', 'updated_at' => now()] : ['is_hidden' => true, 'updated_at' => now()]
                );
            }
        }

        DB::table('community_reports')->where('id', $report->id)->update([
            'status' => $resolution === 'hide' ? 'actioned' : 'dismissed',
            'resolution' => $resolution, 'resolver_id' => Auth::id(), 'resolved_at' => now(), 'updated_at' => now(),
        ]);

        $this->logAction($community, 'report_'.$resolution, null, ['report_id' => $reportId]);
    }

    // ============================================================== overlays

    public function openProfile(int $userId): void
    {
        $this->profileUserId = $userId;
    }

    public function closeProfile(): void
    {
        $this->profileUserId = null;
    }

    public function openSearch(): void
    {
        $this->searchOpen = true;
    }

    public function openPins(): void
    {
        $this->pinsOpen = ! $this->pinsOpen;
    }

    /**
     * @return array{unread: array<int, int>, mentions: array<int, int>}
     */
    private function unreadFor(Community $community, Collection $channels): array
    {
        $userId = Auth::id();
        if (! $userId || ! $community->isMember($userId)) {
            return ['unread' => [], 'mentions' => []];
        }

        $keys = $channels->pluck('id')->all();
        if ($keys === []) {
            return ['unread' => [], 'mentions' => []];
        }

        $reads = CommunityChannelRead::where('user_id', $userId)->whereIn('community_channel_id', $keys)
            ->pluck('last_read_message_id', 'community_channel_id');

        $latest = DB::table('community_messages')->whereIn('community_channel_id', $keys)->where('is_hidden', false)
            ->selectRaw('community_channel_id, max(id) as last_id')
            ->groupBy('community_channel_id')
            ->pluck('last_id', 'community_channel_id');

        $unread = [];
        $mentions = [];

        foreach ($keys as $channelId) {
            $lastRead = (int) ($reads[$channelId] ?? 0);
            $lastId = (int) ($latest[$channelId] ?? 0);
            if ($lastId <= $lastRead) {
                continue;
            }

            $rows = CommunityMessage::where('community_channel_id', $channelId)
                ->where('id', '>', $lastRead)
                ->where('is_hidden', false)
                ->orderByDesc('id')->limit(200)
                ->get(['id', 'mention_user_ids', 'mention_role_ids', 'mention_everyone']);

            if ($rows->isEmpty()) {
                continue;
            }

            $unread[$channelId] = $rows->count();
            $roleIds = $community->memberFor($userId)?->community_role_id;
            $mentions[$channelId] = $rows->filter(function (CommunityMessage $row) use ($userId, $roleIds): bool {
                if ($row->mention_everyone) {
                    return true;
                }
                if (in_array($userId, $row->mention_user_ids ?? [], true)) {
                    return true;
                }

                return $roleIds && in_array($roleIds, $row->mention_role_ids ?? [], true);
            })->count();
        }

        return ['unread' => $unread, 'mentions' => $mentions];
    }

    private function decorateMessage(CommunityMessage $message, Community $community, LoungeFormatter $formatter, array $channelIdsBySlug): void
    {
        $member = $community->memberFor($message->user_id);

        $message->body_html = $formatter->render($message->body, $channelIdsBySlug);
        $message->author_name = $message->author?->name ?? 'Unknown';
        $message->author_username = $message->author?->username ?? 'unknown';
        $message->author_avatar = $message->author?->avatar_url;
        $message->author_display = $member?->displayName() ?? $message->author_name;
        $message->author_color = $community->displayColorFor($message->user_id);
        $message->is_own = $message->user_id === Auth::id();
        $message->mention_me = $message->mention_everyone
            || in_array(Auth::id(), $message->mention_user_ids ?? [], true)
            || ($member && $member->community_role_id && in_array($member->community_role_id, $message->mention_role_ids ?? [], true));
        $message->reply_preview = $message->replyTo ? [
            'id' => $message->replyTo->id,
            'author' => $community->memberFor($message->replyTo->user_id)?->displayName() ?? $message->replyTo->author?->name ?? 'Unknown',
            'body' => Str::limit((string) $message->replyTo->body, 120),
        ] : null;
        $message->reaction_groups = $message->reactions
            ->groupBy('emoji')
            ->map(fn (Collection $rows, string $emoji): array => [
                'emoji' => $emoji,
                'count' => $rows->count(),
                'mine' => $rows->contains(fn (CommunityMessageReaction $reaction): bool => $reaction->user_id === Auth::id()),
            ])
            ->sortByDesc('count')
            ->values();
    }

    // ================================================================= render

    public function render()
    {
        $community = $this->community();
        $membership = Auth::check() ? $community->memberFor(Auth::id()) : null;
        $isAdmin = (bool) Auth::user()?->isAdmin();
        $isOwner = Auth::check() && Auth::id() === $community->owner_id;

        if ($community->visibility === 'invite' && $membership?->status !== 'active' && ! $isAdmin && ! $isOwner) {
            abort(404);
        }

        $activeChannel = $this->currentChannel($community);
        $this->activeChannelId = $activeChannel->id;
        $isMember = $membership?->status === 'active' || $isOwner || $isAdmin;

        $permissions = [];
        foreach (Community::PERMISSIONS as $permission) {
            $permissions[$permission] = $isMember && Auth::check() && $community->hasPermission(Auth::user(), $permission);
        }
        $canManageServer = $isOwner || $isAdmin;

        $categories = $community->categoryGroups()->get();
        $channels = $community->topLevelChannels()->get();
        $threadChannels = CommunityChannel::where('community_id', $community->id)
            ->whereNotNull('parent_id')->where('thread_archived', false)
            ->orderByDesc('last_message_at')->limit(40)->get();
        $threadsByMessage = $threadChannels->whereNotNull('parent_message_id')->keyBy('parent_message_id');
        $threadsByParent = $threadChannels->groupBy('parent_id');
        $channelIdsBySlug = $channels->concat($threadChannels)->pluck('id', 'slug')->all();

        $members = $community->members()->with(['user', 'role'])->get();
        $activeMembers = $members->where('status', 'active')->values();
        $roles = $community->roles()->get();
        $emoji = $community->emoji()->orderBy('name')->get();
        $formatter = new LoungeFormatter($activeMembers, $roles, $emoji, Auth::id());

        $unread = $this->unreadFor($community, $channels->concat($threadChannels));

        $messages = collect();
        $pins = collect();
        $polls = collect();
        $pollOptions = collect();
        $pollVotes = collect();

        if ($activeChannel->isText() || $activeChannel->isThread()) {
            $messages = CommunityMessage::query()
                ->where('community_channel_id', $activeChannel->id)
                ->where('is_hidden', false)
                ->with(['author', 'replyTo.author', 'reactions'])
                ->orderByDesc('id')->limit(120)->get()->reverse()->values();

            $previous = null;
            foreach ($messages as $message) {
                $this->decorateMessage($message, $community, $formatter, $channelIdsBySlug);
                $sameAuthor = $previous && $previous->user_id === $message->user_id;
                $close = $previous && $previous->created_at->diffInMinutes($message->created_at) < 8;
                $message->group_start = ! ($sameAuthor && $close && ! $message->reply_to_id && ! $message->is_system);
                $message->day_divider = ! $previous || ! $previous->created_at->isSameDay($message->created_at);
                $previous = $message;
            }

            $pins = CommunityMessage::where('community_channel_id', $activeChannel->id)
                ->where('is_pinned', true)->where('is_hidden', false)
                ->with('author')->orderByDesc('pinned_at')->limit(50)->get();
            foreach ($pins as $pin) {
                $this->decorateMessage($pin, $community, $formatter, $channelIdsBySlug);
            }

            $polls = DB::table('community_polls')->where('community_channel_id', $activeChannel->id)->latest()->limit(20)->get();
            if ($polls->isNotEmpty()) {
                $pollOptions = DB::table('community_poll_options')->whereIn('community_poll_id', $polls->pluck('id'))->orderBy('position')->get()->groupBy('community_poll_id');
                $optionIds = $pollOptions->flatten(1)->pluck('id');
                $pollVotes = $optionIds->isNotEmpty()
                    ? DB::table('community_poll_votes')->whereIn('community_poll_option_id', $optionIds)
                        ->select('community_poll_option_id')->selectRaw('count(*) as vote_count')
                        ->groupBy('community_poll_option_id')->pluck('vote_count', 'community_poll_option_id')
                    : collect();
            }
        }

        $thread = $activeChannel->isThread() ? $activeChannel : null;
        $threadParent = $thread?->parent;

        // --- forum
        $forumPosts = collect();
        $forumReplies = collect();
        $availableForumTags = collect();
        $forumPostTagMap = collect();
        if ($activeChannel->isForum()) {
            $availableForumTags = DB::table('community_forum_tags')->where('community_id', $community->id)->orderBy('name')->get();

            $forumQuery = DB::table('community_forum_posts')
                ->join('users', 'users.id', '=', 'community_forum_posts.user_id')
                ->where('community_channel_id', $activeChannel->id)
                ->whereIn('community_forum_posts.status', ['open', 'locked'])
                ->select('community_forum_posts.*', 'users.username', 'users.avatar_url');

            if ($this->forumTagFilter) {
                $forumQuery->join('community_forum_post_tag', 'community_forum_post_tag.community_forum_post_id', '=', 'community_forum_posts.id')
                    ->join('community_forum_tags', 'community_forum_tags.id', '=', 'community_forum_post_tag.community_forum_tag_id')
                    ->where('community_forum_tags.slug', $this->forumTagFilter);
            }

            $forumPosts = match ($this->forumSort) {
                'top' => $forumQuery->orderByDesc('community_forum_posts.updated_at')->limit(60)->get(),
                default => $forumQuery->orderByDesc('community_forum_posts.updated_at')->limit(60)->get(),
            };

            if ($forumPosts->isNotEmpty()) {
                $forumReplies = DB::table('community_forum_replies')
                    ->join('users', 'users.id', '=', 'community_forum_replies.user_id')
                    ->whereIn('community_forum_post_id', $forumPosts->pluck('id'))
                    ->where('is_hidden', false)
                    ->select('community_forum_replies.*', 'users.username', 'users.avatar_url')
                    ->oldest('community_forum_replies.id')->get()->groupBy('community_forum_post_id');

                $forumPostTagMap = DB::table('community_forum_post_tag')
                    ->join('community_forum_tags', 'community_forum_tags.id', '=', 'community_forum_post_tag.community_forum_tag_id')
                    ->whereIn('community_forum_post_id', $forumPosts->pluck('id'))
                    ->get(['community_forum_post_tag.community_forum_post_id', 'community_forum_tags.name', 'community_forum_tags.slug'])
                    ->groupBy('community_forum_post_id');
            }
        }

        // --- events
        $events = collect();
        $eventRsvps = collect();
        if ($activeChannel->isEvents()) {
            $events = DB::table('community_events')->join('users', 'users.id', '=', 'community_events.user_id')
                ->where('community_channel_id', $activeChannel->id)->where('starts_at', '>=', now()->subHours(2))
                ->select('community_events.*', 'users.username')
                ->orderBy('starts_at')->get();
            $eventRsvps = $events->isNotEmpty()
                ? DB::table('community_event_rsvps')->whereIn('community_event_id', $events->pluck('id'))->get(['community_event_id', 'user_id'])->groupBy('community_event_id')
                : collect();
        }

        // --- search
        $searchResults = collect();
        if ($this->searchOpen && mb_strlen(trim($this->search)) >= 2) {
            $like = '%'.trim($this->search).'%';
            $searchResults = CommunityMessage::query()
                ->join('community_channels', 'community_channels.id', '=', 'community_messages.community_channel_id')
                ->join('users', 'users.id', '=', 'community_messages.user_id')
                ->where('community_channels.community_id', $community->id)
                ->where('community_messages.is_hidden', false)
                ->where(fn ($query) => $query->where('community_messages.body', 'like', $like)->orWhere('users.username', 'like', $like))
                ->select('community_messages.*', 'community_channels.name as channel_name', 'community_channels.slug as channel_slug', 'users.username', 'users.avatar_url')
                ->latest('community_messages.id')->limit(50)->get();
        }

        // --- management data (loaded only when the relevant panel is open)
        $invites = $this->serverSettingsOpen
            ? DB::table('community_invites')->where('community_id', $community->id)->latest()->limit(50)->get()
            : collect();
        $auditLogs = ($this->auditOpen || $this->serverSettingsOpen)
            ? DB::table('community_action_logs')->leftJoin('users', 'users.id', '=', 'community_action_logs.actor_id')
                ->where('community_id', $community->id)
                ->select('community_action_logs.*', 'users.username as actor_name', 'users.avatar_url as actor_avatar')
                ->latest('community_action_logs.id')->limit(100)->get()
            : collect();
        $reports = Auth::check() && $community->hasPermission(Auth::user(), 'review_reports')
            ? DB::table('community_reports')->join('users', 'users.id', '=', 'community_reports.reporter_id')
                ->where('community_id', $community->id)->whereIn('status', ['pending', 'reviewing'])
                ->select('community_reports.*', 'users.username as reporter_name')
                ->latest('community_reports.created_at')->get()
            : collect();

        $profileMember = $this->profileUserId ? $members->firstWhere('user_id', $this->profileUserId) : null;

        // --- rail / DMs
        $myCommunities = Auth::check()
            ? Community::query()
                ->join('community_members', 'community_members.community_id', '=', 'communities.id')
                ->where('community_members.user_id', Auth::id())
                ->whereIn('community_members.status', ['active', 'pending'])
                ->select('communities.*', 'community_members.status as membership_status')
                ->orderBy('communities.name')->get()
            : collect();

        $recentConversations = Auth::check()
            ? Conversation::query()->visibleFor(Auth::user())
                ->orderByDesc('last_message_at')->limit(8)->with(['userOne', 'userTwo'])->get()
            : collect();

        return view('components.⚡community-space', compact(
            'community', 'membership', 'isMember', 'isOwner', 'isAdmin', 'canManageServer', 'permissions',
            'categories', 'channels', 'threadChannels', 'threadsByMessage', 'threadsByParent',
            'members', 'activeMembers', 'roles', 'emoji', 'formatter',
            'messages', 'pins', 'polls', 'pollVotes',
            'thread', 'threadParent',
            'forumPosts', 'forumReplies', 'availableForumTags', 'forumPostTagMap',
            'events', 'eventRsvps', 'searchResults',
            'invites', 'auditLogs', 'reports', 'profileMember',
            'myCommunities', 'recentConversations', 'unread',
        ))->with('activeChannel', $activeChannel)->with('pollOptionGroups', $pollOptions);
    }
};
?>

<div
    x-data="{
        onlineIds: @js(Auth::check() ? [Auth::id()] : []),
        typingMap: {},
        typingTimers: {},
        sidebarOpen: false,
        initLounge() {
            if (! window.Echo || ! @js($isMember)) return;
            const cid = @js($community->id);
            try {
                const channel = window.Echo.join('community.' + cid);
                channel.here((users) => { this.onlineIds = users.map((u) => u.id); });
                channel.joining((user) => { this.onlineIds = [...new Set([...this.onlineIds, user.id])]; });
                channel.leaving((user) => { this.onlineIds = this.onlineIds.filter((id) => id !== user.id); });
                channel.listen('.community.message.sent', (e) => {
                    $wire.$refresh();
                    if (document.visibilityState === 'visible' && e.channel_id === $wire.activeChannelId) {
                        $wire.markChannelRead();
                        this.scrollToBottom();
                    }
                });
                channel.listen('.community.message.updated', () => { $wire.$refresh(); });
                channel.listen('.community.typing', (e) => {
                    if (e.userId === @js(Auth::id()) || e.channelId !== $wire.activeChannelId) return;
                    this.setTyping(e.displayName, e.userId, e.isTyping);
                });
                channel.listen('.community.presence', () => { $wire.$refresh(); });
            } catch (err) { console.log('Lounge websocket:', err); }
        },
        setTyping(name, id, isTyping) {
            if (! isTyping) { const next = { ...this.typingMap }; delete next[id]; this.typingMap = next; return; }
            this.typingMap = { ...this.typingMap, [id]: name };
            clearTimeout(this.typingTimers[id]);
            this.typingTimers[id] = setTimeout(() => { const next = { ...this.typingMap }; delete next[id]; this.typingMap = next; }, 4000);
        },
        typingLabel() {
            const names = Object.values(this.typingMap);
            if (names.length === 0) return '';
            if (names.length === 1) return names[0] + ' is typing…';
            if (names.length === 2) return names[0] + ' and ' + names[1] + ' are typing…';
            return 'Several people are typing…';
        },
        isOnline(id) { return this.onlineIds.includes(id); },
        scrollToBottom(force = false) {
            const box = this.$refs.messageScroll;
            if (! box) return;
            const nearBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 320;
            if (force || nearBottom) box.scrollTop = box.scrollHeight;
        },
        notifyTyping() {
            const now = Date.now();
            if (! this.lastTypingAt || now - this.lastTypingAt > 2500) {
                this.lastTypingAt = now;
                $wire.typing();
            }
        }
    }"
    x-init="initLounge(); scrollToBottom(true);"
    @lounge-message-sent.window="setTimeout(() => scrollToBottom(true), 40)"
    wire:poll.60s="heartbeat"
    class="flex h-full min-h-0 w-full min-w-0 overflow-hidden bg-[var(--bg-surface-elevated)] text-[var(--text-main)]"
>
    @include('lounge.server-rail')

    {{-- mobile channel drawer backdrop --}}
    <div x-show="sidebarOpen" x-cloak @click="sidebarOpen = false" class="fixed inset-0 z-40 bg-black/60 backdrop-blur-sm md:hidden"></div>

    @include('lounge.sidebar')

    <main class="flex min-w-0 flex-1 flex-col bg-[var(--bg-surface-elevated)]">
        @if($activeChannel->isForum())
            @include('lounge.forum')
        @elseif($activeChannel->isEvents())
            @include('lounge.events')
        @else
            @include('lounge.chat')
        @endif
    </main>

    @if($memberListVisible && $isMember)
        @include('lounge.members')
    @endif

    @include('lounge.settings')
    @include('lounge.overlays')
</div>
