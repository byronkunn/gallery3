<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostMedia extends Model
{
    use HasFactory;

    protected $table = 'post_media';

    protected $fillable = [
        'post_id',
        'order',
        'url',
        'thumbnail_url',
        'width',
        'height',
        'aspect_ratio',
        'duration',
        'specific_tags',
    ];

    protected $casts = [
        'specific_tags' => 'array',
        'aspect_ratio' => 'float',
        'order' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration' => 'integer',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
