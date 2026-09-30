<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'artist_id',
        'is_artist_upload',
        'is_original_creator',
        'artist_name',
        'artist_url',
        'title',
        'description',
        'media_type',
        'media_count',
        'views_count',
        'likes_count',
        'is_nsfw',
        'is_featured',
        'comments_locked',
        'source_url',
        'removal_reason',
    ];

    protected $casts = [
        'is_artist_upload' => 'boolean',
        'is_original_creator' => 'boolean',
        'is_nsfw' => 'boolean',
        'is_featured' => 'boolean',
        'comments_locked' => 'boolean',
        'media_count' => 'integer',
        'views_count' => 'integer',
        'likes_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
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
