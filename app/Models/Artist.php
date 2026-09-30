<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Artist extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'avatar_url',
        'banner_url',
        'bio',
        'is_claimed',
        'claimed_by_user_id',
        'works_count',
        'followers_count',
        'featured_post_ids',
    ];

    protected $casts = [
        'is_claimed' => 'boolean',
        'featured_post_ids' => 'array',
        'works_count' => 'integer',
        'followers_count' => 'integer',
    ];

    public function claimedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(ArtistAlias::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(ArtistLink::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(ArtistClaim::class);
    }

    public function edits(): HasMany
    {
        return $this->hasMany(ArtistEdit::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function followers()
    {
        return $this->belongsToMany(User::class, 'artist_follows', 'artist_id', 'user_id')->withTimestamps();
    }

    public function isFollowedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->followers()->where('user_id', $user->id)->exists();
    }

    public function getAvatarAttribute(): string
    {
        return $this->avatar_url ?: '/sfw/avatar/sample_07acb23be6e4bea15d091117693849b2.jpg';
    }
}
