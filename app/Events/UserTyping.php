<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserTyping implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $conversationId;

    public int $userId;

    public string $username;

    public bool $isTyping;

    public function __construct(int $conversationId, int $userId, string $username, bool $isTyping = true)
    {
        $this->conversationId = $conversationId;
        $this->userId = $userId;
        $this->username = $username;
        $this->isTyping = $isTyping;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('conversation.'.$this->conversationId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'user.typing';
    }
}
