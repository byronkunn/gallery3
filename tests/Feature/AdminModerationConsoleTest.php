<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Comment;
use App\Models\Community;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AdminModerationConsoleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every section of the console must render its own content. A Blade or
     * query error in any one of them shows up here instead of in production.
     */
    public function test_every_admin_section_renders_its_own_content(): void
    {
        $admin = $this->createUser('section-admin', true);

        $expectedContent = [
            'overview' => 'Site Broadcast Announcement',
            'users' => 'User Management & Ban Control',
            'content' => 'Media & Video Moderation',
            'messages' => 'User Conversations',
            'comments' => 'Comment & Activity Moderation',
            'pools' => 'Manga Series & Pool Moderation',
            'tags' => 'Tag management',
            'reports' => 'Content report queue',
            'community-reports' => 'Lounge community reports',
            'blocked' => 'Removing a block restores visibility',
            'moderation' => 'Removals here are reversible',
            'collections' => 'including private ones',
            'communities' => 'Edit server rules',
            'media' => 'Orphaned files',
            'appeals' => 'Suspension appeals',
            'audit' => 'Admin audit log',
            'recovery' => 'Community ownership recovery',
            'settings' => 'Site Configuration & Feature Controls',
        ];

        foreach ($expectedContent as $section => $expected) {
            Livewire::actingAs($admin)
                ->test('⚡admin-dashboard')
                ->call('setSection', $section)
                ->assertSee($expected, escape: false);
        }
    }

    public function test_report_queue_lists_reports_for_every_target_type(): void
    {
        $admin = $this->createUser('queue-admin', true);
        $reporter = $this->createUser('queue-reporter');
        $author = $this->createUser('queue-author');
        $post = $this->createPost($author, 'Queue post');
        $comment = Comment::create(['post_id' => $post->id, 'user_id' => $author->id, 'content' => 'Queue comment body']);
        $conversation = Conversation::create(['user_one_id' => $reporter->id, 'user_two_id' => $author->id]);
        $message = Message::create(['conversation_id' => $conversation->id, 'sender_id' => $author->id, 'text' => 'Queue dm body']);
        $community = Community::create(['owner_id' => $author->id, 'name' => 'Queue Community', 'slug' => 'queue-community']);

        foreach ([
            ['post', $post->id],
            ['comment', $comment->id],
            ['message', $message->id],
            ['user', $author->id],
            ['community', $community->id],
        ] as [$type, $id]) {
            DB::table('content_reports')->insert([
                'reporter_id' => $reporter->id,
                'target_type' => $type,
                'target_id' => $id,
                'reason' => 'spam',
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(5, DB::table('content_reports')->count());

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->call('setSection', 'reports')
            ->assertSee('5 pending')
            ->assertSee('Queue post')
            ->assertSee('Queue comment body')
            ->assertSee('Queue dm body')
            ->assertSee('@queue-author')
            ->assertSee('Queue Community');
    }

    public function test_suspending_the_author_straight_from_a_comment_report_bans_and_notifies_them(): void
    {
        $admin = $this->createUser('suspend-admin', true);
        $reporter = $this->createUser('suspend-reporter');
        $author = $this->createUser('suspend-author');
        $post = $this->createPost($author, 'Suspend post');
        $comment = Comment::create(['post_id' => $post->id, 'user_id' => $author->id, 'content' => 'Abusive comment']);
        $reportId = DB::table('content_reports')->insertGetId([
            'reporter_id' => $reporter->id,
            'target_type' => 'comment',
            'target_id' => $comment->id,
            'reason' => 'harassment',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->set('reportReasons', [$reportId => 'Repeated harassment in comment threads.'])
            ->call('reviewContentReport', $reportId, 'suspend')
            ->assertHasNoErrors();

        $author->refresh();
        $this->assertTrue($author->is_banned);
        $this->assertSame('Repeated harassment in comment threads.', $author->suspension_reason);
        $this->assertDatabaseHas('content_reports', ['id' => $reportId, 'status' => 'actioned', 'reviewer_id' => $admin->id]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $author->id,
            'type' => 'warning',
            'message' => 'Your account was suspended. Reason: Repeated harassment in comment threads.',
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'content_report.suspend',
            'target_type' => 'comment',
            'target_id' => $comment->id,
        ]);
    }

    public function test_locking_comments_from_a_report_stops_new_comments_and_notifies_the_author(): void
    {
        $admin = $this->createUser('lock-admin', true);
        $reporter = $this->createUser('lock-reporter');
        $author = $this->createUser('lock-author');
        $commenter = $this->createUser('lock-commenter');
        $post = $this->createPost($author, 'Flame war post');
        $reportId = DB::table('content_reports')->insertGetId([
            'reporter_id' => $reporter->id,
            'target_type' => 'post',
            'target_id' => $post->id,
            'reason' => 'harassment',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->set('reportReasons', [$reportId => 'Comment thread turned into a flame war.'])
            ->call('reviewContentReport', $reportId, 'lock')
            ->assertHasNoErrors();

        $this->assertTrue($post->fresh()->comments_locked);
        $this->assertDatabaseHas('content_reports', ['id' => $reportId, 'status' => 'actioned']);
        $this->assertDatabaseHas('notifications', ['user_id' => $author->id, 'type' => 'warning']);

        Livewire::actingAs($commenter)
            ->test('⚡post-detail', ['postId' => $post->id])
            ->assertSee('Comments are locked on this post')
            ->set('commentText', 'Trying to comment anyway')
            ->call('addComment')
            ->assertStatus(423);

        $this->assertSame(0, $post->comments()->count());
    }

    public function test_hiding_a_reported_direct_message_removes_it_and_notifies_the_sender(): void
    {
        $admin = $this->createUser('dm-admin', true);
        $reporter = $this->createUser('dm-reporter');
        $sender = $this->createUser('dm-sender');
        $conversation = Conversation::create(['user_one_id' => $reporter->id, 'user_two_id' => $sender->id]);
        $message = Message::create(['conversation_id' => $conversation->id, 'sender_id' => $sender->id, 'text' => 'Unwanted dm']);
        $reportId = DB::table('content_reports')->insertGetId([
            'reporter_id' => $reporter->id,
            'target_type' => 'message',
            'target_id' => $message->id,
            'reason' => 'harassment',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->set('reportReasons', [$reportId => 'Unwanted contact after a block request.'])
            ->call('reviewContentReport', $reportId, 'hide')
            ->assertHasNoErrors();

        $this->assertTrue($message->fresh()->is_hidden);
        $this->assertDatabaseHas('notifications', ['user_id' => $sender->id, 'type' => 'warning']);

        Livewire::actingAs($reporter)
            ->test('⚡messages-view', ['conversationId' => $conversation->id])
            ->assertDontSee('Unwanted dm');
    }

    public function test_warning_a_user_sends_a_notification_without_suspending_them(): void
    {
        $admin = $this->createUser('warn-admin', true);
        $member = $this->createUser('warn-member');

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->call('prepareWarning', $member->id)
            ->set('warningReason', 'Please keep critique constructive.')
            ->call('warnUser', $member->id)
            ->assertHasNoErrors();

        $this->assertFalse($member->fresh()->is_banned);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $member->id,
            'type' => 'warning',
            'message' => 'Moderation notice: Please keep critique constructive.',
        ]);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'user.warned', 'target_id' => $member->id]);
    }

    public function test_hidden_comments_disappear_from_the_thread_and_can_be_restored(): void
    {
        $admin = $this->createUser('hide-admin', true);
        $author = $this->createUser('hide-author');
        $post = $this->createPost($author, 'Hide post');
        $comment = Comment::create(['post_id' => $post->id, 'user_id' => $author->id, 'content' => 'Hide me please']);

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->call('toggleCommentHidden', $comment->id)
            ->assertHasNoErrors();

        $this->assertTrue($comment->fresh()->is_hidden);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $author->id,
            'type' => 'warning',
            'message' => 'Your comment was hidden by a moderator. Reason: Hidden by an administrator.',
        ]);
        Livewire::actingAs($author)
            ->test('⚡post-detail', ['postId' => $post->id])
            ->assertDontSee('Hide me please');

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->call('toggleCommentHidden', $comment->id);

        $this->assertFalse($comment->fresh()->is_hidden);
        Livewire::actingAs($author)
            ->test('⚡post-detail', ['postId' => $post->id])
            ->assertSee('Hide me please');
    }

    public function test_admin_can_edit_a_comment_from_the_console(): void
    {
        $admin = $this->createUser('edit-admin', true);
        $author = $this->createUser('edit-author');
        $post = $this->createPost($author, 'Edit post');
        $comment = Comment::create(['post_id' => $post->id, 'user_id' => $author->id, 'content' => 'Typo ridden comment']);

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->call('startCommentEdit', $comment->id)
            ->assertSet('editingCommentText', 'Typo ridden comment')
            ->set('editingCommentText', 'Corrected comment')
            ->call('saveCommentEdit')
            ->assertHasNoErrors();

        $this->assertSame('Corrected comment', $comment->fresh()->content);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'comment.edited', 'target_id' => $comment->id]);
    }

    public function test_lounge_community_reports_are_visible_to_site_admins_and_resolvable(): void
    {
        $admin = $this->createUser('lounge-admin', true);
        $owner = $this->createUser('lounge-owner');
        $reporter = $this->createUser('lounge-reporter');
        $community = Community::create(['owner_id' => $owner->id, 'name' => 'Lounge Room', 'slug' => 'lounge-room']);
        $channel = DB::table('community_channels')->insertGetId([
            'community_id' => $community->id,
            'name' => 'general',
            'slug' => 'general',
            'type' => 'text',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $messageId = DB::table('community_messages')->insertGetId([
            'community_channel_id' => $channel,
            'user_id' => $owner->id,
            'body' => 'Reported lounge message',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $reportId = DB::table('community_reports')->insertGetId([
            'community_id' => $community->id,
            'reporter_id' => $reporter->id,
            'target_type' => 'message',
            'target_id' => $messageId,
            'reason' => 'spam',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->call('setSection', 'community-reports')
            ->assertSee('Lounge Room')
            ->call('resolveCommunityReport', $reportId, 'hide')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('community_reports', ['id' => $reportId, 'status' => 'actioned', 'resolver_id' => $admin->id]);
        $this->assertTrue((bool) DB::table('community_messages')->where('id', $messageId)->value('is_hidden'));
    }

    public function test_admin_can_remove_a_user_block(): void
    {
        $admin = $this->createUser('block-admin', true);
        $blocker = $this->createUser('block-blocker');
        $blocked = $this->createUser('block-blocked');
        $blocker->blockedUsers()->attach($blocked->id);
        $blockId = DB::table('user_blocks')->value('id');

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->call('setSection', 'blocked')
            ->assertSee('@block-blocker')
            ->assertSee('@block-blocked')
            ->call('removeBlock', $blockId)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('user_blocks', ['id' => $blockId]);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'user_block.removed', 'target_id' => $blocked->id]);
    }

    public function test_bulk_actions_suspend_selected_users_and_feature_selected_posts(): void
    {
        $admin = $this->createUser('bulk-admin', true);
        $first = $this->createUser('bulk-first');
        $second = $this->createUser('bulk-second');
        $post = $this->createPost($first, 'Bulk post');

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->call('toggleSelectAllUsers')
            ->call('bulkUserAction', 'ban')
            ->assertHasNoErrors();

        $this->assertTrue($first->fresh()->is_banned);
        $this->assertTrue($second->fresh()->is_banned);

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->set('selectedPostIds', [$post->id])
            ->call('bulkPostAction', 'feature')
            ->assertHasNoErrors();

        $this->assertTrue($post->fresh()->is_featured);
    }

    public function test_bulk_tagging_attaches_a_new_tag_to_every_selected_post(): void
    {
        $admin = $this->createUser('bulk-tag-admin', true);
        $author = $this->createUser('bulk-tag-author');
        $first = $this->createPost($author, 'Bulk tag one');
        $second = $this->createPost($author, 'Bulk tag two');

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->set('selectedPostIds', [$first->id, $second->id])
            ->set('bulkTagName', 'neon lights')
            ->call('bulkPostAction', 'tag')
            ->assertHasNoErrors();

        $tag = Tag::where('name', 'neon_lights')->firstOrFail();
        $this->assertSame(2, $tag->posts_count);
        $this->assertTrue($first->fresh()->tags->contains($tag->id));
        $this->assertTrue($second->fresh()->tags->contains($tag->id));
    }

    public function test_admin_can_rename_retype_merge_and_delete_tags(): void
    {
        $admin = $this->createUser('tag-admin', true);
        $author = $this->createUser('tag-author');
        $post = $this->createPost($author, 'Tagged post');
        $source = Tag::create(['name' => 'nekomimi', 'slug' => 'nekomimi', 'type' => 'general']);
        $target = Tag::create(['name' => 'cat_girl', 'slug' => 'cat-girl', 'type' => 'character']);
        $post->tags()->attach($source->id);
        $source->update(['posts_count' => 1]);
        $target->update(['posts_count' => 1]);
        $post->tags()->attach($target->id);

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->call('updateTagType', $source->id)
            ->set('newTagType', 'character')
            ->call('updateTagType', $source->id)
            ->assertHasNoErrors();
        $this->assertSame('character', $source->fresh()->type);

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->call('renameTag', $target->id, 'Cat Girl')
            ->assertHasNoErrors();
        $this->assertSame('cat_girl', $target->fresh()->name);

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->set('mergeSourceName', 'nekomimi')
            ->set('mergeTargetName', 'cat_girl')
            ->call('mergeTags')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('tags', ['id' => $source->id]);
        $this->assertDatabaseHas('post_tag', ['post_id' => $post->id, 'tag_id' => $target->id]);
        $this->assertDatabaseHas('tag_aliases', ['alias' => 'nekomimi', 'tag_id' => $target->id]);
        $this->assertSame(1, $target->fresh()->posts_count);

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->call('deleteTag', $target->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('tags', ['id' => $target->id]);
        $this->assertDatabaseMissing('post_tag', ['tag_id' => $target->id]);
    }

    public function test_user_drawer_supports_notes_password_reset_and_force_logout(): void
    {
        $admin = $this->createUser('drawer-admin', true);
        $member = $this->createUser('drawer-member');
        $originalHash = $member->password;
        DB::table('sessions')->insert([
            'id' => 'session-'.$member->id,
            'user_id' => $member->id,
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Test Agent',
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->call('openUserDrawer', $member->id)
            ->set('userNoteText', 'Watch this account.')
            ->call('addUserNote')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('admin_user_notes', ['user_id' => $member->id, 'author_id' => $admin->id, 'note' => 'Watch this account.']);

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->call('forceUserLogout', $member->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['user_id' => $member->id]);

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->call('resetUserPassword', $member->id)
            ->assertHasNoErrors();

        $this->assertNotSame($originalHash, $member->fresh()->password);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'user.password_reset', 'target_id' => $member->id]);
    }

    public function test_media_admin_reports_storage_and_deletes_orphaned_files_only(): void
    {
        Storage::fake('public');
        $admin = $this->createUser('media-admin', true);
        $author = $this->createUser('media-author');
        $post = $this->createPost($author, 'Media post');
        $post->media()->firstOrFail()->update(['url' => '/storage/posts/kept.jpg']);
        Storage::disk('public')->put('posts/kept.jpg', 'kept bytes');
        Storage::disk('public')->put('posts/orphan.jpg', 'orphan bytes');

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->call('setSection', 'media')
            ->assertSee('orphan.jpg')
            ->call('pruneOrphanedMedia')
            ->assertHasNoErrors();

        Storage::disk('public')->assertMissing('posts/orphan.jpg');
        Storage::disk('public')->assertExists('posts/kept.jpg');
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'media.orphans_pruned']);
    }

    public function test_collection_removal_is_reversible_from_the_moderation_history(): void
    {
        $admin = $this->createUser('collection-admin', true);
        $owner = $this->createUser('collection-owner');
        $collection = Collection::create(['user_id' => $owner->id, 'title' => 'Removable collection']);

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->call('setSection', 'collections')
            ->assertSee('Removable collection')
            ->call('removeCollection', $collection->id)
            ->assertHasNoErrors();

        $this->assertSoftDeleted($collection);

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->call('restoreModerated', 'collection', $collection->id)
            ->assertHasNoErrors();

        $this->assertNotSoftDeleted($collection);
    }

    public function test_site_announcement_is_rendered_as_a_banner_for_visitors(): void
    {
        Cache::put('site_announcement', 'Scheduled maintenance on Sunday');

        $this->get('/gallery')
            ->assertOk()
            ->assertSee('Scheduled maintenance on Sunday');
    }

    public function test_announcement_can_also_be_sent_to_every_account_as_a_notification(): void
    {
        $admin = $this->createUser('announce-admin', true);
        $first = $this->createUser('announce-first');
        $second = $this->createUser('announce-second');

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->set('announcementText', 'Batch uploads are live')
            ->set('announcementNotifyUsers', true)
            ->call('saveSettings')
            ->assertHasNoErrors();

        $this->assertSame('Batch uploads are live', Cache::get('site_announcement'));
        $this->assertDatabaseHas('notifications', ['user_id' => $first->id, 'type' => 'announcement', 'message' => 'Batch uploads are live']);
        $this->assertDatabaseHas('notifications', ['user_id' => $second->id, 'type' => 'announcement', 'message' => 'Batch uploads are live']);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'site.announcement_notified']);
    }

    public function test_communities_can_be_archived_and_the_owner_is_notified(): void
    {
        $admin = $this->createUser('archive-admin', true);
        $owner = $this->createUser('archive-owner');
        $community = Community::create(['owner_id' => $owner->id, 'name' => 'Archive Me', 'slug' => 'archive-me']);

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->call('setSection', 'communities')
            ->assertSee('Archive Me')
            ->call('toggleCommunityArchive', $community->id)
            ->assertHasNoErrors();

        $this->assertTrue($community->fresh()->isArchived());
        $this->assertDatabaseHas('notifications', ['user_id' => $owner->id, 'type' => 'warning']);

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->call('toggleCommunityArchive', $community->id);

        $this->assertFalse($community->fresh()->isArchived());
    }

    public function test_non_admins_cannot_open_the_console(): void
    {
        $member = $this->createUser('plain-member');

        Livewire::actingAs($member)
            ->test('⚡admin-dashboard')
            ->assertForbidden();

        $this->actingAs($member)->get('/admin')->assertRedirect(route('gallery'));
    }

    private function createUser(string $username, bool $isAdmin = false): User
    {
        return User::factory()->create([
            'username' => $username,
            'email' => $username.'@example.test',
            'avatar_url' => 'https://example.test/avatar.png',
            'is_admin' => $isAdmin,
        ]);
    }

    private function createPost(User $owner, string $title): Post
    {
        $post = Post::create([
            'user_id' => $owner->id,
            'title' => $title,
            'description' => 'A post used for isolated feature testing.',
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
            'height' => 800,
            'aspect_ratio' => 1.5,
        ]);

        return $post;
    }
}
