<?php

use App\Models\AdminUserNote;
use App\Models\Collection;
use App\Models\Comment;
use App\Models\Community;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Pool;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Support\ContentReports;
use App\Support\MediaMaintenance;
use App\Support\Notifier;
use App\Support\SiteSettings;
use App\Support\SpamControls;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    /**
     * Settings sections plus every moderation section the console exposes.
     *
     * @var array<int, string>
     */
    public const SECTIONS = [
        'overview', 'users', 'content', 'messages', 'comments', 'pools', 'tags',
        'reports', 'community-reports', 'blocked', 'moderation', 'appeals', 'audit',
        'recovery', 'collections', 'communities', 'media', 'settings', 'bug-reports',
    ];

    public const ADMIN_SECTIONS = ['overview', 'users', 'communities', 'media', 'bug-reports', 'audit', 'recovery', 'settings'];

    public const MODERATION_SECTIONS = ['content', 'messages', 'comments', 'pools', 'tags', 'appeals', 'reports', 'community-reports', 'moderation', 'blocked', 'collections'];

    #[Locked]
    public string $area = 'all';

    public string $activeSection = 'overview';

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

    /**
     * Send the announcement to every account as an in-app notification too.
     */
    public bool $announcementNotifyUsers = false;

    // Report queue state: per-report moderator reason keyed by report id.
    /** @var array<int, string> */
    public array $reportReasons = [];

    public string $reportStatusFilter = 'pending';

    public string $reportTypeFilter = 'all';

    public string $reportSearch = '';

    public string $warningReason = '';

    public ?int $warningUserId = null;

    // Inline comment editing
    public ?int $editingCommentId = null;

    public string $editingCommentText = '';

    // User detail drawer
    public ?int $selectedUserId = null;

    public string $userNoteText = '';

    // Bulk selection
    /** @var array<int, int> */
    public array $selectedUserIds = [];

    /** @var array<int, int> */
    public array $selectedPostIds = [];

    public string $bulkTagName = '';

    // Moderation history / trash
    public string $trashSearch = '';

    // Blocked pairs
    public string $blockSearch = '';

    // Communities
    public string $communitySearch = '';

    /** @var array<int, string> */
    public array $communityRulesDrafts = [];

    // Collections
    public string $collectionSearch = '';

    // Tags
    public string $tagSearch = '';

    public string $newTagType = 'general';

    public string $mergeSourceName = '';

    public string $mergeTargetName = '';

    // Site-wide community reports
    public string $communityReportSearch = '';

    public string $bugReportStatusFilter = 'open';

    /** @var array<int, string> */
    public array $bugReportNotes = [];

    public function boot(): void
    {
        abort_unless(Auth::user()?->isAdmin(), 403);
    }

    public function mount(string $area = 'all')
    {
        if (! Auth::check() || ! Auth::user()->isAdmin()) {
            return redirect()->route('gallery');
        }

        $this->area = in_array($area, ['admin', 'moderation'], true) ? $area : 'all';
        $defaultSection = $this->area === 'moderation' ? 'reports' : 'overview';
        $requestedSection = (string) request()->query('section', $defaultSection);
        $this->activeSection = in_array($requestedSection, $this->availableSections(), true) ? $requestedSection : $defaultSection;
        if ($conversationId = (int) request()->query('conversation')) {
            $this->selectedConversationId = $conversationId;
        }

        $this->announcementText = (string) (SiteSettings::get('site_announcement') ?: Cache::get('site_announcement', 'Welcome to Booru.art! Video uploads and batch multi-posting are now live.'));
        $this->maintenanceMode = SiteSettings::bool('site_maintenance');
        $this->allowRegistrations = SiteSettings::bool('site_registrations');
        $this->globalCommissionsOpen = SiteSettings::bool('site_global_commissions');
        $this->spamControlsEnabled = SiteSettings::bool('site_spam_controls_enabled');
        $this->messageLimitPerMinute = SiteSettings::int('site_spam_messages_limit');
        $this->commentLimitPerMinute = SiteSettings::int('site_spam_comments_limit');
        $this->postLimitPerHour = SiteSettings::int('site_spam_posts_limit');
        $this->inactiveOwnerDays = SiteSettings::int('site_inactive_owner_days');
    }

    public function setSection(string $section): void
    {
        $this->activeSection = in_array($section, $this->availableSections(), true) ? $section : ($this->area === 'moderation' ? 'reports' : 'overview');
        $this->resetPage();
        $this->resetValidation();
    }

    /** @return array<int, string> */
    private function availableSections(): array
    {
        return match ($this->area) {
            'admin' => self::ADMIN_SECTIONS,
            'moderation' => self::MODERATION_SECTIONS,
            default => self::SECTIONS,
        };
    }

    public function reviewBugReport(int $reportId, string $status): void
    {
        $this->authorizeAdmin();
        abort_unless(in_array($status, ['open', 'in_progress', 'resolved', 'closed'], true), 422);

        $report = DB::table('bug_reports')->where('id', $reportId)->first();
        abort_unless($report, 404);
        $note = trim($this->bugReportNotes[$reportId] ?? $report->admin_note ?? '');
        abort_if(mb_strlen($note) > 5000, 422);

        DB::table('bug_reports')->where('id', $reportId)->update([
            'status' => $status,
            'admin_note' => $note ?: null,
            'reviewer_id' => Auth::id(),
            'reviewed_at' => now(),
            'updated_at' => now(),
        ]);
        $this->logAdminAction('bug_report.reviewed', 'bug_report', $reportId, null, ['status' => $status]);
        $this->dispatch('notify', 'Bug report updated.');
    }

    public function updatedBugReportStatusFilter(): void
    {
        $this->resetPage('bugReportsPage');
    }

    public function updatedAuditSearch(): void
    {
        $this->resetPage('auditPage');
    }

    public function updatedReportSearch(): void
    {
        $this->resetPage('reportsPage');
    }

    public function updatedCommunityReportSearch(): void
    {
        $this->resetPage('communityReportsPage');
    }

    public function updatedBlockSearch(): void
    {
        $this->resetPage('blocksPage');
    }

    public function updatedTrashSearch(): void
    {
        $this->resetPage('trashPage');
    }

    public function updatedCommunitySearch(): void
    {
        $this->resetPage('communitiesPage');
    }

    public function updatedCollectionSearch(): void
    {
        $this->resetPage('collectionsPage');
    }

    public function updatedTagSearch(): void
    {
        $this->resetPage('tagsPage');
    }

    public function updatedUserSearch(): void
    {
        $this->resetPage('usersPage');
    }

    public function updatedContentSearch(): void
    {
        $this->resetPage('postsPage');
    }

    public function updatedCommentSearch(): void
    {
        $this->resetPage('commentsPage');
    }

    public function updatedMessageSearch(): void
    {
        $this->resetPage('convsPage');
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

    /**
     * Suspend an account and tell the user why.
     */
    private function applySuspension(User $user, string $reason, ?\Illuminate\Support\Carbon $until = null, bool $notify = true): void
    {
        $user->update([
            'is_banned' => true,
            'suspended_until' => $until,
            'suspension_reason' => $reason,
        ]);

        $this->logAdminAction('user.suspended', 'user', $user->id, $reason, ['until' => $until?->toIso8601String()]);

        if ($notify) {
            Notifier::suspension($user, $reason, Auth::user());
        }
    }

    private function liftSuspension(User $user, string $reason): void
    {
        $user->update(['is_banned' => false, 'suspended_until' => null, 'suspension_reason' => null]);
        $this->logAdminAction('user.unsuspended', 'user', $user->id, $reason);
        Notifier::unsuspension($user, $reason, Auth::user());
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
        $this->applySuspension($user, trim($data['suspensionReason']), $until);
        $this->suspendingUserId = null;
        $this->reset('suspensionReason');
        $this->dispatch('notify', "User @{$user->username} was suspended and notified with the reason.");
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
            $this->applySuspension($user, 'Suspended by admin direct toggle');
        } else {
            $this->liftSuspension($user, 'Suspension lifted by admin.');
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

    /**
     * Remove a post from public view. Reversible from the moderation history.
     */
    public function deletePost(int $postId, string $reason = 'Removed by an administrator.'): void
    {
        $post = Post::with(['media', 'tags', 'poolChapters'])->findOrFail($postId);
        $this->removePost($post, $reason);
        $this->dispatch('notify', "Post #{$postId} removed. Restore it from Moderation history if needed.");
    }

    /**
     * Stop new comments on a post without deleting the thread.
     */
    public function lockPostComments(Post $post, string $reason): void
    {
        $post->update(['comments_locked' => true]);
        $this->logAdminAction('post.comments_locked', 'post', $post->id, $reason, ['title' => $post->title]);

        if ($post->user) {
            Notifier::warning($post->user, 'Comments on "'.($post->title ?: 'your post').'" were locked by a moderator. Reason: '.$reason, Auth::user());
        }
    }

    public function togglePostCommentsLock(int $postId): void
    {
        $this->authorizeAdmin();
        $post = Post::findOrFail($postId);

        if ($post->comments_locked) {
            $post->update(['comments_locked' => false]);
            $this->logAdminAction('post.comments_unlocked', 'post', $post->id, 'Comments reopened by an administrator.');
            $this->dispatch('notify', 'Comments reopened.');

            return;
        }

        $this->lockPostComments($post, 'Comments locked by an administrator.');
        $this->dispatch('notify', 'Comments locked and the author was notified.');
    }

    private function removePost(Post $post, string $reason): void
    {
        $this->adjustPostCounters($post, -1);
        $post->update(['removal_reason' => $reason]);
        $post->delete();

        $this->logAdminAction('post.removed', 'post', $post->id, $reason, ['title' => $post->title]);
        $this->tellTheAuthor($post->user, 'Your post "'.($post->title ?: 'Untitled').'" was removed by a moderator. Reason: '.$reason);

        if ($this->inspectPostId === $post->id) {
            $this->inspectPostId = null;
        }
    }

    /**
     * Content should never just vanish: the owner is told what happened and why.
     */
    private function tellTheAuthor(?User $author, string $message): void
    {
        if (! $author) {
            return;
        }

        Notifier::send($author, Auth::user(), 'warning', $message);
    }

    private function restorePostRecord(Post $post): void
    {
        $this->adjustPostCounters($post, 1);
        $post->update(['removal_reason' => null]);
        $post->restore();

        $this->logAdminAction('post.restored', 'post', $post->id, 'Restored from the moderation history.', ['title' => $post->title]);
    }

    /**
     * Keep derived counters (tags, pool chapters, collections) in step with a
     * post being hidden or restored.
     */
    private function adjustPostCounters(Post $post, int $direction): void
    {
        foreach ($post->tags as $tag) {
            $query = Tag::whereKey($tag->id);
            $direction > 0 ? $query->increment('posts_count') : $query->where('posts_count', '>', 0)->decrement('posts_count');
        }

        foreach ($post->poolChapters as $chapter) {
            $query = $chapter->pool();
            $direction > 0 ? $query->increment('chapters_count') : $query->where('chapters_count', '>', 0)->decrement('chapters_count');
        }

        foreach (\App\Models\CollectionItem::where('post_id', $post->id)->get() as $item) {
            $query = Collection::whereKey($item->collection_id);
            $direction > 0 ? $query->increment('items_count') : $query->where('items_count', '>', 0)->decrement('items_count');
        }
    }

    /**
     * Apply a moderator action straight from the report queue.
     */
    public function reviewContentReport(int $reportId, string $action): void
    {
        $this->authorizeAdmin();
        abort_unless(in_array($action, ['dismiss', 'warn', 'remove', 'hide', 'suspend', 'archive', 'lock'], true), 422);

        $report = DB::table('content_reports')->where('id', $reportId)->where('status', 'pending')->first();
        abort_unless($report, 404);

        $reason = trim((string) ($this->reportReasons[$reportId] ?? ''));

        if ($action !== 'dismiss' && ContentReports::requiresReason($action) && mb_strlen($reason) < 5) {
            $this->addError('reportReasons.'.$reportId, 'Write a moderation reason of at least 5 characters before applying this action.');

            return;
        }

        $authorId = ContentReports::authorId($report->target_type, (int) $report->target_id);
        $resolution = $action === 'dismiss'
            ? 'Report reviewed and dismissed.'
            : $this->applyReportAction($action, $report, $reason, $authorId);

        DB::table('content_reports')->where('id', $reportId)->update([
            'status' => $action === 'dismiss' ? 'dismissed' : 'actioned',
            'reviewer_id' => Auth::id(),
            'resolution' => $resolution,
            'resolved_at' => now(),
            'updated_at' => now(),
        ]);

        unset($this->reportReasons[$reportId]);
        $this->logAdminAction('content_report.'.$action, $report->target_type, (int) $report->target_id, $reason ?: null);
        $this->dispatch('notify', 'Report resolved: '.$resolution);
    }

    /**
     * Carry out the moderation effect for a report and describe what happened.
     */
    private function applyReportAction(string $action, object $report, string $reason, ?int $authorId): string
    {
        $author = $authorId ? User::find($authorId) : null;

        if ($action === 'warn') {
            return $this->warnAuthor($author, $reason);
        }

        if ($action === 'suspend') {
            if (! $author) {
                return 'The reported author no longer exists.';
            }

            $this->applySuspension($author, $reason, null);

            return 'Suspended @'.$author->username.' and notified them with the reason. Reason: '.$reason;
        }

        if ($action === 'archive') {
            $community = Community::findOrFail($report->target_id);
            $this->archiveCommunityRecord($community, $reason);

            return 'Archived '.$community->name.'. Reason: '.$reason;
        }

        if ($action === 'lock') {
            if ($report->target_type !== 'post') {
                return 'Comments can only be locked on a post.';
            }

            $post = Post::findOrFail($report->target_id);
            $this->lockPostComments($post, $reason);

            return 'Locked comments on the reported post and notified the author. Reason: '.$reason;
        }

        if ($action === 'remove') {
            if ($report->target_type === 'post') {
                $this->removePost(Post::with(['media', 'tags', 'poolChapters'])->findOrFail($report->target_id), $reason);

                return 'Removed the reported post. Reason: '.$reason;
            }

            if ($report->target_type === 'comment') {
                $this->removeComment(Comment::findOrFail($report->target_id), $reason);

                return 'Deleted the reported comment. Reason: '.$reason;
            }

            return 'No destructive action exists for this target.';
        }

        if ($action === 'hide') {
            if ($report->target_type === 'comment') {
                $this->hideCommentRecord(Comment::findOrFail($report->target_id), $reason);

                return 'Hid the reported comment. Reason: '.$reason;
            }

            if ($report->target_type === 'message') {
                $message = Message::findOrFail($report->target_id);
                $message->update(['is_hidden' => true, 'hidden_reason' => $reason]);
                $this->logAdminAction('message.hidden', 'message', $message->id, $reason);
                Notifier::messageHidden($message, $reason);

                return 'Hid the reported direct message and notified the sender. Reason: '.$reason;
            }

            return 'Hide is not available for this target.';
        }

        return 'No action taken.';
    }

    private function warnAuthor(?User $author, string $reason): string
    {
        if (! $author) {
            return 'The reported author no longer exists.';
        }

        Notifier::warning($author, $reason, Auth::user());
        $this->logAdminAction('user.warned', 'user', $author->id, $reason);

        return 'Sent @'.$author->username.' a moderation notice. Reason: '.$reason;
    }

    /**
     * Warn a user without going through a report.
     */
    public function warnUser(int $userId): void
    {
        $this->authorizeAdmin();
        $data = $this->validate(['warningReason' => ['required', 'string', 'min:5', 'max:1000']]);
        $user = User::findOrFail($userId);
        abort_if($user->id === Auth::id(), 403);

        Notifier::warning($user, trim($data['warningReason']), Auth::user());
        $this->logAdminAction('user.warned', 'user', $user->id, trim($data['warningReason']));
        $this->warningUserId = null;
        $this->reset('warningReason');
        $this->dispatch('notify', "Sent @{$user->username} a moderation notice.");
    }

    public function prepareWarning(int $userId): void
    {
        $user = User::findOrFail($userId);
        abort_if($user->id === Auth::id(), 403);
        $this->warningUserId = $user->id;
        $this->warningReason = '';
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

    public function deletePool(int $poolId): void
    {
        $pool = Pool::findOrFail($poolId);
        $this->logAdminAction('pool.removed', 'pool', $pool->id, 'Removed by an administrator.', ['title' => $pool->title]);
        $pool->delete();
        $this->dispatch('notify', "Pool #{$poolId} removed. Restore it from Moderation history if needed.");
    }

    public function deleteComment(int $commentId, string $reason = 'Removed by an administrator.'): void
    {
        $comment = Comment::findOrFail($commentId);
        $this->removeComment($comment, $reason);
        $this->dispatch('notify', "Comment #{$commentId} removed. Restore it from Moderation history if needed.");
    }

    private function removeComment(Comment $comment, string $reason): void
    {
        $comment->update(['hidden_reason' => $reason]);
        $comment->delete();
        $this->logAdminAction('comment.removed', 'comment', $comment->id, $reason, ['post_id' => $comment->post_id]);
        $this->tellTheAuthor($comment->user, 'Your comment was removed by a moderator. Reason: '.$reason);
    }

    private function hideCommentRecord(Comment $comment, string $reason): void
    {
        $comment->update(['is_hidden' => true, 'hidden_reason' => $reason]);
        $this->logAdminAction('comment.hidden', 'comment', $comment->id, $reason, ['post_id' => $comment->post_id]);
        $this->tellTheAuthor($comment->user, 'Your comment was hidden by a moderator. Reason: '.$reason);
    }

    public function toggleCommentHidden(int $commentId): void
    {
        $this->authorizeAdmin();
        $comment = Comment::findOrFail($commentId);
        $comment->update([
            'is_hidden' => ! $comment->is_hidden,
            'hidden_reason' => $comment->is_hidden ? null : 'Hidden by an administrator.',
        ]);
        $this->logAdminAction($comment->is_hidden ? 'comment.hidden' : 'comment.unhidden', 'comment', $comment->id);

        if ($comment->is_hidden) {
            $this->tellTheAuthor($comment->user, 'Your comment was hidden by a moderator. Reason: '.$comment->hidden_reason);
        }

        $this->dispatch('notify', $comment->is_hidden ? "Comment #{$commentId} hidden from the thread." : "Comment #{$commentId} is visible again.");
    }

    public function startCommentEdit(int $commentId): void
    {
        $comment = Comment::findOrFail($commentId);
        $this->editingCommentId = $comment->id;
        $this->editingCommentText = $comment->content;
    }

    public function saveCommentEdit(): void
    {
        $this->authorizeAdmin();
        $data = $this->validate([
            'editingCommentId' => ['required', 'integer', 'exists:comments,id'],
            'editingCommentText' => ['required', 'string', 'min:2', 'max:1000'],
        ]);
        $comment = Comment::findOrFail($data['editingCommentId']);
        $comment->update(['content' => trim($data['editingCommentText'])]);
        $this->logAdminAction('comment.edited', 'comment', $comment->id, null, ['content' => $comment->content]);
        $this->reset('editingCommentId', 'editingCommentText');
        $this->dispatch('notify', 'Comment updated.');
    }

    /**
     * Restore anything that was soft-removed from moderation.
     */
    public function restoreModerated(string $type, int $id): void
    {
        $this->authorizeAdmin();

        match ($type) {
            'post' => $this->restorePostRecord(Post::withTrashed()->with(['media', 'tags', 'poolChapters'])->findOrFail($id)),
            'comment' => tap(Comment::withTrashed()->findOrFail($id), function (Comment $comment): void {
                $comment->restore();
                $comment->update(['hidden_reason' => null]);
                $this->logAdminAction('comment.restored', 'comment', $comment->id, 'Restored from the moderation history.');
            }),
            'pool' => tap(Pool::withTrashed()->findOrFail($id), function (Pool $pool): void {
                $pool->restore();
                $this->logAdminAction('pool.restored', 'pool', $pool->id, 'Restored from the moderation history.');
            }),
            'collection' => tap(Collection::withTrashed()->findOrFail($id), function (Collection $collection): void {
                $collection->restore();
                $this->logAdminAction('collection.restored', 'collection', $collection->id, 'Restored from the moderation history.');
            }),
            default => abort(422, 'Unsupported moderation target.'),
        };

        $this->dispatch('notify', ucfirst($type).' restored.');
    }

    /**
     * Permanently destroy a moderated record and its stored media.
     */
    public function purgeModerated(string $type, int $id): void
    {
        $this->authorizeAdmin();

        match ($type) {
            'post' => tap(Post::withTrashed()->with('media')->findOrFail($id), function (Post $post): void {
                $this->deleteStoredMedia($post);
                $this->logAdminAction('post.purged', 'post', $post->id, 'Permanently deleted from the moderation history.', ['title' => $post->title]);
                $post->forceDelete();
            }),
            'comment' => tap(Comment::withTrashed()->findOrFail($id), function (Comment $comment): void {
                $this->logAdminAction('comment.purged', 'comment', $comment->id, 'Permanently deleted.');
                $comment->forceDelete();
            }),
            'pool' => tap(Pool::withTrashed()->findOrFail($id), function (Pool $pool): void {
                $this->logAdminAction('pool.purged', 'pool', $pool->id, 'Permanently deleted.');
                $pool->forceDelete();
            }),
            'collection' => tap(Collection::withTrashed()->findOrFail($id), function (Collection $collection): void {
                $this->logAdminAction('collection.purged', 'collection', $collection->id, 'Permanently deleted.');
                $collection->forceDelete();
            }),
            default => abort(422, 'Unsupported moderation target.'),
        };

        $this->dispatch('notify', ucfirst($type).' permanently deleted.');
    }

    private function deleteStoredMedia(Post $post): void
    {
        $paths = $post->media->map(function ($media): ?string {
            $path = parse_url($media->url, PHP_URL_PATH) ?: '';
            if (! str_starts_with($path, '/storage/')) {
                return null;
            }

            $storagePath = substr($path, strlen('/storage/'));

            return str_starts_with($storagePath, 'posts/') || str_starts_with($storagePath, 'videos/') ? $storagePath : null;
        })->filter()->values();

        Storage::disk('public')->delete($paths->all());
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
        $inactiveDays = max(30, SiteSettings::int('site_inactive_owner_days'));
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

        \App\Support\SiteSettings::setMany([
            'site_announcement' => $this->announcementText,
            'site_maintenance' => $this->maintenanceMode,
            'site_registrations' => $this->allowRegistrations,
            'site_global_commissions' => $this->globalCommissionsOpen,
            'site_spam_controls_enabled' => $this->spamControlsEnabled,
            'site_spam_messages_limit' => $this->messageLimitPerMinute,
            'site_spam_comments_limit' => $this->commentLimitPerMinute,
            'site_spam_posts_limit' => $this->postLimitPerHour,
            'site_inactive_owner_days' => $this->inactiveOwnerDays,
        ]);
        $this->logAdminAction('site.settings_updated', null, null, null, [
            'spam_controls_enabled' => $this->spamControlsEnabled,
            'message_limit_per_minute' => $this->messageLimitPerMinute,
            'comment_limit_per_minute' => $this->commentLimitPerMinute,
            'post_limit_per_hour' => $this->postLimitPerHour,
            'inactive_owner_days' => $this->inactiveOwnerDays,
        ]);

        $message = 'Site configuration and announcement updated successfully!';

        if ($this->announcementNotifyUsers && trim($this->announcementText) !== '') {
            $sent = Notifier::announcement(trim($this->announcementText), Auth::user());
            $this->logAdminAction('site.announcement_notified', null, null, null, ['recipients' => $sent]);
            $message .= " Notified {$sent} accounts.";
            $this->announcementNotifyUsers = false;
        }

        $this->dispatch('notify', $message);
    }

    // ------------------------------------------------------------------ users

    public function openUserDrawer(int $userId): void
    {
        $this->selectedUserId = $userId;
        $this->userNoteText = '';
    }

    public function closeUserDrawer(): void
    {
        $this->selectedUserId = null;
        $this->userNoteText = '';
    }

    public function addUserNote(): void
    {
        $this->authorizeAdmin();
        $data = $this->validate([
            'selectedUserId' => ['required', 'integer', 'exists:users,id'],
            'userNoteText' => ['required', 'string', 'min:2', 'max:1000'],
        ]);

        AdminUserNote::create([
            'user_id' => $data['selectedUserId'],
            'author_id' => Auth::id(),
            'note' => trim($data['userNoteText']),
        ]);

        $this->logAdminAction('user.note_added', 'user', $data['selectedUserId'], null, ['note' => trim($data['userNoteText'])]);
        $this->reset('userNoteText');
        $this->dispatch('notify', 'Note saved.');
    }

    public function deleteUserNote(int $noteId): void
    {
        $this->authorizeAdmin();
        $note = AdminUserNote::findOrFail($noteId);
        $this->logAdminAction('user.note_deleted', 'user', $note->user_id, null, ['note' => $note->note]);
        $note->delete();
        $this->dispatch('notify', 'Note removed.');
    }

    /**
     * Issue a temporary password the admin can hand to the user out of band.
     */
    public function resetUserPassword(int $userId): void
    {
        $this->authorizeAdmin();
        $user = User::findOrFail($userId);

        $temporaryPassword = Str::password(14);
        $user->update(['password' => Hash::make($temporaryPassword)]);

        DB::table('sessions')->where('user_id', $user->id)->delete();

        $this->logAdminAction('user.password_reset', 'user', $user->id, 'Temporary password issued by an administrator.');
        Notifier::warning($user, 'An administrator reset your password. Sign in with the temporary password you were given and change it immediately.', Auth::user());

        $this->dispatch('notify', "Temporary password for @{$user->username}: {$temporaryPassword}");
    }

    public function forceUserLogout(int $userId): void
    {
        $this->authorizeAdmin();
        $user = User::findOrFail($userId);
        $removed = DB::table('sessions')->where('user_id', $user->id)->delete();
        $this->logAdminAction('user.sessions_cleared', 'user', $user->id, 'All active sessions terminated.', ['sessions' => $removed]);
        $this->dispatch('notify', "Signed @{$user->username} out of {$removed} session(s).");
    }

    public function requireEmailReverification(int $userId): void
    {
        $this->authorizeAdmin();
        $user = User::findOrFail($userId);
        $user->email_verified_at = null;
        $user->save();
        $this->logAdminAction('user.reverification_required', 'user', $user->id, 'Email verification cleared by an administrator.');
        $this->dispatch('notify', "@{$user->username} now needs to verify their email again.");
    }

    public function markEmailVerified(int $userId): void
    {
        $this->authorizeAdmin();
        $user = User::findOrFail($userId);
        $user->email_verified_at = now();
        $user->save();
        $this->logAdminAction('user.email_verified', 'user', $user->id, 'Email marked verified by an administrator.');
        $this->dispatch('notify', "@{$user->username}'s email is marked verified.");
    }

    // ------------------------------------------------------------------- bulk

    public function bulkUserAction(string $action): void
    {
        $this->authorizeAdmin();
        abort_unless(in_array($action, ['ban', 'unban', 'artist', 'not_artist'], true), 422);

        $ids = collect($this->selectedUserIds)->map(fn ($id) => (int) $id)->unique();
        abort_if($ids->isEmpty(), 422, 'Select at least one user first.');

        $users = User::whereIn('id', $ids)->where('is_admin', false)->whereKeyNot(Auth::id())->get();
        $reason = 'Bulk action applied by an administrator.';

        foreach ($users as $user) {
            match ($action) {
                'ban' => $this->applySuspension($user, $reason, null),
                'unban' => $user->is_banned ? $this->liftSuspension($user, 'Bulk suspension lift by an administrator.') : null,
                'artist' => $user->is_artist ? null : tap($user, function (User $u): void {
                    $u->update(['is_artist' => true]);
                    $this->logAdminAction('user.artist_granted', 'user', $u->id, 'Bulk action.');
                }),
                'not_artist' => $user->is_artist ? tap($user, function (User $u): void {
                    $u->update(['is_artist' => false]);
                    $this->logAdminAction('user.artist_removed', 'user', $u->id, 'Bulk action.');
                }) : null,
            };
        }

        $this->selectedUserIds = [];
        $this->dispatch('notify', 'Bulk action applied to '.$users->count().' user(s).');
    }

    public function bulkPostAction(string $action): void
    {
        $this->authorizeAdmin();
        abort_unless(in_array($action, ['feature', 'unfeature', 'nsfw', 'safe', 'remove', 'tag'], true), 422);

        $ids = collect($this->selectedPostIds)->map(fn ($id) => (int) $id)->unique();
        abort_if($ids->isEmpty(), 422, 'Select at least one post first.');

        if ($action === 'tag') {
            $data = $this->validate(['bulkTagName' => ['required', 'string', 'max:80', 'regex:/^[a-zA-Z0-9 _-]+$/']]);
            $name = Str::of($data['bulkTagName'])->trim()->lower()->replace(' ', '_')->toString();
            $tag = Tag::firstOrCreate(['name' => $name], ['slug' => Str::slug($name), 'type' => 'general', 'posts_count' => 0]);

            foreach (Post::withTrashed()->whereIn('id', $ids)->get() as $post) {
                if (! $post->tags()->whereKey($tag->id)->exists()) {
                    $post->tags()->attach($tag->id);
                    $tag->increment('posts_count');
                }
            }

            $this->logAdminAction('post.tags_bulk_added', 'tag', $tag->id, 'Bulk tag applied.', ['posts' => $ids->all(), 'tag' => $name]);
            $this->selectedPostIds = [];
            $this->reset('bulkTagName');
            $this->dispatch('notify', "Tagged {$ids->count()} post(s) with #{$name}.");

            return;
        }

        foreach (Post::withTrashed()->whereIn('id', $ids)->get() as $post) {
            match ($action) {
                'feature' => tap($post, function (Post $p): void {
                    $p->update(['is_featured' => true]);
                    $this->logAdminAction('post.featured', 'post', $p->id, 'Bulk action.');
                }),
                'unfeature' => tap($post, function (Post $p): void {
                    $p->update(['is_featured' => false]);
                    $this->logAdminAction('post.unfeatured', 'post', $p->id, 'Bulk action.');
                }),
                'nsfw' => tap($post, function (Post $p): void {
                    $p->update(['is_nsfw' => true]);
                    $this->logAdminAction('post.nsfw_toggled', 'post', $p->id, 'Bulk action.');
                }),
                'safe' => tap($post, function (Post $p): void {
                    $p->update(['is_nsfw' => false]);
                    $this->logAdminAction('post.nsfw_toggled', 'post', $p->id, 'Bulk action.');
                }),
                'remove' => $post->trashed() ? null : $this->removePost($post->load(['media', 'tags', 'poolChapters']), 'Bulk removal by an administrator.'),
            };
        }

        $this->selectedPostIds = [];
        $this->dispatch('notify', 'Bulk action applied to '.$ids->count().' post(s).');
    }

    /**
     * Select or clear every user visible on the current page.
     */
    public function toggleSelectAllUsers(): void
    {
        $pageIds = $this->usersListQuery()->paginate(8, ['*'], 'usersPage')->pluck('id')->all();
        $this->selectedUserIds = ($pageIds !== [] && array_diff($pageIds, $this->selectedUserIds) === [])
            ? []
            : $pageIds;
    }

    public function toggleSelectAllPosts(): void
    {
        $pageIds = $this->postsListQuery()->paginate(12, ['*'], 'postsPage')->pluck('id')->all();
        $this->selectedPostIds = ($pageIds !== [] && array_diff($pageIds, $this->selectedPostIds) === [])
            ? []
            : $pageIds;
    }

    // ------------------------------------------------------- community reports

    public function resolveCommunityReport(int $reportId, string $resolution): void
    {
        $this->authorizeAdmin();
        abort_unless(in_array($resolution, ['hide', 'dismiss'], true), 422);

        $report = DB::table('community_reports')->where('id', $reportId)->whereIn('status', ['pending', 'reviewing'])->first();
        abort_unless($report, 404);

        if ($resolution === 'hide') {
            $table = ['message' => 'community_messages', 'forum_post' => 'community_forum_posts', 'forum_reply' => 'community_forum_replies'][$report->target_type] ?? null;
            if ($table) {
                DB::table($table)->where('id', $report->target_id)->update(
                    $report->target_type === 'forum_post' ? ['status' => 'hidden', 'updated_at' => now()] : ['is_hidden' => true, 'updated_at' => now()]
                );
            }
            DB::table('community_action_logs')->insert([
                'community_id' => $report->community_id, 'actor_id' => Auth::id(), 'action' => 'report_hidden_site_admin',
                'details' => json_encode(['report_id' => $reportId]), 'created_at' => now(),
            ]);
        }

        DB::table('community_reports')->where('id', $reportId)->update([
            'status' => $resolution === 'hide' ? 'actioned' : 'dismissed',
            'resolution' => 'Reviewed by a site administrator: '.$resolution,
            'resolver_id' => Auth::id(),
            'resolved_at' => now(),
            'updated_at' => now(),
        ]);

        $this->logAdminAction('community_report.'.$resolution, 'community', (int) $report->community_id, null, ['report_id' => $reportId]);
        $this->dispatch('notify', 'Community report resolved.');
    }

    // ------------------------------------------------------------------ blocks

    public function removeBlock(int $blockId): void
    {
        $this->authorizeAdmin();
        $block = DB::table('user_blocks')->where('id', $blockId)->first();
        abort_unless($block, 404);

        DB::table('user_blocks')->where('id', $blockId)->delete();
        $this->logAdminAction('user_block.removed', 'user', (int) $block->blocked_id, 'Block removed by an administrator.', ['blocker_id' => $block->blocker_id]);
        $this->dispatch('notify', 'Block removed.');
    }

    // ------------------------------------------------------------- communities

    public function archiveCommunityRecord(Community $community, string $reason): void
    {
        $community->update(['archived_at' => now(), 'archive_reason' => $reason]);
        $this->logAdminAction('community.archived', 'community', $community->id, $reason, ['name' => $community->name]);

        $owner = $community->owner;
        if ($owner) {
            Notifier::warning($owner, 'Your community "'.$community->name.'" was archived by a site administrator. Reason: '.$reason, Auth::user());
        }
    }

    public function toggleCommunityArchive(int $communityId): void
    {
        $this->authorizeAdmin();
        $community = Community::findOrFail($communityId);

        if ($community->isArchived()) {
            $community->update(['archived_at' => null, 'archive_reason' => null]);
            $this->logAdminAction('community.unarchived', 'community', $community->id, 'Restored by an administrator.');
            $this->dispatch('notify', 'Community unarchived.');

            return;
        }

        $this->archiveCommunityRecord($community, 'Archived from the community admin list.');
        $this->dispatch('notify', 'Community archived.');
    }

    public function saveCommunityDetails(int $communityId): void
    {
        $this->authorizeAdmin();
        $community = Community::findOrFail($communityId);

        $rules = $this->communityRulesDrafts[$communityId] ?? null;

        $data = $this->validate([
            "communityRulesDrafts.{$communityId}" => ['nullable', 'string', 'max:5000'],
        ]);

        $community->update(['rules' => $rules]);

        $this->logAdminAction('community.rules_updated', 'community', $community->id, 'Rules edited by an administrator.', ['rules' => $rules]);
        $this->dispatch('notify', 'Community rules saved.');
    }

    public function deleteCommunity(int $communityId): void
    {
        $this->authorizeAdmin();
        $community = Community::findOrFail($communityId);
        $this->logAdminAction('community.deleted', 'community', $community->id, 'Deleted by an administrator.', ['name' => $community->name]);
        $community->delete();
        $this->dispatch('notify', 'Community deleted.');
    }

    // -------------------------------------------------------------------- tags

    public function updateTagType(int $tagId): void
    {
        $this->authorizeAdmin();
        $data = $this->validate(['newTagType' => ['required', 'in:artist,character,series,general,meta']]);
        $tag = Tag::findOrFail($tagId);
        $previous = $tag->type;
        $tag->update(['type' => $data['newTagType']]);
        $this->logAdminAction('tag.type_changed', 'tag', $tag->id, null, ['from' => $previous, 'to' => $tag->type]);
        $this->dispatch('notify', "#{$tag->name} is now a {$tag->type} tag.");
    }

    public function renameTag(int $tagId, ?string $newName): void
    {
        $this->authorizeAdmin();
        abort_if(blank($newName), 422, 'A new tag name is required.');
        $name = Str::of($newName)->trim()->lower()->replace(' ', '_')->toString();
        abort_if($name === '' || mb_strlen($name) > 80, 422, 'Enter a tag name of up to 80 characters.');

        $tag = Tag::findOrFail($tagId);
        abort_if(Tag::where('name', $name)->whereKeyNot($tag->id)->exists(), 422, 'Another tag already uses that name.');

        $previousName = $tag->name;
        $tag->update(['name' => $name, 'slug' => Str::slug($name)]);
        $this->logAdminAction('tag.renamed', 'tag', $tag->id, null, ['from' => $previousName, 'to' => $name]);
        $this->dispatch('notify', "#{$previousName} renamed to #{$name}.");
    }

    public function mergeTags(): void
    {
        $this->authorizeAdmin();
        $data = $this->validate([
            'mergeSourceName' => ['required', 'string', 'max:80', 'exists:tags,name'],
            'mergeTargetName' => ['required', 'string', 'max:80', 'exists:tags,name'],
        ]);

        $source = Tag::where('name', $data['mergeSourceName'])->firstOrFail();
        $target = Tag::where('name', $data['mergeTargetName'])->firstOrFail();
        abort_if($source->id === $target->id, 422, 'Pick two different tags.');

        DB::transaction(function () use ($source, $target): void {
            foreach ($source->posts()->pluck('posts.id') as $postId) {
                if (! DB::table('post_tag')->where('post_id', $postId)->where('tag_id', $target->id)->exists()) {
                    DB::table('post_tag')->insert(['post_id' => $postId, 'tag_id' => $target->id]);
                }
            }
            DB::table('post_tag')->where('tag_id', $source->id)->delete();
            DB::table('tag_aliases')->where('tag_id', $source->id)->delete();
            DB::table('tag_aliases')->updateOrInsert(
                ['alias' => $source->name],
                ['tag_id' => $target->id, 'updated_at' => now(), 'created_at' => now()],
            );

            $target->update(['posts_count' => $target->posts()->count()]);
            $this->logAdminAction('tag.merged', 'tag', $target->id, null, ['source' => $source->name, 'target' => $target->name]);
            $source->delete();
        });

        $this->reset('mergeSourceName', 'mergeTargetName');
        $this->dispatch('notify', 'Tags merged and an alias was created.');
    }

    public function deleteTag(int $tagId): void
    {
        $this->authorizeAdmin();
        $tag = Tag::findOrFail($tagId);
        DB::transaction(function () use ($tag): void {
            DB::table('post_tag')->where('tag_id', $tag->id)->delete();
            DB::table('tag_aliases')->where('tag_id', $tag->id)->delete();
            $this->logAdminAction('tag.deleted', 'tag', $tag->id, 'Deleted by an administrator.', ['name' => $tag->name]);
            $tag->delete();
        });
        $this->dispatch('notify', 'Tag deleted.');
    }

    // ------------------------------------------------------------------- media

    public function regenerateThumbnail(int $mediaId): void
    {
        $this->authorizeAdmin();
        $media = \App\Models\PostMedia::findOrFail($mediaId);

        $generated = MediaMaintenance::regenerateThumbnail($media);

        $this->logAdminAction('media.thumbnail_regenerated', 'post', $media->post_id, null, ['media_id' => $media->id, 'generated' => $generated]);
        $this->dispatch('notify', $generated ? 'Thumbnail regenerated.' : 'Thumbnail could not be regenerated (remote file or no image support).');
    }

    public function regenerateMissingThumbnails(): void
    {
        $this->authorizeAdmin();
        $mediaItems = \App\Models\PostMedia::whereNull('thumbnail_url')->get();
        $generated = 0;

        foreach ($mediaItems as $media) {
            if (MediaMaintenance::regenerateThumbnail($media)) {
                $generated++;
            }
        }

        $this->logAdminAction('media.thumbnails_regenerated', null, null, null, [
            'attempted' => $mediaItems->count(),
            'generated' => $generated,
        ]);
        $this->dispatch('notify', "Regenerated {$generated} of {$mediaItems->count()} thumbnail(s).");
    }

    public function removeCollection(int $collectionId): void
    {
        $this->authorizeAdmin();
        $collection = Collection::findOrFail($collectionId);
        $this->logAdminAction('collection.removed', 'collection', $collection->id, 'Removed by an administrator.', ['title' => $collection->title]);
        $collection->delete();
        $this->dispatch('notify', 'Collection removed. Restore it from Moderation history if needed.');
    }

    public function pruneOrphanedMedia(): void
    {
        $this->authorizeAdmin();
        $orphans = MediaMaintenance::orphanedFiles();
        $deleted = 0;

        foreach ($orphans as $path) {
            Storage::disk('public')->delete($path);
            $deleted++;
        }

        $this->logAdminAction('media.orphans_pruned', null, null, null, ['deleted' => $deleted]);
        $this->dispatch('notify', "Removed {$deleted} orphaned file(s) from the public disk.");
    }

    public function render()
    {
        $bugReportsQuery = DB::table('bug_reports')->leftJoin('users as reporters', 'reporters.id', '=', 'bug_reports.user_id')
            ->select('bug_reports.*', 'reporters.username as reporter_username');
        if (in_array($this->bugReportStatusFilter, ['open', 'in_progress', 'resolved', 'closed'], true)) {
            $bugReportsQuery->where('bug_reports.status', $this->bugReportStatusFilter);
        }
        $bugReports = $bugReportsQuery->latest('bug_reports.created_at')->paginate(10, ['*'], 'bugReportsPage');
        $openBugReportCount = DB::table('bug_reports')->where('status', 'open')->count();

        $totalUsers = User::count();
        $totalPosts = Post::count();
        $totalTags = Tag::count();
        $totalPools = Pool::count();
        $totalComments = Comment::count();
        $totalConversations = Conversation::count();

        // 1. Paginated Users
        $users = $this->usersListQuery()->paginate(8, ['*'], 'usersPage');

        // 2. Paginated Posts
        $posts = $this->postsListQuery()->paginate(12, ['*'], 'postsPage');

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
        $conversationsQuery = Conversation::with(['userOne', 'userTwo', 'visibleLatestMessage'])->latest('last_message_at');
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
            ->oldest('user_appeals.created_at')->paginate(20, ['*'], 'appealsPage');

        // Site-wide content report queue. Every target type is included.
        $reportQuery = DB::table('content_reports');
        if (in_array($this->reportStatusFilter, ['pending', 'actioned', 'dismissed'], true)) {
            $reportQuery->where('status', $this->reportStatusFilter);
        }
        if (in_array($this->reportTypeFilter, ContentReports::TARGET_TYPES, true)) {
            $reportQuery->where('target_type', $this->reportTypeFilter);
        }
        if (filled($this->reportSearch)) {
            $search = trim($this->reportSearch);
            $reportQuery->where(function ($query) use ($search): void {
                $query->where('reason', 'like', "%{$search}%")
                    ->orWhere('details', 'like', "%{$search}%");
            });
        }
        $reportsPage = $reportQuery->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->oldest('created_at')->paginate(10, ['*'], 'reportsPage');
        $reportQueue = ContentReports::hydrate(collect($reportsPage->items()));
        $pendingReportCount = DB::table('content_reports')->where('status', 'pending')->count();

        // Community (lounge) reports across every server.
        $communityReportQuery = DB::table('community_reports')
            ->join('communities', 'communities.id', '=', 'community_reports.community_id')
            ->join('users as community_reporters', 'community_reporters.id', '=', 'community_reports.reporter_id')
            ->whereIn('community_reports.status', ['pending', 'reviewing'])
            ->select('community_reports.*', 'communities.name as community_name', 'communities.slug as community_slug', 'community_reporters.username as reporter_username');
        if (filled($this->communityReportSearch)) {
            $search = trim($this->communityReportSearch);
            $communityReportQuery->where(function ($query) use ($search): void {
                $query->where('community_reports.reason', 'like', "%{$search}%")
                    ->orWhere('communities.name', 'like', "%{$search}%")
                    ->orWhere('community_reporters.username', 'like', "%{$search}%");
            });
        }
        $communityReports = $communityReportQuery->oldest('community_reports.created_at')->paginate(10, ['*'], 'communityReportsPage');

        // Blocked user pairs.
        $blockQuery = DB::table('user_blocks')
            ->join('users as blockers', 'blockers.id', '=', 'user_blocks.blocker_id')
            ->join('users as blocked', 'blocked.id', '=', 'user_blocks.blocked_id')
            ->select('user_blocks.*', 'blockers.username as blocker_username', 'blocked.username as blocked_username');
        if (filled($this->blockSearch)) {
            $search = trim($this->blockSearch);
            $blockQuery->where(function ($query) use ($search): void {
                $query->where('blockers.username', 'like', "%{$search}%")
                    ->orWhere('blocked.username', 'like', "%{$search}%");
            });
        }
        $userBlocks = $blockQuery->latest('user_blocks.created_at')->paginate(15, ['*'], 'blocksPage');

        // Soft-removed content that an admin can put back.
        $trashQuery = Post::onlyTrashed()->with('user');
        if (filled($this->trashSearch)) {
            $search = trim($this->trashSearch);
            $trashQuery->where(function ($query) use ($search): void {
                $query->where('title', 'like', "%{$search}%")->orWhere('removal_reason', 'like', "%{$search}%");
            });
        }
        $trashedPosts = $trashQuery->latest('deleted_at')->paginate(10, ['*'], 'trashPage');
        $trashedComments = Comment::onlyTrashed()->with(['user', 'post'])->latest('deleted_at')->limit(25)->get();
        $trashedPools = Pool::onlyTrashed()->with('user')->latest('deleted_at')->limit(25)->get();
        $trashedCollections = Collection::onlyTrashed()->with('user')->latest('deleted_at')->limit(25)->get();

        // Collections administration.
        $collectionQuery = Collection::with('user')->withCount('items');
        if (filled($this->collectionSearch)) {
            $search = trim($this->collectionSearch);
            $collectionQuery->where('title', 'like', "%{$search}%");
        }
        $collections = $collectionQuery->latest()->paginate(10, ['*'], 'collectionsPage');

        // Community administration.
        $communityQuery = Community::with('owner')->withCount('members');
        if (filled($this->communitySearch)) {
            $search = trim($this->communitySearch);
            $communityQuery->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%");
            });
        }
        $communities = $communityQuery->latest()->paginate(10, ['*'], 'communitiesPage');

        // Tag administration.
        $tagQuery = Tag::query();
        if (filled($this->tagSearch)) {
            $search = trim($this->tagSearch);
            $tagQuery->where('name', 'like', "%{$search}%");
        }
        $managedTags = $tagQuery->orderByDesc('posts_count')->paginate(20, ['*'], 'tagsPage');

        // Storage housekeeping for the media section.
        $storageUsage = MediaMaintenance::storageUsage();
        $orphanedFiles = MediaMaintenance::orphanedFiles();
        $missingThumbnails = \App\Models\PostMedia::whereNull('thumbnail_url')->count();

        // User detail drawer.
        $drawerUser = $this->selectedUserId
            ? User::with(['posts' => fn ($query) => $query->latest()->limit(10)])->find($this->selectedUserId)
            : null;
        $drawerData = [];
        if ($drawerUser) {
            $drawerData = [
                'sessions' => DB::table('sessions')->where('user_id', $drawerUser->id)->orderByDesc('last_activity')->get(),
                'comments' => Comment::withTrashed()->where('user_id', $drawerUser->id)->latest()->limit(10)->get(),
                'postCount' => Post::withTrashed()->where('user_id', $drawerUser->id)->count(),
                'hiddenPostCount' => Post::onlyTrashed()->where('user_id', $drawerUser->id)->count(),
                'conversationCount' => Conversation::where('user_one_id', $drawerUser->id)->orWhere('user_two_id', $drawerUser->id)->count(),
                'messageCount' => Message::where('sender_id', $drawerUser->id)->count(),
                'reportsAgainst' => DB::table('content_reports')
                    ->where(function ($query) use ($drawerUser): void {
                        $query->where(function ($q) use ($drawerUser): void {
                            $q->where('target_type', 'user')->where('target_id', $drawerUser->id);
                        })->orWhere(function ($q) use ($drawerUser): void {
                            $q->where('target_type', 'post')->whereIn('target_id', Post::withTrashed()->where('user_id', $drawerUser->id)->select('id'));
                        })->orWhere(function ($q) use ($drawerUser): void {
                            $q->where('target_type', 'comment')->whereIn('target_id', Comment::withTrashed()->where('user_id', $drawerUser->id)->select('id'));
                        })->orWhere(function ($q) use ($drawerUser): void {
                            $q->where('target_type', 'message')->whereIn('target_id', Message::where('sender_id', $drawerUser->id)->select('id'));
                        });
                    })
                    ->latest('created_at')->limit(10)->get(),
                'reportsFiled' => DB::table('content_reports')->where('reporter_id', $drawerUser->id)->count(),
                'notes' => AdminUserNote::with('author')->where('user_id', $drawerUser->id)->latest()->get(),
                'moderationHistory' => DB::table('admin_audit_logs')
                    ->leftJoin('users as history_actors', 'history_actors.id', '=', 'admin_audit_logs.actor_id')
                    ->where('admin_audit_logs.target_type', 'user')
                    ->where('admin_audit_logs.target_id', $drawerUser->id)
                    ->select('admin_audit_logs.*', 'history_actors.username as actor_username')
                    ->latest('admin_audit_logs.created_at')->limit(25)->get(),
                'blocksMade' => DB::table('user_blocks')->where('blocker_id', $drawerUser->id)->count(),
                'blockedByCount' => DB::table('user_blocks')->where('blocked_id', $drawerUser->id)->count(),
            ];
        }

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

        $inactiveOwnerDays = max(30, SiteSettings::int('site_inactive_owner_days'));
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
            'bugReports' => $bugReports,
            'openBugReportCount' => $openBugReportCount,
            'users' => $users,
            'posts' => $posts,
            'popularTags' => $popularTags,
            'tagAliases' => $tagAliases,
            'tagProposals' => $tagProposals,
            'pendingAppeals' => $pendingAppeals,
            'reportsPage' => $reportsPage,
            'reportQueue' => $reportQueue,
            'pendingReportCount' => $pendingReportCount,
            'reportReasons' => $this->reportReasons,
            'communityReports' => $communityReports,
            'userBlocks' => $userBlocks,
            'trashedPosts' => $trashedPosts,
            'trashedComments' => $trashedComments,
            'trashedPools' => $trashedPools,
            'trashedCollections' => $trashedCollections,
            'collections' => $collections,
            'communities' => $communities,
            'managedTags' => $managedTags,
            'storageUsage' => $storageUsage,
            'orphanedFiles' => $orphanedFiles,
            'missingThumbnails' => $missingThumbnails,
            'drawerUser' => $drawerUser,
            'drawerData' => $drawerData,
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

    /**
     * Shared user list query so "select all on page" matches render() exactly.
     *
     * @return \Illuminate\Database\Eloquent\Builder<User>
     */
    private function usersListQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = User::latest();

        if (filled($this->userSearch)) {
            $search = trim($this->userSearch);
            $query->where(function ($sub) use ($search): void {
                $sub->where('username', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    /**
     * Shared post list query so "select all on page" matches render() exactly.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Post>
     */
    private function postsListQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = Post::with(['user', 'primaryMedia', 'tags', 'comments'])->latest();

        if (filled($this->contentSearch)) {
            $search = trim($this->contentSearch);
            $query->where('title', 'like', "%{$search}%");
        }

        return $query;
    }
};
?>

<div x-data="{ navOpen: false, navHover: false }" class="min-h-screen bg-[var(--bg-page)] text-[var(--text-main)] flex flex-col md:flex-row relative">
    <!-- Mobile Top Header Bar -->
    <header class="md:hidden flex items-center justify-between p-4 border-b border-[var(--border-subtle)] bg-[var(--bg-surface)] sticky top-0 z-30 shadow-sm">
        <div class="flex items-center gap-3">
            <button @click="navOpen = !navOpen" aria-label="Toggle Navigation Menu" class="p-2.5 rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-page)] text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)] transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
            </button>
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-xl bg-gradient-to-tr from-purple-600 via-rose-500 to-amber-500 text-white flex items-center justify-center font-black text-sm shadow-md">🛡️</div>
                <span class="font-black text-sm tracking-tight">{{ $area === 'moderation' ? 'Moderation' : 'Admin' }} Console</span>
            </div>
        </div>
        <a href="{{ route('gallery') }}" class="px-3 py-1.5 rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] text-xs font-extrabold flex items-center gap-1.5 hover:bg-[var(--bg-surface-elevated)] transition">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
            <span>Back to Site</span>
        </a>
    </header>

    <!-- Mobile Backdrop Drawer Overlay -->
    <div x-show="navOpen" x-transition.opacity @click="navOpen = false" class="md:hidden fixed inset-0 z-40 bg-black/60 backdrop-blur-sm" x-cloak></div>

    <!-- Twitter-Style Floating Overlay Sidebar -->
    <aside 
        @mouseenter="navHover = true" 
        @mouseleave="navHover = false"
        :class="{ 
            'translate-x-0': navOpen, 
            '-translate-x-full md:translate-x-0': !navOpen,
            'md:w-72 shadow-2xl bg-[var(--bg-surface)]/95 backdrop-blur-2xl border-r border-[var(--border-subtle)]': navHover || navOpen,
            'md:w-20 bg-[var(--bg-surface)] border-r border-[var(--border-subtle)]': !navHover && !navOpen
        }"
        class="fixed md:sticky top-0 left-0 h-screen z-50 flex-shrink-0 flex flex-col justify-between p-3.5 transition-all duration-300 ease-in-out group overflow-y-auto overflow-x-hidden max-md:w-72 max-md:bg-[var(--bg-surface)]"
    >
        <div class="space-y-5">
            <!-- Brand Logo & Header -->
            <div class="flex items-center gap-3 px-2 pt-2">
                <a href="{{ route('admin') }}" class="flex items-center gap-3 group shrink-0">
                    <div class="w-11 h-11 rounded-2xl bg-gradient-to-tr from-purple-600 via-rose-500 to-amber-500 text-white flex items-center justify-center font-black text-xl shadow-lg group-hover:scale-105 transition shrink-0">
                        🛡️
                    </div>
                    <div class="transition-opacity duration-200 whitespace-nowrap" :class="{ 'md:block': navHover || navOpen, 'md:hidden': !navHover && !navOpen }">
                        <div class="font-black text-base tracking-tight text-[var(--text-main)] flex items-center gap-1">
                            Booru<span class="accent-text">.art</span>
                        </div>
                        <span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-wider bg-rose-500/10 text-rose-400 border border-rose-500/20">{{ $area === 'moderation' ? 'Moderation' : 'Admin Panel' }}</span>
                    </div>
                </a>
            </div>

            <!-- Twitter Navigation Items -->
            <nav class="space-y-1">
                <a href="{{ route($area === 'moderation' ? 'admin' : 'moderation') }}" title="Switch to {{ $area === 'moderation' ? 'Admin' : 'Moderation' }}" class="mb-4 flex items-center gap-3.5 rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-surface-elevated)] px-3.5 py-3 text-xs font-extrabold text-[var(--text-main)] hover:accent-bg hover:text-white">
                    <span class="text-lg">↔</span>
                    <span class="whitespace-nowrap" :class="{ 'md:block': navHover || navOpen, 'md:hidden': !navHover && !navOpen }">{{ $area === 'moderation' ? 'Switch to Admin' : 'Switch to Moderation' }}</span>
                </a>
                @if($area !== 'moderation')
                <!-- Overview -->
                <button wire:click="setSection('overview')" 
                        title="Overview"
                        class="w-full flex items-center gap-3.5 px-3.5 py-3 rounded-2xl font-extrabold text-xs transition {{ $activeSection === 'overview' ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                    <span class="whitespace-nowrap truncate" :class="{ 'md:block': navHover || navOpen, 'md:hidden': !navHover && !navOpen }">Overview</span>
                </button>

                <!-- Users & Bans -->
                <button wire:click="setSection('users')" 
                        title="Users & Bans"
                        class="w-full flex items-center gap-3.5 px-3.5 py-3 rounded-2xl font-extrabold text-xs transition {{ $activeSection === 'users' ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                    <span class="whitespace-nowrap truncate" :class="{ 'md:block': navHover || navOpen, 'md:hidden': !navHover && !navOpen }">Users & Bans</span>
                </button>

                <button wire:click="setSection('bug-reports')" title="Bug Reports" class="w-full flex items-center justify-between gap-3.5 rounded-2xl px-3.5 py-3 text-xs font-extrabold transition {{ $activeSection === 'bug-reports' ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:bg-[var(--bg-surface-elevated)] hover:text-[var(--text-main)]' }}">
                    <span class="flex items-center gap-3.5"><span class="text-lg">🐛</span><span class="whitespace-nowrap" :class="{ 'md:block': navHover || navOpen, 'md:hidden': !navHover && !navOpen }">Bug Reports</span></span>
                    @if($openBugReportCount > 0)<span class="rounded-full bg-rose-500 px-2 py-0.5 text-[10px] text-white">{{ $openBugReportCount }}</span>@endif
                </button>
                @endif

                @if($area !== 'admin')
                <!-- Media & Videos -->
                <button wire:click="setSection('content')" 
                        title="Media & Videos"
                        class="w-full flex items-center gap-3.5 px-3.5 py-3 rounded-2xl font-extrabold text-xs transition {{ $activeSection === 'content' ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                    <span class="whitespace-nowrap truncate" :class="{ 'md:block': navHover || navOpen, 'md:hidden': !navHover && !navOpen }">Media & Videos</span>
                </button>

                <!-- User DMs Audit -->
                <button wire:click="setSection('messages')" 
                        title="User DMs Audit"
                        class="w-full flex items-center gap-3.5 px-3.5 py-3 rounded-2xl font-extrabold text-xs transition {{ $activeSection === 'messages' ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path></svg>
                    <span class="whitespace-nowrap truncate" :class="{ 'md:block': navHover || navOpen, 'md:hidden': !navHover && !navOpen }">User DMs Audit</span>
                </button>

                <!-- Comments -->
                <button wire:click="setSection('comments')" 
                        title="Comments"
                        class="w-full flex items-center gap-3.5 px-3.5 py-3 rounded-2xl font-extrabold text-xs transition {{ $activeSection === 'comments' ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"></path></svg>
                    <span class="whitespace-nowrap truncate" :class="{ 'md:block': navHover || navOpen, 'md:hidden': !navHover && !navOpen }">Comments</span>
                </button>

                <!-- Pools & Series -->
                <button wire:click="setSection('pools')" 
                        title="Pools & Series"
                        class="w-full flex items-center gap-3.5 px-3.5 py-3 rounded-2xl font-extrabold text-xs transition {{ $activeSection === 'pools' ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
                    <span class="whitespace-nowrap truncate" :class="{ 'md:block': navHover || navOpen, 'md:hidden': !navHover && !navOpen }">Pools & Series</span>
                </button>

                <!-- Tag Aliases -->
                <button wire:click="setSection('tags')" 
                        title="Tag Aliases"
                        class="w-full flex items-center gap-3.5 px-3.5 py-3 rounded-2xl font-extrabold text-xs transition {{ $activeSection === 'tags' ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path></svg>
                    <span class="whitespace-nowrap truncate" :class="{ 'md:block': navHover || navOpen, 'md:hidden': !navHover && !navOpen }">Tag Aliases</span>
                </button>

                <!-- Appeals -->
                <button wire:click="setSection('appeals')" 
                        title="Appeals"
                        class="w-full flex items-center justify-between gap-3.5 px-3.5 py-3 rounded-2xl font-extrabold text-xs transition {{ $activeSection === 'appeals' ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    <div class="flex items-center gap-3.5 min-w-0">
                        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7h6m6 1l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9h6m-12 0a2 2 0 100-4 2 2 0 000 4z"></path></svg>
                        <span class="whitespace-nowrap truncate" :class="{ 'md:block': navHover || navOpen, 'md:hidden': !navHover && !navOpen }">Ban Appeals</span>
                    </div>
                    @if($pendingAppeals->total() > 0)
                        <span class="px-2 py-0.5 text-[10px] font-black rounded-full bg-rose-500 text-white shrink-0">{{ $pendingAppeals->total() }}</span>
                    @endif
                </button>

                <!-- Content Reports -->
                <button wire:click="setSection('reports')" 
                        title="Content Reports"
                        class="w-full flex items-center justify-between gap-3.5 px-3.5 py-3 rounded-2xl font-extrabold text-xs transition {{ $activeSection === 'reports' ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    <div class="flex items-center gap-3.5 min-w-0">
                        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9"></path></svg>
                        <span class="whitespace-nowrap truncate" :class="{ 'md:block': navHover || navOpen, 'md:hidden': !navHover && !navOpen }">Content Reports</span>
                    </div>
                    @if($pendingReportCount > 0)
                        <span class="px-2 py-0.5 text-[10px] font-black rounded-full bg-amber-500 text-white shrink-0">{{ $pendingReportCount }}</span>
                    @endif
                </button>

                <!-- Lounge Reports -->
                <button wire:click="setSection('community-reports')" 
                        title="Lounge Reports"
                        class="w-full flex items-center justify-between gap-3.5 px-3.5 py-3 rounded-2xl font-extrabold text-xs transition {{ $activeSection === 'community-reports' ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    <div class="flex items-center gap-3.5 min-w-0">
                        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                        <span class="whitespace-nowrap truncate" :class="{ 'md:block': navHover || navOpen, 'md:hidden': !navHover && !navOpen }">Lounge Reports</span>
                    </div>
                    @if($communityReports->total() > 0)
                        <span class="px-2 py-0.5 text-[10px] font-black rounded-full bg-purple-500 text-white shrink-0">{{ $communityReports->total() }}</span>
                    @endif
                </button>

                <!-- Moderation History -->
                <button wire:click="setSection('moderation')" 
                        title="Moderation History"
                        class="w-full flex items-center gap-3.5 px-3.5 py-3 rounded-2xl font-extrabold text-xs transition {{ $activeSection === 'moderation' ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span class="whitespace-nowrap truncate" :class="{ 'md:block': navHover || navOpen, 'md:hidden': !navHover && !navOpen }">Moderation History</span>
                </button>

                <!-- User Blocks -->
                <button wire:click="setSection('blocked')" 
                        title="User Blocks"
                        class="w-full flex items-center justify-between gap-3.5 px-3.5 py-3 rounded-2xl font-extrabold text-xs transition {{ $activeSection === 'blocked' ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    <div class="flex items-center gap-3.5 min-w-0">
                        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path></svg>
                        <span class="whitespace-nowrap truncate" :class="{ 'md:block': navHover || navOpen, 'md:hidden': !navHover && !navOpen }">User Blocks</span>
                    </div>
                    @if($userBlocks->total() > 0)
                        <span class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-[var(--bg-page)] text-[var(--text-dim)] shrink-0">{{ $userBlocks->total() }}</span>
                    @endif
                </button>

                <!-- Collections -->
                <button wire:click="setSection('collections')" 
                        title="Collections"
                        class="w-full flex items-center gap-3.5 px-3.5 py-3 rounded-2xl font-extrabold text-xs transition {{ $activeSection === 'collections' ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                    <span class="whitespace-nowrap truncate" :class="{ 'md:block': navHover || navOpen, 'md:hidden': !navHover && !navOpen }">Collections</span>
                </button>

                @endif
                @if($area !== 'moderation')
                <!-- Communities -->
                <button wire:click="setSection('communities')" 
                        title="Communities"
                        class="w-full flex items-center gap-3.5 px-3.5 py-3 rounded-2xl font-extrabold text-xs transition {{ $activeSection === 'communities' ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5m3 0h1m-1-4h.01M9 16h.01M9 12h.01M9 8h.01M15 16h.01M15 12h.01M15 8h.01M4 21h16"></path></svg>
                    <span class="whitespace-nowrap truncate" :class="{ 'md:block': navHover || navOpen, 'md:hidden': !navHover && !navOpen }">Communities</span>
                </button>

                <!-- Media Storage -->
                <button wire:click="setSection('media')" 
                        title="Media & Storage"
                        class="w-full flex items-center gap-3.5 px-3.5 py-3 rounded-2xl font-extrabold text-xs transition {{ $activeSection === 'media' ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path></svg>
                    <span class="whitespace-nowrap truncate" :class="{ 'md:block': navHover || navOpen, 'md:hidden': !navHover && !navOpen }">Media Storage</span>
                </button>

                <!-- Admin Audit Log -->
                <button wire:click="setSection('audit')" 
                        title="Admin Audit Log"
                        class="w-full flex items-center gap-3.5 px-3.5 py-3 rounded-2xl font-extrabold text-xs transition {{ $activeSection === 'audit' ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    <span class="whitespace-nowrap truncate" :class="{ 'md:block': navHover || navOpen, 'md:hidden': !navHover && !navOpen }">Admin Audit Log</span>
                </button>

                <!-- Community Recovery -->
                <button wire:click="setSection('recovery')" 
                        title="Community Recovery"
                        class="w-full flex items-center justify-between gap-3.5 px-3.5 py-3 rounded-2xl font-extrabold text-xs transition {{ $activeSection === 'recovery' ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    <div class="flex items-center gap-3.5 min-w-0">
                        <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                        <span class="whitespace-nowrap truncate" :class="{ 'md:block': navHover || navOpen, 'md:hidden': !navHover && !navOpen }">Community Recovery</span>
                    </div>
                    @if($recoverableCommunities->total() > 0)
                        <span class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-emerald-500 text-white shrink-0">{{ $recoverableCommunities->total() }}</span>
                    @endif
                </button>

                <!-- Site Config -->
                <button wire:click="setSection('settings')" 
                        title="Site Config"
                        class="w-full flex items-center gap-3.5 px-3.5 py-3 rounded-2xl font-extrabold text-xs transition {{ $activeSection === 'settings' ? 'accent-bg text-white shadow-md' : 'text-[var(--text-muted)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)]' }}">
                    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    <span class="whitespace-nowrap truncate" :class="{ 'md:block': navHover || navOpen, 'md:hidden': !navHover && !navOpen }">Site Config</span>
                </button>
                @endif
            </nav>
        </div>

        <!-- Twitter Profile Card & Back to Site Button -->
        <div class="pt-4 border-t border-[var(--border-subtle)] space-y-3">
            <div class="p-2.5 rounded-2xl bg-[var(--bg-surface-elevated)] flex items-center justify-between border border-[var(--border-subtle)]">
                <div class="flex items-center gap-3 min-w-0">
                    <img src="{{ $currentUser->avatar_url }}" class="w-9 h-9 rounded-full object-cover shrink-0 border border-purple-500/50">
                    <div class="min-w-0 whitespace-nowrap" :class="{ 'md:block': navHover || navOpen, 'md:hidden': !navHover && !navOpen }">
                        <div class="font-bold text-xs text-[var(--text-main)] truncate">{{ $currentUser->name }}</div>
                        <div class="text-[10px] text-[var(--text-dim)] truncate">@<span>{{ $currentUser->username }}</span></div>
                    </div>
                </div>
            </div>

            <!-- Return / Back to Site Link -->
            <a href="{{ route('gallery') }}" 
               title="Back to Site"
               class="w-full flex items-center justify-center gap-2.5 py-3 px-3.5 rounded-2xl accent-bg hover:opacity-90 text-white font-extrabold text-xs transition shadow-md">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                <span class="whitespace-nowrap truncate" :class="{ 'md:inline': navHover || navOpen, 'md:hidden': !navHover && !navOpen }">Back to Site</span>
            </a>
        </div>
    </aside>

    <!-- Admin Main Content Body Area -->
    <main class="flex-1 p-4 md:p-8 space-y-8 min-w-0 overflow-x-hidden">
        @if($activeSection === 'bug-reports')
            <section class="space-y-5">
                <div>
                    <h1 class="text-2xl font-black">Bug reports</h1>
                    <p class="mt-1 text-sm text-[var(--text-muted)]">Reports sent by site visitors and members. Notes are visible only to admins.</p>
                </div>
                <label class="block text-sm font-bold">Status
                    <select wire:model.live="bugReportStatusFilter" class="ml-2 rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-3 py-2 text-[var(--text-main)]">
                        <option value="open">Open</option><option value="in_progress">In progress</option><option value="resolved">Resolved</option><option value="closed">Closed</option><option value="all">All</option>
                    </select>
                </label>
                @forelse($bugReports as $report)
                    <article wire:key="bug-report-{{ $report->id }}" class="space-y-4 rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div><h2 class="font-black">#{{ $report->id }} · {{ $report->subject }}</h2><p class="text-xs text-[var(--text-muted)]">{{ $report->reporter_username ? '@'.$report->reporter_username : 'Guest' }} · {{ $report->email }} · {{ \Illuminate\Support\Carbon::parse($report->created_at)->format('M j, Y g:i A') }}</p></div>
                            <span class="rounded-full bg-[var(--bg-page)] px-3 py-1 text-xs font-bold">{{ str_replace('_', ' ', ucfirst($report->status)) }}</span>
                        </div>
                        <p class="whitespace-pre-wrap break-words text-sm">{{ $report->description }}</p>
                        @if($report->steps_to_reproduce)<div><h3 class="text-xs font-black uppercase text-[var(--text-muted)]">Steps to reproduce</h3><p class="mt-1 whitespace-pre-wrap break-words text-sm">{{ $report->steps_to_reproduce }}</p></div>@endif
                        @if($report->page_url)<p class="break-all text-xs text-[var(--text-muted)]">Page: {{ $report->page_url }}</p>@endif
                        <label class="block text-xs font-bold">Internal note
                            <textarea wire:model="bugReportNotes.{{ $report->id }}" maxlength="5000" rows="3" placeholder="{{ $report->admin_note ?: 'Add a note for other admins' }}" class="mt-2 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] p-3 text-sm text-[var(--text-main)]"></textarea>
                        </label>
                        @if($report->admin_note)<p class="text-xs text-[var(--text-muted)]">Saved note: {{ $report->admin_note }}</p>@endif
                        <div class="flex flex-wrap gap-2">
                            @foreach(['open' => 'Reopen', 'in_progress' => 'In progress', 'resolved' => 'Resolve', 'closed' => 'Close'] as $status => $label)
                                <button type="button" wire:click="reviewBugReport({{ $report->id }}, '{{ $status }}')" class="rounded-xl border border-[var(--border-subtle)] px-3 py-2 text-xs font-bold hover:bg-[var(--bg-surface-elevated)]">{{ $label }}</button>
                            @endforeach
                        </div>
                    </article>
                @empty
                    <p class="rounded-2xl bg-[var(--bg-surface)] p-6 text-sm text-[var(--text-muted)]">No bug reports match this status.</p>
                @endforelse
                {{ $bugReports->links() }}
            </section>
        @endif
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
                        <p class="text-xs text-[var(--text-dim)]">Publish a live site-wide announcement banner visible to all visitors. Leave empty to hide the banner.</p>
                    </div>
                    <button wire:click="saveSettings" class="px-5 py-2.5 rounded-2xl accent-bg text-white font-bold text-xs shadow hover:opacity-90 transition">
                        Update Broadcast
                    </button>
                </div>
                <input type="text" wire:model="announcementText" placeholder="Enter broadcast announcement message..."
                       class="w-full px-4 py-3 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-xs outline-none focus:border-[var(--accent-primary)] font-medium">
                <label class="flex items-center gap-2 text-xs font-bold text-[var(--text-main)]">
                    <input type="checkbox" wire:model="announcementNotifyUsers" class="rounded border-[var(--border-medium)]">
                    Also send this as an in-app notification to every account
                </label>
                @if(filled($announcementText))
                    <div class="rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-page)] p-3">
                        <p class="text-[10px] font-black uppercase tracking-wide text-[var(--text-dim)]">Banner preview</p>
                        <div class="mt-2 flex items-start gap-3 rounded-2xl border border-[var(--accent-primary)]/40 bg-[var(--bg-surface)] px-4 py-3">
                            <span aria-hidden="true">📢</span>
                            <p class="text-sm font-semibold text-[var(--text-main)]">{{ $announcementText }}</p>
                        </div>
                    </div>
                @endif
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

                <!-- Mobile User Cards View (md:hidden) -->
                <div class="md:hidden space-y-3">
                    @foreach($users as $u)
                        <div wire:key="user-card-{{ $u->id }}" class="p-4 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] space-y-3.5 {{ $u->is_banned ? 'border-rose-500/30 bg-rose-500/5' : '' }}">
                            <!-- Card Header: Checkbox, Avatar, Name & Status Badges -->
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex items-center gap-3 min-w-0">
                                    <input type="checkbox" value="{{ $u->id }}" wire:model.live="selectedUserIds" class="rounded border-[var(--border-medium)] shrink-0">
                                    <img src="{{ $u->avatar_url }}" class="w-10 h-10 rounded-full object-cover shrink-0 border border-[var(--border-subtle)]">
                                    <div class="min-w-0">
                                        <a href="{{ route('profile', $u->username) }}" class="font-extrabold text-sm text-[var(--text-main)] hover:underline flex items-center gap-1.5 truncate">
                                            <span>{{ $u->name }}</span>
                                            @if($u->is_banned)
                                                <span class="px-1.5 py-0.2 rounded text-[9px] font-black bg-rose-500 text-white shrink-0">BANNED</span>
                                            @endif
                                        </a>
                                        <div class="text-[var(--text-dim)] text-xs truncate">@<span>{{ $u->username }}</span></div>
                                    </div>
                                </div>

                                <!-- Badges -->
                                <div class="flex flex-col items-end gap-1 shrink-0">
                                    @if($u->isAdmin())
                                        <span class="px-2 py-0.5 rounded-full text-[9px] font-black bg-purple-500/20 text-purple-400 border border-purple-500/30 uppercase">ADMIN</span>
                                    @else
                                        <span class="px-2 py-0.5 rounded-full text-[9px] font-semibold bg-white/10 text-[var(--text-muted)] uppercase">USER</span>
                                    @endif

                                    @if($u->is_banned)
                                        <span class="px-2 py-0.5 rounded-xl font-bold text-[9px] bg-rose-500/20 text-rose-400 border border-rose-500/30">Suspended</span>
                                    @else
                                        <span class="px-2 py-0.5 rounded-xl font-bold text-[9px] bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">Active</span>
                                    @endif
                                </div>
                            </div>

                            <!-- Artist Status & Last Active -->
                            <div class="flex flex-wrap items-center justify-between gap-2 pt-2 border-t border-[var(--border-subtle)]/50 text-xs">
                                <button wire:click="toggleUserArtist({{ $u->id }})" class="px-3 py-1.5 rounded-xl font-bold text-xs border transition {{ $u->is_artist ? 'accent-bg text-white border-transparent' : 'border-[var(--border-subtle)] text-[var(--text-dim)] hover:text-[var(--text-main)]' }}">
                                    {{ $u->is_artist ? '✓ Verified Artist' : '+ Grant Artist' }}
                                </button>
                                
                                <span class="text-[11px] text-[var(--text-dim)]">
                                    Last active {{ $u->last_active_at?->diffForHumans() ?? 'never' }}
                                </span>
                            </div>

                            @if($u->is_banned && $u->suspension_reason)
                                <p class="text-xs text-rose-400/90 bg-rose-500/10 p-2.5 rounded-xl border border-rose-500/20">
                                    <strong>Reason:</strong> {{ $u->suspension_reason }}
                                </p>
                            @endif

                            <!-- Action Buttons Grid -->
                            <div class="grid grid-cols-2 gap-2 pt-1">
                                <button wire:click="openUserDrawer({{ $u->id }})" class="w-full py-2 px-3 rounded-xl text-xs font-bold border border-[var(--border-medium)] text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)] transition text-center">
                                    Details
                                </button>
                                @if($u->id !== auth()->id())
                                    <button wire:click="prepareWarning({{ $u->id }})" class="w-full py-2 px-3 rounded-xl text-xs font-bold border border-amber-500/40 text-amber-400 hover:bg-amber-500/10 transition text-center">
                                        Warn…
                                    </button>
                                    @if($u->isAdmin())
                                        <span class="col-span-2 py-2 px-3 text-center text-xs text-[var(--text-dim)] bg-[var(--bg-surface)] rounded-xl border border-[var(--border-subtle)]">Admin protected</span>
                                    @elseif($u->is_banned)
                                        <button wire:click="toggleUserBan({{ $u->id }})" class="col-span-2 w-full py-2 px-3 rounded-xl text-xs font-bold bg-emerald-500 text-white transition text-center">
                                            Lift suspension
                                        </button>
                                    @else
                                        <button wire:click="prepareSuspension({{ $u->id }})" class="w-full py-2 px-3 rounded-xl text-xs font-bold border border-rose-500/40 text-rose-400 hover:bg-rose-500/10 transition text-center">
                                            Suspend…
                                        </button>
                                    @endif
                                    @if(!$u->isAdmin())
                                        <button wire:click="toggleUserAdmin({{ $u->id }})" class="w-full py-2 px-3 rounded-xl text-xs font-bold border border-purple-500/30 text-purple-400 hover:bg-purple-500/10 transition text-center">
                                            Make Admin
                                        </button>
                                    @else
                                        <button wire:click="toggleUserAdmin({{ $u->id }})" class="col-span-2 w-full py-2 px-3 rounded-xl text-xs font-bold border border-purple-500/30 text-purple-400 hover:bg-purple-500/10 transition text-center">
                                            Demote Admin
                                        </button>
                                    @endif
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <!-- Desktop User Table View (hidden md:block) -->
                <div class="hidden md:block overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="border-b border-[var(--border-subtle)] text-[var(--text-dim)] uppercase text-[10px]">
                                <th class="py-3 px-3 w-8">
                                    <input type="checkbox" title="Select all users on this page"
                                           @checked(count($selectedUserIds) > 0 && count(array_diff($users->pluck('id')->all(), $selectedUserIds)) === 0)
                                           wire:click="toggleSelectAllUsers" class="rounded border-[var(--border-medium)]">
                                </th>
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
                                    <td class="py-3 px-3">
                                        <input type="checkbox" value="{{ $u->id }}" wire:model.live="selectedUserIds" class="rounded border-[var(--border-medium)]">
                                    </td>
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
                                            @if($u->suspension_reason)
                                                <p class="mt-1 max-w-48 text-[10px] text-[var(--text-dim)]">{{ \Illuminate\Support\Str::limit($u->suspension_reason, 60) }}</p>
                                            @endif
                                        @else
                                            <span class="px-2.5 py-1 rounded-xl font-bold text-[10px] bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">Active</span>
                                        @endif
                                        <p class="mt-1 text-[10px] text-[var(--text-dim)]">
                                            Last active {{ $u->last_active_at?->diffForHumans() ?? 'never' }}
                                        </p>
                                    </td>
                                    <td class="py-3 px-3 text-right space-x-1.5">
                                        <button wire:click="openUserDrawer({{ $u->id }})" class="px-2.5 py-1 rounded-xl text-[10px] font-bold border border-[var(--border-medium)] text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)] transition">Details</button>
                                        @if($u->id !== auth()->id())
                                            <button wire:click="prepareWarning({{ $u->id }})" class="px-2.5 py-1 rounded-xl text-[10px] font-bold border border-amber-500/40 text-amber-400 hover:bg-amber-500/10 transition">Warn…</button>
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

                <div class="flex flex-wrap items-center gap-2 rounded-2xl bg-[var(--bg-page)] p-3">
                    <span class="text-xs font-bold text-[var(--text-dim)]">{{ count($selectedUserIds) }} selected</span>
                    <button wire:click="bulkUserAction('ban')" wire:confirm="Suspend every selected user?" class="rounded-xl bg-rose-600 px-3 py-2 text-xs font-bold text-white">Suspend selected</button>
                    <button wire:click="bulkUserAction('unban')" class="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-bold text-white">Lift suspension</button>
                    <button wire:click="bulkUserAction('artist')" class="rounded-xl border border-[var(--border-medium)] px-3 py-2 text-xs font-bold">Grant artist</button>
                    <button wire:click="bulkUserAction('not_artist')" class="rounded-xl border border-[var(--border-medium)] px-3 py-2 text-xs font-bold">Remove artist</button>
                    <button wire:click="$set('selectedUserIds', [])" class="rounded-xl px-3 py-2 text-xs font-bold text-[var(--text-dim)]">Clear selection</button>
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

                <div class="flex flex-wrap items-center gap-2 rounded-2xl bg-[var(--bg-page)] p-3">
                    <label class="flex items-center gap-2 text-xs font-bold text-[var(--text-dim)]">
                        <input type="checkbox" wire:click="toggleSelectAllPosts" class="rounded border-[var(--border-medium)]">
                        Select page
                    </label>
                    <span class="text-xs font-bold text-[var(--text-dim)]">{{ count($selectedPostIds) }} selected</span>
                    <button wire:click="bulkPostAction('feature')" class="rounded-xl bg-amber-500 px-3 py-2 text-xs font-bold text-black">Feature</button>
                    <button wire:click="bulkPostAction('unfeature')" class="rounded-xl border border-[var(--border-medium)] px-3 py-2 text-xs font-bold">Unfeature</button>
                    <button wire:click="bulkPostAction('nsfw')" class="rounded-xl border border-rose-500/40 px-3 py-2 text-xs font-bold text-rose-400">Mark NSFW</button>
                    <button wire:click="bulkPostAction('safe')" class="rounded-xl border border-emerald-500/40 px-3 py-2 text-xs font-bold text-emerald-400">Mark safe</button>
                    <input wire:model="bulkTagName" placeholder="tag_name" class="w-32 rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-3 py-2 text-xs">
                    <button wire:click="bulkPostAction('tag')" class="rounded-xl accent-bg px-3 py-2 text-xs font-bold text-white">Add tag</button>
                    <button wire:click="bulkPostAction('remove')" wire:confirm="Remove every selected post? They can be restored from Moderation history." class="rounded-xl bg-rose-600 px-3 py-2 text-xs font-bold text-white">Remove</button>
                    <button wire:click="$set('selectedPostIds', [])" class="rounded-xl px-3 py-2 text-xs font-bold text-[var(--text-dim)]">Clear</button>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
                    @foreach($posts as $p)
                        <div class="p-5 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] space-y-4 flex flex-col justify-between">
                            <div class="flex items-start gap-4">
                                <input type="checkbox" value="{{ $p->id }}" wire:model.live="selectedPostIds" class="mt-1 rounded border-[var(--border-medium)]">
                                <div class="relative w-24 h-24 rounded-xl overflow-hidden bg-neutral-900 shrink-0 cursor-pointer"
                                     wire:click="openInspectModal({{ $p->id }})">
                                    @if($p->media_type === 'video')
                                        <video src="{{ $p->primaryMedia->url }}" class="w-full h-full object-cover" muted></video>
                                        <span class="absolute bottom-1 right-1 px-1.5 rounded text-[9px] font-black bg-purple-600 text-white">VID</span>
                                    @else
                                        <img src="{{ $p->primaryMedia->thumbnail_url ?? $p->primaryMedia->url }}" class="w-full h-full object-cover">
                                    @endif
                                </div>
                                <div class="min-w-0 flex-1 space-y-1.5">
                                    <div class="font-bold text-sm text-[var(--text-main)] line-clamp-2">{{ $p->title }}</div>
                                    <div class="text-xs text-[var(--text-dim)]">by <button type="button" wire:click="openUserDrawer({{ $p->user_id }})" class="underline decoration-dotted">{{ '@'.($p->user->username) }}</button></div>
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <a href="{{ route('post.detail', ['id' => $p->id, 'from_admin' => 1]) }}" class="text-[10px] font-bold accent-text hover:underline flex items-center gap-0.5">
                                            <span>Full Page ↗</span>
                                        </a>
                                        @if($p->is_featured)
                                            <span class="px-2 py-0.5 rounded text-[9px] font-bold bg-amber-500/20 text-amber-400">★ FEATURED</span>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-2 pt-3 border-t border-[var(--border-subtle)] md:justify-between">
                                <button wire:click="openInspectModal({{ $p->id }})" class="whitespace-nowrap px-3 py-1.5 rounded-xl text-[11px] font-bold bg-[var(--bg-surface-elevated)] text-[var(--text-main)] hover:bg-[var(--border-subtle)] transition">
                                    Inspect 🔍
                                </button>
                                <button wire:click="togglePostNsfw({{ $p->id }})" class="whitespace-nowrap px-3 py-1.5 rounded-xl text-[11px] font-bold border transition {{ $p->is_nsfw ? 'bg-rose-500/20 text-rose-400 border-rose-500/30' : 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30' }}">
                                    {{ $p->is_nsfw ? 'NSFW' : 'SAFE' }}
                                </button>
                                <button wire:click="togglePostFeatured({{ $p->id }})" class="whitespace-nowrap px-3 py-1.5 rounded-xl text-[11px] font-bold border transition {{ $p->is_featured ? 'bg-amber-500 text-black font-extrabold border-transparent' : 'border-[var(--border-subtle)] text-[var(--text-dim)]' }}">
                                    {{ $p->is_featured ? '★ Featured' : '+ Feature' }}
                                </button>
                                <button wire:click="togglePostCommentsLock({{ $p->id }})" class="whitespace-nowrap px-3 py-1.5 rounded-xl text-[11px] font-bold border transition {{ $p->comments_locked ? 'bg-amber-500/20 text-amber-400 border-amber-500/30' : 'border-[var(--border-subtle)] text-[var(--text-dim)]' }}">
                                    {{ $p->comments_locked ? '🔒 Locked' : 'Unlocked' }}
                                </button>
                                <button wire:click="deletePost({{ $p->id }})" wire:confirm="Remove this post? It can be restored from Moderation history." class="whitespace-nowrap px-3 py-1.5 rounded-xl text-[11px] font-bold bg-rose-500 text-white hover:opacity-90 transition">
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
                                            {{ $conv->visibleLatestMessage->text ?? 'Shared post attachment' }}
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
                        <p class="text-xs text-[var(--text-dim)]">Flag, hide, edit, or remove comments. Hiding keeps the comment in the database and is reversible; deletion is reversible from Moderation history.</p>
                    </div>
                    <input type="text" wire:model.live.debounce.200ms="commentSearch" placeholder="Search comment text..."
                           class="px-4 py-2 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] text-xs outline-none focus:border-[var(--accent-primary)] w-full sm:w-72">
                </div>

                <div class="space-y-3">
                    @forelse($comments as $c)
                        <div class="p-4 rounded-2xl bg-[var(--bg-page)] border border-[var(--border-subtle)] flex items-start justify-between gap-4 {{ $c->is_hidden ? 'opacity-70' : '' }}">
                            <div class="flex items-start gap-3 min-w-0">
                                <img src="{{ $c->user->avatar_url }}" class="w-8 h-8 rounded-full object-cover shrink-0">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button type="button" wire:click="openUserDrawer({{ $c->user_id }})" class="font-bold text-xs text-[var(--text-main)] underline decoration-dotted">{{ $c->user->name }}</button>
                                        <span class="text-[11px] text-[var(--text-dim)]">{{ $c->user->username }}</span>
                                        <span class="text-[10px] text-[var(--text-dim)]">· {{ $c->created_at->diffForHumans() }}</span>
                                        @if($c->is_hidden)
                                            <span class="rounded-lg bg-amber-500/20 px-2 py-0.5 text-[10px] font-black uppercase text-amber-400">hidden</span>
                                        @endif
                                    </div>

                                    @if($editingCommentId === $c->id)
                                        <div class="mt-2 space-y-2">
                                            <textarea wire:model="editingCommentText" rows="2" class="w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-2.5 text-xs text-[var(--text-main)]"></textarea>
                                            @error('editingCommentText') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                                            <div class="flex gap-2">
                                                <button wire:click="saveCommentEdit" class="rounded-lg bg-emerald-600 px-2.5 py-1 text-[10px] font-bold text-white">Save</button>
                                                <button wire:click="$set('editingCommentId', null)" class="rounded-lg border border-[var(--border-medium)] px-2.5 py-1 text-[10px] font-bold">Cancel</button>
                                            </div>
                                        </div>
                                    @else
                                        <p class="text-xs text-[var(--text-main)] mt-1 font-medium">{{ $c->content }}</p>
                                    @endif

                                    @if($c->hidden_reason)<p class="mt-1 text-[10px] text-amber-400">Reason: {{ $c->hidden_reason }}</p>@endif
                                    @if($c->post)
                                        <div class="text-[10px] text-[var(--text-dim)] mt-1">On Post: <a href="{{ route('post.detail', ['id' => $c->post->id, 'from_admin' => 1]) }}" class="accent-text font-bold hover:underline">#{{ $c->post->id }} - {{ $c->post->title }}</a></div>
                                    @endif
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-2 shrink-0">
                                <button wire:click="toggleCommentFlag({{ $c->id }})" class="px-2.5 py-1 rounded-xl text-[10px] font-bold border transition {{ $c->is_flagged ? 'bg-amber-500/20 text-amber-400 border-amber-500/30' : 'border-[var(--border-subtle)] text-[var(--text-dim)]' }}">
                                    {{ $c->is_flagged ? '🚩 Flagged' : 'Flag' }}
                                </button>
                                <button wire:click="toggleCommentHidden({{ $c->id }})" class="px-2.5 py-1 rounded-xl text-[10px] font-bold border border-[var(--border-medium)] transition">
                                    {{ $c->is_hidden ? 'Unhide' : 'Hide' }}
                                </button>
                                <button wire:click="startCommentEdit({{ $c->id }})" class="px-2.5 py-1 rounded-xl text-[10px] font-bold border border-[var(--border-medium)] transition">Edit</button>
                                <button wire:click="deleteComment({{ $c->id }})" wire:confirm="Remove this comment? It can be restored from Moderation history." class="px-2.5 py-1 rounded-xl text-[10px] font-bold bg-rose-500 text-white hover:opacity-90 transition">
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

                <!-- Tag management: rename, retype, merge, delete -->
                <div class="mt-6 space-y-4 rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6">
                    <header>
                        <h3 class="text-lg font-bold">Tag management</h3>
                        <p class="mt-1 text-xs text-[var(--text-dim)]">Change a tag's type, rename it (aliases and post links follow automatically), merge duplicates into a canonical tag, or delete a tag entirely.</p>
                    </header>

                    <div class="flex flex-wrap items-end gap-2">
                        <input wire:model.live.debounce.300ms="tagSearch" placeholder="Search tags" class="min-w-56 flex-1 rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-4 py-2.5 text-sm outline-none">
                        <input wire:model="mergeSourceName" placeholder="merge from (e.g. nekomimi)" class="w-48 rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2.5 text-sm">
                        <input wire:model="mergeTargetName" placeholder="merge into (canonical)" class="w-48 rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2.5 text-sm">
                        <button wire:click="mergeTags" wire:confirm="Merge tags and create an alias? This cannot be undone." class="rounded-xl accent-bg px-4 py-2.5 text-xs font-bold text-white">Merge tags</button>
                    </div>
                    @error('mergeSourceName') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                    @error('mergeTargetName') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror

                    <div class="space-y-2">
                        @forelse($managedTags as $tag)
                            <div wire:key="managed-tag-{{ $tag->id }}" class="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-[var(--bg-page)] p-3">
                                <div class="min-w-0">
                                    <span class="px-2.5 py-1 rounded-xl text-xs font-bold border {{ $tag->getTypeBadgeClasses() }}">#{{ $tag->name }}</span>
                                    <span class="ml-2 text-[11px] text-[var(--text-dim)]">{{ $tag->type }} · {{ $tag->posts_count }} posts</span>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <select wire:model="newTagType" class="rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-2 py-1.5 text-xs">
                                        <option value="general">general</option>
                                        <option value="artist">artist</option>
                                        <option value="character">character</option>
                                        <option value="series">series</option>
                                        <option value="meta">meta</option>
                                    </select>
                                    <button wire:click="updateTagType({{ $tag->id }})" class="rounded-xl border border-[var(--border-medium)] px-3 py-1.5 text-xs font-bold">Set type</button>
                                    <button type="button" x-on:click="const name = window.prompt('New tag name', @js($tag->name)); if (name !== null) $wire.renameTag({{ $tag->id }}, name)" class="rounded-xl border border-[var(--border-medium)] px-3 py-1.5 text-xs font-bold">Rename</button>
                                    <button wire:click="deleteTag({{ $tag->id }})" wire:confirm="Delete #{{ $tag->name }} and detach it from every post?" class="rounded-xl bg-rose-600 px-3 py-1.5 text-xs font-bold text-white">Delete</button>
                                </div>
                            </div>
                        @empty
                            <p class="rounded-2xl bg-[var(--bg-page)] p-4 text-sm text-[var(--text-dim)]">No tags match that search.</p>
                        @endforelse
                    </div>

                    {{ $managedTags->links() }}
                </div>
            </div>
        @endif

        @if($activeSection === 'reports')
            <section class="space-y-4 rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6">
                <header class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-xl font-black">Content report queue</h2>
                        <p class="text-sm text-[var(--text-muted)]">Every report type: posts, comments, direct messages, accounts and communities. Write a reason, then act straight from the queue — the reported user is told what happened.</p>
                    </div>
                    <span class="rounded-full bg-[var(--bg-page)] px-3 py-1 text-xs font-black">{{ $pendingReportCount }} pending</span>
                </header>

                <div class="flex flex-wrap items-center gap-2">
                    <input wire:model.live.debounce.300ms="reportSearch" placeholder="Search reason or details" class="min-w-56 flex-1 rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-4 py-2.5 text-sm outline-none">
                    <select wire:model.live="reportStatusFilter" class="rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2.5 text-sm">
                        <option value="pending">Pending</option>
                        <option value="actioned">Actioned</option>
                        <option value="dismissed">Dismissed</option>
                        <option value="all">All statuses</option>
                    </select>
                    <select wire:model.live="reportTypeFilter" class="rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2.5 text-sm">
                        <option value="all">All target types</option>
                        @foreach(\App\Support\ContentReports::TARGET_TYPES as $targetType)
                            <option value="{{ $targetType }}">{{ \App\Support\ContentReports::labelFor($targetType) }}</option>
                        @endforeach
                    </select>
                </div>

                @forelse($reportQueue as $row)
                    @php($report = $row['report'])
                    <article wire:key="report-{{ $report->id }}" class="space-y-3 rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-page)] p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-lg bg-[var(--bg-surface-elevated)] px-2 py-0.5 text-[10px] font-black uppercase tracking-wide text-[var(--text-muted)]">{{ $row['label'] }}</span>
                                    <span class="rounded-lg px-2 py-0.5 text-[10px] font-black uppercase tracking-wide {{ $report->status === 'pending' ? 'bg-amber-500/20 text-amber-400' : 'bg-emerald-500/20 text-emerald-400' }}">{{ $report->status }}</span>
                                    @if($row['missing'])
                                        <span class="rounded-lg bg-rose-500/20 px-2 py-0.5 text-[10px] font-black uppercase tracking-wide text-rose-400">already handled</span>
                                    @endif
                                </div>

                                <p class="text-sm font-bold text-[var(--text-main)]">
                                    {{ $row['title'] ?? 'Target no longer exists' }}
                                    @if($row['author_username'])
                                        <span class="font-normal text-[var(--text-dim)]">by <button type="button" wire:click="openUserDrawer({{ $row['author_id'] }})" class="underline decoration-dotted hover:text-[var(--text-main)]">{{ '@'.($row['author_username']) }}</button></span>
                                    @endif
                                </p>

                                @if($row['excerpt'])
                                    <p class="line-clamp-3 text-xs text-[var(--text-muted)]">{{ \Illuminate\Support\Str::limit($row['excerpt'], 220) }}</p>
                                @endif

                                <p class="text-xs text-[var(--text-muted)]">
                                    Reason: <b>{{ str($report->reason)->replace('_', ' ')->title() }}</b>
                                    · reported by {{ '@'.($row['reporter_username'] ?? 'deleted user') }}
                                    · {{ \Illuminate\Support\Carbon::parse($report->created_at)->diffForHumans() }}
                                    @if($row['context']) · {{ $row['context'] }} @endif
                                </p>
                                @if($report->details)<p class="rounded-xl bg-[var(--bg-surface)] p-2.5 text-xs text-[var(--text-main)]">{{ $report->details }}</p>@endif
                                @if($report->resolution)<p class="text-xs italic text-[var(--text-dim)]">Outcome: {{ $report->resolution }}</p>@endif
                            </div>

                            <div class="flex shrink-0 flex-wrap items-center gap-2">
                                @if($row['url'])
                                    <a href="{{ $row['url'] }}" target="_blank" class="rounded-xl border border-[var(--border-medium)] px-3 py-2 text-xs font-bold">Inspect ↗</a>
                                @endif
                            </div>
                        </div>

                        @if($report->status === 'pending')
                            <div class="flex flex-wrap items-end gap-2 border-t border-[var(--border-subtle)] pt-3">
                                <label class="min-w-56 flex-1 text-[11px] font-bold text-[var(--text-dim)]">Moderation reason (required for actions, shown to the user)
                                    <input wire:model="reportReasons.{{ $report->id }}" maxlength="1000" placeholder="e.g. Harassment in the comment thread" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-3 py-2 text-sm font-normal text-[var(--text-main)] outline-none">
                                </label>
                                @error('reportReasons.'.$report->id) <p class="w-full text-xs text-rose-400">{{ $message }}</p> @enderror

                                <div class="flex flex-wrap items-center gap-2">
                                    <button wire:click="reviewContentReport({{ $report->id }}, 'warn')" class="rounded-xl border border-amber-500/40 px-3 py-2 text-xs font-bold text-amber-400 hover:bg-amber-500/10">Warn user</button>

                                    @foreach($row['actions'] as $actionKey => $actionLabel)
                                        <button wire:click="reviewContentReport({{ $report->id }}, '{{ $actionKey }}')"
                                                wire:confirm="{{ $actionLabel }}? This is recorded in the admin audit log."
                                                class="rounded-xl px-3 py-2 text-xs font-bold text-white {{ $actionKey === 'suspend' ? 'bg-rose-600' : 'bg-amber-600' }}">{{ $actionLabel }}</button>
                                    @endforeach

                                    <button wire:click="reviewContentReport({{ $report->id }}, 'dismiss')" class="rounded-xl border border-[var(--border-medium)] px-3 py-2 text-xs font-bold">Dismiss</button>
                                </div>
                            </div>
                        @endif
                    </article>
                @empty
                    <p class="rounded-2xl bg-[var(--bg-page)] p-5 text-sm text-[var(--text-dim)]">No reports match these filters.</p>
                @endforelse

                {{ $reportsPage->links() }}
            </section>
        @endif

        @if($activeSection === 'community-reports')
            <section class="space-y-4 rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6">
                <header>
                    <h2 class="text-xl font-black">Lounge community reports</h2>
                    <p class="text-sm text-[var(--text-muted)]">Reports raised inside lounge servers. Community moderators see their own queue; site admins can act on every server from here.</p>
                </header>

                <input wire:model.live.debounce.300ms="communityReportSearch" placeholder="Search server, reason, or reporter" class="w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-4 py-3 text-sm outline-none">

                @forelse($communityReports as $report)
                    <article wire:key="community-report-{{ $report->id }}" class="flex flex-col gap-3 rounded-2xl bg-[var(--bg-page)] p-4 lg:flex-row lg:items-center lg:justify-between">
                        <div class="space-y-1">
                            <p class="text-sm font-bold">{{ $report->community_name }} <span class="font-normal text-[var(--text-dim)]">/ {{ $report->target_type }} #{{ $report->target_id }}</span></p>
                            <p class="text-xs text-[var(--text-muted)]">Reason: <b>{{ str($report->reason)->replace('_', ' ')->title() }}</b> · by {{ '@'.($report->reporter_username) }} · {{ \Illuminate\Support\Carbon::parse($report->created_at)->diffForHumans() }} · {{ $report->status }}</p>
                            @if($report->details)<p class="text-sm">{{ $report->details }}</p>@endif
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-2">
                            <a href="{{ route('lounge.community', $report->community_slug) }}" target="_blank" class="rounded-xl border border-[var(--border-medium)] px-3 py-2 text-xs font-bold">Open server ↗</a>
                            <button wire:click="resolveCommunityReport({{ $report->id }}, 'hide')" wire:confirm="Hide the reported content in this server?" class="rounded-xl bg-amber-600 px-3 py-2 text-xs font-bold text-white">Hide content</button>
                            <button wire:click="resolveCommunityReport({{ $report->id }}, 'dismiss')" class="rounded-xl border border-[var(--border-medium)] px-3 py-2 text-xs font-bold">Dismiss</button>
                        </div>
                    </article>
                @empty
                    <p class="rounded-2xl bg-[var(--bg-page)] p-5 text-sm text-[var(--text-dim)]">No open community reports.</p>
                @endforelse

                {{ $communityReports->links() }}
            </section>
        @endif

        @if($activeSection === 'moderation')
            <section class="space-y-6 rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6">
                <header>
                    <h2 class="text-xl font-black">Moderation history &amp; restore</h2>
                    <p class="text-sm text-[var(--text-muted)]">Removals here are reversible. Restore puts content back and rebuilds its counters; permanent delete also removes the stored files.</p>
                </header>

                <input wire:model.live.debounce.300ms="trashSearch" placeholder="Search removed posts by title or reason" class="w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-4 py-3 text-sm outline-none">

                <div class="space-y-2">
                    <h3 class="text-sm font-black uppercase tracking-wide text-[var(--text-dim)]">Removed posts ({{ $trashedPosts->total() }})</h3>
                    @forelse($trashedPosts as $post)
                        <article wire:key="trashed-post-{{ $post->id }}" class="flex flex-col gap-3 rounded-2xl bg-[var(--bg-page)] p-4 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <p class="text-sm font-bold">#{{ $post->id }} · {{ $post->title ?: 'Untitled' }}</p>
                                <p class="text-xs text-[var(--text-muted)]">by {{ '@'.($post->user?->username ?? 'deleted user') }} · removed {{ $post->deleted_at?->diffForHumans() }}@if($post->removal_reason) · {{ $post->removal_reason }} @endif</p>
                            </div>
                            <div class="flex shrink-0 gap-2">
                                <button wire:click="restoreModerated('post', {{ $post->id }})" class="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-bold text-white">Restore</button>
                                <button wire:click="purgeModerated('post', {{ $post->id }})" wire:confirm="Permanently delete this post and its stored files? This cannot be undone." class="rounded-xl bg-rose-600 px-3 py-2 text-xs font-bold text-white">Delete forever</button>
                            </div>
                        </article>
                    @empty
                        <p class="rounded-2xl bg-[var(--bg-page)] p-4 text-sm text-[var(--text-dim)]">No removed posts.</p>
                    @endforelse
                    {{ $trashedPosts->links() }}
                </div>

                <div class="grid gap-4 lg:grid-cols-3">
                    <div class="space-y-2">
                        <h3 class="text-sm font-black uppercase tracking-wide text-[var(--text-dim)]">Removed comments</h3>
                        @forelse($trashedComments as $comment)
                            <div wire:key="trashed-comment-{{ $comment->id }}" class="space-y-2 rounded-2xl bg-[var(--bg-page)] p-3">
                                <p class="line-clamp-2 text-xs">{{ $comment->content }}</p>
                                <p class="text-[11px] text-[var(--text-dim)]">{{ '@'.($comment->user?->username ?? 'deleted') }} · {{ $comment->deleted_at?->diffForHumans() }}</p>
                                <div class="flex gap-2">
                                    <button wire:click="restoreModerated('comment', {{ $comment->id }})" class="rounded-lg bg-emerald-600 px-2 py-1 text-[11px] font-bold text-white">Restore</button>
                                    <button wire:click="purgeModerated('comment', {{ $comment->id }})" wire:confirm="Permanently delete this comment?" class="rounded-lg bg-rose-600 px-2 py-1 text-[11px] font-bold text-white">Delete forever</button>
                                </div>
                            </div>
                        @empty
                            <p class="rounded-2xl bg-[var(--bg-page)] p-3 text-xs text-[var(--text-dim)]">Nothing removed.</p>
                        @endforelse
                    </div>

                    <div class="space-y-2">
                        <h3 class="text-sm font-black uppercase tracking-wide text-[var(--text-dim)]">Removed pools</h3>
                        @forelse($trashedPools as $pool)
                            <div wire:key="trashed-pool-{{ $pool->id }}" class="space-y-2 rounded-2xl bg-[var(--bg-page)] p-3">
                                <p class="text-xs font-bold">{{ $pool->title }}</p>
                                <p class="text-[11px] text-[var(--text-dim)]">{{ '@'.($pool->user?->username ?? 'deleted') }} · {{ $pool->deleted_at?->diffForHumans() }}</p>
                                <div class="flex gap-2">
                                    <button wire:click="restoreModerated('pool', {{ $pool->id }})" class="rounded-lg bg-emerald-600 px-2 py-1 text-[11px] font-bold text-white">Restore</button>
                                    <button wire:click="purgeModerated('pool', {{ $pool->id }})" wire:confirm="Permanently delete this pool?" class="rounded-lg bg-rose-600 px-2 py-1 text-[11px] font-bold text-white">Delete forever</button>
                                </div>
                            </div>
                        @empty
                            <p class="rounded-2xl bg-[var(--bg-page)] p-3 text-xs text-[var(--text-dim)]">Nothing removed.</p>
                        @endforelse
                    </div>

                    <div class="space-y-2">
                        <h3 class="text-sm font-black uppercase tracking-wide text-[var(--text-dim)]">Removed collections</h3>
                        @forelse($trashedCollections as $collection)
                            <div wire:key="trashed-collection-{{ $collection->id }}" class="space-y-2 rounded-2xl bg-[var(--bg-page)] p-3">
                                <p class="text-xs font-bold">{{ $collection->title }}</p>
                                <p class="text-[11px] text-[var(--text-dim)]">{{ '@'.($collection->user?->username ?? 'deleted') }} · {{ $collection->deleted_at?->diffForHumans() }}</p>
                                <div class="flex gap-2">
                                    <button wire:click="restoreModerated('collection', {{ $collection->id }})" class="rounded-lg bg-emerald-600 px-2 py-1 text-[11px] font-bold text-white">Restore</button>
                                    <button wire:click="purgeModerated('collection', {{ $collection->id }})" wire:confirm="Permanently delete this collection?" class="rounded-lg bg-rose-600 px-2 py-1 text-[11px] font-bold text-white">Delete forever</button>
                                </div>
                            </div>
                        @empty
                            <p class="rounded-2xl bg-[var(--bg-page)] p-3 text-xs text-[var(--text-dim)]">Nothing removed.</p>
                        @endforelse
                    </div>
                </div>
            </section>
        @endif

        @if($activeSection === 'blocked')
            <section class="space-y-4 rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6">
                <header>
                    <h2 class="text-xl font-black">User blocks</h2>
                    <p class="text-sm text-[var(--text-muted)]">Every block relationship on the platform. Removing a block restores visibility between the two accounts.</p>
                </header>

                <input wire:model.live.debounce.300ms="blockSearch" placeholder="Search blocker or blocked username" class="w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-4 py-3 text-sm outline-none">

                <div class="space-y-2">
                    @forelse($userBlocks as $block)
                        <article wire:key="block-{{ $block->id }}" class="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-[var(--bg-page)] p-4">
                            <div class="text-sm">
                                <button type="button" wire:click="openUserDrawer({{ $block->blocker_id }})" class="font-bold underline decoration-dotted">{{ '@'.($block->blocker_username) }}</button>
                                <span class="mx-2 text-[var(--text-dim)]">blocked</span>
                                <button type="button" wire:click="openUserDrawer({{ $block->blocked_id }})" class="font-bold underline decoration-dotted">{{ '@'.($block->blocked_username) }}</button>
                                <span class="ml-2 text-xs text-[var(--text-dim)]">{{ \Illuminate\Support\Carbon::parse($block->created_at)->diffForHumans() }}</span>
                            </div>
                            <button wire:click="removeBlock({{ $block->id }})" wire:confirm="Remove this block?" class="rounded-xl border border-rose-500/40 px-3 py-2 text-xs font-bold text-rose-400 hover:bg-rose-500/10">Remove block</button>
                        </article>
                    @empty
                        <p class="rounded-2xl bg-[var(--bg-page)] p-5 text-sm text-[var(--text-dim)]">No blocks recorded.</p>
                    @endforelse
                </div>

                {{ $userBlocks->links() }}
            </section>
        @endif

        @if($activeSection === 'collections')
            <section class="space-y-4 rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6">
                <header>
                    <h2 class="text-xl font-black">Collections</h2>
                    <p class="text-sm text-[var(--text-muted)]">Every user collection, including private ones. Removal is reversible from Moderation history.</p>
                </header>

                <input wire:model.live.debounce.300ms="collectionSearch" placeholder="Search collection title" class="w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-4 py-3 text-sm outline-none">

                <div class="grid gap-3 md:grid-cols-2">
                    @forelse($collections as $collection)
                        <article wire:key="collection-{{ $collection->id }}" class="flex min-w-0 items-start justify-between gap-3 rounded-2xl bg-[var(--bg-page)] p-4">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-bold">{{ $collection->title }}</p>
                                <p class="text-xs text-[var(--text-muted)]">
                                    by <button type="button" wire:click="openUserDrawer({{ $collection->user_id }})" class="underline decoration-dotted">{{ '@'.($collection->user?->username ?? 'deleted') }}</button>
                                    · {{ $collection->items_count }} item(s)
                                    @if($collection->is_private) · <span class="font-bold text-amber-400">private</span> @endif
                                </p>
                            </div>
                            <div class="flex shrink-0 gap-2">
                                <a href="{{ route('collection.detail', $collection->id) }}" target="_blank" class="rounded-xl border border-[var(--border-medium)] px-3 py-2 text-xs font-bold">Open ↗</a>
                                <button wire:click="removeCollection({{ $collection->id }})" wire:confirm="Remove this collection? It can be restored from Moderation history." class="rounded-xl bg-rose-600 px-3 py-2 text-xs font-bold text-white">Remove</button>
                            </div>
                        </article>
                    @empty
                        <p class="rounded-2xl bg-[var(--bg-page)] p-5 text-sm text-[var(--text-dim)]">No collections found.</p>
                    @endforelse
                </div>

                {{ $collections->links() }}
            </section>
        @endif

        @if($activeSection === 'communities')
            <section class="space-y-4 rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6">
                <header>
                    <h2 class="text-xl font-black">Communities</h2>
                    <p class="text-sm text-[var(--text-muted)]">Edit server rules, archive a server (hides it from the lounge and notifies the owner), or delete it outright.</p>
                </header>

                <input wire:model.live.debounce.300ms="communitySearch" placeholder="Search community name or slug" class="w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-4 py-3 text-sm outline-none">

                @forelse($communities as $community)
                    <article wire:key="community-{{ $community->id }}" class="space-y-3 rounded-2xl bg-[var(--bg-page)] p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-bold">
                                    {{ $community->name }}
                                    @if($community->isArchived())
                                        <span class="ml-1 rounded-lg bg-rose-500/20 px-2 py-0.5 text-[10px] font-black uppercase text-rose-400">archived</span>
                                    @endif
                                </p>
                                <p class="text-xs text-[var(--text-muted)]">
                                    {{ $community->slug }} · owner <button type="button" wire:click="openUserDrawer({{ $community->owner_id }})" class="underline decoration-dotted">{{ '@'.($community->owner?->username ?? 'deleted') }}</button>
                                    · {{ $community->member_count }} members · {{ $community->visibility }}
                                </p>
                                @if($community->archive_reason)<p class="text-xs text-rose-300">Archive reason: {{ $community->archive_reason }}</p>@endif
                            </div>
                            <div class="flex shrink-0 flex-wrap gap-2">
                                <a href="{{ route('lounge.community', $community->slug) }}" target="_blank" class="rounded-xl border border-[var(--border-medium)] px-3 py-2 text-xs font-bold">Open ↗</a>
                                <button wire:click="toggleCommunityArchive({{ $community->id }})" class="rounded-xl {{ $community->isArchived() ? 'bg-emerald-600' : 'bg-amber-600' }} px-3 py-2 text-xs font-bold text-white">
                                    {{ $community->isArchived() ? 'Unarchive' : 'Archive' }}
                                </button>
                                <button wire:click="deleteCommunity({{ $community->id }})" wire:confirm="Delete this community permanently? All channels and messages will be removed." class="rounded-xl bg-rose-600 px-3 py-2 text-xs font-bold text-white">Delete</button>
                            </div>
                        </div>

                        <label class="block text-[11px] font-bold text-[var(--text-dim)]">Server rules
                            <textarea wire:model="communityRulesDrafts.{{ $community->id }}" rows="3" placeholder="No rules recorded yet." class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-3 text-sm font-normal text-[var(--text-main)] outline-none">{{ $community->rules }}</textarea>
                        </label>
                        <button wire:click="saveCommunityDetails({{ $community->id }})" class="rounded-xl accent-bg px-4 py-2 text-xs font-bold text-white">Save rules</button>
                    </article>
                @empty
                    <p class="rounded-2xl bg-[var(--bg-page)] p-5 text-sm text-[var(--text-dim)]">No communities found.</p>
                @endforelse

                {{ $communities->links() }}
            </section>
        @endif

        @if($activeSection === 'media')
            <section class="space-y-6 rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6">
                <header>
                    <h2 class="text-xl font-black">Media &amp; storage</h2>
                    <p class="text-sm text-[var(--text-muted)]">Upload footprint, orphaned files left behind by removed posts, and thumbnail backfill for uploads that never got one.</p>
                </header>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="rounded-2xl bg-[var(--bg-page)] p-4">
                        <div class="text-[11px] font-black uppercase tracking-wide text-[var(--text-dim)]">Stored media</div>
                        <div class="text-2xl font-black">{{ \App\Support\MediaMaintenance::formatBytes($storageUsage['bytes']) }}</div>
                        <div class="text-xs text-[var(--text-muted)]">{{ $storageUsage['files'] }} file(s)</div>
                    </div>
                    <div class="rounded-2xl bg-[var(--bg-page)] p-4">
                        <div class="text-[11px] font-black uppercase tracking-wide text-[var(--text-dim)]">Orphaned files</div>
                        <div class="text-2xl font-black {{ count($orphanedFiles) > 0 ? 'text-amber-400' : '' }}">{{ count($orphanedFiles) }}</div>
                        <div class="text-xs text-[var(--text-muted)]">Not referenced by any post</div>
                    </div>
                    <div class="rounded-2xl bg-[var(--bg-page)] p-4">
                        <div class="text-[11px] font-black uppercase tracking-wide text-[var(--text-dim)]">Missing thumbnails</div>
                        <div class="text-2xl font-black">{{ $missingThumbnails }}</div>
                        <div class="text-xs text-[var(--text-muted)]">Uploads without a generated thumbnail</div>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button wire:click="pruneOrphanedMedia" wire:confirm="Delete every orphaned upload file from the public disk?" class="rounded-xl bg-rose-600 px-4 py-2 text-xs font-bold text-white">Delete orphaned files</button>
                    <button wire:click="regenerateMissingThumbnails" class="rounded-xl accent-bg px-4 py-2 text-xs font-bold text-white">Re-run missing thumbnails</button>
                </div>

                @if(count($orphanedFiles) > 0)
                    <div class="space-y-1 rounded-2xl bg-[var(--bg-page)] p-4">
                        <h3 class="text-xs font-black uppercase tracking-wide text-[var(--text-dim)]">Orphaned paths</h3>
                        @foreach(array_slice($orphanedFiles, 0, 25) as $file)
                            <p class="break-all font-mono text-[11px] text-[var(--text-muted)]">{{ $file }}</p>
                        @endforeach
                        @if(count($orphanedFiles) > 25)
                            <p class="text-[11px] text-[var(--text-dim)]">…and {{ count($orphanedFiles) - 25 }} more.</p>
                        @endif
                    </div>
                @endif

                <div class="space-y-2">
                    <h3 class="text-xs font-black uppercase tracking-wide text-[var(--text-dim)]">Uploads missing thumbnails</h3>
                    @php($missingMedia = \App\Models\PostMedia::whereNull('thumbnail_url')->latest('id')->limit(20)->get())
                    @forelse($missingMedia as $media)
                        <div wire:key="media-{{ $media->id }}" class="flex flex-wrap items-center justify-between gap-2 rounded-2xl bg-[var(--bg-page)] p-3">
                            <div class="min-w-0">
                                <p class="truncate font-mono text-[11px] text-[var(--text-muted)]">{{ $media->url }}</p>
                                <p class="text-[11px] text-[var(--text-dim)]">Post #{{ $media->post_id }}</p>
                            </div>
                            <button wire:click="regenerateThumbnail({{ $media->id }})" class="rounded-xl border border-[var(--border-medium)] px-3 py-1.5 text-[11px] font-bold">Regenerate</button>
                        </div>
                    @empty
                        <p class="rounded-2xl bg-[var(--bg-page)] p-4 text-sm text-[var(--text-dim)]">Every upload has a thumbnail.</p>
                    @endforelse
                </div>
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

                {{ $pendingAppeals->links() }}
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

    @if($warningUserId)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" wire:click.self="$set('warningUserId', null)">
            <form wire:submit="warnUser({{ $warningUserId }})" class="w-full max-w-lg space-y-4 rounded-3xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 shadow-2xl">
                <div><h2 class="text-xl font-black">Send a moderation warning</h2><p class="text-sm text-[var(--text-muted)]">The user receives an in-app notification explaining the problem. Nothing is hidden or deleted.</p></div>
                <label class="block text-sm font-bold">Warning reason<textarea wire:model="warningReason" required minlength="5" maxlength="1000" rows="3" class="mt-2 w-full rounded-xl border bg-[var(--bg-page)] p-3" placeholder="e.g. Please keep critique constructive and avoid personal attacks."></textarea></label>
                @error('warningReason') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                <div class="flex justify-end gap-2"><button type="button" wire:click="$set('warningUserId', null)" class="rounded-xl border px-4 py-2 text-sm">Cancel</button><button class="rounded-xl bg-amber-600 px-4 py-2 text-sm font-bold text-white">Send warning</button></div>
            </form>
        </div>
    @endif

    <!-- User Detail Drawer -->
    @if($drawerUser)
        <div class="fixed inset-0 z-50 flex justify-end bg-black/70" wire:click.self="closeUserDrawer">
            <aside class="h-full w-full max-w-3xl overflow-y-auto border-l border-[var(--border-subtle)] bg-[var(--bg-surface)] p-6 space-y-6 shadow-2xl">
                <header class="flex items-start justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <img src="{{ $drawerUser->avatar_url }}" class="h-12 w-12 rounded-full object-cover">
                        <div>
                            <h2 class="text-lg font-black">{{ $drawerUser->name }}</h2>
                            <p class="text-xs text-[var(--text-muted)]">
                                {{ '@'.($drawerUser->username) }} · joined {{ $drawerUser->created_at?->format('M j, Y') }}
                                · {{ $drawerUser->isAdmin() ? 'admin' : 'member' }}
                                @if($drawerUser->is_banned) · <b class="text-rose-400">suspended</b> @endif
                            </p>
                            <p class="text-xs text-[var(--text-dim)]">
                                Last active {{ $drawerUser->last_active_at?->diffForHumans() ?? 'never' }}
                                · email {{ $drawerUser->email_verified_at ? 'verified' : 'unverified' }}
                            </p>
                        </div>
                    </div>
                    <button wire:click="closeUserDrawer" class="rounded-xl border border-[var(--border-medium)] px-3 py-2 text-xs font-bold">Close ✕</button>
                </header>

                @if($drawerUser->is_banned && $drawerUser->suspension_reason)
                    <p class="rounded-2xl border border-rose-500/30 bg-rose-500/10 p-3 text-xs text-rose-300">Suspension reason: {{ $drawerUser->suspension_reason }}</p>
                @endif

                <section class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-2xl bg-[var(--bg-page)] p-3"><div class="text-[10px] font-black uppercase text-[var(--text-dim)]">Posts</div><div class="text-xl font-black">{{ $drawerData['postCount'] }}</div><div class="text-[10px] text-[var(--text-muted)]">{{ $drawerData['hiddenPostCount'] }} removed</div></div>
                    <div class="rounded-2xl bg-[var(--bg-page)] p-3"><div class="text-[10px] font-black uppercase text-[var(--text-dim)]">DMs sent</div><div class="text-xl font-black">{{ $drawerData['messageCount'] }}</div><div class="text-[10px] text-[var(--text-muted)]">in {{ $drawerData['conversationCount'] }} conversation(s)</div></div>
                    <div class="rounded-2xl bg-[var(--bg-page)] p-3"><div class="text-[10px] font-black uppercase text-[var(--text-dim)]">Reports</div><div class="text-xl font-black">{{ $drawerData['reportsAgainst']->count() }}</div><div class="text-[10px] text-[var(--text-muted)]">against · {{ $drawerData['reportsFiled'] }} filed</div></div>
                </section>

                <section class="space-y-2">
                    <h3 class="text-xs font-black uppercase tracking-wide text-[var(--text-dim)]">Account actions</h3>
                    <div class="flex flex-wrap gap-2">
                        @if($drawerUser->id !== auth()->id())
                            <button wire:click="prepareWarning({{ $drawerUser->id }})" class="rounded-xl border border-amber-500/40 px-3 py-2 text-xs font-bold text-amber-400 hover:bg-amber-500/10">Send warning</button>
                            @unless($drawerUser->isAdmin())
                                <button wire:click="prepareSuspension({{ $drawerUser->id }})" class="rounded-xl border border-rose-500/40 px-3 py-2 text-xs font-bold text-rose-400 hover:bg-rose-500/10">Suspend…</button>
                                @if($drawerUser->is_banned)
                                    <button wire:click="toggleUserBan({{ $drawerUser->id }})" class="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-bold text-white">Lift suspension</button>
                                @endif
                            @endunless
                        @endif
                        <button wire:click="resetUserPassword({{ $drawerUser->id }})" wire:confirm="Issue a temporary password and sign the user out everywhere?" class="rounded-xl border border-[var(--border-medium)] px-3 py-2 text-xs font-bold">Reset password</button>
                        <button wire:click="forceUserLogout({{ $drawerUser->id }})" wire:confirm="Terminate every active session for this user?" class="rounded-xl border border-[var(--border-medium)] px-3 py-2 text-xs font-bold">Force logout</button>
                        @if($drawerUser->email_verified_at)
                            <button wire:click="requireEmailReverification({{ $drawerUser->id }})" class="rounded-xl border border-[var(--border-medium)] px-3 py-2 text-xs font-bold">Require re-verification</button>
                        @else
                            <button wire:click="markEmailVerified({{ $drawerUser->id }})" class="rounded-xl border border-emerald-500/40 px-3 py-2 text-xs font-bold text-emerald-400">Mark email verified</button>
                        @endif
                        <a href="{{ route('profile', $drawerUser->username) }}" target="_blank" class="rounded-xl border border-[var(--border-medium)] px-3 py-2 text-xs font-bold">View profile ↗</a>
                    </div>
                </section>

                <section class="grid gap-4 lg:grid-cols-2">
                    <div class="space-y-2">
                        <h3 class="text-xs font-black uppercase tracking-wide text-[var(--text-dim)]">Sessions &amp; IPs ({{ $drawerData['sessions']->count() }})</h3>
                        @forelse($drawerData['sessions'] as $session)
                            <div class="rounded-2xl bg-[var(--bg-page)] p-3 text-[11px]">
                                <p class="font-mono text-[var(--text-main)]">{{ $session->ip_address }}</p>
                                <p class="truncate text-[var(--text-muted)]">{{ \Illuminate\Support\Str::limit($session->user_agent, 90) }}</p>
                                <p class="text-[var(--text-dim)]">active {{ \Illuminate\Support\Carbon::createFromTimestamp($session->last_activity)->diffForHumans() }}</p>
                            </div>
                        @empty
                            <p class="rounded-2xl bg-[var(--bg-page)] p-3 text-[11px] text-[var(--text-dim)]">No active sessions.</p>
                        @endforelse
                    </div>

                    <div class="space-y-2">
                        <h3 class="text-xs font-black uppercase tracking-wide text-[var(--text-dim)]">Reports against this account</h3>
                        @forelse($drawerData['reportsAgainst'] as $report)
                            <div class="rounded-2xl bg-[var(--bg-page)] p-3 text-[11px]">
                                <p><b>{{ $report->target_type }}</b> · {{ str($report->reason)->replace('_', ' ')->title() }} · {{ $report->status }}</p>
                                @if($report->resolution)<p class="text-[var(--text-dim)]">{{ $report->resolution }}</p>@endif
                            </div>
                        @empty
                            <p class="rounded-2xl bg-[var(--bg-page)] p-3 text-[11px] text-[var(--text-dim)]">No reports against this account.</p>
                        @endforelse
                    </div>
                </section>

                <section class="space-y-2">
                    <h3 class="text-xs font-black uppercase tracking-wide text-[var(--text-dim)]">Moderation history</h3>
                    @forelse($drawerData['moderationHistory'] as $entry)
                        <div class="rounded-2xl bg-[var(--bg-page)] p-3 text-[11px]">
                            <p><b>{{ str_replace('.', ' · ', $entry->action) }}</b> <span class="text-[var(--text-dim)]">by {{ $entry->actor_username ? '@'.$entry->actor_username : 'system' }} · {{ \Illuminate\Support\Carbon::parse($entry->created_at)->format('M j, Y g:i A') }}</span></p>
                            @if($entry->reason)<p class="text-[var(--text-muted)]">{{ $entry->reason }}</p>@endif
                        </div>
                    @empty
                        <p class="rounded-2xl bg-[var(--bg-page)] p-3 text-[11px] text-[var(--text-dim)]">No moderation actions recorded for this account.</p>
                    @endforelse
                </section>

                <section class="space-y-2">
                    <h3 class="text-xs font-black uppercase tracking-wide text-[var(--text-dim)]">Admin notes</h3>
                    <div class="flex items-start gap-2">
                        <textarea wire:model="userNoteText" rows="2" placeholder="Internal note about this account (never shown to the user)" class="flex-1 rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] p-3 text-sm"></textarea>
                        <button wire:click="addUserNote" class="rounded-xl accent-bg px-4 py-2 text-xs font-bold text-white">Add note</button>
                    </div>
                    @error('userNoteText') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                    @forelse($drawerData['notes'] as $note)
                        <div class="flex items-start justify-between gap-3 rounded-2xl bg-[var(--bg-page)] p-3 text-[11px]">
                            <div>
                                <p>{{ $note->note }}</p>
                                <p class="text-[var(--text-dim)]">{{ $note->author?->username ? '@'.$note->author->username : 'deleted admin' }} · {{ $note->created_at?->diffForHumans() }}</p>
                            </div>
                            <button wire:click="deleteUserNote({{ $note->id }})" wire:confirm="Delete this note?" class="text-rose-400">Remove</button>
                        </div>
                    @empty
                        <p class="rounded-2xl bg-[var(--bg-page)] p-3 text-[11px] text-[var(--text-dim)]">No notes yet.</p>
                    @endforelse
                </section>

                <section class="grid gap-4 lg:grid-cols-2">
                    <div class="space-y-2">
                        <h3 class="text-xs font-black uppercase tracking-wide text-[var(--text-dim)]">Recent posts</h3>
                        @forelse($drawerUser->posts as $post)
                            <div class="flex items-center justify-between gap-2 rounded-2xl bg-[var(--bg-page)] p-3 text-[11px]">
                                <span class="truncate">#{{ $post->id }} {{ $post->title ?: 'Untitled' }}</span>
                                <a href="{{ route('post.detail', ['id' => $post->id, 'from_admin' => 1]) }}" target="_blank" class="shrink-0 accent-text font-bold">Open ↗</a>
                            </div>
                        @empty
                            <p class="rounded-2xl bg-[var(--bg-page)] p-3 text-[11px] text-[var(--text-dim)]">No posts.</p>
                        @endforelse
                    </div>

                    <div class="space-y-2">
                        <h3 class="text-xs font-black uppercase tracking-wide text-[var(--text-dim)]">Recent comments</h3>
                        @forelse($drawerData['comments'] as $comment)
                            <div class="rounded-2xl bg-[var(--bg-page)] p-3 text-[11px]">
                                <p class="line-clamp-2">{{ $comment->content }}</p>
                                <p class="text-[var(--text-dim)]">{{ $comment->trashed() ? 'removed' : 'visible' }} · {{ $comment->created_at?->diffForHumans() }}</p>
                            </div>
                        @empty
                            <p class="rounded-2xl bg-[var(--bg-page)] p-3 text-[11px] text-[var(--text-dim)]">No comments.</p>
                        @endforelse
                    </div>
                </section>

                <section class="text-[11px] text-[var(--text-dim)]">
                    Blocks: this user blocked {{ $drawerData['blocksMade'] }} account(s) and is blocked by {{ $drawerData['blockedByCount'] }}.
                </section>
            </aside>
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
