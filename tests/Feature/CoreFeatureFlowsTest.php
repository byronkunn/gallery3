<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Community;
use App\Models\Conversation;
use App\Models\Pool;
use App\Models\PoolChapter;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CoreFeatureFlowsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pool_creation_following_chapters_and_reading_progress(): void
    {
        $creator = $this->createUser();
        $reader = $this->createUser();
        $post = $this->createPost($creator);

        Livewire::actingAs($creator)->test('⚡pools-index')
            ->set('newTitle', 'Astral Journey')
            ->set('newDescription', 'A test series')
            ->call('createPool');

        $pool = Pool::where('title', 'Astral Journey')->firstOrFail();
        $this->assertTrue($pool->isFollowedBy($creator));

        Livewire::actingAs($creator)->test('⚡pool-detail', ['poolId' => $pool->id])
            ->set('chapterTitle', 'The First Page')
            ->set('chapterNumber', 1)
            ->set('selectedPostId', $post->id)
            ->call('addChapter');

        $chapter = PoolChapter::where('pool_id', $pool->id)->firstOrFail();
        $this->assertSame(1, $pool->fresh()->chapters_count);

        Livewire::actingAs($reader)->test('⚡pool-detail', ['poolId' => $pool->id])
            ->call('toggleFollow')
            ->call('readChapter', $chapter->id, 3);

        $this->assertDatabaseHas('pool_progress', [
            'user_id' => $reader->id,
            'pool_id' => $pool->id,
            'last_chapter_id' => $chapter->id,
            'last_page' => 3,
        ]);
    }

    public function test_collection_owner_can_edit_reorder_and_remove_items(): void
    {
        $owner = $this->createUser();
        $first = $this->createPost($owner);
        $second = $this->createPost($owner);
        $collection = Collection::create(['user_id' => $owner->id, 'title' => 'Favorites', 'items_count' => 2]);
        $itemOne = CollectionItem::create(['collection_id' => $collection->id, 'post_id' => $first->id, 'order' => 1]);
        $itemTwo = CollectionItem::create(['collection_id' => $collection->id, 'post_id' => $second->id, 'order' => 2]);

        Livewire::actingAs($owner)->test('⚡collection-detail', ['collectionId' => $collection->id])
            ->call('moveItem', $itemTwo->id, 'up')
            ->set('editTitle', 'Curated favorites')
            ->set('editDescription', 'A changed description')
            ->set('editIsPrivate', true)
            ->call('saveSettings')
            ->call('removeItem', $itemOne->id);

        $this->assertSame(1, $itemTwo->fresh()->order);
        $this->assertDatabaseHas('collections', ['id' => $collection->id, 'title' => 'Curated favorites', 'is_private' => true]);
        $this->assertDatabaseMissing('collection_items', ['id' => $itemOne->id]);
        $this->assertSame(1, $collection->fresh()->items_count);
    }

    public function test_direct_messages_can_be_sent_replied_to_and_reacted_to(): void
    {
        $sender = $this->createUser();
        $recipient = $this->createUser();
        $conversation = Conversation::create(['user_one_id' => $sender->id, 'user_two_id' => $recipient->id]);

        Livewire::actingAs($sender)->test('⚡messages-view')
            ->call('selectConversation', $conversation->id)
            ->set('messageText', 'Hello there')
            ->call('sendMessage');

        $messageId = DB::table('messages')->where('conversation_id', $conversation->id)->value('id');
        $this->assertNotNull($messageId);

        Livewire::actingAs($recipient)->test('⚡messages-view')
            ->call('selectConversation', $conversation->id)
            ->call('addReaction', $messageId, '❤️');

        $this->assertDatabaseHas('messages', ['id' => $messageId]);
        $this->assertNotNull($conversation->fresh()->last_message_at);
    }

    public function test_community_creation_and_members_can_use_core_channels(): void
    {
        $owner = $this->createUser();
        $member = $this->createUser();

        Livewire::actingAs($owner)->test('⚡lounge-explore')
            ->set('communityName', 'Watercolor Club')
            ->set('communityDescription', 'Share watercolor work')
            ->set('communityTopics', 'Watercolor, Landscapes')
            ->call('createCommunity');

        $community = Community::where('slug', 'watercolor-club')->firstOrFail();
        $this->assertSame(3, $community->channels()->count());
        Livewire::actingAs($member)->test('⚡lounge-explore')
            ->set('applyingCommunityId', $community->id)
            ->call('submitApplication');

        $general = $community->channels()->where('slug', 'general')->firstOrFail();
        Livewire::actingAs($member)->test('⚡community-space', ['slug' => $community->slug, 'channel' => 'general'])
            ->set('body', 'Hello, artists!')
            ->call('sendMessage');

        $this->assertDatabaseHas('community_messages', [
            'community_channel_id' => $general->id,
            'user_id' => $member->id,
            'body' => 'Hello, artists!',
        ]);
    }

    public function test_pending_member_can_join_when_community_becomes_public(): void
    {
        $owner = $this->createUser();
        $member = $this->createUser();
        Livewire::actingAs($owner)->test('⚡lounge-explore')
            ->set('communityName', 'Sketch Club')
            ->set('communityVisibility', 'approval')
            ->call('createCommunity');
        $community = Community::where('slug', 'sketch-club')->firstOrFail();
        Livewire::actingAs($member)->test('⚡community-space', ['slug' => $community->slug])
            ->call('joinCommunity');
        $community->update(['visibility' => 'public']);

        Livewire::actingAs($member)->test('⚡community-space', ['slug' => $community->slug])
            ->call('joinCommunity');

        $this->assertDatabaseHas('community_members', [
            'community_id' => $community->id,
            'user_id' => $member->id,
            'status' => 'active',
        ]);
        $this->assertSame(2, $community->fresh()->member_count);
    }

    public function test_lounge_rejects_names_without_a_url_slug(): void
    {
        $owner = $this->createUser();

        Livewire::actingAs($owner)->test('⚡lounge-explore')
            ->set('communityName', '✨✨')
            ->call('createCommunity')
            ->assertHasErrors(['communityName']);

        $this->assertDatabaseCount('communities', 0);
    }

    public function test_read_only_forum_rejects_replies(): void
    {
        $owner = $this->createUser();
        Livewire::actingAs($owner)->test('⚡lounge-explore')
            ->set('communityName', 'Figure Drawing')
            ->call('createCommunity');
        $community = Community::where('slug', 'figure-drawing')->firstOrFail();
        $forum = $community->channels()->where('slug', 'show-and-tell')->firstOrFail();
        Livewire::actingAs($owner)->test('⚡community-space', ['slug' => $community->slug, 'channel' => $forum->slug])
            ->set('forumTitle', 'Weekly sketches')
            ->call('createForumPost');
        $postId = DB::table('community_forum_posts')->where('community_channel_id', $forum->id)->value('id');
        $forum->update(['is_read_only' => true]);

        Livewire::actingAs($owner)->test('⚡community-space', ['slug' => $community->slug, 'channel' => $forum->slug])
            ->set('replyBody', 'A new sketch')
            ->call('replyToForumPost', $postId)
            ->assertStatus(403);

        $this->assertDatabaseCount('community_forum_replies', 0);
    }

    public function test_member_moderator_can_use_lounge_management_controls(): void
    {
        $owner = $this->createUser();
        $moderator = $this->createUser();
        Livewire::actingAs($owner)->test('⚡lounge-explore')
            ->set('communityName', 'Color Studies')
            ->call('createCommunity');
        $community = Community::where('slug', 'color-studies')->firstOrFail();
        $roleId = DB::table('community_roles')->insertGetId([
            'community_id' => $community->id,
            'name' => 'Community moderator',
            'permissions' => json_encode(['manage_members', 'manage_tags']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('community_members')->insert([
            'community_id' => $community->id,
            'user_id' => $moderator->id,
            'community_role_id' => $roleId,
            'status' => 'active',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($moderator)->test('⚡community-space', ['slug' => $community->slug])
            ->call('openInvites')
            ->assertSee('Create invite')
            ->call('createInvite');

        $this->assertDatabaseHas('community_invites', [
            'community_id' => $community->id,
            'created_by' => $moderator->id,
        ]);

        $forum = $community->channels()->where('slug', 'show-and-tell')->firstOrFail();
        Livewire::actingAs($moderator)->test('⚡community-space', ['slug' => $community->slug, 'channel' => $forum->slug])
            ->assertSee('Add forum tag')
            ->set('forumTagName', 'Critique')
            ->call('createForumTag');

        $this->assertDatabaseHas('community_forum_tags', [
            'community_id' => $community->id,
            'slug' => 'critique',
        ]);

        DB::table('community_members')->insert([
            'community_id' => $community->id,
            'user_id' => $this->createUser()->id,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $applicantId = DB::table('community_members')->where('community_id', $community->id)->where('status', 'pending')->value('user_id');

        Livewire::actingAs($moderator)->test('⚡community-space', ['slug' => $community->slug])
            ->call('openServerSettings')
            ->set('serverTab', 'members')
            ->call('reviewMember', $applicantId, 'approve');

        $this->assertDatabaseHas('community_members', [
            'community_id' => $community->id,
            'user_id' => $applicantId,
            'status' => 'active',
        ]);
    }

    public function test_plain_member_cannot_use_lounge_management_controls(): void
    {
        $owner = $this->createUser();
        $member = $this->createUser();
        Livewire::actingAs($owner)->test('⚡lounge-explore')
            ->set('communityName', 'Reader Club')
            ->call('createCommunity');
        $community = Community::where('slug', 'reader-club')->firstOrFail();
        DB::table('community_members')->insert([
            'community_id' => $community->id,
            'user_id' => $member->id,
            'status' => 'active',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($member)->test('⚡community-space', ['slug' => $community->slug])
            ->call('createInvite')
            ->assertStatus(403);
        Livewire::actingAs($member)->test('⚡community-space', ['slug' => $community->slug])
            ->call('openServerSettings')
            ->assertStatus(403);

        $this->assertDatabaseCount('community_invites', 0);
    }

    private function createPost(User $user): Post
    {
        $post = Post::create(['user_id' => $user->id, 'title' => 'Artwork', 'media_type' => 'image', 'media_count' => 1]);
        PostMedia::create(['post_id' => $post->id, 'url' => 'https://example.test/art.png', 'type' => 'image', 'order' => 0]);

        return $post;
    }

    private function createUser(): User
    {
        return User::factory()->create(['username' => 'artist-'.Str::lower(Str::random(12))]);
    }
}
