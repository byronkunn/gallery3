<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BugReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_view_the_bug_report_form(): void
    {
        $this->get(route('bug-reports.create'))
            ->assertSee('Report a bug');
    }

    public function test_guest_submission_requires_an_email(): void
    {
        $this->post(route('bug-reports.store'), [
            'subject' => 'Checkout fails',
            'description' => 'The checkout button does nothing after a payment method is chosen.',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseCount('bug_reports', 0);
    }

    public function test_submission_rejects_a_description_shorter_than_twenty_characters(): void
    {
        $this->post(route('bug-reports.store'), [
            'email' => 'reporter@example.test',
            'subject' => 'Upload is stuck',
            'description' => 'Too short',
        ])->assertSessionHasErrors('description');

        $this->assertDatabaseCount('bug_reports', 0);
    }

    public function test_valid_guest_submission_creates_an_open_bug_report(): void
    {
        $response = $this->post(route('bug-reports.store'), [
            'email' => 'reporter@example.test',
            'subject' => 'Upload is stuck',
            'description' => 'Uploading a PNG larger than 5 MB never finishes on the upload page.',
            'page_url' => 'https://example.test/upload',
        ]);

        $response->assertRedirect(route('bug-reports.create'));
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('bug_reports', [
            'user_id' => null,
            'email' => 'reporter@example.test',
            'subject' => 'Upload is stuck',
            'status' => 'open',
        ]);
    }

    public function test_authenticated_submission_uses_the_account_email(): void
    {
        $user = User::factory()->approved()->create();

        $this->actingAs($user)
            ->post(route('bug-reports.store'), [
                'subject' => 'Avatar will not save',
                'description' => 'Saving a new avatar on the profile page returns an error every time.',
            ])
            ->assertRedirect(route('bug-reports.create'));

        $this->assertDatabaseHas('bug_reports', [
            'user_id' => $user->id,
            'email' => $user->email,
            'status' => 'open',
        ]);
    }
}
