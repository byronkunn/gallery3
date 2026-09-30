<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoolChapter extends Model
{
    use HasFactory;

    protected $fillable = [
        'pool_id',
        'post_id',
        'chapter_number',
        'title',
        'order',
    ];

    protected $casts = [
        'chapter_number' => 'float',
        'order' => 'integer',
    ];

    public function pool(): BelongsTo
    {
        return $this->belongsTo(Pool::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
