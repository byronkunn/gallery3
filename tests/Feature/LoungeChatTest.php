<?php

namespace Tests\Feature;

use App\Models\Community;
use App\Models\CommunityMessage;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class LoungeChatTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private Community $community;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['username' => 'owner-user']);
        $this->member = User::factory()->create(['username' => 'member-user']);

        Livewire::actingAs($this->owner)->test('⚡lounge-explore')
            ->set('communityName', 'Chat Server')
            ->call('createCommunity');

        $this->community = Community::where('slug', 'chat-server')->firstOrFail();

        DB::table('community_members')->insert([
            'community_id' => $this->community->id,
            'user_id' => $this->member->id,
            'status' => 'active',
            'presence' => 'online',
            'last_seen_at' => now(),
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function general(): int
    {
        return (int) $this->community->channels()->where('slug', 'general')->value('id');
    }

    private function space(User $user, ?string $channel = 'general')
    {
        return Livewire::actingAs($user)->test('⚡community-space', [
            'slug' => $this->community->slug,
            'channel' => $channel,
        ]);
    }

    public function test_member_can_send_a_message_and_mentions_are_recorded(): void
    {
        $this->space($this->owner)
            ->set('body', 'hey @'.$this->member->username.' take a look')
            ->call('sendMessage');

        $message = CommunityMessage::where('community_channel_id', $this->general())->latest('id')->firstOrFail();

        $this->assertSame([$this->member->id], $message->mention_user_ids);
        $this->assertSame(1, $this->community->fresh()->channels()->where('slug', 'general')->value('message_count'));
    }

    public function test_member_can_toggle_a_reaction(): void
    {
        $this->space($this->member)->set('body', 'react to me')->call('sendMessage');
        $messageId = (int) CommunityMessage::where('community_channel_id', $this->general())->value('id');

        $this->space($this->member)->call('toggleReaction', $messageId, '👍');
        $this->assertDatabaseHas('community_message_reactions', [
            'community_message_id' => $messageId,
            'user_id' => $this->member->id,
            'emoji' => '👍',
        ]);

        $this->space($this->member)->call('toggleReaction', $messageId, '👍');
        $this->assertDatabaseCount('community_message_reactions', 0);
    }

    public function test_member_can_edit_and_delete_their_own_message(): void
    {
        $this->space($this->member)->set('body', 'original body')->call('sendMessage');
        $messageId = (int) CommunityMessage::where('community_channel_id', $this->general())->value('id');

        $this->space($this->member)
            ->call('startEditing', $messageId)
            ->set('editingBody', 'edited body')
            ->call('saveEdit');

        $message = CommunityMessage::findOrFail($messageId);
        $this->assertSame('edited body', $message->body);
        $this->assertNotNull($message->edited_at);

        $this->space($this->member)->call('deleteMessage', $messageId);
        $this->assertTrue(CommunityMessage::findOrFail($messageId)->is_hidden);
    }

    public function test_member_cannot_edit_or_delete_another_members_message(): void
    {
        $other = User::factory()->create(['username' => 'other-user']);
        DB::table('community_members')->insert([
            'community_id' => $this->community->id,
            'user_id' => $other->id,
            'status' => 'active',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->space($this->member)->set('body', 'mine only')->call('sendMessage');
        $messageId = (int) CommunityMessage::where('community_channel_id', $this->general())->value('id');

        $this->space($other)->call('startEditing', $messageId)->assertStatus(403);
        $this->space($other)->call('deleteMessage', $messageId)->assertStatus(403);

        $this->assertFalse(CommunityMessage::findOrFail($messageId)->is_hidden);

        // The server owner holds the delete_messages permission and can moderate.
        $this->space($this->owner)->call('deleteMessage', $messageId);
        $this->assertTrue(CommunityMessage::findOrFail($messageId)->is_hidden);
    }

    public function test_member_can_start_a_thread_and_reply_in_it(): void
    {
        $this->space($this->member)->set('body', 'thread starter')->call('sendMessage');
        $messageId = (int) CommunityMessage::where('community_channel_id', $this->general())->value('id');

        $this->space($this->member)->call('createThread', $messageId)->assertRedirect();

        $thread = DB::table('community_channels')->where('parent_message_id', $messageId)->first();
        $this->assertNotNull($thread, 'A thread channel should be created for the message.');

        $this->space($this->member, $thread->slug)->set('body', 'first thread reply')->call('sendMessage');

        $this->assertDatabaseHas('community_messages', [
            'community_channel_id' => $thread->id,
            'thread_id' => $thread->id,
            'body' => 'first thread reply',
        ]);
    }

    public function test_pinning_requires_the_delete_messages_permission(): void
    {
        $this->space($this->member)->set('body', 'pin me')->call('sendMessage');
        $messageId = (int) CommunityMessage::where('community_channel_id', $this->general())->value('id');

        $this->space($this->member)->call('togglePin', $messageId)->assertStatus(403);

        $this->space($this->owner)->call('togglePin', $messageId);
        $message = CommunityMessage::findOrFail($messageId);
        $this->assertTrue($message->is_pinned);
        $this->assertNotNull($message->pinned_at);
    }

    public function test_member_can_change_their_presence(): void
    {
        $this->space($this->member)->call('setPresence', 'dnd');

        $this->assertDatabaseHas('community_members', [
            'community_id' => $this->community->id,
            'user_id' => $this->member->id,
            'presence' => 'dnd',
        ]);
    }

    public function test_read_only_channels_reject_messages(): void
    {
        $this->community->channels()->where('slug', 'general')->update(['is_read_only' => true]);

        $this->space($this->member)->set('body', 'should not send')->call('sendMessage')->assertStatus(403);

        $this->assertDatabaseCount('community_messages', 0);
    }

    public function test_slowmode_blocks_rapid_follow_up_messages(): void
    {
        $this->community->channels()->where('slug', 'general')->update(['slowmode_seconds' => 60]);

        $this->space($this->member)->set('body', 'first message here')->call('sendMessage');
        $this->space($this->member)->set('body', 'second message here')->call('sendMessage')->assertHasErrors('body');

        $this->assertDatabaseMissing('community_messages', ['body' => 'second message here']);
    }

    public function test_timed_out_members_cannot_post(): void
    {
        DB::table('community_members')
            ->where('community_id', $this->community->id)
            ->where('user_id', $this->member->id)
            ->update(['timeout_until' => now()->addMinutes(10), 'timeout_reason' => 'cool down']);

        $this->space($this->member)->set('body', 'let me in')->call('sendMessage')->assertStatus(403);

        $this->assertDatabaseCount('community_messages', 0);
    }

    public function test_manager_can_group_channels_into_categories(): void
    {
        $this->space($this->owner)
            ->set('categoryName', 'Creative')
            ->call('createCategory');

        $categoryId = (int) DB::table('community_channel_categories')->where('community_id', $this->community->id)->value('id');
        $this->assertGreaterThan(0, $categoryId);

        $this->space($this->owner)
            ->call('openChannelSettings', $this->general())
            ->set('settingsSlowmode', 10)
            ->set('settingsCategoryId', $categoryId)
            ->call('saveChannelSettings');

        $this->assertDatabaseHas('community_channels', [
            'id' => $this->general(),
            'category_id' => $categoryId,
            'slowmode_seconds' => 10,
        ]);
    }

    public function test_lounge_direct_messages_can_be_sent_and_replied_to(): void
    {
        Livewire::actingAs($this->owner)
            ->test('⚡lounge-dms')
            ->call('startConversationWithUser', $this->member->id)
            ->set('messageText', 'Hello in Lounge DMs!')
            ->call('sendMessage');

        $conversationId = DB::table('conversations')->where('user_one_id', $this->owner->id)->where('user_two_id', $this->member->id)->value('id');
        $this->assertNotNull($conversationId);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversationId,
            'sender_id' => $this->owner->id,
            'text' => 'Hello in Lounge DMs!',
        ]);

        Livewire::actingAs($this->member)
            ->test('⚡lounge-dms', ['conversationId' => $conversationId])
            ->set('messageText', 'Replying from Lounge DMs!')
            ->call('sendMessage');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversationId,
            'sender_id' => $this->member->id,
            'text' => 'Replying from Lounge DMs!',
        ]);
    }

    public function test_deleting_a_direct_message_thread_hides_it_only_for_that_user(): void
    {
        Livewire::actingAs($this->owner)
            ->test('⚡lounge-dms')
            ->call('startConversationWithUser', $this->member->id)
            ->set('messageText', 'thread to delete')
            ->call('sendMessage');

        $conversation = Conversation::firstOrFail();

        Livewire::actingAs($this->owner)
            ->test('⚡lounge-dms', ['conversationId' => $conversation->id])
            ->assertSee('Delete')
            ->call('deleteConversation');

        $conversation->refresh();
        $this->assertNotNull($conversation->user_one_hidden_at);
        $this->assertNull($conversation->user_two_hidden_at);

        Livewire::actingAs($this->owner)
            ->test('⚡lounge-dms')
            ->assertDontSee('thread to delete');

        Livewire::actingAs($this->member)
            ->test('⚡lounge-dms')
            ->assertSee('thread to delete');
    }

    public function test_a_new_message_restores_a_deleted_thread_for_the_recipient(): void
    {
        Livewire::actingAs($this->owner)
            ->test('⚡lounge-dms')
            ->call('startConversationWithUser', $this->member->id)
            ->set('messageText', 'first hello')
            ->call('sendMessage');

        $conversation = Conversation::firstOrFail();

        Livewire::actingAs($this->owner)
            ->test('⚡lounge-dms', ['conversationId' => $conversation->id])
            ->call('deleteConversation');

        $this->assertNotNull($conversation->fresh()->user_one_hidden_at);

        Livewire::actingAs($this->member)
            ->test('⚡lounge-dms', ['conversationId' => $conversation->id])
            ->set('messageText', 'are you still there?')
            ->call('sendMessage');

        $this->assertNull($conversation->fresh()->user_one_hidden_at);

        Livewire::actingAs($this->owner)
            ->test('⚡lounge-dms')
            ->assertSee('are you still there?');
    }

    public function test_owner_can_delete_their_community_with_all_of_its_data(): void
    {
        $this->space($this->member)->set('body', 'hello before deletion')->call('sendMessage');
        $this->assertDatabaseCount('community_messages', 1);

        $this->space($this->owner)
            ->assertSee('Delete server')
            ->call('deleteCommunity')
            ->assertRedirect(route('lounge.explore'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('communities', ['id' => $this->community->id]);
        $this->assertDatabaseCount('community_members', 0);
        $this->assertDatabaseCount('community_channels', 0);
        $this->assertDatabaseCount('community_messages', 0);
        $this->assertDatabaseCount('community_roles', 0);
    }

    public function test_non_owner_cannot_delete_the_community(): void
    {
        $this->space($this->member)->call('deleteCommunity')->assertStatus(403);

        $this->assertDatabaseHas('communities', ['id' => $this->community->id]);
    }
}
