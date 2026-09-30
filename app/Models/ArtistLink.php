<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArtistLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'artist_id',
        'platform',
        'url',
        'title',
        'status',
        'submitted_by_user_id',
        'verified_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
    ];

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function submittedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function getPlatformIconAttribute(): string
    {
        return match ($this->platform) {
            'x' => '🌐',
            'pixiv' => '🎨',
            'bluesky' => '🦋',
            'patreon', 'fanbox' => '💖',
            'youtube' => '▶️',
            'instagram' => '📷',
            'deviantart' => '🖌️',
            'tumblr' => '📝',
            'store' => '🛍️',
            default => '🔗',
        };
    }
}
