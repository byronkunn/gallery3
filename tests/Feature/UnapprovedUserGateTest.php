<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnapprovedUserGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_unapproved_user_is_redirected_back_with_an_error_instead_of_a_raw_403(): void
    {
        $user = User::factory()->unapproved()->create();

        $response = $this->actingAs($user)
            ->from(route('bug-reports.create'))
            ->post(route('bug-reports.store'), $this->validBugReport());

        $response->assertRedirect(route('bug-reports.create'));
        $response->assertSessionHas('error');

        $this->assertDatabaseCount('bug_reports', 0);
    }

    public function test_unapproved_livewire_request_is_redirected_back_with_an_error(): void
    {
        $user = User::factory()->unapproved()->create();

        $response = $this->actingAs($user)
            ->from(route('bug-reports.create'))
            ->withHeaders(['X-Livewire' => '1', 'Accept' => 'application/json'])
            ->post(route('bug-reports.store'), $this->validBugReport());

        $response->assertRedirect(route('bug-reports.create'));
        $response->assertSessionHas('error');
    }

    public function test_unapproved_json_api_request_still_receives_a_403(): void
    {
        $user = User::factory()->unapproved()->create();

        $this->actingAs($user)
            ->postJson(route('bug-reports.store'), $this->validBugReport())
            ->assertForbidden();
    }

    public function test_unapproved_user_can_still_browse_read_only_pages(): void
    {
        $user = User::factory()->unapproved()->create();

        $this->actingAs($user)->get(route('gallery'))->assertOk();
    }

    public function test_factory_users_are_full_fledged_and_can_post(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->isApproved());

        $this->actingAs($user)
            ->post(route('bug-reports.store'), $this->validBugReport())
            ->assertRedirect(route('bug-reports.create'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('bug_reports', ['user_id' => $user->id]);
    }

    public function test_error_flash_is_rendered_as_a_toast(): void
    {
        $this->withSession(['error' => 'Your account is awaiting approval.'])
            ->get(route('gallery'))
            ->assertSee('Your account is awaiting approval.');
    }

    /**
     * @return array<string, string>
     */
    private function validBugReport(): array
    {
        return [
            'subject' => 'Posting is blocked',
            'description' => 'The quick brown fox jumps over the lazy dog every single morning.',
        ];
    }
}
