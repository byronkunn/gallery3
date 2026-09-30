<?php

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\TagHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TagWikiBoundariesTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_tag_keeps_its_wiki_summary_and_alias(): void
    {
        $editor = User::factory()->create();

        Livewire::actingAs($editor)->test('tags-hub')
            ->call('openCreateModal', 'Golden Hour')
            ->set('createTagCategory', 'general')
            ->set('createTagWikiSummary', 'Warm light shortly before sunset.')
            ->set('newAliasInput', 'Sunset Light')
            ->call('addAlias')
            ->call('createTag');

        $tag = Tag::where('name', 'golden_hour')->firstOrFail();
        $this->assertSame('Warm light shortly before sunset.', $tag->wiki_summary);
        $this->assertDatabaseHas('tag_aliases', ['tag_id' => $tag->id, 'alias' => 'sunset_light']);
        $this->get(route('tags.show', 'sunset_light'))
            ->assertRedirect(route('tags.show', 'golden_hour'));
    }

    public function test_wiki_edit_without_an_edit_summary_changes_nothing(): void
    {
        $editor = User::factory()->create();
        $tag = Tag::create([
            'name' => 'golden_hour', 'slug' => 'golden_hour', 'type' => 'general',
            'wiki_summary' => 'Original description.',
        ]);

        Livewire::actingAs($editor)->test('tag-wiki-editor', ['name' => $tag->name])
            ->set('wikiSummary', 'Replacement description.')
            ->call('saveWiki')
            ->assertSet('message', 'Please provide a brief edit summary explaining your changes.');

        $this->assertSame('Original description.', $tag->fresh()->wiki_summary);
        $this->assertDatabaseCount('tag_histories', 0);
    }

    public function test_locked_wiki_rejects_member_edit_without_a_history_entry(): void
    {
        $editor = User::factory()->create();
        $tag = Tag::create([
            'name' => 'golden_hour', 'slug' => 'golden_hour', 'type' => 'general',
            'wiki_summary' => 'Original description.', 'is_locked' => true,
        ]);

        Livewire::actingAs($editor)->test('tag-wiki-editor', ['name' => $tag->name])
            ->set('wikiSummary', 'Replacement description.')
            ->set('editSummary', 'Corrected the description')
            ->call('saveWiki')
            ->assertSet('message', 'This tag wiki is locked and cannot be edited directly.');

        $this->assertSame('Original description.', $tag->fresh()->wiki_summary);
        $this->assertDatabaseCount('tag_histories', 0);
    }

    public function test_admin_can_update_a_locked_wiki_and_records_the_change(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $tag = Tag::create([
            'name' => 'golden_hour', 'slug' => 'golden_hour', 'type' => 'general',
            'wiki_summary' => 'Original description.', 'is_locked' => true,
        ]);

        Livewire::actingAs($admin)->test('tag-wiki-editor', ['name' => $tag->name])
            ->set('wikiSummary', 'Updated by the admin.')
            ->set('editSummary', 'Clarified the definition')
            ->call('saveWiki')
            ->assertRedirect(route('tags.show', ['name' => $tag->name, 'tab' => 'wiki']));

        $this->assertSame('Updated by the admin.', $tag->fresh()->wiki_summary);
        $history = TagHistory::where('tag_id', $tag->id)->firstOrFail();
        $this->assertSame($admin->id, $history->user_id);
        $this->assertSame('Original description.', $history->old_wiki['wiki_summary']);
        $this->assertSame('Updated by the admin.', $history->new_wiki['wiki_summary']);
    }
}
