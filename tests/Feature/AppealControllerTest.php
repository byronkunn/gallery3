<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppealControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_or_submit_an_appeal(): void
    {
        $this->withSession(['is_guest' => true])->get(route('appeals.index'))->assertUnauthorized();
        $this->withSession(['is_guest' => true])->post(route('appeals.store'), [
            'statement' => 'Please reconsider this suspension.',
        ])->assertForbidden();

        $this->assertDatabaseCount('user_appeals', 0);
    }

    public function test_suspended_user_can_submit_one_pending_appeal(): void
    {
        $user = User::factory()->create(['is_banned' => true]);

        $this->actingAs($user)->post(route('appeals.store'), [
            'statement' => '  Please reconsider my suspension.  ',
        ])->assertSessionHas('status', 'Your appeal was submitted. You can return here to check its status.');

        $this->assertDatabaseHas('user_appeals', [
            'user_id' => $user->id, 'statement' => 'Please reconsider my suspension.', 'status' => 'pending',
        ]);

        $this->actingAs($user)->post(route('appeals.store'), [
            'statement' => 'I would also like to add another statement.',
        ])->assertSessionHasErrors(['statement' => 'You already have an appeal waiting for review.']);

        $this->assertDatabaseCount('user_appeals', 1);
    }

    public function test_short_appeal_is_rejected_without_a_record(): void
    {
        $user = User::factory()->create(['is_banned' => true]);

        $this->actingAs($user)->post(route('appeals.store'), [
            'statement' => 'Too short',
        ])->assertSessionHasErrors('statement');

        $this->assertDatabaseCount('user_appeals', 0);
    }

    public function test_active_user_cannot_submit_an_appeal(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('appeals.store'), [
            'statement' => 'Please reconsider this suspension.',
        ])->assertForbidden();

        $this->assertDatabaseCount('user_appeals', 0);
    }
}
