<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommunityMessage extends Model
{
    protected $fillable = [
        'community_channel_id',
        'user_id',
        'body',
        'attachment_url',
        'attachment_type',
        'reply_to_id',
        'edited_at',
        'is_hidden',
        'mention_user_ids',
        'mention_role_ids',
        'mention_everyone',
        'is_pinned',
        'pinned_at',
        'pinned_by',
        'is_system',
        'thread_id',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
            'is_hidden' => 'boolean',
            'mention_user_ids' => 'array',
            'mention_role_ids' => 'array',
            'mention_everyone' => 'boolean',
            'is_pinned' => 'boolean',
            'pinned_at' => 'datetime',
            'is_system' => 'boolean',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(CommunityChannel::class, 'community_channel_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(CommunityChannel::class, 'thread_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(CommunityMessageReaction::class, 'community_message_id');
    }

    public function mentionsUser(int $userId): bool
    {
        return in_array($userId, $this->mention_user_ids ?? [], true) || (bool) $this->mention_everyone;
    }
}
