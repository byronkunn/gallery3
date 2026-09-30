<?php

use App\Models\Collection;
use App\Models\Comment;
use App\Models\Community;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Pool;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Support\SpamControls;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $activeSection = 'overview'; // 'overview', 'users', 'content', 'messages', 'comments', 'pools', 'tags', 'settings'

    public string $userSearch = '';

    public string $contentSearch = '';

    public string $commentSearch = '';

    public string $messageSearch = '';

    public string $aliasFrom = '';

    public string $aliasTo = '';

    public string $proposalReviewNote = '';

    public string $auditSearch = '';

    public bool $spamControlsEnabled = true;

    public int $messageLimitPerMinute = 20;

    public int $commentLimitPerMinute = 8;

    public int $postLimitPerHour = 12;

    public int $inactiveOwnerDays = 90;

    public ?int $suspendingUserId = null;

    public string $suspensionReason = '';

    public string $suspensionDuration = 'permanent';

    public string $appealResponse = '';

    // DM Conversation Audit Selection
    public ?int $selectedConversationId = null;

    // Artwork Inspect Modal State
    public ?int $inspectPostId = null;

    // Settings
    public string $announcementText = '';

    public bool $maintenanceMode = false;

    public bool $allowRegistrations = true;

    public bool $globalCommissionsOpen = true;

    public function boot(): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);
    }

    public function mount()
    {
        if (! Auth::check() || ! Auth::user()->isAdmin()) {
            return redirect()->route('gallery');
        }

        $this->announcementText = Cache::get('site_announcement', 'Welcome to Booru.art! Video uploads and batch multi-posting are now live.');
        $this->maintenanceMode = (bool) Cache::get('site_maintenance', false);
        $this->allowRegistrations = (bool) Cache::get('site_registrations', true);
        $this->globalCommissionsOpen = (bool) Cache::get('site_global_commissions', true);
        $this->spamControlsEnabled = (bool) Cache::get('site_spam_controls_enabled', true);
        $this->messageLimitPerMinute = (int) Cache::get('site_spam_messages_limit', 20);
        $this->commentLimitPerMinute = (int) Cache::get('site_spam_comments_limit', 8);
        $this->postLimitPerHour = (int) Cache::get('site_spam_posts_limit', 12);
        $this->inactiveOwnerDays = (int) Cache::get('site_inactive_owner_days', 90);
    }

    public function setSection(string $section)
    {
        $this->activeSection = $section;
        $this->resetPage();
    }

    public function updatedAuditSearch(): void
    {
        $this->resetPage('auditPage');
    }

    public function selectConversation(int $convId)
    {
        $this->selectedConversationId = $convId;
    }

    public function openInspectModal(int $postId)
    {
        $this->inspectPostId = $postId;
    }

    public function closeInspectModal()
    {
        $this->inspectPostId = null;
    }

    private function logAdminAction(string $action, ?string $targetType = null, ?int $targetId = null, ?string $reason = null, array $details = []): int
    {
        return DB::table('admin_audit_logs')->insertGetId([
            'actor_id' => Auth::id(), 'action' => $action, 'target_type' => $targetType, 'target_id' => $targetId,
            'reason' => $reason, 'details' => json_encode($details), 'ip_address' => request()->ip(),
            'user_agent' => Str::limit((string) request()->userAgent(), 500, ''), 'created_at' => now(),
        ]);
    }

    public function prepareSuspension(int $userId): void
    {
        $user = User::findOrFail($userId);
        abort_if($user->id === Auth::id() || $user->isAdmin(), 403);
        $this->suspendingUserId = $user->id;
        $this->suspensionReason = '';
        $this->suspensionDuration = 'permanent';
    }

    public function suspendUser(): void
    {
        $data = $this->validate([
            'suspendingUserId' => ['required', 'integer', 'exists:users,id'],
            'suspensionReason' => ['required', 'string', 'min:5', 'max:1000'],
            'suspensionDuration' => ['required', 'in:hour,day,week,permanent'],
        ]);
        $user = User::findOrFail($data['suspendingUserId']);
        abort_if($user->id === Auth::id() || $user->isAdmin(), 403);
        $until = match ($data['suspensionDuration']) {
            'hour' => now()->addHour(),
            'day' => now()->addDay(),
            'week' => now()->addWeek(),
            default => null,
        };
        $user->update(['is_banned' => true, 'suspended_until' => $until, 'suspension_reason' => trim($data['suspensionReason'])]);
        $this->logAdminAction('user.suspended', 'user', $user->id, trim($data['suspensionReason']), ['until' => $until?->toIso8601String()]);
        $this->suspendingUserId = null;
        $this->reset('suspensionReason');
        $this->dispatch('notify', "User @{$user->username} was suspended.");
    }

    public function toggleUserAdmin(int $userId)
    {
        $user = User::findOrFail($userId);
        abort_if($user->id === Auth::id(), 403);
        $user->update(['is_admin' => ! $user->is_admin]);
        $this->logAdminAction($user->is_admin ? 'user.admin_granted' : 'user.admin_removed', 'user', $user->id);
        $this->dispatch('notify', "Updated admin status for @{$user->username}");
    }

    public function toggleUserArtist(int $userId)
    {
        $user = User::findOrFail($userId);
        $user->update(['is_artist' => ! $user->is_artist]);
        $this->logAdminAction($user->is_artist ? 'user.artist_granted' : 'user.artist_removed', 'user', $user->id);
        $this->dispatch('notify', "Updated artist status for @{$user->username}");
    }

    public function toggleUserBan(int $userId)
    {
        $user = User::findOrFail($userId);
        if ($user->id === auth()->id()) {
            $this->dispatch('notify', 'You cannot ban yourself!');

            return;
        }

        abort_if($user->isAdmin(), 403);
        $newBanned = ! $user->is_banned;
        if ($newBanned) {
            $user->update([
                'is_banned' => true,
                'suspended_until' => null,
                'suspension_reason' => 'Suspended by admin direct toggle',
            ]);
            $this->logAdminAction('user.suspended', 'user', $user->id, 'Suspended by admin direct toggle');
        } else {
            $user->update([
                'is_banned' => false,
                'suspended_until' => null,
                'suspension_reason' => null,
            ]);
            $this->logAdminAction('user.unsuspended', 'user', $user->id, 'Suspension lifted by admin.');
        }

        $status = $newBanned ? 'BANNED' : 'UNBANNED';
        $this->dispatch('notify', "User @{$user->username} has been {$status}");
    }

    public function togglePostNsfw(int $postId)
    {
        $post = Post::findOrFail($postId);
        $post->update(['is_nsfw' => ! $post->is_nsfw]);
        $this->logAdminAction('post.nsfw_toggled', 'post', $post->id, null, ['is_nsfw' => $post->is_nsfw]);
        $this->dispatch('notify', "Updated NSFW flag for Post #{$post->id}");
    }

    public function togglePostFeatured(int $postId)
    {
        $post = Post::findOrFail($postId);
        $post->update(['is_featured' => ! $post->is_featured]);
        $this->logAdminAction($post->is_featured ? 'post.featured' : 'post.unfeatured', 'post', $post->id);
        $status = $post->is_featured ? 'FEATURED' : 'UNFEATURED';
        $this->dispatch('notify', "Post #{$post->id} marked as {$status}");
    }

    public function deletePost(int $postId)
    {
        $post = Post::with(['media', 'tags', 'poolChapters'])->findOrFail($postId);
        $this->logAdminAction('post.deleted', 'post', $post->id, null, ['title' => $post->title]);
        foreach ($post->media as $media) {
            $path = parse_url($media->url, PHP_URL_PATH) ?: '';
            if (str_starts_with($path, '/storage/')) {
                $storagePath = substr($path, strlen('/storage/'));
                if (str_starts_with($storagePath, 'posts/') || str_starts_with($storagePath, 'videos/')) {
                    Storage::disk('public')->delete($storagePath);
                }
            }
        }
        foreach ($post->tags as $tag) {
            Tag::whereKey($tag->id)->where('posts_count', '>', 0)->decrement('posts_count');
        }
        foreach ($post->poolChapters as $chapter) {
            $chapter->pool()->where('chapters_count', '>', 0)->decrement('chapters_count');
        }
        foreach (\App\Models\CollectionItem::where('post_id', $post->id)->get() as $item) {
            Collection::whereKey($item->collection_id)->where('items_count', '>', 0)->decrement('items_count');
        }
        $post->delete();
        if ($this->inspectPostId === $postId) {
            $this->inspectPostId = null;
        }
        $this->dispatch('notify', "Deleted Post #{$postId}");
    }

    public function reviewContentReport(int $reportId, string $decision): void
    {
        $this->authorizeAdmin();
        abort_unless(in_array($decision, ['dismiss', 'remove'], true), 422);

        $report = DB::table('content_reports')->where('id', $reportId)->where('status', 'pending')->first();
        abort_unless($report && $report->target_type === 'post', 404);

        if ($decision === 'remove') {
            $this->deletePost((int) $report->target_id);
        }

        DB::table('content_reports')->where('id', $reportId)->update([
            'status' => $decision === 'remove' ? 'actioned' : 'dismissed',
            'reviewer_id' => Auth::id(),
            'resolution' => $decision === 'remove' ? 'Post removed after review.' : 'Report reviewed and dismissed.',
            'resolved_at' => now(),
            'updated_at' => now(),
        ]);
        $this->logAdminAction('content_report.'.$decision, 'post', (int) $report->target_id);
    }

    public function togglePoolLock(int $poolId)
    {
        $pool = Pool::findOrFail($poolId);
        $pool->update(['is_locked' => ! $pool->is_locked]);
        $this->logAdminAction($pool->is_locked ? 'pool.locked' : 'pool.unlocked', 'pool', $pool->id);
        $this->dispatch('notify', "Updated lock state for series '{$pool->title}'");
    }

    public function togglePoolFeatured(int $poolId)
    {
        $pool = Pool::findOrFail($poolId);
        $pool->update(['is_featured' => ! $pool->is_featured]);
        $this->logAdminAction($pool->is_featured ? 'pool.featured' : 'pool.unfeatured', 'pool', $pool->id);
        $status = $pool->is_featured ? 'FEATURED' : 'UNFEATURED';
        $this->dispatch('notify', "Series '{$pool->title}' marked as {$status}");
    }

    public function deletePool(int $poolId)
    {
        $pool = Pool::findOrFail($poolId);
        $this->logAdminAction('pool.deleted', 'pool', $pool->id, null, ['title' => $pool->title]);
        $pool->delete();
        $this->dispatch('notify', "Deleted Pool #{$poolId}");
    }

    public function deleteComment(int $commentId)
    {
        $comment = Comment::findOrFail($commentId);
        $this->logAdminAction('comment.deleted', 'comment', $comment->id);
        $comment->delete();
        $this->dispatch('notify', "Deleted Comment #{$commentId}");
    }

    public function toggleCommentFlag(int $commentId)
    {
        $comment = Comment::findOrFail($commentId);
        $comment->update(['is_flagged' => ! $comment->is_flagged]);
        $this->logAdminAction($comment->is_flagged ? 'comment.flagged' : 'comment.unflagged', 'comment', $comment->id);
        $this->dispatch('notify', "Updated flag status for Comment #{$commentId}");
    }

    public function addTagAlias()
    {
        $from = Str::of($this->aliasFrom)->lower()->trim()->replace(' ', '_')->toString();
        $to = Str::of($this->aliasTo)->lower()->trim()->replace(' ', '_')->toString();
        $this->aliasFrom = $from;
        $this->aliasTo = $to;
        $this->validate([
            'aliasFrom' => ['required', 'string', 'max:80'],
            'aliasTo' => ['required', 'string', 'max:80', 'exists:tags,name'],
        ]);
        abort_if($from === $to, 422, 'An alias must differ from its canonical tag.');
        abort_if(Tag::where('name', $from)->exists(), 422, 'An existing tag cannot be replaced with an alias.');

        $alias = DB::table('tag_aliases')->where('alias', $from)->first();
        if ($alias) {
            DB::table('tag_aliases')->where('id', $alias->id)->update([
                'tag_id' => Tag::where('name', $to)->value('id'),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('tag_aliases')->insert([
                'alias' => $from,
                'tag_id' => Tag::where('name', $to)->value('id'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->aliasFrom = '';
        $this->aliasTo = '';
        $this->dispatch('notify', "Created tag alias: #{$from} → #{$to}");
    }

    public function deleteTagAlias(int $aliasId): void
    {
        DB::table('tag_aliases')->where('id', $aliasId)->delete();
        $this->dispatch('notify', 'Tag alias removed.');
    }

    public function reviewTagProposal(int $proposalId, string $decision): void
    {
        abort_unless(in_array($decision, ['approve', 'reject'], true), 422);
        $proposal = DB::table('post_tag_proposals')->where('id', $proposalId)->where('status', 'pending')->first();
        abort_unless($proposal, 404);
        DB::transaction(function () use ($proposal, $decision): void {
            $post = Post::with('tags')->findOrFail($proposal->post_id);
            if ($decision === 'approve' && $proposal->action === 'add') {
                $tag = $proposal->tag_id ? Tag::find($proposal->tag_id) : null;
                if (! $tag) {
                    $tag = Tag::firstOrCreate(['name' => $proposal->tag_name], ['slug' => Str::slug($proposal->tag_name), 'type' => 'general', 'posts_count' => 0]);
                }
                if (! $post->tags()->whereKey($tag->id)->exists()) { $post->tags()->attach($tag->id); $tag->increment('posts_count'); }
            } elseif ($decision === 'approve' && $proposal->action === 'remove' && $proposal->tag_id && $post->tags()->whereKey($proposal->tag_id)->exists()) {
                $post->tags()->detach($proposal->tag_id);
                Tag::whereKey($proposal->tag_id)->where('posts_count', '>', 0)->decrement('posts_count');
            }
            DB::table('post_tag_proposals')->where('id', $proposal->id)->update([
                'status' => $decision === 'approve' ? 'approved' : 'rejected', 'reviewer_id' => Auth::id(),
                'review_note' => $this->proposalReviewNote ?: null, 'reviewed_at' => now(), 'updated_at' => now(),
            ]);
        });
        $this->reset('proposalReviewNote');
        $this->logAdminAction('tag_proposal.'.$decision, 'post', $proposal->post_id, null, ['proposal_id' => $proposalId, 'tag' => $proposal->tag_name]);
        $this->dispatch('notify', 'Tag request '.($decision === 'approve' ? 'approved.' : 'rejected.'));
    }

    public function reviewAppeal(int $appealId, string $decision): void
    {
        abort_unless(in_array($decision, ['accept', 'reject'], true), 422);
        $data = $this->validate(['appealResponse' => ['required', 'string', 'min:5', 'max:1000']]);
        DB::transaction(function () use ($appealId, $decision, $data): void {
            $appeal = DB::table('user_appeals')->where('id', $appealId)->where('status', 'pending')->lockForUpdate()->first();
            abort_unless($appeal, 404);
            $user = User::findOrFail($appeal->user_id);
            if ($decision === 'accept') {
                $user->update(['is_banned' => false, 'suspended_until' => null, 'suspension_reason' => null]);
            }
            DB::table('user_appeals')->where('id', $appealId)->update([
                'status' => $decision === 'accept' ? 'accepted' : 'rejected',
                'reviewer_id' => Auth::id(), 'response' => trim($data['appealResponse']),
                'reviewed_at' => now(), 'updated_at' => now(),
            ]);
            $this->logAdminAction('appeal.'.($decision === 'accept' ? 'accepted' : 'rejected'), 'user', $user->id, trim($data['appealResponse']), ['appeal_id' => $appealId]);
            if ($decision === 'accept') {
                $this->logAdminAction('user.unsuspended', 'user', $user->id, 'Suspension overturned on appeal.', ['appeal_id' => $appealId]);
            }
        });
        $this->reset('appealResponse');
    }

    public function transferCommunityOwnership(int $communityId, int $newOwnerId): void
    {
        $inactiveDays = max(30, (int) Cache::get('site_inactive_owner_days', 90));
        DB::transaction(function () use ($communityId, $newOwnerId, $inactiveDays): void {
            $community = Community::whereKey($communityId)->lockForUpdate()->firstOrFail();
            $previousOwner = User::findOrFail($community->owner_id);
            $ownerLastActivity = $previousOwner->last_active_at ?? $community->created_at;
            abort_if($ownerLastActivity->gt(now()->subDays($inactiveDays)), 422, 'The owner is still active.');
            $newOwner = User::whereKey($newOwnerId)->where('is_banned', false)->firstOrFail();
            abort_if($newOwner->id === $previousOwner->id, 422);
            $newOwnerMembership = DB::table('community_members')
                ->where('community_id', $community->id)
                ->where('user_id', $newOwner->id)
                ->where('status', 'active')
                ->first();
            abort_unless($newOwnerMembership, 422, 'The new owner must be an active community member.');

            DB::table('community_members')->where('community_id', $community->id)->where('user_id', $previousOwner->id)->update(['community_role_id' => null, 'updated_at' => now()]);
            DB::table('community_members')->where('id', $newOwnerMembership->id)->update(['community_role_id' => null, 'updated_at' => now()]);
            $community->update(['owner_id' => $newOwner->id]);
            $this->logAdminAction('community.owner_transferred', 'community', $community->id, 'Previous owner inactive for at least '.$inactiveDays.' days.', [
                'previous_owner_id' => $previousOwner->id, 'new_owner_id' => $newOwner->id,
            ]);
        });
        $this->dispatch('notify', 'Community ownership transferred.');
    }

    public function saveSettings()
    {
        $this->authorizeAdmin();
        $this->validate([
            'announcementText' => ['nullable', 'string', 'max:1000'],
            'messageLimitPerMinute' => ['required', 'integer', 'min:1', 'max:120'],
            'commentLimitPerMinute' => ['required', 'integer', 'min:1', 'max:60'],
            'postLimitPerHour' => ['required', 'integer', 'min:1', 'max:100'],
            'inactiveOwnerDays' => ['required', 'integer', 'min:30', 'max:730'],
        ]);

        Cache::put('site_announcement', $this->announcementText);
        Cache::put('site_maintenance', $this->maintenanceMode);
        Cache::put('site_registrations', $this->allowRegistrations);
        Cache::put('site_global_commissions', $this->globalCommissionsOpen);
        Cache::put('site_spam_controls_enabled', $this->spamControlsEnabled);
        Cache::put('site_spam_messages_limit', $this->messageLimitPerMinute);
        Cache::put('site_spam_comments_limit', $this->commentLimitPerMinute);
        Cache::put('site_spam_posts_limit', $this->postLimitPerHour);
        Cache::put('site_inactive_owner_days', $this->inactiveOwnerDays);
        $this->logAdminAction('site.settings_updated', null, null, null, [
            'spam_controls_enabled' => $this->spamControlsEnabled,
            'message_limit_per_minute' => $this->messageLimitPerMinute,
            'comment_limit_per_minute' => $this->commentLimitPerMinute,
            'post_limit_per_hour' => $this->postLimitPerHour,
            'inactive_owner_days' => $this->inactiveOwnerDays,
        ]);

        $this->dispatch('notify', 'Site configuration and announcement updated successfully!');
    }

    public function render()
    {
        $totalUsers = User::count();
        $totalPosts = Post::count();
        $totalTags = Tag::count();
        $totalPools = Pool::count();
        $totalComments = Comment::count();
        $totalConversations = Conversation::count();

        // 1. Paginated Users
        $usersQuery = User::latest();
        if (! empty($this->userSearch)) {
            $s = trim($this->userSearch);
            $usersQuery->where(function ($q) use ($s) {
                $q->where('username', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%");
            });
        }
        $users = $usersQuery->paginate(8, ['*'], 'usersPage');

        // 2. Paginated Posts
        $postsQuery = Post::with(['user', 'primaryMedia', 'tags', 'comments'])->latest();
        if (! empty($this->contentSearch)) {
            $s = trim($this->contentSearch);
            $postsQuery->where('title', 'like', "%{$s}%");
        }
        $posts = $postsQuery->paginate(12, ['*'], 'postsPage');

        // 3. Paginated Pools
        $pools = Pool::with(['user', 'chapters'])->latest()->paginate(8, ['*'], 'poolsPage');

        // 4. Paginated Comments
        $commentsQuery = Comment::with(['user', 'post'])->latest();
        if (! empty($this->commentSearch)) {
            $s = trim($this->commentSearch);
            $commentsQuery->where('content', 'like', "%{$s}%");
        }
        $comments = $commentsQuery->paginate(10, ['*'], 'commentsPage');

        // 5. User Conversations Audit
        $conversationsQuery = Conversation::with(['userOne', 'userTwo', 'latestMessage'])->latest('last_message_at');
        if (! empty($this->messageSearch)) {
            $s = trim($this->messageSearch);
            $conversationsQuery->whereHas('userOne', function ($q) use ($s) {
                $q->where('username', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%");
            })->orWhereHas('userTwo', function ($q) use ($s) {
                $q->where('username', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%");
            });
        }
        $conversations = $conversationsQuery->paginate(8, ['*'], 'convsPage');

        $activeConversation = null;
        $activeMessages = collect();
        if ($this->selectedConversationId) {
            $activeConversation = Conversation::with(['userOne', 'userTwo'])->find($this->selectedConversationId);
            if ($activeConversation) {
                $activeMessages = Message::where('conversation_id', $activeConversation->id)
                    ->with(['sender', 'sharedPost.primaryMedia'])
                    ->oldest()
                    ->get();
            }
        }

        // Inspection Post
        $inspectedPost = $this->inspectPostId ? Post::with(['user', 'media', 'tags', 'comments.user'])->find($this->inspectPostId) : null;

        $popularTags = Tag::orderBy('posts_count', 'desc')->take(20)->get();
        $tagAliases = DB::table('tag_aliases')
            ->join('tags', 'tags.id', '=', 'tag_aliases.tag_id')
            ->select('tag_aliases.id', 'tag_aliases.alias', 'tags.name as target')
            ->orderBy('tag_aliases.alias')
            ->get();
        $tagProposals = DB::table('post_tag_proposals')
            ->join('posts', 'posts.id', '=', 'post_tag_proposals.post_id')
            ->join('users', 'users.id', '=', 'post_tag_proposals.requester_id')
            ->where('post_tag_proposals.status', 'pending')
            ->select('post_tag_proposals.*', 'posts.title as post_title', 'users.username as requester_name')
            ->oldest('post_tag_proposals.created_at')->get();
        $pendingAppeals = DB::table('user_appeals')
            ->join('users', 'users.id', '=', 'user_appeals.user_id')
            ->where('user_appeals.status', 'pending')
            ->select('user_appeals.*', 'users.username', 'users.suspension_reason')
            ->oldest('user_appeals.created_at')->limit(100)->get();
        $pendingContentReports = DB::table('content_reports')
            ->join('users as reporters', 'reporters.id', '=', 'content_reports.reporter_id')
            ->leftJoin('posts', function ($join): void {
                $join->on('posts.id', '=', 'content_reports.target_id')->where('content_reports.target_type', '=', 'post');
            })
            ->leftJoin('users as creators', 'creators.id', '=', 'posts.user_id')
            ->where('content_reports.status', 'pending')
            ->where('content_reports.target_type', 'post')
            ->select('content_reports.*', 'reporters.username as reporter_username', 'posts.title as post_title', 'creators.username as creator_username')
            ->oldest('content_reports.created_at')->limit(100)->get();
        $auditQuery = DB::table('admin_audit_logs')
            ->leftJoin('users as audit_actors', 'audit_actors.id', '=', 'admin_audit_logs.actor_id')
            ->select('admin_audit_logs.*', 'audit_actors.username as actor_username');
        if (filled($this->auditSearch)) {
            $search = trim($this->auditSearch);
            $auditQuery->where(function ($query) use ($search): void {
                $query->where('admin_audit_logs.action', 'like', "%{$search}%")
                    ->orWhere('admin_audit_logs.target_type', 'like', "%{$search}%")
                    ->orWhere('admin_audit_logs.reason', 'like', "%{$search}%")
                    ->orWhere('audit_actors.username', 'like', "%{$search}%");
            });
        }
        $adminAuditLogs = $auditQuery->orderByDesc('admin_audit_logs.created_at')->paginate(20, ['*'], 'auditPage');

        $inactiveOwnerDays = max(30, (int) Cache::get('site_inactive_owner_days', 90));
        $recoverableCommunities = Community::query()
            ->join('users as community_owners', 'community_owners.id', '=', 'communities.owner_id')
            ->where(function ($query) use ($inactiveOwnerDays): void {
                $query->where('community_owners.last_active_at', '<=', now()->subDays($inactiveOwnerDays))
                    ->orWhere(function ($query) use ($inactiveOwnerDays): void {
                        $query->whereNull('community_owners.last_active_at')
                            ->where('communities.created_at', '<=', now()->subDays($inactiveOwnerDays));
                    });
            })
            ->select('communities.*', 'community_owners.username as owner_username', 'community_owners.last_active_at as owner_last_active_at')
            ->orderBy('community_owners.last_active_at')
            ->paginate(10, ['*'], 'recoveryPage');
        $recoveryMembersByCommunity = DB::table('community_members')
            ->join('users', 'users.id', '=', 'community_members.user_id')
            ->join('communities', 'communities.id', '=', 'community_members.community_id')
            ->whereIn('community_members.community_id', $recoverableCommunities->getCollection()->pluck('id'))
            ->whereColumn('users.id', '!=', 'communities.owner_id')
            ->where('community_members.status', 'active')
            ->where('users.is_banned', false)
            ->select('community_members.community_id', 'users.id', 'users.username')
            ->orderBy('users.username')->get()->groupBy('community_id');

        return view('components.⚡admin-dashboard', [
            'totalUsers' => $totalUsers,
            'totalPosts' => $totalPosts,
            'totalTags' => $totalTags,
            'totalPools' => $totalPools,
            'totalComments' => $totalComments,
            'totalConversations' => $totalConversations,
            'users' => $users,
            'posts' => $posts,
            'popularTags' => $popularTags,
            'tagAliases' => $tagAliases,
            'tagProposals' => $tagProposals,
            'pendingAppeals' => $pendingAppeals,
            'pendingContentReports' => $pendingContentReports,
            'adminAuditLogs' => $adminAuditLogs,
            'recoverableCommunities' => $recoverableCommunities,
            'recoveryMembersByCommunity' => $recoveryMembersByCommunity,
            'inactiveOwnerDays' => $inactiveOwnerDays,
            'pools' => $pools,
            'comments' => $comments,
            'conversations' => $conversations,
            'activeConversation' => $activeConversation,
            'activeMessages' => $activeMessages,
            'inspectedPost' => $inspectedPost,
            'currentUser' => Auth::user(),
        ]);
    }

    private function authorizeAdmin(): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);
    }
};
?>

<div class="min-h-screen bg-[var(--bg-page)] text-[var(--text-main)] flex flex-col md:flex-row">
    <!-- Twitter-Style Vertical Left Navigation Bar -->
    <aside class="w-full md:w-64 flex-shrink-0 bg-[var(--bg-surface)] border-b md:border-b-0 md:border-r border-[var(--border-subtle)] flex flex-col justify-between p-4 md:sticky md:top-0 md:h-screen z-40">
        <div class="space-y-6">
            <!-- Brand Logo & Admin Badge -->
            <div class="flex items-center gap-3 px-2 pt-2">
                <a href="{{ route('admin') }}" class="flex items-center gap-3 group">
                    <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-purple-600 via-rose-500 to-amber-500 text-white flex items-center justify-center font-black text-lg shadow-lg group-hover:scale-105 transition">
                        🛡️
                    </div>
                    <div>
                        <div class="font-black text-base tracking-tight text-[var(--text-main)] flex items-center gap-1">
                            Booru<span class="accent-text">.art</span>
                        </div>
                        <span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider bg-rose-500/10 text-rose-400 border border-rose-500/20">Admin Panel</span>
                    </div>
                </a>
            </div>

            <!-- Twitter-Like Navigation Links -->
            <nav class="space-y-1.5">
                <button wire:click="setSection('overview')" 
                        class="w-full flex items-center gap-3.5 px-4 py-3 rounded-2xl font-extrabold text-xs transition {{ $activeSection === 'overview' ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                    <span>Overview</span>
                </button>

                <button wire:click="setSection('users')" 
                        class="w-full flex items-center gap-3.5 px-4 py-3 rounded-2xl font-extrabold text-xs transition {{ $activeSection === 'users' ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                    <span>Users & Bans</span>
                </button>

                <button wire:click="setSection('content')" 
                        class="w-full flex items-center gap-3.5 px-4 py-3 rounded-2xl font-extrabold text-xs transition {{ $activeSection === 'content' ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                    <span>Media & Videos</span>
                </button>

                <button wire:click="setSection('messages')" 
                        class="w-full flex items-center gap-3.5 px-4 py-3 rounded-2xl font-extrabold text-xs transition {{ $activeSection === 'messages' ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path></svg>
                    <span>User DMs Audit</span>
                </button>

                <button wire:click="setSection('comments')" 
                        class="w-full flex items-center gap-3.5 px-4 py-3 rounded-2xl font-extrabold text-xs transition {{ $activeSection === 'comments' ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"></path></svg>
                    <span>Comments</span>
                </button>

                <button wire:click="setSection('pools')" 
                        class="w-full flex items-center gap-3.5 px-4 py-3 rounded-2xl font-extrabold text-xs transition {{ $activeSection === 'pools' ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
                    <span>Pools & Series</span>
                </button>

                <button wire:click="setSection('tags')" 
                        class="w-full flex items-center gap-3.5 px-4 py-3 rounded-2xl font-extrabold text-xs transition {{ $activeSection === 'tags' ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path></svg>
                    <span>Tag Aliases</span>
                </button>

                <button wire:click="setSection('appeals')" class="w-full rounded-2xl px-4 py-3 text-left text-xs font-extrabold transition {{ $activeSection === 'appeals' ? 'accent-bg text-white' : 'text-[var(--text-muted)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    Appeals <span class="ml-1 rounded-full bg-[var(--bg-page)] px-2 py-0.5">{{ $pendingAppeals->count() }}</span>
                </button>
                <button wire:click="setSection('reports')" class="w-full rounded-2xl px-4 py-3 text-left text-xs font-extrabold transition {{ $activeSection === 'reports' ? 'accent-bg text-white' : 'text-[var(--text-muted)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    Content reports <span class="ml-1 rounded-full bg-[var(--bg-page)] px-2 py-0.5">{{ $pendingContentReports->count() }}</span>
                </button>
                <button wire:click="setSection('audit')" class="w-full rounded-2xl px-4 py-3 text-left text-xs font-extrabold transition {{ $activeSection === 'audit' ? 'accent-bg text-white' : 'text-[var(--text-muted)] hover:bg-[var(--bg-surface-elevated)]' }}">Admin audit log</button>
                <button wire:click="setSection('recovery')" class="w-full rounded-2xl px-4 py-3 text-left text-xs font-extrabold transition {{ $activeSection === 'recovery' ? 'accent-bg text-white' : 'text-[var(--text-muted)] hover:bg-[var(--bg-surface-elevated)]' }}">Community recovery <span class="ml-1 rounded-full bg-[var(--bg-page)] px-2 py-0.5">{{ $recoverableCommunities->total() }}</span></button>

                <button wire:click="setSection('settings')" 
                        class="w-full flex items-center gap-3.5 px-4 py-3 rounded-2xl font-extrabold text-xs transition {{ $activeSection === 'settings' ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    <span>Site Config</span>
                </button>
            </nav>
        </div>

        <!-- Admin Profile & Back to Site Card -->
        <div class="pt-4 border-t border-[var(--border-subtle)] space-y-3">
            <div class="p-3 rounded-2xl bg-[var(--bg-surface-elevated)] flex items-center justify-between">
                <div class="flex items-center gap-2.5 min-w-0">
                    <img src="{{ $currentUser->avatar_url }}" class="w-8 h-8 rounded-full object-cover shrink-0 border border-purple-500/50">
                    <div class="min-w-0">
                        <div class="font-bold text-xs text-[var(--text-main)] truncate">{{ $currentUser->name }}</div>
                        <div class="text-[10px] text-[var(--text-dim)] truncate">{{ $currentUser->username }}</div>
                    </div>
                </div>
            </div>

            <a href="{{ route('gallery') }}" class="w-full flex items-center justify-center gap-2 py-2.5 rounded-2xl bg-[var(--bg-page)] hover:bg-[var(--border-subtle)] text-[var(--text-main)] text-xs font-bold transition border border-[var(--border-subtle)] shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                <span>Return to Site</span>
            </a>
        </div>
    </aside>

    <!-- Admin Main Content Body Area -->
    <main class="flex-1 p-4 md:p-8 space-y-8 min-w-0 overflow-x-hidden">
        <!-- Overview Section -->
        @if($activeSection === 'overview')
            <!-- Stat Cards -->
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-1 shadow-sm">
                    <div class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Total Users</div>
                    <div class="text-3xl font-black text-[var(--text-main)]">{{ number_format($totalUsers) }}</div>
                </div>
                <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-1 shadow-sm">
                    <div class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Artworks & Videos</div>
                    <div class="text-3xl font-black accent-text">{{ number_format($totalPosts) }}</div>
                </div>
                <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-1 shadow-sm">
                    <div class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">User DMs Audit</div>
                    <div class="text-3xl font-black text-[var(--text-main)]">{{ number_format($totalConversations) }}</div>
                </div>
                <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-1 shadow-sm">
                    <div class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Comments</div>
                    <div class="text-3xl font-black text-[var(--text-main)]">{{ number_format($totalComments) }}</div>
                </div>
                <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-1 shadow-sm">
                    <div class="text-xs font-bold text-[var(--text-dim)] uppercase tracking-wider">Indexed Tags</div>
                    <div class="text-3xl font-black text-[var(--text-main)]">{{ number_format($totalTags) }}</div>
                </div>
            </div>

            <!-- Global Announcement Banner Editor -->
            <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="font-bold text-lg text-[var(--text-main)]">📢 Site Broadcast Announcement</h3>
                        <p class="text-xs text-[var(--text-dim)]">Publish a live site-wide announcement banner visible to all visitors.</p>
                    </div>
                    <button wire:click="saveSettings" class="px-5 py-2.5 rounded-2xl accent-bg text-white font-bold text-xs shadow hover:opacity-90 transition">
                        Update Broadcast
                    </button>
                </div>
                <input type="text" wire:model="announcementText" placeholder="Enter broadcast announcement message..."
                       class="w-full px-4 py-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-xs outline-none focus:border-[var(--accent-primary)] font-medium">
            </div>

            <!-- Recent Uploads Quick Audit -->
            <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-4 shadow-sm">
                <h3 class="font-bold text-lg">Recent Artwork Uploads (Click to Inspect)</h3>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-3">
                    @foreach($posts->take(6) as $p)
                        <div class="relative group rounded-2xl overflow-hidden border border-[var(--border-subtle)] aspect-square bg-neutral-900 cursor-pointer"
                             wire:click="openInspectModal({{ $p->id }})">
                            @if($p->media_type === 'video')
                                <video src="{{ $p->primaryMedia->url }}" class="w-full h-full object-cover" muted></video>
                                <span class="absolute top-2 left-2 px-1.5 py-0.5 rounded text-[9px] font-black bg-purple-600 text-white shadow">VIDEO</span>
                            @else
                                <img src="{{ $p->primaryMedia->thumbnail_url ?? $p->primaryMedia->url }}" class="w-full h-full object-cover">
                            @endif
                            <div class="absolute inset-0 bg-black/70 opacity-0 group-hover:opacity-100 transition p-3 flex flex-col justify-between text-white text-xs">
                                <div>
                                    <div class="font-bold truncate">{{ $p->title }}</div>
                                    <div class="text-[10px] opacity-75">by {{ $p->user->username }}</div>
                                </div>
                                <div class="flex items-center gap-1">
                                    <span class="px-2 py-1 rounded text-[10px] font-black bg-white/20 backdrop-blur">Inspect 🔍</span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Users & Bans Section -->
        @if($activeSection === 'users')
            <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-6 shadow-sm">
                <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-4">
                    <div>
                        <h3 class="font-bold text-lg">User Management & Ban Control</h3>
                        <p class="text-xs text-[var(--text-dim)]">Search users, manage roles, grant verified status, or ban accounts.</p>
                    </div>
                    <input type="text" wire:model.live.debounce.200ms="userSearch" placeholder="Search by name or @username..."
                           class="px-4 py-2 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-xs outline-none focus:border-[var(--accent-primary)] w-full sm:w-72">
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="border-b border-[var(--border-subtle)] text-[var(--text-dim)] uppercase text-[10px]">
                                <th class="py-3 px-3">User Account</th>
                                <th class="py-3 px-3">Role</th>
                                <th class="py-3 px-3">Artist Status</th>
                                <th class="py-3 px-3">Account Status</th>
                                <th class="py-3 px-3 text-right">Admin Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--border-subtle)]">
                            @foreach($users as $u)
                                <tr class="{{ $u->is_banned ? 'bg-rose-500/5' : '' }}">
                                    <td class="py-3 px-3 flex items-center gap-3">
                                        <img src="{{ $u->avatar_url }}" class="w-9 h-9 rounded-full object-cover">
                                        <div>
                                            <a href="{{ route('profile', $u->username) }}" class="font-bold text-[var(--text-main)] hover:underline flex items-center gap-1.5">
                                                {{ $u->name }}
                                                @if($u->is_banned)
                                                    <span class="px-1.5 py-0.2 rounded text-[9px] font-black bg-rose-500 text-white">BANNED</span>
                                                @endif
                                            </a>
                                            <div class="text-[var(--text-dim)] text-[11px]">{{ $u->username }}</div>
                                        </div>
                                    </td>
                                    <td class="py-3 px-3">
                                        @if($u->isAdmin())
                                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-purple-500/20 text-purple-400 border border-purple-500/30">ADMIN</span>
                                        @else
                                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-white/10 text-[var(--text-muted)]">USER</span>
                                        @endif
                                    </td>
                                    <td class="py-3 px-3">
                                        <button wire:click="toggleUserArtist({{ $u->id }})" class="px-2.5 py-1 rounded-xl font-bold text-[10px] border transition {{ $u->is_artist ? 'accent-bg text-white border-transparent' : 'border-[var(--border-subtle)] text-[var(--text-dim)] hover:text-[var(--text-main)]' }}">
                                            {{ $u->is_artist ? '✓ Verified Artist' : '+ Grant Artist' }}
                                        </button>
                                    </td>
                                    <td class="py-3 px-3">
                                        @if($u->is_banned)
                                            <span class="px-2.5 py-1 rounded-xl font-bold text-[10px] bg-rose-500/20 text-rose-400 border border-rose-500/30">Suspended</span>
                                        @else
                                            <span class="px-2.5 py-1 rounded-xl font-bold text-[10px] bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">Active</span>
                                        @endif
                                    </td>
                                    <td class="py-3 px-3 text-right space-x-1.5">
                                        @if($u->id !== auth()->id())
                                            @if($u->isAdmin())
                                                <span class="px-2.5 py-1 text-[10px] text-[var(--text-dim)]">Admin protected</span>
                                            @elseif($u->is_banned)
                                                <button wire:click="toggleUserBan({{ $u->id }})" class="px-2.5 py-1 rounded-xl text-[10px] font-bold border bg-emerald-500 text-white border-transparent">Lift suspension</button>
                                            @else
                                                <button wire:click="prepareSuspension({{ $u->id }})" class="px-2.5 py-1 rounded-xl text-[10px] font-bold border border-rose-500/40 text-rose-400 hover:bg-rose-500/10">Suspend…</button>
                                            @endif
                                            <button wire:click="toggleUserAdmin({{ $u->id }})" class="px-2.5 py-1 rounded-xl text-[10px] font-bold border border-purple-500/30 text-purple-400 hover:bg-purple-500/10 transition">
                                                {{ $u->is_admin ? 'Demote Admin' : 'Make Admin' }}
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <!-- Livewire Pagination Links for Users -->
                <div class="pt-2">
                    {{ $users->links() }}
                </div>
            </div>
        @endif

        <!-- Media & Video Moderation Section -->
        @if($activeSection === 'content')
            <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-6 shadow-sm">
                <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-4">
                    <div>
                        <h3 class="font-bold text-lg">Media & Video Moderation</h3>
                        <p class="text-xs text-[var(--text-dim)]">Audit uploaded artworks and videos, set featured status, toggle NSFW rating, inspect or delete.</p>
                    </div>
                    <input type="text" wire:model.live.debounce.200ms="contentSearch" placeholder="Search media by title..."
                           class="px-4 py-2 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-xs outline-none focus:border-[var(--accent-primary)] w-full sm:w-72">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                    @foreach($posts as $p)
                        <div class="p-4 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] space-y-3 flex flex-col justify-between">
                            <div class="flex items-start gap-3">
                                <div class="relative w-16 h-16 rounded-xl overflow-hidden bg-neutral-900 shrink-0 cursor-pointer"
                                     wire:click="openInspectModal({{ $p->id }})">
                                    @if($p->media_type === 'video')
                                        <video src="{{ $p->primaryMedia->url }}" class="w-full h-full object-cover" muted></video>
                                        <span class="absolute bottom-1 right-1 px-1 rounded text-[8px] font-black bg-purple-600 text-white">VID</span>
                                    @else
                                        <img src="{{ $p->primaryMedia->thumbnail_url ?? $p->primaryMedia->url }}" class="w-full h-full object-cover">
                                    @endif
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="font-bold text-xs text-[var(--text-main)] truncate">{{ $p->title }}</div>
                                    <div class="text-[11px] text-[var(--text-dim)]">by {{ $p->user->username }}</div>
                                    <div class="flex items-center gap-1.5 mt-1">
                                        <a href="{{ route('post.detail', ['id' => $p->id, 'from_admin' => 1]) }}" class="text-[10px] font-bold accent-text hover:underline flex items-center gap-0.5">
                                            <span>Full Page ↗</span>
                                        </a>
                                        @if($p->is_featured)
                                            <span class="px-2 py-0.5 rounded text-[9px] font-bold bg-amber-500/20 text-amber-400">★ FEATURED</span>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center justify-between gap-1.5 pt-2 border-t border-[var(--border-subtle)]">
                                <button wire:click="openInspectModal({{ $p->id }})" class="px-2.5 py-1 rounded-xl text-[10px] font-bold bg-[var(--bg-surface-elevated)] text-[var(--text-main)] hover:bg-[var(--border-subtle)] transition">
                                    Inspect 🔍
                                </button>
                                <button wire:click="togglePostNsfw({{ $p->id }})" class="px-2 py-1 rounded-xl text-[10px] font-bold border transition {{ $p->is_nsfw ? 'bg-rose-500/20 text-rose-400 border-rose-500/30' : 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30' }}">
                                    {{ $p->is_nsfw ? 'NSFW' : 'SAFE' }}
                                </button>
                                <button wire:click="togglePostFeatured({{ $p->id }})" class="px-2 py-1 rounded-xl text-[10px] font-bold border transition {{ $p->is_featured ? 'bg-amber-500 text-black font-extrabold border-transparent' : 'border-[var(--border-subtle)] text-[var(--text-dim)]' }}">
                                    {{ $p->is_featured ? '★ Featured' : '+ Feature' }}
                                </button>
                                <button wire:click="deletePost({{ $p->id }})" class="px-2 py-1 rounded-xl text-[10px] font-bold bg-rose-500 text-white hover:opacity-90 transition">
                                    Delete
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>

                <!-- Livewire Pagination Links for Posts -->
                <div class="pt-2">
                    {{ $posts->links() }}
                </div>
            </div>
        @endif

        <!-- User Messages Audit Section -->
        @if($activeSection === 'messages')
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Conversations Directory List -->
                <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-4 shadow-sm md:col-span-1">
                    <div class="space-y-1">
                        <h3 class="font-bold text-base">User Conversations</h3>
                        <p class="text-[11px] text-[var(--text-dim)]">Select any user DM thread to inspect messages and download chat logs.</p>
                    </div>

                    <input type="text" wire:model.live.debounce.200ms="messageSearch" placeholder="Search by participant name..."
                           class="w-full px-3.5 py-2 rounded-xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-xs outline-none focus:border-[var(--accent-primary)]">

                    <div class="space-y-2">
                        @forelse($conversations as $conv)
                            <div wire:click="selectConversation({{ $conv->id }})"
                                 class="p-3 rounded-2xl border transition cursor-pointer flex items-center justify-between gap-3 {{ $selectedConversationId === $conv->id ? 'accent-bg text-white border-transparent shadow' : 'bg-[var(--bg-page)] border-[var(--border-subtle)] hover:bg-[var(--bg-surface-elevated)]' }}">
                                <div class="flex items-center gap-2.5 min-w-0">
                                    <div class="flex -space-x-2 overflow-hidden shrink-0">
                                        <img src="{{ $conv->userOne->avatar_url }}" class="inline-block h-7 w-7 rounded-full ring-2 ring-[var(--bg-surface)] object-cover">
                                        <img src="{{ $conv->userTwo->avatar_url }}" class="inline-block h-7 w-7 rounded-full ring-2 ring-[var(--bg-surface)] object-cover">
                                    </div>
                                    <div class="min-w-0">
                                        <div class="font-bold text-xs truncate">
                                            {{ $conv->userOne->name }} & {{ $conv->userTwo->name }}
                                        </div>
                                        <div class="text-[10px] opacity-75 truncate">
                                            {{ $conv->latestMessage->text ?? 'Shared post attachment' }}
                                        </div>
                                    </div>
                                </div>
                                <span class="text-[10px] font-bold opacity-60 shrink-0">#{{ $conv->id }}</span>
                            </div>
                        @empty
                            <div class="p-6 text-center text-xs text-[var(--text-dim)]">No conversations found.</div>
                        @endforelse
                    </div>

                    <div class="pt-2">
                        {{ $conversations->links() }}
                    </div>
                </div>

                <!-- Selected Conversation Thread Viewer & Download Actions -->
                <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-6 shadow-sm md:col-span-2 flex flex-col justify-between">
                    @if($activeConversation)
                        <div class="space-y-6">
                            <!-- Header & Download Buttons -->
                            <div class="flex flex-wrap items-center justify-between gap-4 pb-4 border-b border-[var(--border-subtle)]">
                                <div class="flex items-center gap-3">
                                    <div class="flex -space-x-2 overflow-hidden">
                                        <img src="{{ $activeConversation->userOne->avatar_url }}" class="h-9 w-9 rounded-full object-cover">
                                        <img src="{{ $activeConversation->userTwo->avatar_url }}" class="h-9 w-9 rounded-full object-cover">
                                    </div>
                                    <div>
                                        <div class="font-black text-sm text-[var(--text-main)]">
                                            {{ $activeConversation->userOne->username }} ↔ {{ $activeConversation->userTwo->username }}
                                        </div>
                                        <div class="text-xs text-[var(--text-dim)]">Conversation #{{ $activeConversation->id }} · {{ $activeMessages->count() }} Messages</div>
                                    </div>
                                </div>

                                <div class="flex items-center gap-2">
                                    <a href="{{ route('admin.export-chat', $activeConversation->id) }}" 
                                       target="_blank"
                                       class="px-3.5 py-2 rounded-xl accent-bg text-white font-bold text-xs shadow flex items-center gap-1.5 hover:opacity-90 transition">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                                        <span>Download Chat (JSON)</span>
                                    </a>

                                    <a href="{{ route('admin.export-chat-media', $activeConversation->id) }}" 
                                       target="_blank"
                                       class="px-3.5 py-2 rounded-xl bg-[var(--bg-surface-elevated)] border border-[var(--border-subtle)] text-[var(--text-main)] font-bold text-xs flex items-center gap-1.5 hover:bg-[var(--border-subtle)] transition">
                                        <svg class="w-4 h-4 accent-text" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                        <span>Download Shared Media</span>
                                    </a>
                                </div>
                            </div>

                            <!-- Chat Messages Stream -->
                            <div class="space-y-4 max-h-[480px] overflow-y-auto pr-2">
                                @foreach($activeMessages as $m)
                                    <div class="flex items-start gap-3">
                                        <img src="{{ $m->sender->avatar_url }}" class="w-8 h-8 rounded-full object-cover shrink-0">
                                        <div class="space-y-1 max-w-xl">
                                            <div class="flex items-center gap-2">
                                                <a href="{{ route('profile', $m->sender->username) }}" class="font-bold text-xs text-[var(--text-main)] hover:underline">
                                                    {{ $m->sender->name }}
                                                </a>
                                                <span class="text-[10px] text-[var(--text-dim)]">{{ $m->sender->username }} · {{ $m->created_at->format('M d, H:i') }}</span>
                                            </div>
                                            <div class="p-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-xs text-[var(--text-main)] space-y-2">
                                                @if($m->text)
                                                    <p class="leading-relaxed">{{ $m->text }}</p>
                                                @endif

                                                @if($m->sharedPost)
                                                    <div class="p-2.5 rounded-xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] flex items-center gap-3">
                                                        <img src="{{ $m->sharedPost->primaryMedia->thumbnail_url ?? $m->sharedPost->primaryMedia->url }}" class="w-12 h-12 rounded-lg object-cover">
                                                        <div>
                                                            <div class="font-bold text-xs accent-text">{{ $m->sharedPost->title }}</div>
                                                            <a href="{{ route('post.detail', ['id' => $m->sharedPost->id, 'from_admin' => 1]) }}" class="text-[10px] text-[var(--text-dim)] hover:underline">View Post #{{ $m->sharedPost->id }} ↗</a>
                                                        </div>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <div class="p-16 text-center space-y-3 my-auto">
                            <div class="w-12 h-12 rounded-2xl bg-purple-500/10 text-purple-400 flex items-center justify-center mx-auto text-xl font-bold">💬</div>
                            <h4 class="font-bold text-base">Select a User Conversation</h4>
                            <p class="text-xs text-[var(--text-dim)] max-w-xs mx-auto">Choose any conversation thread from the left panel to inspect message history and download logs.</p>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        <!-- Comments Moderation Section -->
        @if($activeSection === 'comments')
            <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-6 shadow-sm">
                <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-4">
                    <div>
                        <h3 class="font-bold text-lg">Comment & Activity Moderation</h3>
                        <p class="text-xs text-[var(--text-dim)]">Review user comments, flag inappropriate remarks, or delete spam.</p>
                    </div>
                    <input type="text" wire:model.live.debounce.200ms="commentSearch" placeholder="Search comment text..."
                           class="px-4 py-2 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-xs outline-none focus:border-[var(--accent-primary)] w-full sm:w-72">
                </div>

                <div class="space-y-3">
                    @forelse($comments as $c)
                        <div class="p-4 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] flex items-start justify-between gap-4">
                            <div class="flex items-start gap-3 min-w-0">
                                <img src="{{ $c->user->avatar_url }}" class="w-8 h-8 rounded-full object-cover shrink-0">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <a href="{{ route('profile', $c->user->username) }}" class="font-bold text-xs text-[var(--text-main)] hover:underline">{{ $c->user->name }}</a>
                                        <span class="text-[11px] text-[var(--text-dim)]">{{ $c->user->username }}</span>
                                        <span class="text-[10px] text-[var(--text-dim)]">· {{ $c->created_at->diffForHumans() }}</span>
                                    </div>
                                    <p class="text-xs text-[var(--text-main)] mt-1 font-medium">{{ $c->content }}</p>
                                    @if($c->post)
                                        <div class="text-[10px] text-[var(--text-dim)] mt-1">On Post: <a href="{{ route('post.detail', ['id' => $c->post->id, 'from_admin' => 1]) }}" class="accent-text font-bold hover:underline">#{{ $c->post->id }} - {{ $c->post->title }}</a></div>
                                    @endif
                                </div>
                            </div>

                            <div class="flex items-center gap-2 shrink-0">
                                <button wire:click="toggleCommentFlag({{ $c->id }})" class="px-2.5 py-1 rounded-xl text-[10px] font-bold border transition {{ $c->is_flagged ? 'bg-amber-500/20 text-amber-400 border-amber-500/30' : 'border-[var(--border-subtle)] text-[var(--text-dim)]' }}">
                                    {{ $c->is_flagged ? '🚩 Flagged' : 'Flag' }}
                                </button>
                                <button wire:click="deleteComment({{ $c->id }})" class="px-2.5 py-1 rounded-xl text-[10px] font-bold bg-rose-500 text-white hover:opacity-90 transition">
                                    Delete
                                </button>
                            </div>
                        </div>
                    @empty
                        <div class="p-8 text-center text-xs text-[var(--text-dim)]">No comments found matching your query.</div>
                    @endforelse
                </div>

                <!-- Livewire Pagination Links for Comments -->
                <div class="pt-2">
                    {{ $comments->links() }}
                </div>
            </div>
        @endif

        <!-- Pools & Manga Section -->
        @if($activeSection === 'pools')
            <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-6 shadow-sm">
                <div>
                    <h3 class="font-bold text-lg">Manga Series & Pool Moderation</h3>
                    <p class="text-xs text-[var(--text-dim)]">Manage series edit locks, feature top series, or delete invalid pools.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach($pools as $pool)
                        <div class="p-4 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] flex items-center justify-between gap-4">
                            <div class="flex items-center gap-3">
                                <img src="{{ $pool->cover_url }}" class="w-12 h-16 rounded-xl object-cover">
                                <div>
                                    <div class="font-bold text-xs text-[var(--text-main)]">{{ $pool->title }}</div>
                                    <div class="text-[11px] text-[var(--text-dim)]">by {{ $pool->user->username }} · {{ $pool->chapters_count }} Ch</div>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <button wire:click="togglePoolFeatured({{ $pool->id }})" class="px-2 py-1 rounded-xl text-[10px] font-bold border transition {{ $pool->is_featured ? 'bg-amber-500 text-black font-extrabold border-transparent' : 'border-[var(--border-subtle)] text-[var(--text-dim)]' }}">
                                    {{ $pool->is_featured ? '★ Featured' : '+ Feature' }}
                                </button>
                                <button wire:click="togglePoolLock({{ $pool->id }})" class="px-2 py-1 rounded-xl text-[10px] font-bold border transition {{ $pool->is_locked ? 'bg-amber-500/20 text-amber-400 border-amber-500/30' : 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30' }}">
                                    {{ $pool->is_locked ? '🔒 Locked' : '🔓 Open' }}
                                </button>
                                <button wire:click="deletePool({{ $pool->id }})" class="px-2 py-1 rounded-xl text-[10px] font-bold bg-rose-500 text-white hover:opacity-90 transition">
                                    Delete
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>

                <!-- Livewire Pagination Links for Pools -->
                <div class="pt-2">
                    {{ $pools->links() }}
                </div>
            </div>
        @endif

        <!-- Tag Aliases Section -->
        @if($activeSection === 'tags')
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="md:col-span-2 rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6">
                    <h3 class="text-lg font-bold">Artwork tag requests <span class="text-sm text-[var(--text-dim)]">({{ $tagProposals->count() }} pending)</span></h3>
                    <p class="mt-1 text-xs text-[var(--text-dim)]">Review proposed additions and removals. Approved changes update artwork tags and tag counts.</p>
                    <div class="mt-4 space-y-3">
                        @forelse($tagProposals as $proposal)
                            <article class="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-[var(--bg-page)] p-4 text-sm">
                                <div><span class="font-bold">{{ ucfirst($proposal->action) }} #{{ $proposal->tag_name }}</span> · <a class="underline" href="{{ route('post.detail', $proposal->post_id) }}">{{ $proposal->post_title ?: 'Artwork #'.$proposal->post_id }}</a><p class="text-xs text-[var(--text-dim)]">Requested by {{ $proposal->requester_name }}{{ $proposal->reason ? ' · '.$proposal->reason : '' }}</p></div>
                                <div class="flex gap-2"><button wire:click="reviewTagProposal({{ $proposal->id }}, 'approve')" class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white">Approve</button><button wire:click="reviewTagProposal({{ $proposal->id }}, 'reject')" class="rounded-lg bg-rose-600 px-3 py-2 text-xs font-bold text-white">Reject</button></div>
                            </article>
                        @empty
                            <p class="text-sm text-[var(--text-dim)]">No pending tag requests.</p>
                        @endforelse
                    </div>
                </div>
                <!-- Add Alias Form -->
                <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-4 shadow-sm">
                    <h3 class="font-bold text-lg">Create Tag Alias</h3>
                    <p class="text-xs text-[var(--text-dim)]">Map user search terms directly to canonical tags (e.g., #cat_girl → #nekomimi).</p>
                    <div class="space-y-3">
                        <div>
                            <label class="text-[11px] font-bold text-[var(--text-dim)]">Search Term (Alias)</label>
                            <input type="text" wire:model="aliasFrom" placeholder="cat_girl" class="w-full px-3.5 py-2 rounded-xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-xs outline-none">
                        </div>
                        <div>
                            <label class="text-[11px] font-bold text-[var(--text-dim)]">Target Tag</label>
                            <input type="text" wire:model="aliasTo" placeholder="nekomimi" class="w-full px-3.5 py-2 rounded-xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-xs outline-none">
                        </div>
                        <button wire:click="addTagAlias" class="w-full py-2.5 rounded-xl accent-bg text-white font-bold text-xs shadow hover:opacity-90 transition">
                            Save Tag Alias
                        </button>
                    </div>
                </div>

                <div class="space-y-6">
                    <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-4 shadow-sm">
                        <h3 class="font-bold text-lg">Saved Aliases</h3>
                        @forelse($tagAliases as $tagAlias)
                            <div class="flex items-center justify-between gap-3 text-sm">
                                <span>#{{ $tagAlias->alias }} → #{{ $tagAlias->target }}</span>
                                <button type="button" wire:click="deleteTagAlias({{ $tagAlias->id }})" class="text-rose-400 hover:text-rose-300">Remove</button>
                            </div>
                        @empty
                            <p class="text-xs text-[var(--text-dim)]">No custom aliases yet.</p>
                        @endforelse
                    </div>
                    <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-4 shadow-sm">
                        <h3 class="font-bold text-lg">Popular Database Tags</h3>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($popularTags as $t)
                                <span class="px-2.5 py-1 rounded-xl text-xs font-semibold border {{ $t->getTypeBadgeClasses() }}">
                                    #{{ $t->name }} <span class="opacity-60 text-[10px]">({{ $t->posts_count }})</span>
                                </span>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if($activeSection === 'reports')
            <section class="space-y-4 rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6">
                <header><h2 class="text-xl font-black">Reported posts</h2><p class="text-sm text-[var(--text-muted)]">Review reports submitted by gallery members.</p></header>
                @forelse($pendingContentReports as $report)
                    <article class="flex flex-col gap-4 rounded-2xl bg-[var(--bg-page)] p-4 lg:flex-row lg:items-center lg:justify-between">
                        <div class="space-y-1">
                            <p class="text-sm font-bold">{{ $report->post_title ?: 'Untitled post' }} <span class="font-normal text-[var(--text-dim)]">by @{{ $report->creator_username }}</span></p>
                            <p class="text-xs text-[var(--text-muted)]">Reason: {{ str($report->reason)->replace('_', ' ')->title() }} · reported by @{{ $report->reporter_username }} · {{ $report->created_at }}</p>
                            @if($report->details)<p class="text-sm">{{ $report->details }}</p>@endif
                        </div>
                        <div class="flex shrink-0 gap-2">
                            @if($report->post_title)<a href="{{ route('post.detail', $report->target_id) }}?from_admin=1" class="rounded-xl border border-[var(--border-medium)] px-3 py-2 text-xs font-bold">Inspect post</a>@endif
                            <button wire:click="reviewContentReport({{ $report->id }}, 'dismiss')" class="rounded-xl border border-[var(--border-medium)] px-3 py-2 text-xs font-bold">Dismiss</button>
                            @if($report->post_title)<button wire:click="reviewContentReport({{ $report->id }}, 'remove')" wire:confirm="Remove this reported post?" class="rounded-xl bg-rose-500 px-3 py-2 text-xs font-bold text-white">Remove post</button>@endif
                        </div>
                    </article>
                @empty
                    <p class="rounded-2xl bg-[var(--bg-page)] p-5 text-sm text-[var(--text-dim)]">No pending post reports.</p>
                @endforelse
            </section>
        @endif

        @if($activeSection === 'appeals')
            <section class="space-y-4 rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6">
                <header><h2 class="text-xl font-black">Suspension appeals</h2><p class="text-sm text-[var(--text-muted)]">Review the suspension reason and the user’s statement. Accepting an appeal restores account access.</p></header>
                @forelse($pendingAppeals as $appeal)
                    <article class="space-y-3 rounded-2xl bg-[var(--bg-page)] p-4">
                        <div class="flex flex-wrap justify-between gap-2"><div class="font-bold">{{ $appeal->username }} · appeal #{{ $appeal->id }}</div><time class="text-xs text-[var(--text-dim)]">{{ \Illuminate\Support\Carbon::parse($appeal->created_at)->diffForHumans() }}</time></div>
                        <p class="text-xs text-rose-300"><b>Suspension reason:</b> {{ $appeal->suspension_reason ?: 'No reason recorded' }}</p>
                        <p class="whitespace-pre-line rounded-xl bg-[var(--bg-surface)] p-3 text-sm">{{ $appeal->statement }}</p>
                        <textarea wire:model="appealResponse" rows="2" maxlength="1000" placeholder="Response to the user (required)" class="w-full rounded-xl border bg-[var(--bg-surface)] p-3 text-sm"></textarea>@error('appealResponse')<p class="text-xs text-rose-400">{{ $message }}</p>@enderror
                        <div class="flex flex-wrap gap-2"><button wire:click="reviewAppeal({{ $appeal->id }}, 'accept')" class="rounded-xl bg-emerald-600 px-4 py-2 text-xs font-bold text-white">Accept and restore access</button><button wire:click="reviewAppeal({{ $appeal->id }}, 'reject')" class="rounded-xl bg-rose-600 px-4 py-2 text-xs font-bold text-white">Reject appeal</button></div>
                    </article>
                @empty
                    <p class="rounded-2xl bg-[var(--bg-page)] p-5 text-sm text-[var(--text-muted)]">No pending appeals.</p>
                @endforelse
            </section>
        @endif

        @if($activeSection === 'audit')
            <section class="space-y-4 rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6">
                <header><h2 class="text-xl font-black">Admin audit log</h2><p class="text-sm text-[var(--text-muted)]">Sensitive admin actions with actor, target, reason, request IP, and timestamp.</p></header>
                <input wire:model.live.debounce.300ms="auditSearch" placeholder="Search action, admin, target type, or reason" class="w-full rounded-xl border bg-[var(--bg-page)] px-4 py-3 text-sm">
                <div class="space-y-2">@forelse($adminAuditLogs as $entry)<article class="grid gap-2 rounded-2xl bg-[var(--bg-page)] p-4 text-sm sm:grid-cols-[1fr_auto]"><div><b>{{ str_replace('.', ' · ', $entry->action) }}</b><span class="ml-2 text-xs text-[var(--text-dim)]">by {{ $entry->actor_username ? '@'.$entry->actor_username : 'deleted admin' }}</span><p class="mt-1 text-xs text-[var(--text-muted)]">{{ $entry->target_type ? ucfirst($entry->target_type).' #'.$entry->target_id : 'Platform' }}{{ $entry->reason ? ' · '.$entry->reason : '' }}</p><p class="mt-1 break-all font-mono text-[10px] text-[var(--text-dim)]">{{ $entry->ip_address }}</p></div><time class="text-xs text-[var(--text-dim)]">{{ \Illuminate\Support\Carbon::parse($entry->created_at)->format('M j, Y g:i A') }}</time></article>@empty<p class="text-sm text-[var(--text-muted)]">No matching admin actions.</p>@endforelse</div>
                {{ $adminAuditLogs->links() }}
            </section>
        @endif

        @if($activeSection === 'recovery')
            <section class="space-y-4 rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6">
                <header><h2 class="text-xl font-black">Community ownership recovery</h2><p class="text-sm text-[var(--text-muted)]">Communities appear here after their owner has had no recorded activity for {{ $inactiveOwnerDays }} days. Transfer only to an active member.</p></header>
                @forelse($recoverableCommunities as $community)
                    <article class="flex flex-col gap-3 rounded-2xl bg-[var(--bg-page)] p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div><h3 class="font-bold">{{ $community->name }}</h3><p class="text-xs text-[var(--text-muted)]">Owner {{ $community->owner_username }} · {{ $community->owner_last_active_at ? \Illuminate\Support\Carbon::parse($community->owner_last_active_at)->diffForHumans() : 'activity unknown' }}</p></div>
                        @php($eligibleMembers = $recoveryMembersByCommunity->get($community->id, collect()))
                        @if($eligibleMembers->isNotEmpty())<div class="flex flex-wrap gap-2"><select id="new-owner-{{ $community->id }}" class="rounded-xl border bg-[var(--bg-surface)] px-3 py-2 text-sm">@foreach($eligibleMembers as $member)<option value="{{ $member->id }}">{{ $member->username }}</option>@endforeach</select><button x-on:click="if (window.confirm('Transfer community ownership to this member? This action will be recorded in the admin audit log.')) $wire.transferCommunityOwnership({{ $community->id }}, Number(document.getElementById('new-owner-{{ $community->id }}').value))" class="rounded-xl bg-amber-600 px-3 py-2 text-xs font-bold text-white">Transfer ownership</button></div>@else<p class="text-xs text-[var(--text-dim)]">No active member can receive ownership yet.</p>@endif
                    </article>
                @empty<p class="rounded-2xl bg-[var(--bg-page)] p-5 text-sm text-[var(--text-muted)]">No communities currently meet the inactivity threshold.</p>@endforelse
                {{ $recoverableCommunities->links() }}
            </section>
        @endif

        <!-- Site Configuration Section -->
        @if($activeSection === 'settings')
            <div class="p-6 rounded-3xl bg-[var(--bg-surface)] border border-[var(--border-subtle)] space-y-6 shadow-sm">
                <div>
                    <h3 class="font-bold text-lg">Site Configuration & Feature Controls</h3>
                    <p class="text-xs text-[var(--text-dim)]">Configure global platform behaviors, maintenance mode, and commission triggers.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="p-5 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] flex items-center justify-between">
                        <div>
                            <div class="font-bold text-sm text-[var(--text-main)]">Maintenance Mode</div>
                            <div class="text-xs text-[var(--text-dim)]">Restricts non-admin access to maintenance notice</div>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" wire:model="maintenanceMode" class="sr-only peer">
                            <div class="w-11 h-6 bg-neutral-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:accent-bg"></div>
                        </label>
                    </div>

                    <div class="p-5 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] flex items-center justify-between">
                        <div>
                            <div class="font-bold text-sm text-[var(--text-main)]">Allow New User Registrations</div>
                            <div class="text-xs text-[var(--text-dim)]">Enable or disable new user account creation</div>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" wire:model="allowRegistrations" class="sr-only peer">
                            <div class="w-11 h-6 bg-neutral-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:accent-bg"></div>
                        </label>
                    </div>

                    <div class="p-5 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] flex items-center justify-between">
                        <div>
                            <div class="font-bold text-sm text-[var(--text-main)]">Global Commission System</div>
                            <div class="text-xs text-[var(--text-dim)]">Enable artist commission request modals across site</div>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" wire:model="globalCommissionsOpen" class="sr-only peer">
                            <div class="w-11 h-6 bg-neutral-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:accent-bg"></div>
                        </label>
                    </div>
                </div>

                <section class="space-y-4 rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-page)] p-5">
                    <div><h4 class="font-bold">Spam controls</h4><p class="text-xs text-[var(--text-dim)]">Limit rapid posting and block identical content repeated during a short cooldown.</p></div>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="spamControlsEnabled"> Enable rate limits and duplicate detection</label>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="text-xs">Messages per minute<input type="number" min="1" max="120" wire:model.number="messageLimitPerMinute" class="mt-1 w-full rounded-xl border bg-[var(--bg-surface)] px-3 py-2"></label>
                        <label class="text-xs">Comments per minute<input type="number" min="1" max="60" wire:model.number="commentLimitPerMinute" class="mt-1 w-full rounded-xl border bg-[var(--bg-surface)] px-3 py-2"></label>
                        <label class="text-xs">Uploads per hour<input type="number" min="1" max="100" wire:model.number="postLimitPerHour" class="mt-1 w-full rounded-xl border bg-[var(--bg-surface)] px-3 py-2"></label>
                    </div>
                </section>

                <section class="space-y-2 rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-page)] p-5">
                    <h4 class="font-bold">Inactive community owners</h4>
                    <p class="text-xs text-[var(--text-dim)]">Communities become eligible for admin recovery after this many days without activity.</p>
                    <label class="block max-w-xs text-xs">Inactivity threshold (30–730 days)<input type="number" min="30" max="730" wire:model.number="inactiveOwnerDays" class="mt-1 w-full rounded-xl border bg-[var(--bg-surface)] px-3 py-2"></label>
                </section>

                <div class="flex justify-end pt-2">
                    <button wire:click="saveSettings" class="px-6 py-3 rounded-2xl accent-bg text-white font-bold text-xs shadow hover:opacity-90 transition">
                        Save Site Configuration
                    </button>
                </div>
            </div>
        @endif
    </main>

    @if($suspendingUserId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" wire:click.self="$set('suspendingUserId', null)">
            <form wire:submit="suspendUser" class="w-full max-w-lg space-y-4 rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 shadow-2xl">
                <div><h2 class="text-xl font-black">Suspend account</h2><p class="text-sm text-[var(--text-muted)]">A reason is required and will be shown to the user with the appeal form.</p></div>
                <label class="block text-sm font-bold">Reason<textarea wire:model="suspensionReason" required minlength="5" maxlength="1000" rows="3" class="mt-2 w-full rounded-xl border bg-[var(--bg-page)] p-3"></textarea></label>
                <label class="block text-sm font-bold">Duration<select wire:model="suspensionDuration" class="mt-2 w-full rounded-xl border bg-[var(--bg-page)] px-3 py-2"><option value="hour">1 hour</option><option value="day">24 hours</option><option value="week">7 days</option><option value="permanent">Until an admin lifts it</option></select></label>
                <div class="flex justify-end gap-2"><button type="button" wire:click="$set('suspendingUserId', null)" class="rounded-xl border px-4 py-2 text-sm">Cancel</button><button class="rounded-xl bg-rose-600 px-4 py-2 text-sm font-bold text-white">Confirm suspension</button></div>
            </form>
        </div>
    @endif

    <!-- Inline Artwork Quick Inspect Modal -->
    @if($inspectedPost)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-fade-in"
             wire:click.self="closeInspectModal">
            <div class="bg-[var(--bg-surface)] border border-[var(--border-subtle)] rounded-3xl max-w-3xl w-full p-6 space-y-6 shadow-2xl overflow-y-auto max-h-[90vh]">
                <!-- Modal Header -->
                <div class="flex items-center justify-between border-b border-[var(--border-subtle)] pb-4">
                    <div class="flex items-center gap-3">
                        <img src="{{ $inspectedPost->user->avatar_url }}" class="w-10 h-10 rounded-full object-cover">
                        <div>
                            <h3 class="font-extrabold text-base text-[var(--text-main)]">{{ $inspectedPost->title }}</h3>
                            <div class="text-xs text-[var(--text-dim)]">by {{ $inspectedPost->user->username }} · {{ $inspectedPost->created_at->format('M d, Y') }}</div>
                        </div>
                    </div>
                    <button wire:click="closeInspectModal" class="p-2 rounded-xl bg-[var(--bg-surface-elevated)] hover:bg-[var(--border-subtle)] text-[var(--text-muted)] font-bold text-xs">
                        ✕ Close
                    </button>
                </div>

                <!-- Media Preview -->
                <div class="rounded-2xl overflow-hidden bg-neutral-950 border border-[var(--border-subtle)] max-h-[420px] flex items-center justify-center">
                    @if($inspectedPost->media_type === 'video')
                        <video src="{{ $inspectedPost->primaryMedia->url }}" controls class="max-h-[420px] w-auto mx-auto"></video>
                    @else
                        <img src="{{ $inspectedPost->primaryMedia->url }}" class="max-h-[420px] w-auto object-contain mx-auto">
                    @endif
                </div>

                <!-- Post Description & Stats -->
                <div class="space-y-3">
                    @if($inspectedPost->description)
                        <p class="text-xs text-[var(--text-main)] leading-relaxed bg-[var(--bg-page)] p-3 rounded-xl border border-[var(--border-subtle)]">
                            {{ $inspectedPost->description }}
                        </p>
                    @endif

                    <div class="flex flex-wrap items-center gap-2">
                        @foreach($inspectedPost->tags as $t)
                            <span class="px-2.5 py-1 rounded-xl text-xs font-bold border {{ $t->getTypeBadgeClasses() }}">
                                #{{ $t->name }}
                            </span>
                        @endforeach
                    </div>
                </div>

                <!-- Modal Admin Actions Bar -->
                <div class="flex flex-wrap items-center justify-between gap-3 pt-4 border-t border-[var(--border-subtle)]">
                    <a href="{{ route('post.detail', ['id' => $inspectedPost->id, 'from_admin' => 1]) }}" 
                       class="px-4 py-2.5 rounded-2xl accent-bg text-white font-bold text-xs shadow hover:opacity-90 transition flex items-center gap-1.5">
                        <span>Open Full Page View ↗</span>
                    </a>

                    <div class="flex items-center gap-2">
                        <button wire:click="togglePostNsfw({{ $inspectedPost->id }})" class="px-3.5 py-2 rounded-2xl text-xs font-bold border transition {{ $inspectedPost->is_nsfw ? 'bg-rose-500/20 text-rose-400 border-rose-500/30' : 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30' }}">
                            {{ $inspectedPost->is_nsfw ? 'NSFW' : 'SAFE' }}
                        </button>
                        <button wire:click="togglePostFeatured({{ $inspectedPost->id }})" class="px-3.5 py-2 rounded-2xl text-xs font-bold border transition {{ $inspectedPost->is_featured ? 'bg-amber-500 text-black font-extrabold border-transparent' : 'border-[var(--border-subtle)] text-[var(--text-dim)]' }}">
                            {{ $inspectedPost->is_featured ? '★ Featured' : '+ Feature' }}
                        </button>
                        <button wire:click="deletePost({{ $inspectedPost->id }})" class="px-4 py-2.5 rounded-2xl bg-rose-500 text-white font-bold text-xs hover:opacity-90 transition">
                            Delete Artwork
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
