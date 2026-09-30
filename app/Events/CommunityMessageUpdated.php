<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommunityMessageUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  'edit'|'delete'|'pin'|'unpin'|'reaction'  $action
     */
    public function __construct(
        public int $communityId,
        public int $channelId,
        public ?int $threadId,
        public int $messageId,
        public string $action = 'edit',
    ) {}

    /**
     * @return array<int, PresenceChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('community.'.$this->communityId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'community.message.updated';
    }
}
