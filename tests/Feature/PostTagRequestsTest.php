<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class PostTagRequestsTest extends TestCase
{
    use RefreshDatabase;

    private const SFW_IMAGE = '/sfw/image/sample_fd6cf1c5b5dda7658bf8b050ebb8240f.jpg';

    public function test_member_tagging_request_waits_for_approval_before_adding_a_tag(): void
    {
        $owner = User::factory()->create();
        $requester = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $post = $this->createPost($owner);

        Livewire::actingAs($requester)->test('⚡post-detail', ['postId' => $post->id])
            ->set('tagToAdd', 'Golden Hour')
            ->set('tagRequestReason', 'The image shows sunset light')
            ->call('requestTagChange', 'add')
            ->assertHasNoErrors();

        $proposal = DB::table('post_tag_proposals')->where('post_id', $post->id)->first();
        $this->assertNotNull($proposal);
        $this->assertSame('golden_hour', $proposal->tag_name);
        $this->assertSame('pending', $proposal->status);
        $this->assertSame($requester->id, $proposal->requester_id);
        $this->assertSame('The image shows sunset light', $proposal->reason);
        $this->assertDatabaseMissing('tags', ['name' => 'golden_hour']);

        Livewire::actingAs($admin)->test('⚡admin-dashboard')
            ->call('reviewTagProposal', $proposal->id, 'approve');

        $tag = Tag::where('name', 'golden_hour')->firstOrFail();
        $this->assertDatabaseHas('post_tag', ['post_id' => $post->id, 'tag_id' => $tag->id]);
        $this->assertSame(1, $tag->posts_count);
        $this->assertDatabaseHas('post_tag_proposals', [
            'id' => $proposal->id, 'status' => 'approved', 'reviewer_id' => $admin->id,
        ]);
    }

    public function test_member_removal_request_waits_for_approval_before_detaching_a_tag(): void
    {
        $owner = User::factory()->create();
        $requester = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $post = $this->createPost($owner);
        $tag = Tag::create(['name' => 'sunset', 'slug' => 'sunset', 'type' => 'general', 'posts_count' => 1]);
        $post->tags()->attach($tag->id);

        Livewire::actingAs($requester)->test('⚡post-detail', ['postId' => $post->id])
            ->set('tagToRemove', $tag->id)
            ->set('tagRequestReason', 'This is a sunrise')
            ->call('requestTagChange', 'remove')
            ->assertHasNoErrors();

        $proposal = DB::table('post_tag_proposals')->where('post_id', $post->id)->first();
        $this->assertNotNull($proposal);
        $this->assertSame('remove', $proposal->action);
        $this->assertSame($tag->id, $proposal->tag_id);
        $this->assertSame('pending', $proposal->status);
        $this->assertDatabaseHas('post_tag', ['post_id' => $post->id, 'tag_id' => $tag->id]);

        Livewire::actingAs($admin)->test('⚡admin-dashboard')
            ->call('reviewTagProposal', $proposal->id, 'approve');

        $this->assertDatabaseMissing('post_tag', ['post_id' => $post->id, 'tag_id' => $tag->id]);
        $this->assertSame(0, $tag->fresh()->posts_count);
        $this->assertDatabaseHas('post_tag_proposals', [
            'id' => $proposal->id, 'status' => 'approved', 'reviewer_id' => $admin->id,
        ]);
    }

    public function test_rejected_tagging_request_does_not_change_post_tags(): void
    {
        $owner = User::factory()->create();
        $requester = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $post = $this->createPost($owner);

        Livewire::actingAs($requester)->test('⚡post-detail', ['postId' => $post->id])
            ->set('tagToAdd', 'unrelated')
            ->call('requestTagChange', 'add');
        $proposalId = DB::table('post_tag_proposals')->where('post_id', $post->id)->value('id');

        Livewire::actingAs($admin)->test('⚡admin-dashboard')
            ->set('proposalReviewNote', 'The tag does not describe this image')
            ->call('reviewTagProposal', $proposalId, 'reject');

        $this->assertDatabaseHas('post_tag_proposals', [
            'id' => $proposalId, 'status' => 'rejected', 'reviewer_id' => $admin->id,
            'review_note' => 'The tag does not describe this image',
        ]);
        $this->assertDatabaseCount('post_tag', 0);
        $this->assertDatabaseMissing('tags', ['name' => 'unrelated']);
    }

    public function test_rejected_removal_request_keeps_the_tag_on_the_post(): void
    {
        $owner = User::factory()->create();
        $requester = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $post = $this->createPost($owner);
        $tag = Tag::create(['name' => 'sunset', 'slug' => 'sunset', 'type' => 'general', 'posts_count' => 1]);
        $post->tags()->attach($tag->id);

        Livewire::actingAs($requester)->test('⚡post-detail', ['postId' => $post->id])
            ->set('tagToRemove', $tag->id)
            ->call('requestTagChange', 'remove');
        $proposalId = DB::table('post_tag_proposals')->where('post_id', $post->id)->value('id');

        Livewire::actingAs($admin)->test('⚡admin-dashboard')
            ->call('reviewTagProposal', $proposalId, 'reject');

        $this->assertDatabaseHas('post_tag_proposals', ['id' => $proposalId, 'status' => 'rejected']);
        $this->assertDatabaseHas('post_tag', ['post_id' => $post->id, 'tag_id' => $tag->id]);
        $this->assertSame(1, $tag->fresh()->posts_count);
    }

    public function test_non_admin_cannot_review_a_tag_request(): void
    {
        $owner = User::factory()->create();
        $requester = User::factory()->create();
        $post = $this->createPost($owner);

        Livewire::actingAs($requester)->test('⚡post-detail', ['postId' => $post->id])
            ->set('tagToAdd', 'sunset')
            ->call('requestTagChange', 'add');
        $proposalId = DB::table('post_tag_proposals')->where('post_id', $post->id)->value('id');

        Livewire::actingAs($requester)->test('⚡admin-dashboard')
            ->assertForbidden();

        $this->assertDatabaseHas('post_tag_proposals', ['id' => $proposalId, 'status' => 'pending']);
        $this->assertDatabaseMissing('tags', ['name' => 'sunset']);
    }

    public function test_owner_tag_changes_apply_without_a_moderation_request(): void
    {
        $owner = User::factory()->create();
        $post = $this->createPost($owner);

        Livewire::actingAs($owner)->test('⚡post-detail', ['postId' => $post->id])
            ->set('tagToAdd', 'Own Artwork')
            ->call('requestTagChange', 'add')
            ->assertHasNoErrors();

        $tag = Tag::where('name', 'own_artwork')->firstOrFail();
        $this->assertDatabaseHas('post_tag', ['post_id' => $post->id, 'tag_id' => $tag->id]);
        $this->assertSame(1, $tag->posts_count);
        $this->assertDatabaseCount('post_tag_proposals', 0);

        Livewire::actingAs($owner)->test('⚡post-detail', ['postId' => $post->id])
            ->set('tagToRemove', $tag->id)
            ->call('requestTagChange', 'remove')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('post_tag', ['post_id' => $post->id, 'tag_id' => $tag->id]);
        $this->assertSame(0, $tag->fresh()->posts_count);
        $this->assertDatabaseCount('post_tag_proposals', 0);
    }

    public function test_invalid_tagging_or_removal_request_creates_no_proposal(): void
    {
        $owner = User::factory()->create();
        $requester = User::factory()->create();
        $post = $this->createPost($owner);
        $otherTag = Tag::create(['name' => 'elsewhere', 'slug' => 'elsewhere', 'type' => 'general']);

        Livewire::actingAs($requester)->test('⚡post-detail', ['postId' => $post->id])
            ->set('tagToAdd', '<script>')
            ->call('requestTagChange', 'add')
            ->assertHasErrors('tagToAdd');
        Livewire::actingAs($requester)->test('⚡post-detail', ['postId' => $post->id])
            ->set('tagToRemove', $otherTag->id)
            ->call('requestTagChange', 'remove')
            ->assertNotFound();

        $this->assertDatabaseCount('post_tag_proposals', 0);
        $this->assertDatabaseCount('post_tag', 0);
    }

    private function createPost(User $owner): Post
    {
        $this->assertFileExists(public_path(ltrim(self::SFW_IMAGE, '/')));
        $post = Post::create([
            'user_id' => $owner->id,
            'title' => 'SFW artwork',
            'media_type' => 'image',
            'media_count' => 1,
        ]);
        PostMedia::create(['post_id' => $post->id, 'url' => self::SFW_IMAGE, 'order' => 0]);

        return $post;
    }
}
