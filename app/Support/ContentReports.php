<?php

namespace App\Support;

use App\Models\Comment;
use App\Models\Community;
use App\Models\Message;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Filing and reading the site-wide content report queue.
 *
 * Reports may target a post, a comment, a direct message, a user account, or a
 * whole community. Target rows are resolved in bulk so the queue never runs a
 * query per row.
 */
class ContentReports
{
    /**
     * Every reportable target type.
     *
     * @var array<int, string>
     */
    public const TARGET_TYPES = ['post', 'comment', 'message', 'user', 'community'];

    /**
     * Moderation actions offered for each target type.
     *
     * @var array<string, array<string, string>>
     */
    public const ACTIONS = [
        'post' => ['remove' => 'Remove post', 'lock' => 'Lock comments', 'suspend' => 'Suspend author'],
        'comment' => ['hide' => 'Hide comment', 'remove' => 'Delete comment', 'suspend' => 'Suspend author'],
        'message' => ['hide' => 'Hide message', 'suspend' => 'Suspend sender'],
        'user' => ['suspend' => 'Suspend user'],
        'community' => ['archive' => 'Archive community', 'suspend' => 'Suspend owner'],
    ];

    /**
     * Human labels for each target type.
     *
     * @var array<string, string>
     */
    public const LABELS = [
        'post' => 'Post',
        'comment' => 'Comment',
        'message' => 'Direct message',
        'user' => 'User account',
        'community' => 'Community',
    ];

    /**
     * Actions that need a written reason before they can be applied.
     *
     * @var array<int, string>
     */
    public const REASON_REQUIRED = ['remove', 'hide', 'suspend', 'archive', 'warn', 'lock'];

