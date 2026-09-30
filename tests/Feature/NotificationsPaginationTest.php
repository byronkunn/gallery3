<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationsPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function userWithNotifications(string $username, string $type, int $count = 25): User
    {
        $user = User::factory()->create(['username' => $username]);
        $actor = User::factory()->create(['username' => $username.'-actor']);

        // 25 notifications at 20 per page forces the paginator to render.
        foreach (range(1, $count) as $index) {
            Notification::create([
                'user_id' => $user->id,
                'actor_id' => $actor->id,
                'type' => $type,
                'message' => 'activity #'.$index,
            ]);
        }

        return $user;
    }

    public function test_notifications_page_uses_themed_pagination(): void
    {
        $user = $this->userWithNotifications('notif-owner', 'like');

        $html = Livewire::actingAs($user)->test('⚡notifications-view')->html();

        // Pagination controls must be present and wired to Livewire...
        $this->assertStringContainsString('Pagination Navigation', $html);
        $this->assertStringContainsString('gotoPage(2', $html);

        // ...and styled with the app's tokens rather than Livewire's hard-coded
        // light/dark Tailwind palette (which never activates because the app themes
        // via `data-theme-mode`, not Tailwind's `dark:` variant).
        $this->assertStringContainsString('accent-bg', $html);
        $this->assertStringContainsString('var(--bg-surface)', $html);
        $this->assertStringNotContainsString('bg-white', $html);
        $this->assertStringNotContainsString('dark:bg-gray-800', $html);
    }

    public function test_notifications_page_paginates_to_the_second_page(): void
    {
        $user = $this->userWithNotifications('notif-pager', 'follow');

        $component = Livewire::actingAs($user)->test('⚡notifications-view');

        $component->call('gotoPage', 2);

        $this->assertSame(2, $component->instance()->getPage());
        $component->assertSee('activity #25');
    }

    public function test_changing_the_filter_resets_to_the_first_page(): void
    {
        $user = $this->userWithNotifications('notif-filter', 'like');

        Livewire::actingAs($user)->test('⚡notifications-view')
            ->call('gotoPage', 2)
            ->assertSet('paginators.page', 2)
            ->call('setFilter', 'unread')
            ->assertSet('paginators.page', 1);
    }
}
