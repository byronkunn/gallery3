<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class PostCreatorAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_upload_keeps_the_uploader_as_original_creator(): void
    {
        $uploader = User::factory()->create();

        Livewire::actingAs($uploader)->test('⚡upload-view')
            ->set('title', 'My own painting')
            ->set('imageUploads', [UploadedFile::fake()->image('painting.jpg')])
            ->set('isOriginalCreator', true)
            ->set('artistName', 'Incorrect attribution')
            ->set('artistUrl', 'https://example.test/incorrect')
            ->call('submitPost')
            ->assertHasNoErrors();

        $post = Post::where('title', 'My own painting')->firstOrFail();
        $this->assertTrue($post->is_original_creator);
        $this->assertNull($post->artist_name);
        $this->assertNull($post->artist_url);
        $this->assertDatabaseHas('post_media', ['post_id' => $post->id]);
        $this->get(route('post.detail', $post->id))->assertDontSee('Original Artist Attribution');
    }

    public function test_upload_by_someone_else_credits_the_named_artist(): void
    {
        $uploader = User::factory()->create();

        Livewire::actingAs($uploader)->test('⚡upload-view')
            ->set('title', 'Shared painting')
            ->set('imageUploads', [UploadedFile::fake()->image('painting.jpg')])
            ->set('isOriginalCreator', false)
            ->set('artistName', '  Another Artist  ')
            ->set('artistUrl', '  https://example.test/artist  ')
            ->call('submitPost')
            ->assertHasNoErrors();

        $post = Post::where('title', 'Shared painting')->firstOrFail();
        $this->assertFalse($post->is_original_creator);
        $this->assertSame('Another Artist', $post->artist_name);
        $this->assertSame('https://example.test/artist', $post->artist_url);
        $this->assertDatabaseHas('post_media', ['post_id' => $post->id]);
        $this->get(route('post.detail', $post->id))
            ->assertSee('Original Artist Attribution')
            ->assertSee('Another Artist')
            ->assertSee('https://example.test/artist');
    }
}
