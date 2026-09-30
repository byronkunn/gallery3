<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunityEmoji extends Model
{
    protected $table = 'community_emoji';

    protected $fillable = [
        'community_id',
        'name',
        'image_url',
        'created_by',
    ];

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }
}
