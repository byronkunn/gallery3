<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Notification $notification;

    public array $notificationData;

    public function __construct(Notification $notification)
    {
        $this->notification = $notification;
        $notification->load('actor');

        $this->notificationData = [
            'id' => $notification->id,
            'user_id' => $notification->user_id,
            'actor_name' => $notification->actor?->name ?? 'System',
            'actor_avatar' => $notification->actor?->avatar_url,
            'type' => $notification->type,
            'message' => $notification->message,
            'created_at' => $notification->created_at->diffForHumans(),
        ];
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('users.'.$this->notification->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'notification.sent';
    }
}
