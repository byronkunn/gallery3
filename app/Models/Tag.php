<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tag extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'type', // 'artist', 'character', 'series', 'general', 'meta'
        'posts_count',
        'short_description',
        'wiki_summary',
        'wiki_usage',
        'wiki_do_not_use',
        'wiki_examples',
        'wiki_notes',
        'is_locked',
        'lock_level',
    ];

    protected $casts = [
        'posts_count' => 'integer',
        'is_locked' => 'boolean',
    ];

    public static function normalizeName(string $name): string
    {
        $normalized = mb_strtolower(trim($name));
        $normalized = preg_replace('/[\s\-]+/', '_', $normalized);
        $normalized = preg_replace('/[^\w]/', '', $normalized);
        $normalized = preg_replace('/_+/', '_', $normalized);

        return trim($normalized, '_');
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_tag');
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_tag_follows')->withTimestamps();
    }

    public function muters(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_muted_tags')->withTimestamps();
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(TagAlias::class);
    }

    public function implications(): HasMany
    {
        return $this->hasMany(TagImplication::class, 'tag_id');
    }

    public function impliedBy(): HasMany
    {
        return $this->hasMany(TagImplication::class, 'implied_tag_id');
    }

    public function relatedTags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'tag_relations', 'tag_id', 'related_tag_id')->withTimestamps();
    }

    public function histories(): HasMany
    {
        return $this->hasMany(TagHistory::class)->latest();
    }

    // Color code and badge style based on Danbooru standard conventions (high contrast in light & dark modes)
    public function getTypeBadgeClasses(): string
    {
        return match ($this->type) {
            'artist' => 'text-red-600 dark:text-red-400 bg-red-500/10 border-red-500/25 hover:bg-red-500/20',
            'character' => 'text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 border-emerald-500/25 hover:bg-emerald-500/20',
            'series', 'copyright' => 'text-purple-600 dark:text-purple-400 bg-purple-500/10 border-purple-500/25 hover:bg-purple-500/20',
            'meta' => 'text-amber-600 dark:text-amber-400 bg-amber-500/10 border-amber-500/25 hover:bg-amber-500/20',
            default => 'text-sky-600 dark:text-sky-400 bg-sky-500/10 border-sky-500/25 hover:bg-sky-500/20',
        };
    }

    public function getDotColorClass(): string
    {
        return match ($this->type) {
            'artist' => 'bg-red-500 dark:bg-red-400',
            'character' => 'bg-emerald-500 dark:bg-emerald-400',
            'series', 'copyright' => 'bg-purple-500 dark:bg-purple-400',
            'meta' => 'bg-amber-500 dark:bg-amber-400',
            default => 'bg-sky-500 dark:bg-sky-400',
        };
    }

    public function getCategoryLabel(): string
    {
        return match ($this->type) {
            'artist' => 'Artist',
            'character' => 'Character',
            'series', 'copyright' => 'Copyright',
            'meta' => 'Meta',
            default => 'General',
        };
    }
}