    /**
     * File a report. Returns false when an identical pending report already exists.
     *
     * @throws HttpException
     */
    public static function file(User $reporter, string $targetType, int $targetId, string $reason, ?string $details = null): bool
    {
        abort_unless(in_array($targetType, self::TARGET_TYPES, true), 422, 'Unsupported report target.');

        $authorId = self::authorId($targetType, $targetId);
        abort_if($authorId === $reporter->id, 422, 'You cannot report your own content.');

        $duplicate = DB::table('content_reports')
            ->where('reporter_id', $reporter->id)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->where('status', 'pending')
            ->exists();

        if ($duplicate) {
            return false;
        }

        DB::table('content_reports')->insert([
            'reporter_id' => $reporter->id,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'reason' => $reason,
            'details' => $details ?: null,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    /**
     * Resolve the id of the user who owns the reported target.
     *
     * @throws HttpException
     */
    public static function authorId(string $targetType, int $targetId): ?int
    {
        return match ($targetType) {
            'post' => Post::withTrashed()->whereKey($targetId)->value('user_id') ?? abort(404),
            'comment' => Comment::withTrashed()->whereKey($targetId)->value('user_id') ?? abort(404),
            'message' => Message::whereKey($targetId)->value('sender_id') ?? abort(404),
            'user' => User::whereKey($targetId)->value('id') ?? abort(404),
            'community' => Community::whereKey($targetId)->value('owner_id') ?? abort(404),
            default => abort(422, 'Unsupported report target.'),
        };
    }

    /**
     * @return array<int, string>
     */
    public static function actionsFor(string $targetType): array
    {
        return self::ACTIONS[$targetType] ?? [];
    }

    public static function labelFor(string $targetType): string
    {
        return self::LABELS[$targetType] ?? ucfirst($targetType);
    }

    public static function requiresReason(string $action): bool
    {
        return in_array($action, self::REASON_REQUIRED, true);
    }

    /**
     * Enrich report rows with the data the moderation queue needs.
     *
     * @param  Collection<int, object>  $reports
     * @return array<int, array<string, mixed>>
     */
    public static function hydrate(Collection $reports): array
    {
        if ($reports->isEmpty()) {
            return [];
        }

        $idsFor = fn (string $type): Collection => $reports->where('target_type', $type)->pluck('target_id')->unique()->values();

        $posts = Post::withTrashed()->whereIn('id', $idsFor('post'))->get()->keyBy('id');
        $comments = Comment::withTrashed()->whereIn('id', $idsFor('comment'))->get()->keyBy('id');
        $messages = Message::whereIn('id', $idsFor('message'))->with('conversation')->get()->keyBy('id');
        $reportedUsers = User::whereIn('id', $idsFor('user'))->get()->keyBy('id');
        $communities = Community::whereIn('id', $idsFor('community'))->get()->keyBy('id');

        $commentPosts = Post::withTrashed()->whereIn('id', $comments->pluck('post_id'))->get()->keyBy('id');

        $authorIds = collect()
            ->merge($posts->pluck('user_id'))
            ->merge($comments->pluck('user_id'))
            ->merge($messages->pluck('sender_id'))
            ->merge($reportedUsers->pluck('id'))
            ->merge($communities->pluck('owner_id'))
            ->filter()
            ->unique()
            ->values();

        $authors = User::whereIn('id', $authorIds)->get()->keyBy('id');
        $reporters = User::whereIn('id', $reports->pluck('reporter_id')->unique())->get()->keyBy('id');

        return $reports->map(function (object $report) use ($posts, $comments, $messages, $reportedUsers, $communities, $commentPosts, $authors, $reporters): array {
            $row = [
                'report' => $report,
                'reporter_username' => $reporters->get($report->reporter_id)?->username,
                'label' => self::labelFor($report->target_type),
                'actions' => self::actionsFor($report->target_type),
                'title' => null,
                'excerpt' => null,
                'context' => null,
                'author_id' => null,
                'author_username' => null,
                'url' => null,
                'missing' => true,
            ];

            switch ($report->target_type) {
                case 'post':
                    $post = $posts->get($report->target_id);
                    if ($post) {
                        $row['title'] = $post->title ?: 'Untitled post #'.$post->id;
                        $row['excerpt'] = $post->description;
                        $row['author_id'] = $post->user_id;
                        $row['url'] = route('post.detail', ['id' => $post->id, 'from_admin' => 1]);
                        $row['missing'] = $post->trashed();
                    }
                    break;

                case 'comment':
                    $comment = $comments->get($report->target_id);
                    if ($comment) {
                        $parent = $commentPosts->get($comment->post_id);
                        $row['title'] = 'Comment on "'.($parent?->title ?: 'post #'.$comment->post_id).'"';
                        $row['excerpt'] = $comment->content;
                        $row['author_id'] = $comment->user_id;
                        $row['url'] = $parent ? route('post.detail', ['id' => $parent->id, 'from_admin' => 1]) : null;
                        $row['missing'] = $comment->trashed();
                    }
                    break;

                case 'message':
                    $message = $messages->get($report->target_id);
                    if ($message) {
                        $conversation = $message->conversation;
                        $row['title'] = 'Direct message in conversation #'.$message->conversation_id;
                        $row['excerpt'] = $message->text;
                        $row['author_id'] = $message->sender_id;
                        $row['context'] = $conversation
                            ? '@'.$conversation->userOne?->username.' ↔ @'.$conversation->userTwo?->username
                            : null;
                        $row['url'] = route('admin', ['section' => 'messages', 'conversation' => $message->conversation_id]);
                        $row['missing'] = (bool) $message->is_hidden;
                    }
                    break;

                case 'user':
                    $reported = $reportedUsers->get($report->target_id);
                    if ($reported) {
                        $row['title'] = $reported->name.' (@'.$reported->username.')';
                        $row['excerpt'] = $reported->bio;
                        $row['author_id'] = $reported->id;
                        $row['url'] = route('profile', $reported->username);
                        $row['missing'] = false;
                    }
                    break;

                case 'community':
                    $community = $communities->get($report->target_id);
                    if ($community) {
                        $row['title'] = $community->name;
                        $row['excerpt'] = $community->description;
                        $row['author_id'] = $community->owner_id;
                        $row['context'] = '@'.$community->slug;
                        $row['url'] = route('lounge.community', $community->slug);
                        $row['missing'] = $community->isArchived();
                    }
                    break;
            }

            $author = $row['author_id'] ? $authors->get($row['author_id']) : null;
            $row['author_username'] = $author?->username;

            return $row;
        })->all();
    }
}
