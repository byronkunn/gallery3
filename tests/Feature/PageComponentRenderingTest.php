<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageComponentRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_collections_page_renders_the_hub_component(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/collections')
            ->assertSee('Community Collections Hub');
    }

    public function test_following_page_renders_the_following_component(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/following')
            ->assertSee('Not following any artists yet');
    }
}
