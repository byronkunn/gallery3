<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\ArtistAlias;
use App\Models\ArtistLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtistSeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_populates_the_artist_directory(): void
    {
        $this->seed();

        $this->assertGreaterThan(0, Artist::count());
        $this->assertGreaterThan(0, ArtistAlias::count());
        $this->assertGreaterThan(0, ArtistLink::count());

        $this->get('/artists')
            ->assertSee('John Staub');
    }
}
