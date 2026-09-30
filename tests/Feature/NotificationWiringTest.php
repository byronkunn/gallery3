<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Conversation;
use App\Models\Pool;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use App\Support\ContentReports;
use App\Support\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class NotificationWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_liking_a_post_notifies_the_author_once_per_unread_notification(): void
    {
        $author = $this->createUser('like-author');
        $liker = $this->createUser('like-liker');
        $post = $this->createPost($author, 'Likeable artwork');

        Livewire::actingAs($liker)->test('⚡gallery-feed')->call('toggleLike', $post->id);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $author->id,
            'actor_id' => $liker->id,
            'type' => 'like',
            'notifiable_type' => Post::class,
            'notifiable_id' => $post->id,
        ]);

        Livewire::actingAs($liker)->test('⚡gallery-feed')->call('toggleLike', $post->id);
        Livewire::actingAs($liker)->test('⚡gallery-feed')->call('toggleLike', $post->id);

        $this->assertSame(1, $author->notifications()->where('type', 'like')->count());
    }

    public function test_liking_your_own_post_does_not_notify_you(): void
    {
        $author = $this->createUser('self-like-author');
        $post = $this->createPost($author, 'Own artwork');

        Livewire::actingAs($author)->test('⚡post-detail', ['postId' => $post->id])->call('toggleLike');

        $this->assertSame(0, $author->notifications()->count());
    }

    public function test_following_a_user_notifies_them(): void
    {
        $follower = $this->createUser('follow-follower');
        $artist = $this->createUser('follow-artist');

        Livewire::actingAs($follower)
            ->test('⚡profile-view', ['username' => $artist->username])
            ->call('toggleFollow');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $artist->id,
            'actor_id' => $follower->id,
            'type' => 'follow',
        ]);
    }

    public function test_commenting_notifies_the_post_author_with_the_comment_text(): void
    {
        $author = $this->createUser('comment-author');
        $commenter = $this->createUser('comment-commenter');
        $post = $this->createPost($author, 'Commented artwork');

        Livewire::actingAs($commenter)
            ->test('⚡post-detail', ['postId' => $post->id])
            ->set('commentText', 'This piece is wonderful.')
            ->call('addComment');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $author->id,
            'actor_id' => $commenter->id,
            'type' => 'comment',
        ]);
        $this->assertStringContainsString('This piece is wonderful.', (string) $author->notifications()->value('message'));
    }

    public function test_commenting_on_your_own_post_does_not_notify_you(): void
    {
        $author = $this->createUser('self-comment-author');
        $post = $this->createPost($author, 'Own commented artwork');

        Livewire::actingAs($author)
            ->test('⚡post-detail', ['postId' => $post->id])
            ->set('commentText', 'Adding my own note.')
            ->call('addComment');

        $this->assertSame(0, $author->notifications()->count());
    }

    public function test_adding_a_pool_chapter_notifies_followers_but_not_the_contributor(): void
    {
        $owner = $this->createUser('pool-owner');
        $follower = $this->createUser('pool-follower');
        $contributor = $this->createUser('pool-contributor');
        $pool = Pool::create(['user_id' => $owner->id, 'title' => 'Astral Blade', 'chapters_count' => 0]);
        $follower->followingPools()->attach($pool->id);
        $contributor->followingPools()->attach($pool->id);
        $chapterPost = $this->createPost($contributor, 'Chapter one artwork');

        Livewire::actingAs($contributor)
            ->test('⚡pool-detail', ['poolId' => $pool->id])
            ->set('chapterTitle', 'Chapter 1')
            ->set('chapterNumber', 1)
            ->set('selectedPostId', $chapterPost->id)
            ->call('addChapter');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $follower->id,
            'actor_id' => $contributor->id,
            'type' => 'pool_chapter',
            'notifiable_id' => $pool->id,
        ]);
        $this->assertSame(0, $contributor->notifications()->count());
    }

    public function test_following_a_collection_notifies_the_owner(): void
    {
        $owner = $this->createUser('collection-follow-owner');
        $follower = $this->createUser('collection-follower');
        $collection = Collection::create(['user_id' => $owner->id, 'title' => 'Neon Nights']);

        Livewire::actingAs($follower)
            ->test('⚡collection-detail', ['collectionId' => $collection->id])
            ->call('toggleFollow');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'actor_id' => $follower->id,
            'type' => 'collection_update',
            'notifiable_id' => $collection->id,
        ]);
    }

    public function test_sending_a_direct_message_notifies_the_other_participant(): void
    {
        $sender = $this->createUser('dm-notify-sender');
        $recipient = $this->createUser('dm-notify-recipient');
        $conversation = Conversation::create(['user_one_id' => $sender->id, 'user_two_id' => $recipient->id]);

        Livewire::actingAs($sender)
            ->test('⚡messages-view')
            ->call('selectConversation', $conversation->id)
            ->set('messageText', 'Are you free for a commission?')
            ->call('sendMessage');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $recipient->id,
            'actor_id' => $sender->id,
            'type' => 'message',
            'notifiable_id' => $conversation->id,
        ]);
    }

    public function test_a_member_can_report_a_comment_through_the_post_page(): void
    {
        $reporter = $this->createUser('report-commenter');
        $author = $this->createUser('report-comment-author');
        $post = $this->createPost($author, 'Reported comment post');
        $comment = $post->comments()->create(['user_id' => $author->id, 'content' => 'Reported comment']);

        Livewire::actingAs($reporter)
            ->test('⚡post-detail', ['postId' => $post->id])
            ->call('openReportModal', 'comment', $comment->id)
            ->set('reportReason', 'harassment')
            ->set('reportDetails', 'Personal attack.')
            ->call('submitReport')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('content_reports', [
            'reporter_id' => $reporter->id,
            'target_type' => 'comment',
            'target_id' => $comment->id,
            'reason' => 'harassment',
            'status' => 'pending',
        ]);
    }

    public function test_reports_cannot_target_your_own_content_or_be_filed_twice(): void
    {
        $author = $this->createUser('self-report-author');
        $post = $this->createPost($author, 'Own reportable post');

        $this->expectException(HttpException::class);

        ContentReports::file($author, 'post', $post->id, 'spam');
    }

    public function test_a_duplicate_pending_report_is_not_created(): void
    {
        $reporter = $this->createUser('duplicate-reporter');
        $author = $this->createUser('duplicate-author');
        $post = $this->createPost($author, 'Duplicate report post');

        $first = ContentReports::file($reporter, 'post', $post->id, 'spam');
        $second = ContentReports::file($reporter, 'post', $post->id, 'spam');

        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertSame(1, DB::table('content_reports')->count());
    }

    public function test_announcements_fan_out_to_every_account_including_the_sender(): void
    {
        $admin = User::factory()->create([
            'username' => 'announce-sender',
            'email' => 'announce-sender@example.test',
            'is_admin' => true,
        ]);
        $member = $this->createUser('announce-recipient');

        $sent = Notifier::announcement('Maintenance at 22:00 UTC', $admin);

        $this->assertSame(2, $sent);
        $this->assertDatabaseHas('notifications', ['user_id' => $member->id, 'type' => 'announcement', 'message' => 'Maintenance at 22:00 UTC']);
        $this->assertDatabaseHas('notifications', ['user_id' => $admin->id, 'type' => 'announcement']);
    }

    private function createUser(string $username): User
    {
        return User::factory()->create([
            'username' => $username,
            'email' => $username.'@example.test',
            'avatar_url' => 'https://example.test/avatar.png',
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
