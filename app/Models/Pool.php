<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pool extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'cover_url',
        'is_locked',
        'is_featured',
        'chapters_count',
        'followers_count',
    ];

    protected $casts = [
        'is_locked' => 'boolean',
        'is_featured' => 'boolean',
        'chapters_count' => 'integer',
        'followers_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(PoolChapter::class)->orderBy('order', 'asc');
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'pool_follows')->withTimestamps();
    }

    public function history(): HasMany
    {
        return $this->hasMany(PoolHistory::class)->latest();
    }

    public function progress(): HasMany
    {
        return $this->hasMany(PoolProgress::class);
    }

    public function isFollowedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->followers()->where('user_id', $user->id)->exists();
    }

    public function getUserProgress(?User $user): ?PoolProgress
    {
        if (! $user) {
            return null;
        }

        return $this->progress()->where('user_id', $user->id)->first();
    }
}
