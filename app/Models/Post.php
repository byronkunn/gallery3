<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'media_type',
        'media_count',
        'views_count',
        'likes_count',
        'is_nsfw',
        'is_featured',
        'source_url',
    ];

    protected $casts = [
        'is_nsfw' => 'boolean',
        'is_featured' => 'boolean',
        'media_count' => 'integer',
        'views_count' => 'integer',
        'likes_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(PostMedia::class)->orderBy('order', 'asc');
    }

    public function primaryMedia()
    {
        return $this->hasOne(PostMedia::class)->orderBy('order', 'asc');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'post_tag');
    }

    public function likes(): HasMany
    {
        return $this->hasMany(Like::class);
    }

    public function likedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'likes')->withTimestamps();
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class, 'collection_items')->withPivot('order')->withTimestamps();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->latest();
    }

    public function poolChapters(): HasMany
    {
        return $this->hasMany(PoolChapter::class);
    }

    public function isLikedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->likes()->where('user_id', $user->id)->exists();
    }

    public function isSavedInCollectionBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return CollectionItem::whereHas('collection', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })->where('post_id', $this->id)->exists();
    }
}
