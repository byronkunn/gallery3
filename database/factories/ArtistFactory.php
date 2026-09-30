<?php

namespace Database\Factories;

use App\Models\Artist;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Artist>
 */
class ArtistFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->name();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'avatar_url' => 'https://picsum.photos/seed/artist-'.Str::random(8).'/200/200',
            'banner_url' => 'https://picsum.photos/seed/artist-banner-'.Str::random(8).'/1200/300',
            'bio' => fake()->paragraph(),
            'is_claimed' => false,
            'claimed_by_user_id' => null,
            'works_count' => 0,
            'followers_count' => 0,
        ];
    }

    /**
     * Indicate that the artist page has been claimed by the given (or a new) user.
     */
    public function claimed(?User $user = null): static
    {
        return $this->state(fn (array $attributes) => [
            'is_claimed' => true,
            'claimed_by_user_id' => ($user ?? User::factory()->create())->id,
        ]);
    }
}
