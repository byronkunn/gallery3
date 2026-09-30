<?php

namespace App\Support;

use App\Events\NotificationSent;
use App\Models\Collection;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Pool;
use App\Models\PoolChapter;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates the application's in-app notifications.
 *
 * Every notification written here is also broadcast on the recipient's private
 * channel so the realtime toast in the layout can pick it up.
 */
class Notifier
{
    /**
     * Create a notification for a recipient and broadcast it.
     */
    public static function send(User $recipient, User $actor, string $type, string $message, ?string $notifiableType = null, ?int $notifiableId = null, bool $once = false): ?Notification
    {
        if ($recipient->id === $actor->id) {
            return null;
        }

        if ($once) {
            $alreadyNotified = Notification::query()
                ->where('user_id', $recipient->id)
                ->where('actor_id', $actor->id)
                ->where('type', $type)
                ->where('notifiable_type', $notifiableType)
                ->where('notifiable_id', $notifiableId)
                ->whereNull('read_at')
                ->exists();

            if ($alreadyNotified) {
                return null;
            }
        }

        $notification = Notification::create([
            'user_id' => $recipient->id,
            'actor_id' => $actor->id,
            'type' => $type,
            'notifiable_type' => $notifiableType,
            'notifiable_id' => $notifiableId,
            'message' => $message,
        ]);

        // The row above is the source of truth; the realtime nudge is
        // best-effort so a missing websocket server cannot break a like.
        try {
            NotificationSent::dispatch($notification);
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $notification;
    }

    public static function like(Post $post, User $actor): ?Notification
    {
        $recipient = $post->user;

        return $recipient
            ? self::send($recipient, $actor, 'like', 'liked your post "'.self::excerpt($post->title, 60).'"', Post::class, $post->id, true)
            : null;
    }

    public static function follow(User $recipient, User $actor): ?Notification
    {
        return self::send($recipient, $actor, 'follow', 'started following you', User::class, $recipient->id, true);
    }

    public static function comment(Comment $comment, User $actor): ?Notification
    {
        $post = $comment->post;

        return $post?->user
            ? self::send($post->user, $actor, 'comment', 'commented on your post "'.self::excerpt($post->title, 60).'": '.self::excerpt($comment->content, 80), Post::class, $post->id)
            : null;
    }

    public static function directMessage(Message $message, User $actor): void
    {
        $conversation = $message->conversation;
        if (! $conversation) {
            return;
        }

        $recipient = $conversation->getOtherUser($actor);

        self::send(
            $recipient,
            $actor,
            'message',
            'sent you a direct message: '.self::excerpt($message->text ?? 'Attached artwork', 80),
            Conversation::class,
            $conversation->id,
        );
    }

    public static function messageHidden(Message $message, string $reason): void
    {
        $sender = $message->sender;
        if (! $sender) {
            return;
        }

        self::send(
            $sender,
            self::systemActor() ?? $sender,
            'warning',
            'One of your direct messages was hidden by a moderator. Reason: '.$reason,
            Message::class,
            $message->id,
        );
    }

    public static function poolChapter(PoolChapter $chapter, User $actor): void
    {
        $pool = $chapter->pool;
        if (! $pool) {
            return;
        }

        $pool->followers()
            ->where('users.id', '!=', $actor->id)
            ->chunkById(100, function ($followers) use ($pool, $chapter, $actor): void {
                foreach ($followers as $follower) {
                    self::send(
                        $follower,
                        $actor,
                        'pool_chapter',
                        'added "'.$chapter->title.'" to '.$pool->title,
                        Pool::class,
                        $pool->id,
                    );
                }
            });
    }

    public static function collectionFollow(Collection $collection, User $actor): ?Notification
    {
        $recipient = $collection->user;

        return $recipient
            ? self::send($recipient, $actor, 'collection_update', 'started following your collection "'.self::excerpt($collection->title, 60).'"', Collection::class, $collection->id, true)
            : null;
    }

    public static function collectionItemAdded(Collection $collection, Post $post, User $actor): void
    {
        $collection->followers()
            ->where('users.id', '!=', $actor->id)
            ->chunkById(100, function ($followers) use ($collection, $post, $actor): void {
                foreach ($followers as $follower) {
                    self::send(
                        $follower,
                        $actor,
                        'collection_update',
                        'added "'.self::excerpt($post->title, 60).'" to '.$collection->title,
                        Collection::class,
                        $collection->id,
                    );
                }
            });
    }

    /**
     * Tell a user why their content or account was moderated.
     */
    public static function warning(User $recipient, string $reason, ?User $actor = null): ?Notification
    {
        $actor ??= self::systemActor();
        if (! $actor) {
            return null;
        }

        return self::send(
            $recipient,
            $actor,
            'warning',
            'Moderation notice: '.$reason,
        );
    }

    public static function suspension(User $recipient, string $reason, ?User $actor = null): ?Notification
    {
        $actor ??= self::systemActor();
        if (! $actor) {
            return null;
        }

        return self::send(
            $recipient,
            $actor,
            'warning',
            'Your account was suspended. Reason: '.$reason,
        );
    }

    public static function unsuspension(User $recipient, string $reason, ?User $actor = null): ?Notification
    {
        $actor ??= self::systemActor();
        if (! $actor) {
            return null;
        }

        return self::send(
            $recipient,
            $actor,
            'announcement',
            'Your suspension was lifted. '.$reason,
        );
    }

    /**
     * Fan a site-wide announcement out to every account.
     *
     * Rows are inserted in bulk: a broadcast per recipient would mean one
     * websocket round-trip per user, which is not worth it for a banner.
     */
    public static function announcement(string $message, ?User $actor = null): int
    {
        $actor ??= self::systemActor();
        if (! $actor) {
            return 0;
        }

        $now = now();
        $sent = 0;

        User::query()->select('id')->orderBy('id')->chunkById(500, function ($users) use ($message, $actor, $now, &$sent): void {
            $rows = $users->map(fn (User $user): array => [
                'user_id' => $user->id,
                'actor_id' => $actor->id,
                'type' => 'announcement',
                'notifiable_type' => null,
                'notifiable_id' => null,
                'message' => $message,
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            DB::table('notifications')->insert($rows);
            $sent += count($rows);
        });

        return $sent;
    }

    /**
     * Any admin will do as the actor for system generated notifications.
     */
    private static function systemActor(): ?User
    {
        return User::where('is_admin', true)->orderBy('id')->first() ?? User::orderBy('id')->first();
    }

    private static function excerpt(?string $value, int $length): string
    {
        return (string) str((string) $value)->squish()->limit($length, '…');
    }
}
