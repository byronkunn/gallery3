<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SfwMessagingTest extends TestCase
{
    use RefreshDatabase;

    private const SFW_IMAGE = '/sfw/image/sample_fd6cf1c5b5dda7658bf8b050ebb8240f.jpg';

    public function test_message_shares_a_post_using_an_existing_sfw_image(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $conversation = Conversation::create(['user_one_id' => $sender->id, 'user_two_id' => $recipient->id]);
        $post = $this->createSfwPost($sender);

        Livewire::actingAs($sender)->test('⚡messages-view', ['conversationId' => $conversation->id])
            ->call('attachPost', $post->id)
            ->set('messageText', 'Look at this artwork')
            ->call('sendMessage');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'shared_post_id' => $post->id,
            'text' => 'Look at this artwork',
        ]);
        Livewire::actingAs($recipient)->test('⚡messages-view', ['conversationId' => $conversation->id])
            ->assertSee(self::SFW_IMAGE);
    }

    public function test_lounge_dm_shares_a_post_using_an_existing_sfw_image(): void
    {
        $sender = User::factory()->create();
        $recipient = User::factory()->create();
        $conversation = Conversation::create(['user_one_id' => $sender->id, 'user_two_id' => $recipient->id]);
        $post = $this->createSfwPost($sender);

        Livewire::actingAs($sender)->test('⚡lounge-dms', ['conversationId' => $conversation->id])
            ->call('attachPost', $post->id)
            ->set('messageText', 'Shared from the SFW gallery')
            ->call('sendMessage');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'shared_post_id' => $post->id,
            'text' => 'Shared from the SFW gallery',
        ]);
        Livewire::actingAs($recipient)->test('⚡lounge-dms', ['conversationId' => $conversation->id])
            ->assertSee(self::SFW_IMAGE);
    }

    private function createSfwPost(User $owner): Post
    {
        $this->assertFileExists(public_path(ltrim(self::SFW_IMAGE, '/')));
        $post = Post::create([
            'user_id' => $owner->id,
            'title' => 'SFW gallery artwork',
            'media_type' => 'image',
            'media_count' => 1,
        ]);
        PostMedia::create(['post_id' => $post->id, 'url' => self::SFW_IMAGE, 'order' => 0]);

        return $post;
    }
}
