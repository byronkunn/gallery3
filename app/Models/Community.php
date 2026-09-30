<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Community extends Model
{
    public const PERMISSIONS = [
        'review_reports',
        'resolve_reports',
        'delete_messages',
        'manage_members',
        'manage_channels',
        'manage_events',
        'manage_forums',
        'manage_tags',
    ];

    /**
     * @var array<int, CommunityMember|null>
     */
    private array $memberCache = [];

    protected $fillable = [
        'owner_id',
        'name',
        'slug',
        'description',
        'icon_url',
        'banner_url',
        'visibility',
        'topics',
        'onboarding_questions',
        'rules',
        'member_count',
        'archived_at',
        'archive_reason',
    ];

    protected function casts(): array
    {
        return [
            'topics' => 'array',
            'onboarding_questions' => 'array',
            'member_count' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return ! is_null($this->archived_at);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function channels(): HasMany
    {
        return $this->hasMany(CommunityChannel::class)->orderBy('position');
    }

    /**
     * Sidebar channels: everything that is not a thread.
     */
    public function topLevelChannels(): HasMany
    {
        return $this->hasMany(CommunityChannel::class)->whereNull('parent_id')->orderBy('position');
    }

    public function categoryGroups(): HasMany
    {
        return $this->hasMany(CommunityChannelCategory::class)->orderBy('position');
    }

    public function members(): HasMany
    {
        return $this->hasMany(CommunityMember::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(CommunityRole::class)->orderByDesc('position');
    }

    public function emoji(): HasMany
    {
        return $this->hasMany(CommunityEmoji::class);
    }

    /**
     * Resolve a membership row (cached for the life of the model instance).
     */
    public function memberFor(User|int|null $user): ?CommunityMember
    {
        $userId = $user instanceof User ? $user->id : $user;
        if (! $userId) {
            return null;
        }

        if (! array_key_exists($userId, $this->memberCache)) {
            $this->memberCache[$userId] = $this->members()->where('user_id', $userId)->with('role')->first();
        }

        return $this->memberCache[$userId];
    }

    public function roleFor(User|int|null $user): ?CommunityRole
    {
        return $this->memberFor($user)?->role;
    }

    public function roleColorFor(User|int|null $user): ?string
    {
        return $this->roleFor($user)?->color;
    }

    public function isMember(User|int $user): bool
    {
        $userId = $user instanceof User ? $user->id : $user;

        return DB::table('community_members')
            ->where('community_id', $this->id)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    public function permissionsFor(User|int|null $user): array
    {
        $userModel = $user instanceof User ? $user : ($user ? User::find($user) : null);
        if (! $userModel) {
            return [];
        }

        if ($userModel->isAdmin() || $this->owner_id === $userModel->id) {
            return self::PERMISSIONS;
        }

        return $this->roleFor($userModel)?->permissions ?? [];
    }

    public function hasPermission(User|int|null $user, string $permission): bool
    {
        $userModel = $user instanceof User ? $user : ($user ? User::find($user) : null);
        if (! $userModel) {
            return false;
        }

        if ($userModel->isAdmin() || $this->owner_id === $userModel->id) {
            return true;
        }

        return in_array($permission, $this->permissionsFor($userModel), true);
    }

    /**
     * Colour used for a member's name in chat and the member list.
     */
    public function displayColorFor(User|int|null $user): ?string
    {
        if ($user instanceof User && $this->owner_id === $user->id) {
            return 'var(--accent-primary)';
        }

        return $this->roleColorFor($user);
    }
}
