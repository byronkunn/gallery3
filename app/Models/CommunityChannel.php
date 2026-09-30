<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommunityChannel extends Model
{
    public const TYPE_TEXT = 'text';

    public const TYPE_FORUM = 'forum';

    public const TYPE_EVENTS = 'events';

    public const TYPE_THREAD = 'thread';

    protected $fillable = [
        'community_id',
        'name',
        'slug',
        'type',
        'description',
        'position',
        'is_read_only',
        'category_id',
        'parent_id',
        'parent_message_id',
        'owner_id',
        'is_nsfw',
        'slowmode_seconds',
        'message_count',
        'thread_archived',
        'thread_locked',
        'icon_emoji',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'is_read_only' => 'boolean',
            'is_nsfw' => 'boolean',
            'thread_archived' => 'boolean',
            'thread_locked' => 'boolean',
            'position' => 'integer',
            'slowmode_seconds' => 'integer',
            'message_count' => 'integer',
            'last_message_at' => 'datetime',
        ];
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CommunityChannelCategory::class, 'category_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function threads(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderByDesc('last_message_at');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CommunityMessage::class);
    }

    /**
     * Messages that belong to the channel itself, excluding thread replies.
     */
    public function rootMessages(): HasMany
    {
        return $this->hasMany(CommunityMessage::class)->whereNull('thread_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(CommunityChannelRead::class, 'community_channel_id');
    }

    public function isThread(): bool
    {
        return $this->type === self::TYPE_THREAD || $this->parent_id !== null;
    }

    public function isForum(): bool
    {
        return $this->type === self::TYPE_FORUM;
    }

    public function isEvents(): bool
    {
        return $this->type === self::TYPE_EVENTS;
    }

    public function isText(): bool
    {
        return $this->type === self::TYPE_TEXT;
    }

    public function isPostable(): bool
    {
        return ! $this->is_read_only;
    }

    /**
     * Discord-style channel glyph.
     */
    public function icon(): string
    {
        return match ($this->type) {
            self::TYPE_FORUM => '▤',
            self::TYPE_EVENTS => '◷',
            self::TYPE_THREAD => '❞',
            default => '#',
        };
    }
}
