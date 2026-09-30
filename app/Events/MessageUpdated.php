<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $messageId;

    public int $conversationId;

    public string $action; // 'update', 'delete', 'reaction'

    public ?string $text;

    public array $reactions;

    public function __construct(Message $message, string $action = 'update')
    {
        $this->messageId = $message->id;
        $this->conversationId = $message->conversation_id;
        $this->action = $action;
        $this->text = $message->text;
        $this->reactions = $message->reactions ?? [];
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.'.$this->conversationId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.updated';
    }
}
