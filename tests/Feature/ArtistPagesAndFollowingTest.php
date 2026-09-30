<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ArtistPagesAndFollowingTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(): User
    {
        return User::factory()->create(['username' => 'user_'.Str::random(6)]);
    }

    public function test_artist_directory_loads_and_lists_artists(): void
    {
        $artist = Artist::create([
            'name' => 'JaneArt',
            'slug' => 'janeart',
            'works_count' => 12,
            'followers_count' => 5,
        ]);

        $response = $this->get('/artists');
        $response->assertStatus(200);
        $response->assertSee('Artist Directory');
        $response->assertSee('JaneArt');
    }

    public function test_artist_detail_page_loads_works_and_alias_resolution(): void
    {
        $artist = Artist::create([
            'name' => 'MasterJane',
            'slug' => 'masterjane',
            'bio' => 'Sample artist bio',
        ]);

        ArtistAlias::create([
            'artist_id' => $artist->id,
            'alias' => 'Jane_Pixiv',
            'slug' => 'jane_pixiv',
        ]);

        $uploader = $this->createUser();
        Post::create([
            'user_id' => $uploader->id,
            'artist_id' => $artist->id,
            'artist_name' => $artist->name,
            'title' => 'Jane Masterpiece',
            'media_type' => 'image',
            'media_count' => 1,
        ]);

        // Access via primary slug
        $response = $this->get('/artists/masterjane');
        $response->assertStatus(200);
        $response->assertSee('MasterJane');
        $response->assertSee('Jane Masterpiece');

        // Access via alias slug
        $aliasResponse = $this->get('/artists/jane_pixiv');
        $aliasResponse->assertStatus(200);
        $aliasResponse->assertSee('MasterJane');
    }

    public function test_user_can_follow_artist_and_suggest_links(): void
    {
        $user = $this->createUser();
        $artist = Artist::create([
            'name' => 'CyberArtist',
            'slug' => 'cyberartist',
        ]);

        // Test Livewire component follow & suggest link
        Livewire::actingAs($user)
            ->test('⚡artist-detail', ['slug' => $artist->slug])
            ->call('toggleFollowArtist')
            ->set('linkPlatform', 'pixiv')
            ->set('linkUrl', 'https://pixiv.net/users/99999')
            ->call('submitSuggestLink');

        $this->assertTrue($user->isFollowingArtist($artist));
        $this->assertDatabaseHas('artist_links', [
            'artist_id' => $artist->id,
            'platform' => 'pixiv',
            'url' => 'https://pixiv.net/users/99999',
            'submitted_by_user_id' => $user->id,
        ]);
    }

    public function test_user_can_submit_artist_claim_request(): void
    {
        $user = $this->createUser();
        $artist = Artist::create([
            'name' => 'UnclaimedCreator',
            'slug' => 'unclaimedcreator',
        ]);

        Livewire::actingAs($user)
            ->test('⚡artist-detail', ['slug' => $artist->slug])
            ->set('claimNotes', 'Placed code in my bio')
            ->call('submitClaimPage');

        $this->assertDatabaseHas('artist_claims', [
            'artist_id' => $artist->id,
            'user_id' => $user->id,
            'status' => 'pending',
            'notes' => 'Placed code in my bio',
        ]);
    }

    public function test_following_management_includes_artists_tab(): void
    {
        $user = $this->createUser();
        $artist = Artist::create([
            'name' => 'FollowedArtist',
            'slug' => 'followedartist',
        ]);
        $user->followingArtists()->attach($artist->id);

        Livewire::actingAs($user)
            ->test('⚡following-view')
            ->assertSee('FollowedArtist')
            ->call('unfollowArtist', $artist->id);

        $this->assertFalse($user->isFollowingArtist($artist));
    }
}
