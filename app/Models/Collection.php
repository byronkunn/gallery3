<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Collection extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'visibility',
        'is_private',
        'cover_url',
        'items_count',
        'followers_count',
    ];

    protected $casts = [
        'is_private' => 'boolean',
        'items_count' => 'integer',
        'followers_count' => 'integer',
    ];

    public function isPublic(): bool
    {
        return $this->visibility === 'public' && ! $this->is_private;
    }

    public function isUnlisted(): bool
    {
        return $this->visibility === 'unlisted';
    }

    public function isPrivate(): bool
    {
        return $this->visibility === 'private' || $this->is_private;
    }

    /**
     * Get up to $limit media URLs to display as a collage / mosaic thumbnail.
     *
     * @return array<int, string>
     */
    public function mosaicThumbnails(int $limit = 4): array
    {
        if ($this->cover_url) {
            return [$this->cover_url];
        }

        $urls = [];
        $posts = $this->posts()->with('primaryMedia')->take($limit)->get();

        foreach ($posts as $p) {
            $media = $p->primaryMedia;
            if ($media) {
                $urls[] = $media->thumbnail_url ?? $media->url;
            }
        }

        return $urls;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CollectionItem::class)->orderBy('order', 'asc');
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'collection_items')
            ->withPivot('order')
            ->orderByPivot('order', 'asc')
            ->withTimestamps();
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'collection_follows')->withTimestamps();
    }

    public function isFollowedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->followers()->where('user_id', $user->id)->exists();
    }
}
