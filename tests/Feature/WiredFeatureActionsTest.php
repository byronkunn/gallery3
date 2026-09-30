<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Community;
use App\Models\CommunityMessage;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class WiredFeatureActionsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $username = 'wired-user'): User
    {
        return User::factory()->approved()->create(['username' => $username]);
    }

    private function artworkPost(User $owner, string $title = 'Wired artwork'): Post
    {
        $post = Post::create([
            'user_id' => $owner->id,
            'title' => $title,
            'description' => 'A post used to exercise newly wired feed actions.',
            'media_type' => 'image',
            'media_count' => 1,
            'views_count' => 0,
            'likes_count' => 0,
            'is_nsfw' => false,
        ]);

        PostMedia::create([
            'post_id' => $post->id,
            'order' => 1,
            'url' => 'https://example.test/artwork.jpg',
            'thumbnail_url' => 'https://example.test/artwork.jpg',
            'width' => 1200,
            'height' => 1600,
            'aspect_ratio' => 0.75,
        ]);

        return $post;
    }

    public function test_gallery_not_interested_records_a_dislike(): void
    {
        $user = $this->user();
        $post = $this->artworkPost($user);

        Livewire::actingAs($user)->test('⚡gallery-feed')->call('notInterested', $post->id);

        $this->assertDatabaseHas('user_disliked_posts', [
            'user_id' => $user->id,
            'post_id' => $post->id,
        ]);
    }

    public function test_gallery_mute_tag_adds_the_tag_to_the_muted_list(): void
    {
        $user = $this->user();
        $tag = Tag::create(['name' => 'scenery', 'slug' => 'scenery', 'type' => 'general']);

        Livewire::actingAs($user)->test('⚡gallery-feed')->call('muteTag', $tag->name);

        $this->assertDatabaseHas('user_muted_tags', [
            'user_id' => $user->id,
            'tag_id' => $tag->id,
        ]);
    }

    public function test_collections_discover_sort_updates(): void
    {
        Livewire::actingAs($this->user())
            ->test('⚡collections-hub')
            ->call('setDiscoverSort', 'new')
            ->assertSet('discoverSort', 'new');
    }

    public function test_dm_set_replying_to_records_the_target_message(): void
    {
        Livewire::actingAs($this->user())
            ->test('⚡lounge-dms')
            ->call('setReplyingTo', 42)
            ->assertSet('replyingToMessageId', 42);
    }

    public function test_settings_update_font_size_is_persisted(): void
    {
        $user = $this->user();

        Livewire::actingAs($user)->test('⚡settings-view')->call('updateFontSize', 'lg');

        $this->assertSame('lg', $user->fresh()->font_size);
    }

    public function test_settings_logout_other_sessions_keeps_current_and_deletes_the_rest(): void
    {
        $user = $this->user();
        $other = $this->user('other-user');

        foreach ([['sess-a', $user->id], ['sess-b', $user->id], ['sess-c', $other->id]] as [$id, $userId]) {
            DB::table('sessions')->insert([
                'id' => $id,
                'user_id' => $userId,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
                'payload' => base64_encode(serialize([])),
                'last_activity' => now()->timestamp,
            ]);
        }

        Livewire::actingAs($user)->test('⚡settings-view')->call('logoutAllOtherSessions');

        $this->assertDatabaseCount('sessions', 1);
        $this->assertDatabaseHas('sessions', ['id' => 'sess-c', 'user_id' => $other->id]);
    }

    public function test_artist_alias_suggestion_creates_an_alias_record(): void
    {
        $artist = Artist::create(['name' => 'JaneArt', 'slug' => 'janeart']);

        Livewire::actingAs($this->user())
            ->test('⚡artist-detail', ['slug' => $artist->slug])
            ->set('aliasName', 'Jane Alt')
            ->call('submitSuggestAlias');

        $this->assertDatabaseHas('artist_aliases', [
            'artist_id' => $artist->id,
            'alias' => 'Jane Alt',
            'slug' => 'jane-alt',
        ]);
    }

    public function test_artist_merge_request_creates_a_pending_edit(): void
    {
        $artist = Artist::create(['name' => 'MasterJane', 'slug' => 'masterjane']);

        Livewire::actingAs($this->user())
            ->test('⚡artist-detail', ['slug' => $artist->slug])
            ->set('mergeTargetName', 'Jane Duplicate')
            ->set('mergeReason', 'Same artist under two names.')
            ->call('submitMergeRequest');

        $this->assertDatabaseHas('artist_edits', [
            'artist_id' => $artist->id,
            'action' => 'merge_request',
            'status' => 'pending',
        ]);
    }

    public function test_community_owner_can_create_a_poll(): void
    {
        [$owner, $community] = $this->community();

        $this->space($owner, $community)
            ->set('pollQuestion', 'Which palette should we study next?')
            ->set('pollOptions', "Warm sunset\nCool night")
            ->call('createPoll');

        $this->assertDatabaseHas('community_polls', ['question' => 'Which palette should we study next?']);
        $this->assertSame(2, DB::table('community_poll_options')->count());
    }

    public function test_community_owner_can_lock_and_archive_a_thread(): void
    {
        [$owner, $community] = $this->community();

        $this->space($owner, $community)->set('body', 'thread starter')->call('sendMessage');
        $messageId = (int) CommunityMessage::where('community_channel_id', $this->general($community))->value('id');

        $this->space($owner, $community)->call('createThread', $messageId)->assertRedirect();

        $threadId = (int) DB::table('community_channels')->where('parent_message_id', $messageId)->value('id');

        $this->space($owner, $community)->call('lockThread', $threadId);
        $this->assertTrue((bool) DB::table('community_channels')->where('id', $threadId)->value('thread_locked'));

        $this->space($owner, $community)->call('archiveThread', $threadId);
        $this->assertTrue((bool) DB::table('community_channels')->where('id', $threadId)->value('thread_archived'));
    }

    /**
     * @return array{0: User, 1: Community}
     */
    private function community(): array
    {
        $owner = $this->user('community-owner');

        Livewire::actingAs($owner)->test('⚡lounge-explore')
            ->set('communityName', 'Wired Server')
            ->call('createCommunity');

        return [$owner, Community::where('slug', 'wired-server')->firstOrFail()];
    }

    private function general(Community $community): int
    {
        return (int) $community->channels()->where('slug', 'general')->value('id');
    }

    private function space(User $user, Community $community)
    {
        return Livewire::actingAs($user)->test('⚡community-space', [
            'slug' => $community->slug,
            'channel' => 'general',
        ]);
    }
}
