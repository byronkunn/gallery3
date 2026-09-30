<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunityMember extends Model
{
    public const PRESENCE_ONLINE = 'online';

    public const PRESENCE_IDLE = 'idle';

    public const PRESENCE_DND = 'dnd';

    public const PRESENCE_INVISIBLE = 'invisible';

    public const PRESENCE_OFFLINE = 'offline';

    public const PRESENCE_STATES = [
        self::PRESENCE_ONLINE,
        self::PRESENCE_IDLE,
        self::PRESENCE_DND,
        self::PRESENCE_INVISIBLE,
    ];

    protected $fillable = [
        'community_id',
        'user_id',
        'community_role_id',
        'status',
        'onboarding_answers',
        'joined_at',
        'presence',
        'last_seen_at',
        'timeout_until',
        'timeout_reason',
        'nickname',
        'status_emoji',
        'status_text',
        'message_count',
        'is_muted',
        'notify_level',
    ];

    protected function casts(): array
    {
        return [
            'onboarding_answers' => 'array',
            'joined_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'timeout_until' => 'datetime',
            'message_count' => 'integer',
            'is_muted' => 'boolean',
        ];
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(CommunityRole::class, 'community_role_id');
    }

    /**
     * Discord shows the server nickname first, then the account name.
     */
    public function displayName(): string
    {
        return $this->nickname ?: ($this->user?->name ?? 'Member');
    }

    public function isTimedOut(): bool
    {
        return $this->timeout_until !== null && $this->timeout_until->isFuture();
    }

    /**
     * Resolve the presence Discord would show. Invisible members appear offline.
     */
    public function presenceState(): string
    {
        if ($this->presence === self::PRESENCE_INVISIBLE) {
            return self::PRESENCE_OFFLINE;
        }

        return $this->presence ?: self::PRESENCE_OFFLINE;
    }

    public function roleColor(): ?string
    {
        return $this->role?->color;
    }
}
