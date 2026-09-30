<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Collection extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'description',
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
