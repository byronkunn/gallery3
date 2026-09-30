<?php

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\TagAlias;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TagWikiSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_tag_name_normalization(): void
    {
        $this->assertEquals('big_butt', Tag::normalizeName('Big Butt'));
        $this->assertEquals('huge_thighs_2', Tag::normalizeName(' huge__thighs_2- '));
        $this->assertEquals('scenery', Tag::normalizeName('ScenErY'));
    }

    public function test_tags_hub_page_loads_successfully(): void
    {
        Tag::create([
            'name' => 'landscape',
            'slug' => 'landscape',
            'type' => 'general',
            'short_description' => 'Scenery images',
            'posts_count' => 10,
        ]);

        $response = $this->get('/tags');

        $response->assertStatus(200);
        $response->assertSee('Unified Tags');
        $response->assertSee('#landscape');
    }

    public function test_can_search_tags_and_filter_by_category(): void
    {
        Tag::create(['name' => 'frieren', 'slug' => 'frieren', 'type' => 'character']);
        Tag::create(['name' => 'landscape', 'slug' => 'landscape', 'type' => 'general']);

        Livewire::test('tags-hub')
            ->set('search', 'frieren')
            ->assertSee('#frieren')
            ->assertDontSee('#landscape')
            ->set('search', '')
            ->set('categoryFilter', 'character')
            ->assertSee('#frieren')
            ->assertDontSee('#landscape');
    }

    public function test_authenticated_user_can_create_new_tag(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('tags-hub')
            ->call('openCreateModal', 'huge_thighs_2')
            ->set('createTagCategory', 'general')
            ->set('createTagShortDescription', 'Thick thighs tag')
            ->call('createTag');

        $this->assertDatabaseHas('tags', [
            'name' => 'huge_thighs_2',
            'type' => 'general',
            'short_description' => 'Thick thighs tag',
        ]);

        $this->assertDatabaseHas('tag_histories', [
            'action' => 'created',
            'user_id' => $user->id,
        ]);
    }

    public function test_tag_detail_page_loads_with_tabs(): void
    {
        Tag::create([
            'name' => 'landscape',
            'slug' => 'landscape',
            'type' => 'general',
            'short_description' => 'Scenery images',
            'wiki_summary' => 'Landscape scenery depicts nature and environment.',
        ]);

        $response = $this->get('/tags/landscape');
        $response->assertStatus(200);
        $response->assertSee('#landscape');
        $response->assertSee('Documentation');
    }

    public function test_alias_redirects_to_canonical_tag_page(): void
    {
        $tag = Tag::create(['name' => 'landscape', 'slug' => 'landscape', 'type' => 'general']);
        TagAlias::create(['alias' => 'landscapes', 'tag_id' => $tag->id]);

        $response = $this->get('/tags/landscapes');
        $response->assertRedirect('/tags/landscape');
    }

    public function test_can_edit_tag_wiki_and_record_history(): void
    {
        $user = User::factory()->create();
        $tag = Tag::create(['name' => 'landscape', 'slug' => 'landscape', 'type' => 'general']);

        Livewire::actingAs($user)
            ->test('tag-wiki-editor', ['name' => 'landscape'])
            ->set('wikiSummary', 'Updated summary for landscape tag')
            ->set('wikiUsage', 'Use when mountains or sky are featured')
            ->set('editSummary', 'Added detailed usage rules')
            ->call('saveWiki');

        $this->assertDatabaseHas('tags', [
            'name' => 'landscape',
            'wiki_summary' => 'Updated summary for landscape tag',
            'wiki_usage' => 'Use when mountains or sky are featured',
        ]);

        $this->assertDatabaseHas('tag_histories', [
            'tag_id' => $tag->id,
            'user_id' => $user->id,
            'action' => 'wiki_edit',
            'edit_summary' => 'Added detailed usage rules',
        ]);
    }

    public function test_user_can_follow_and_mute_tags(): void
    {
        $user = User::factory()->create();
        $tag = Tag::create(['name' => 'landscape', 'slug' => 'landscape', 'type' => 'general']);

        Livewire::actingAs($user)
            ->test('tag-detail', ['name' => 'landscape'])
            ->call('toggleFollow');

        $this->assertTrue($user->isFollowingTag($tag));

        Livewire::actingAs($user)
            ->test('tag-detail', ['name' => 'landscape'])
            ->call('toggleMute');

        $this->assertFalse($user->isFollowingTag($tag));
        $this->assertTrue($user->mutedTags()->where('tag_id', $tag->id)->exists());
    }
}
