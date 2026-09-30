<?php

namespace Tests\Feature;

use App\Models\AdminUserNote;
use App\Models\Comment;
use App\Models\Community;
use App\Models\Pool;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AdminActionWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_role_buttons_and_suspension_form_change_the_account(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create();

        Livewire::actingAs($admin)->test('⚡admin-dashboard')
            ->call('toggleUserArtist', $member->id)
            ->call('toggleUserAdmin', $member->id);

        $this->assertTrue($member->fresh()->is_artist);
        $this->assertTrue($member->fresh()->is_admin);

        Livewire::actingAs($admin)->test('⚡admin-dashboard')
            ->call('toggleUserAdmin', $member->id)
            ->call('prepareSuspension', $member->id)
            ->set('suspensionReason', 'Repeated spam in the gallery')
            ->set('suspensionDuration', 'day')
            ->call('suspendUser')
            ->assertHasNoErrors();

        $member->refresh();
        $this->assertFalse($member->is_admin);
        $this->assertTrue($member->is_banned);
        $this->assertNotNull($member->suspended_until);
        $this->assertSame('Repeated spam in the gallery', $member->suspension_reason);
    }

    public function test_post_comment_and_pool_controls_update_their_records(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $author = User::factory()->create();
        $post = $this->createPost($author);
        $comment = Comment::create(['post_id' => $post->id, 'user_id' => $author->id, 'content' => 'A comment']);
        $pool = Pool::create(['user_id' => $author->id, 'title' => 'A series']);

        Livewire::actingAs($admin)->test('⚡admin-dashboard')
            ->call('toggleSelectAllPosts')
            ->assertSet('selectedPostIds', [$post->id])
            ->call('openInspectModal', $post->id)
            ->assertSet('inspectPostId', $post->id)
            ->call('closeInspectModal')
            ->assertSet('inspectPostId', null)
            ->call('togglePostNsfw', $post->id)
            ->call('togglePostCommentsLock', $post->id)
            ->call('toggleCommentFlag', $comment->id)
            ->call('togglePoolLock', $pool->id);

        $this->assertTrue($post->fresh()->is_nsfw);
        $this->assertTrue($post->fresh()->comments_locked);
        $this->assertTrue($comment->fresh()->is_flagged);
        $this->assertTrue($pool->fresh()->is_locked);
    }

    public function test_remove_buttons_soft_delete_content_and_delete_a_community(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $author = User::factory()->create();
        $post = $this->createPost($author);
        $comment = Comment::create(['post_id' => $post->id, 'user_id' => $author->id, 'content' => 'Remove this comment']);
        $pool = Pool::create(['user_id' => $author->id, 'title' => 'Remove this series']);
        $community = Community::create(['owner_id' => $author->id, 'name' => 'Remove this server', 'slug' => 'remove-this-server']);

        Livewire::actingAs($admin)->test('⚡admin-dashboard')
            ->call('deleteComment', $comment->id)
            ->call('deletePool', $pool->id)
            ->call('deleteCommunity', $community->id)
            ->assertHasNoErrors();

        $this->assertSoftDeleted($comment);
        $this->assertSoftDeleted($pool);
        $this->assertDatabaseMissing('communities', ['id' => $community->id]);
        $this->assertDatabaseHas('posts', ['id' => $post->id]);
    }

    public function test_alias_and_user_note_remove_buttons_delete_the_selected_rows(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create();
        $tag = Tag::create(['name' => 'sunset', 'slug' => 'sunset', 'type' => 'general']);

        Livewire::actingAs($admin)->test('⚡admin-dashboard')
            ->set('aliasFrom', 'evening light')
            ->set('aliasTo', $tag->name)
            ->call('addTagAlias')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('tag_aliases', ['alias' => 'evening_light', 'tag_id' => $tag->id]);
        $aliasId = (int) DB::table('tag_aliases')->where('alias', 'evening_light')->value('id');

        Livewire::actingAs($admin)->test('⚡admin-dashboard')
            ->call('deleteTagAlias', $aliasId)
            ->call('openUserDrawer', $member->id)
            ->set('userNoteText', 'Follow up with this member.')
            ->call('addUserNote');

        $note = AdminUserNote::where('user_id', $member->id)->firstOrFail();
        Livewire::actingAs($admin)->test('⚡admin-dashboard')
            ->call('deleteUserNote', $note->id)
            ->call('closeUserDrawer')
            ->assertSet('selectedUserId', null);

        $this->assertDatabaseMissing('tag_aliases', ['id' => $aliasId]);
        $this->assertDatabaseMissing('admin_user_notes', ['id' => $note->id]);
    }

    public function test_thumbnail_buttons_generate_previews_for_uploaded_media(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_admin' => true]);
        $author = User::factory()->create();
        $post = $this->createPost($author);
        $media = $post->media()->firstOrFail();
        $media->update(['url' => '/storage/posts/sample.png', 'thumbnail_url' => null]);
        Storage::disk('public')->put('posts/sample.png', UploadedFile::fake()->image('sample.png', 30, 20)->get());

        Livewire::actingAs($admin)->test('⚡admin-dashboard')
            ->call('regenerateThumbnail', $media->id)
            ->assertHasNoErrors();

        $thumbnailUrl = $media->fresh()->thumbnail_url;
        $this->assertNotNull($thumbnailUrl);
        Storage::disk('public')->assertExists(ltrim(str_replace('/storage/', '', $thumbnailUrl), '/'));

        $media->update(['thumbnail_url' => null]);
        Livewire::actingAs($admin)->test('⚡admin-dashboard')
            ->call('regenerateMissingThumbnails')
            ->assertHasNoErrors();

        $this->assertNotNull($media->fresh()->thumbnail_url);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'media.thumbnails_regenerated']);
    }

    private function createPost(User $owner): Post
    {
        $post = Post::create([
            'user_id' => $owner->id, 'title' => 'Admin test artwork', 'media_type' => 'image', 'media_count' => 1,
        ]);
        PostMedia::create([
            'post_id' => $post->id, 'url' => '/sfw/image/sample_fd6cf1c5b5dda7658bf8b050ebb8240f.jpg', 'order' => 0,
        ]);

        return $post;
    }
}
