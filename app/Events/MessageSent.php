<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Message $message;

    public array $messageData;

    public function __construct(Message $message)
    {
        $this->message = $message;
        $message->load(['sender', 'replyTo.sender', 'sharedPost.primaryMedia']);

        $this->messageData = [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'sender_id' => $message->sender_id,
            'sender' => [
                'id' => $message->sender->id,
                'name' => $message->sender->name,
                'username' => $message->sender->username,
                'avatar_url' => $message->sender->avatar_url,
            ],
            'text' => $message->text,
            'attachment_url' => $message->attachment_url,
            'shared_post_id' => $message->shared_post_id,
            'reply_to_message_id' => $message->reply_to_message_id,
            'replyTo' => $message->replyTo ? [
                'id' => $message->replyTo->id,
                'text' => $message->replyTo->text,
                'sender_name' => $message->replyTo->sender?->name ?? 'User',
            ] : null,
            'sharedPost' => $message->sharedPost ? [
                'id' => $message->sharedPost->id,
                'title' => $message->sharedPost->title,
                'primary_media_url' => $message->sharedPost->primaryMedia->url ?? null,
            ] : null,
            'reactions' => $message->reactions ?? [],
            'created_at' => $message->created_at->toISOString(),
            'formatted_time' => $message->created_at->format('g:i A'),
        ];
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.'.$this->message->conversation_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }
}
