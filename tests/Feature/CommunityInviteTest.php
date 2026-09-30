<?php

namespace Tests\Feature;

use App\Models\Community;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommunityInviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_invite_adds_a_member_and_redirects_to_the_community(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $community = Community::create(['owner_id' => $owner->id, 'name' => 'Sketch Club', 'slug' => 'sketch-club']);
        $inviteId = $this->createInvite($community, $owner, 'SKETCH');

        $this->actingAs($member)->get(route('lounge.invite', 'SKETCH'))
            ->assertRedirect(route('lounge.community', $community->slug));

        $this->assertDatabaseHas('community_members', [
            'community_id' => $community->id, 'user_id' => $member->id, 'status' => 'active',
        ]);
        $this->assertSame(1, $community->fresh()->member_count);
        $this->assertDatabaseHas('community_invites', ['id' => $inviteId, 'used_count' => 1]);
    }

    public function test_expired_invite_does_not_add_a_member(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $community = Community::create(['owner_id' => $owner->id, 'name' => 'Sketch Club', 'slug' => 'sketch-club']);
        $inviteId = $this->createInvite($community, $owner, 'EXPIRED', now()->subDay());

        $this->actingAs($member)->get(route('lounge.invite', 'EXPIRED'))->assertNotFound();

        $this->assertDatabaseCount('community_members', 0);
        $this->assertDatabaseHas('community_invites', ['id' => $inviteId, 'used_count' => 0]);
    }

    public function test_banned_member_cannot_rejoin_through_an_invite(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $community = Community::create(['owner_id' => $owner->id, 'name' => 'Sketch Club', 'slug' => 'sketch-club']);
        $inviteId = $this->createInvite($community, $owner, 'BANNED');
        DB::table('community_members')->insert([
            'community_id' => $community->id, 'user_id' => $member->id, 'status' => 'banned',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($member)->get(route('lounge.invite', 'BANNED'))->assertForbidden();

        $this->assertDatabaseHas('community_members', [
            'community_id' => $community->id, 'user_id' => $member->id, 'status' => 'banned',
        ]);
        $this->assertDatabaseHas('community_invites', ['id' => $inviteId, 'used_count' => 0]);
    }

    private function createInvite(Community $community, User $owner, string $code, ?\DateTimeInterface $expiresAt = null): int
    {
        return DB::table('community_invites')->insertGetId([
            'community_id' => $community->id,
            'created_by' => $owner->id,
            'code' => $code,
            'expires_at' => $expiresAt,
            'used_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
