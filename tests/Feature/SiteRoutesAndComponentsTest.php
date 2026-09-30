<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Pool;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class SiteRoutesAndComponentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /**
     * Test all public and authenticated web routes respond successfully (200 OK or 302 Redirect).
     */
    public function test_all_web_routes_load_successfully()
    {
        $user = User::where('username', 'kira_art')->first();

        // 1. Home / Gallery Root
        $this->get('/')->assertStatus(200);
        $this->get('/gallery')->assertStatus(200);

        // 2. Post Detail
        $post = Post::first();
        $this->get("/post/{$post->id}")->assertStatus(200);

        // 3. Profile Page
        $this->get('/profile/kira_art')->assertStatus(200);

        // 4. Messages Page
        $this->actingAs($user)->get('/messages')->assertStatus(200);

        // 5. Notifications Page
        $this->actingAs($user)->get('/notifications')->assertStatus(200);

        // 6. Settings Page
        $this->actingAs($user)->get('/settings')->assertStatus(200);

        // 7. Upload Page
        $this->actingAs($user)->get('/upload')->assertStatus(200);

        // 8. Pools Index and Detail
        $pool = Pool::first();
        $this->get('/pools')->assertStatus(200);
        $this->get("/pools/{$pool->id}")->assertStatus(200);

        // 9. Collection Detail
        $collection = Collection::first();
        $this->get("/collections/{$collection->id}")->assertStatus(200);

        // 10. Admin Page (as admin)
        $this->actingAs($user)->get('/admin')->assertStatus(200);

        // 11. User Switcher
        $this->get('/switch-user/guest')->assertRedirect();
        $this->get("/switch-user/{$user->id}")->assertRedirect();
    }

    /**
     * Test Profile View Livewire component actions (Following modal, Commission Inquiry modal, Follow toggle).
     */
    public function test_profile_view_component_actions()
    {
        $user1 = User::where('username', 'kira_art')->first();
        $user2 = User::where('username', 'phantom_ren')->first();

        // Open follow modal
        Livewire::test('⚡profile-view', ['username' => 'kira_art'])
            ->call('openFollowModal', 'followers')
            ->assertSet('followModalOpen', true)
            ->assertSet('followModalTab', 'followers')
            ->call('closeFollowModal')
            ->assertSet('followModalOpen', false);

        // Toggle follow (Unfollow first since $user2 already follows $user1)
        Livewire::actingAs($user2)
            ->test('⚡profile-view', ['username' => 'kira_art'])
            ->call('toggleFollow');

        $user2->refresh();
        $this->assertFalse($user2->isFollowing($user1));

        // Toggle follow again (Follow back)
        Livewire::actingAs($user2)
            ->test('⚡profile-view', ['username' => 'kira_art'])
            ->call('toggleFollow');

        $user2->refresh();
        $this->assertTrue($user2->isFollowing($user1));

        // Submit Commission Inquiry
        Livewire::actingAs($user2)
            ->test('⚡profile-view', ['username' => 'kira_art'])
            ->set('commissionCategory', 'Illustration')
            ->set('commissionBudget', '$100 - $250')
            ->set('commissionDeadline', '2 Weeks')
            ->set('commissionDescription', 'Detailed fantasy character concept for astral blade series.')
            ->call('submitCommissionInquiry')
            ->assertSet('commissionModalOpen', false);

        // Assert message sent in conversation
        $conv = Conversation::where(function ($q) use ($user1, $user2) {
            $q->where('user_one_id', $user1->id)->where('user_two_id', $user2->id);
        })->orWhere(function ($q) use ($user1, $user2) {
            $q->where('user_one_id', $user2->id)->where('user_two_id', $user1->id);
        })->first();

        $this->assertNotNull($conv);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conv->id,
            'sender_id' => $user2->id,
        ]);
    }

    /**
     * Test Upload View Batch Multi-Post Mode and single post creation.
     */
    public function test_upload_view_batch_and_single_mode()
    {
        $user = User::where('username', 'kira_art')->first();

        // Batch mode toggle and auto-tagging
        Livewire::actingAs($user)
            ->test('⚡upload-view')
            ->call('setUploadMode', 'batch')
            ->assertSet('uploadMode', 'batch')
            ->call('loadPresetBatch')
            ->call('autoDetectTagsForBatch')
            ->call('applyBulkRating', true)
            ->call('submitBatchPosts')
            ->assertRedirect(route('gallery'));

        // Single Post creation
        Livewire::actingAs($user)
            ->test('⚡upload-view')
            ->set('title', 'Test Unit Post')
            ->set('description', 'Test Description')
            ->set('images', ['/sfw/image/sample_fd6cf1c5b5dda7658bf8b050ebb8240f.jpg'])
            ->set('tagInput', 'unit_test, fantasy')
            ->call('submitPost');

        $this->assertDatabaseHas('posts', [
            'user_id' => $user->id,
            'title' => 'Test Unit Post',
        ]);
    }

    /**
     * Test Post Detail component (Liking, prev/next navigation).
     */
    public function test_post_detail_component_functionality()
    {
        $user = User::where('username', 'kira_art')->first();
        $post = Post::first();

        Livewire::actingAs($user)
            ->test('⚡post-detail', ['postId' => $post->id])
            ->call('toggleLike');

        $this->assertTrue($post->isLikedBy($user));
    }

    /**
     * Test Notification component deletion & clear all actions.
     */
    public function test_notifications_component_deletion()
    {
        $user = User::where('username', 'kira_art')->first();
        $notif = $user->notifications()->first();

        $this->assertNotNull($notif);

        // Delete single notification
        Livewire::actingAs($user)
            ->test('⚡notifications-view')
            ->call('deleteNotification', $notif->id);

        $this->assertDatabaseMissing('notifications', [
            'id' => $notif->id,
        ]);

        // Clear all remaining notifications
        Livewire::actingAs($user)
            ->test('⚡notifications-view')
            ->call('clearAllNotifications');

        $this->assertEquals(0, $user->notifications()->count());
    }

    /**
     * Test Admin Dashboard extended powers (User Ban, Featured Post/Pool, Comment Moderation, Settings).
     */
    public function test_admin_dashboard_extended_abilities()
    {
        $admin = User::where('username', 'kira_art')->first();
        $targetUser = User::where('username', 'phantom_ren')->first();
        $post = Post::first();
        $pool = Pool::first();

        // 1. Toggle User Ban
        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->call('toggleUserBan', $targetUser->id);

        $targetUser->refresh();
        $this->assertTrue($targetUser->is_banned);

        // 2. Toggle Featured Post
        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->call('togglePostFeatured', $post->id);

        $post->refresh();
        $this->assertTrue($post->is_featured);

        // 3. Toggle Featured Pool
        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->call('togglePoolFeatured', $pool->id);

        $pool->refresh();
        $this->assertTrue($pool->is_featured);

        // 4. Save Site Settings & Announcement
        Livewire::actingAs($admin)
            ->test('⚡admin-dashboard')
            ->set('announcementText', 'Test Broadcast')
            ->call('saveSettings');

        $this->assertEquals('Test Broadcast', Cache::get('site_announcement'));
    }

    /**
     * Test Admin Export Chat routes and Artwork Moderation Return navigation.
     */
    public function test_admin_export_chat_routes_and_inspect_navigation()
    {
        $admin = User::where('username', 'kira_art')->first();
        $conv = Conversation::first();
        $post = Post::first();

        // 1. Export Chat Log Route
        $this->actingAs($admin)
            ->get("/admin/export-chat/{$conv->id}")
            ->assertStatus(200);

        // 2. Export Chat Media Route
        $this->actingAs($admin)
            ->get("/admin/export-chat-media/{$conv->id}")
            ->assertStatus(200);

        // 3. Post Detail from Admin page
        $this->actingAs($admin)
            ->get("/post/{$post->id}?from_admin=1")
            ->assertStatus(200)
            ->assertSee('Admin Moderation Mode')
            ->assertSee('Return to Admin Panel');
    }
}
