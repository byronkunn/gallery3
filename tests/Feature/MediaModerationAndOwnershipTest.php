<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class MediaModerationAndOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploaded_image_is_stored_with_its_post(): void
    {
        Storage::fake('public');
        $owner = $this->createUser('upload-owner');
        $image = UploadedFile::fake()->image('gallery-upload.jpg', 30, 20);

        Livewire::actingAs($owner)
            ->test('⚡upload-view')
            ->set('title', 'Uploaded gallery image')
            ->set('imageUploads', [$image])
            ->call('submitPost')
            ->assertHasNoErrors();

        $post = Post::where('title', 'Uploaded gallery image')->firstOrFail();
        $media = PostMedia::where('post_id', $post->id)->firstOrFail();
        $this->assertSame('/storage/posts/'.basename(parse_url($media->url, PHP_URL_PATH)), $media->url);
        Storage::disk('public')->assertExists('posts/'.basename($media->url));
    }

    public function test_unsupported_upload_is_rejected_without_creating_a_post(): void
    {
        $owner = $this->createUser('invalid-upload-owner');
        $file = UploadedFile::fake()->create('not-an-image.txt', 1, 'text/plain');

        Livewire::actingAs($owner)
            ->test('⚡upload-view')
            ->set('title', 'Invalid upload')
            ->set('imageUploads', [$file])
            ->call('submitPost')
            ->assertHasErrors('imageUploads.0');

        $this->assertDatabaseMissing('posts', ['title' => 'Invalid upload']);
    }

    public function test_profile_avatar_upload_updates_the_profile(): void
    {
        Storage::fake('public');
        $owner = $this->createUser('avatar-owner');
        $image = UploadedFile::fake()->image('avatar.png', 40, 40);

        Livewire::actingAs($owner)
            ->test('⚡profile-view', ['username' => $owner->username])
            ->set('avatarUpload', $image)
            ->call('saveProfile')
            ->assertHasNoErrors();

        $owner->refresh();
        $this->assertStringStartsWith('/storage/avatars/', $owner->avatar_url);
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $owner->avatar_url));
    }

    public function test_owner_can_edit_and_delete_a_post(): void
    {
        $owner = $this->createUser('post-owner');
        $post = $this->createPost($owner, 'Original title');

        Livewire::actingAs($owner)
            ->test('⚡post-detail', ['postId' => $post->id])
            ->set('editTitle', 'Updated title')
            ->set('editDescription', 'Updated description')
            ->set('editSourceUrl', 'https://example.test/source')
            ->call('savePost')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('posts', [
            'id' => $post->id,
            'title' => 'Updated title',
            'description' => 'Updated description',
            'source_url' => 'https://example.test/source',
        ]);

        Livewire::actingAs($owner)
            ->test('⚡post-detail', ['postId' => $post->id])
            ->call('deletePost')
            ->assertRedirect(route('gallery'));

        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
    }

    public function test_non_owner_cannot_edit_a_post(): void
    {
        $owner = $this->createUser('owned-author');
        $otherUser = $this->createUser('other-member');
        $post = $this->createPost($owner, 'Owner title');

        Livewire::actingAs($otherUser)
            ->test('⚡post-detail', ['postId' => $post->id])
            ->set('editTitle', 'Attempted change')
            ->call('savePost')
            ->assertForbidden();

        Livewire::actingAs($otherUser)
            ->test('⚡post-detail', ['postId' => $post->id])
            ->call('deletePost')
            ->assertForbidden();

        $this->assertDatabaseHas('posts', ['id' => $post->id, 'title' => 'Owner title']);
    }

    public function test_post_report_reaches_admin_queue_and_can_be_dismissed(): void
    {
        $reporter = $this->createUser('reporting-member');
        $owner = $this->createUser('reported-author');
        $admin = $this->createUser('report-reviewer', true);
        $post = $this->createPost($owner, 'Reported title');

        Livewire::actingAs($reporter)
            ->test('⚡post-detail', ['postId' => $post->id])
            ->set('reportReason', 'spam')
            ->set('reportDetails', 'This appears to be spam.')
            ->call('submitReport')
            ->assertHasNoErrors();

        $reportId = DB::table('content_reports')->value('id');
        $this->assertDatabaseHas('content_reports', [
            'id' => $reportId,
            'reporter_id' => $reporter->id,
            'target_type' => 'post',
            'target_id' => $post->id,
            'status' => 'pending',
        ]);

        Livewire::actingAs($reporter)
            ->test('⚡post-detail', ['postId' => $post->id])
            ->set('reportReason', 'spam')
            ->call('submitReport');
        $this->assertDatabaseCount('content_reports', 1);

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->call('setSection', 'reports')
            ->assertSee('Reported title')
            ->call('reviewContentReport', $reportId, 'dismiss')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('content_reports', [
            'id' => $reportId,
            'status' => 'dismissed',
            'reviewer_id' => $admin->id,
        ]);
    }

    public function test_admin_can_remove_a_reported_post_and_its_uploaded_file(): void
    {
        Storage::fake('public');
        $reporter = $this->createUser('removal-reporter');
        $owner = $this->createUser('removal-author');
        $admin = $this->createUser('removal-reviewer', true);
        $post = $this->createPost($owner, 'Reported media to remove');
        Storage::disk('public')->put('posts/reported-media.jpg', 'test image bytes');
        $media = $post->media()->firstOrFail();
        $media->update(['url' => '/storage/posts/reported-media.jpg']);
        $reportId = DB::table('content_reports')->insertGetId([
            'reporter_id' => $reporter->id,
            'target_type' => 'post',
            'target_id' => $post->id,
            'reason' => 'copyright',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->call('reviewContentReport', $reportId, 'remove')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
        $this->assertDatabaseHas('content_reports', ['id' => $reportId, 'status' => 'actioned']);
        Storage::disk('public')->assertMissing('posts/reported-media.jpg');
    }

    public function test_blocking_an_author_hides_posts_and_prevents_direct_messages(): void
    {
        $viewer = $this->createUser('blocking-member');
        $author = $this->createUser('blocked-author');
        $post = $this->createPost($author, 'Hidden after block');
        $conversation = Conversation::create([
            'user_one_id' => min($viewer->id, $author->id),
            'user_two_id' => max($viewer->id, $author->id),
        ]);

        Livewire::actingAs($viewer)
            ->test('⚡post-detail', ['postId' => $post->id])
            ->call('toggleBlockAuthor')
            ->assertRedirect(route('gallery'));

        $this->assertDatabaseHas('user_blocks', [
            'blocker_id' => $viewer->id,
            'blocked_id' => $author->id,
        ]);
        Livewire::actingAs($viewer)
            ->test('⚡gallery-feed')
            ->assertDontSee('Hidden after block');
        $this->actingAs($viewer)->get('/post/'.$post->id)->assertNotFound();

        Livewire::actingAs($viewer)
            ->test('⚡messages-view', ['conversationId' => $conversation->id])
            ->assertNotFound();

        Livewire::actingAs($viewer)
            ->test('⚡settings-view')
            ->call('unblockUser', $author->id)
            ->assertHasNoErrors();
        $this->assertDatabaseMissing('user_blocks', [
            'blocker_id' => $viewer->id,
            'blocked_id' => $author->id,
        ]);
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
