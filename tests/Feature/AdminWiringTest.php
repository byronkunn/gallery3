<?php

namespace Tests\Feature;

use App\Models\Community;
use App\Models\User;
use App\Support\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class AdminWiringTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        SiteSettings::flush();

        parent::tearDown();
    }

    public function test_user_drawer_can_require_and_restore_email_verification(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create();

        Livewire::actingAs($admin)->test('⚡admin-dashboard')
            ->call('openUserDrawer', $member->id)
            ->call('requireEmailReverification', $member->id);

        $this->assertNull($member->fresh()->email_verified_at);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'user.reverification_required', 'target_id' => $member->id,
        ]);

        Livewire::actingAs($admin)->test('⚡admin-dashboard')
            ->call('markEmailVerified', $member->id);

        $this->assertNotNull($member->fresh()->email_verified_at);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'user.email_verified', 'target_id' => $member->id,
        ]);
    }

    public function test_community_recovery_uses_the_saved_inactivity_threshold(): void
    {
        $this->travelTo('2026-09-29 12:00:00');
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create(['last_active_at' => now()->subDays(45)]);
        $member = User::factory()->create();
        $community = Community::create([
            'owner_id' => $owner->id, 'name' => 'Recoverable Studio', 'slug' => 'recoverable-studio',
        ]);
        DB::table('community_members')->insert([
            'community_id' => $community->id, 'user_id' => $member->id,
            'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        SiteSettings::set('site_inactive_owner_days', 30);
        Cache::forget('site_inactive_owner_days');

        Livewire::actingAs($admin)->test('⚡admin-dashboard')
            ->call('setSection', 'recovery')
            ->assertSee('Recoverable Studio')
            ->call('transferCommunityOwnership', $community->id, $member->id)
            ->assertHasNoErrors();

        $this->assertSame($member->id, $community->fresh()->owner_id);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'community.owner_transferred', 'target_id' => $community->id,
        ]);
        $this->travelBack();
    }

    public function test_admin_settings_persist_feature_controls(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('⚡admin-dashboard')
            ->set('allowRegistrations', false)
            ->set('spamControlsEnabled', false)
            ->set('messageLimitPerMinute', 12)
            ->set('inactiveOwnerDays', 45)
            ->call('saveSettings')
            ->assertHasNoErrors();

        Cache::forget('site_registrations');
        Cache::forget('site_spam_controls_enabled');
        Cache::forget('site_spam_messages_limit');
        Cache::forget('site_inactive_owner_days');
        SiteSettings::flush();
        $this->assertFalse(SiteSettings::bool('site_registrations'));
        $this->assertFalse(SiteSettings::bool('site_spam_controls_enabled'));
        $this->assertSame(12, SiteSettings::int('site_spam_messages_limit'));
        $this->assertSame(45, SiteSettings::int('site_inactive_owner_days'));
    }

    public function test_admin_can_accept_a_pending_appeal_and_restore_account_access(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create(['is_banned' => true, 'suspension_reason' => 'Spam']);
        $appealId = DB::table('user_appeals')->insertGetId([
            'user_id' => $member->id,
            'statement' => 'Please reconsider this decision.',
            'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Livewire::actingAs($admin)->test('⚡admin-dashboard')
            ->call('setSection', 'appeals')
            ->assertSee('Please reconsider this decision.')
            ->set('appealResponse', 'Your appeal has been accepted.')
            ->call('reviewAppeal', $appealId, 'accept')
            ->assertHasNoErrors();

        $this->assertFalse($member->fresh()->is_banned);
        $this->assertDatabaseHas('user_appeals', [
            'id' => $appealId, 'status' => 'accepted', 'reviewer_id' => $admin->id,
            'response' => 'Your appeal has been accepted.',
        ]);
    }

    public function test_admin_can_save_community_rules_from_the_community_list(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();
        $community = Community::create([
            'owner_id' => $owner->id, 'name' => 'Studio', 'slug' => 'studio',
        ]);

        Livewire::actingAs($admin)->test('⚡admin-dashboard')
            ->call('setSection', 'communities')
            ->set('communityRulesDrafts.'.$community->id, 'Be respectful of other artists.')
            ->call('saveCommunityDetails', $community->id)
            ->assertHasNoErrors();

        $this->assertSame('Be respectful of other artists.', $community->fresh()->rules);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'community.rules_updated', 'target_id' => $community->id,
        ]);
    }
}
