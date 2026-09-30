<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'avatar_url',
        'banner_url',
        'bio',
        'website',
        'is_artist',
        'is_admin',
        'is_banned',
        'suspended_until',
        'suspension_reason',
        'last_active_at',
        'commission_status',
        'theme_mode',
        'theme_palette',
        'blur_nsfw',
        'hide_nsfw',
        'font_size',
        'reduced_motion',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_artist' => 'boolean',
            'is_admin' => 'boolean',
            'is_banned' => 'boolean',
            'blur_nsfw' => 'boolean',
            'hide_nsfw' => 'boolean',
            'reduced_motion' => 'boolean',
            'last_active_at' => 'datetime',
            'suspended_until' => 'datetime',
        ];
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(Like::class);
    }

    public function likedPosts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'likes')->withTimestamps();
    }

    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class);
    }

    public function pools(): HasMany
    {
        return $this->hasMany(Pool::class);
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'following_id', 'follower_id')->withTimestamps();
    }

    public function following(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'follower_id', 'following_id')->withTimestamps();
    }

    public function blockedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_blocks', 'blocker_id', 'blocked_id')->withTimestamps();
    }

    public function blockedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_blocks', 'blocked_id', 'blocker_id')->withTimestamps();
    }

    public function followedTags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'user_tag_follows')->withTimestamps();
    }

    public function blacklistedTags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'user_tag_blacklists')->withTimestamps();
    }

    public function followingCollections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class, 'collection_follows')->withTimestamps();
    }

    public function followingPools(): BelongsToMany
    {
        return $this->belongsToMany(Pool::class, 'pool_follows')->withTimestamps();
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class)->latest();
    }

    public function conversations()
    {
        return Conversation::where(function ($query) {
            $query->where('user_one_id', $this->id)
                ->orWhere('user_two_id', $this->id);
        });
    }

    public function communityMembers(): HasMany
    {
        return $this->hasMany(CommunityMember::class);
    }

    public function communities(): BelongsToMany
    {
        return $this->belongsToMany(Community::class, 'community_members')
            ->withPivot(['status', 'presence', 'community_role_id'])
            ->withTimestamps();
    }

    public function isFollowing(User $user): bool
    {
        return $this->following()->where('following_id', $user->id)->exists();
    }

    public function isBlocking(User $user): bool
    {
        return $this->blockedUsers()->where('blocked_id', $user->id)->exists();
    }

    public function isBlockedBy(User $user): bool
    {
        return $this->blockedByUsers()->where('blocker_id', $user->id)->exists();
    }

    public function isFollowingTag(Tag $tag): bool
    {
        return $this->followedTags()->where('tag_id', $tag->id)->exists();
    }

    public function isTagBlacklisted(Tag $tag): bool
    {
        return $this->blacklistedTags()->where('tag_id', $tag->id)->exists();
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }
}
