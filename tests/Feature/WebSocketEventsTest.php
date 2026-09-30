<?php

namespace Tests\Feature;

use App\Events\MessageSent;
use App\Events\NotificationSent;
use App\Events\UserTyping;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class WebSocketEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_sent_event_broadcasts_on_private_conversation_channel()
    {
        Event::fake([MessageSent::class]);

        $user1 = User::factory()->create(['username' => 'testuser1', 'email' => 't1@example.com']);
        $user2 = User::factory()->create(['username' => 'testuser2', 'email' => 't2@example.com']);

        $conv = Conversation::create([
            'user_one_id' => $user1->id,
            'user_two_id' => $user2->id,
            'last_message_at' => now(),
        ]);

        $message = Message::create([
            'conversation_id' => $conv->id,
            'sender_id' => $user1->id,
            'text' => 'Hello over WebSockets!',
            'is_read' => false,
        ]);

        event(new MessageSent($message));

        Event::assertDispatched(MessageSent::class, function ($e) use ($message, $conv) {
            return $e->message->id === $message->id && $e->broadcastOn()[0]->name === 'private-conversation.'.$conv->id;
        });
    }

    public function test_user_typing_and_notification_sent_events()
    {
        Event::fake([UserTyping::class, NotificationSent::class]);

        event(new UserTyping(1, 2, 'kira', true));

        Event::assertDispatched(UserTyping::class, function ($e) {
            return $e->conversationId === 1 && $e->username === 'kira';
        });

        $user = User::factory()->create(['username' => 'notifuser', 'email' => 'notif@example.com']);
        $notif = Notification::create([
            'user_id' => $user->id,
            'actor_id' => $user->id,
            'type' => 'like',
            'message' => 'Liked your creation',
        ]);

        event(new NotificationSent($notif));

        Event::assertDispatched(NotificationSent::class, function ($e) use ($user) {
            return $e->broadcastOn()[0]->name === 'private-users.'.$user->id;
        });
    }
}
