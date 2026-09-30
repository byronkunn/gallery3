<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'type', // 'artist', 'character', 'series', 'general', 'meta'
        'posts_count',
    ];

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_tag');
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_tag_follows')->withTimestamps();
    }

    // Color code and badge style based on Danbooru standard conventions (high contrast in light & dark modes)
    public function getTypeBadgeClasses(): string
    {
        return match ($this->type) {
            'artist' => 'text-red-600 dark:text-red-400 bg-red-500/10 border-red-500/25 hover:bg-red-500/20',
            'character' => 'text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 border-emerald-500/25 hover:bg-emerald-500/20',
            'series' => 'text-purple-600 dark:text-purple-400 bg-purple-500/10 border-purple-500/25 hover:bg-purple-500/20',
            'meta' => 'text-amber-600 dark:text-amber-400 bg-amber-500/10 border-amber-500/25 hover:bg-amber-500/20',
            default => 'text-sky-600 dark:text-sky-400 bg-sky-500/10 border-sky-500/25 hover:bg-sky-500/20',
        };
    }

    public function getDotColorClass(): string
    {
        return match ($this->type) {
            'artist' => 'bg-red-500 dark:bg-red-400',
            'character' => 'bg-emerald-500 dark:bg-emerald-400',
            'series' => 'bg-purple-500 dark:bg-purple-400',
            'meta' => 'bg-amber-500 dark:bg-amber-400',
            default => 'bg-sky-500 dark:bg-sky-400',
        };
    }
}
