<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunityChannelRead extends Model
{
    protected $fillable = [
        'community_id',
        'community_channel_id',
        'user_id',
        'last_read_message_id',
        'mention_count',
    ];

    protected function casts(): array
    {
        return [
            'last_read_message_id' => 'integer',
            'mention_count' => 'integer',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(CommunityChannel::class, 'community_channel_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
