<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_an_approved_account_and_signs_it_in(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'New Artist', 'username' => 'new_artist', 'email' => 'new@example.test',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('gallery'));
        $user = User::where('username', 'new_artist')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->approved_at);
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'user.registered', 'target_id' => $user->id]);
    }

    public function test_closed_registration_rejects_new_accounts(): void
    {
        SiteSettings::set('site_registrations', false);

        $this->get(route('register'))->assertForbidden();
        $this->post(route('register.store'), [
            'name' => 'Closed Artist', 'username' => 'closed_artist', 'email' => 'closed@example.test',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertForbidden();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_invite_only_registration_consumes_a_valid_code_once(): void
    {
        SiteSettings::setMany([
            'site_registration_invite_only' => true,
            'site_registration_invite_codes' => ['ART-ONE'],
            'site_registration_approval' => true,
        ]);

        $this->post(route('register.store'), [
            'name' => 'Invited Artist', 'username' => 'invited_artist', 'email' => 'invited@example.test',
            'password' => 'password123', 'password_confirmation' => 'password123', 'invite_code' => 'ART-ONE',
        ])->assertRedirect(route('gallery'));

        $user = User::where('username', 'invited_artist')->firstOrFail();
        $this->assertNull($user->approved_at);
        $this->assertSame([], SiteSettings::list('site_registration_invite_codes'));
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'user.registration_pending', 'target_id' => $user->id]);
    }

    public function test_invalid_invite_code_does_not_create_an_account(): void
    {
        SiteSettings::setMany([
            'site_registration_invite_only' => true,
            'site_registration_invite_codes' => ['ART-ONE'],
        ]);

        $this->post(route('register.store'), [
            'name' => 'Uninvited Artist', 'username' => 'uninvited_artist', 'email' => 'uninvited@example.test',
            'password' => 'password123', 'password_confirmation' => 'password123', 'invite_code' => 'WRONG',
        ])->assertSessionHasErrors(['invite_code' => 'That invite code is not valid or has already been used.']);

        $this->assertDatabaseCount('users', 0);
        $this->assertSame(['ART-ONE'], SiteSettings::list('site_registration_invite_codes'));
    }

    public function test_invalid_login_keeps_the_visitor_signed_out(): void
    {
        User::factory()->create(['email' => 'artist@example.test']);

        $this->post(route('login.store'), [
            'email' => 'artist@example.test', 'password' => 'incorrect',
        ])->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);

        $this->assertGuest();
    }

    public function test_banned_user_is_sent_to_appeals_after_login(): void
    {
        $user = User::factory()->create(['is_banned' => true]);

        $this->post(route('login.store'), [
            'email' => $user->email, 'password' => 'password',
        ])->assertRedirect(route('appeals.index'));

        $this->assertAuthenticatedAs($user);
    }
}
