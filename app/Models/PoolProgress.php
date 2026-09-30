<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoolProgress extends Model
{
    use HasFactory;

    protected $table = 'pool_progress';

    protected $fillable = [
        'user_id',
        'pool_id',
        'last_chapter_id',
        'last_page',
    ];

    protected $casts = [
        'last_page' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(Pool::class);
    }

    public function lastChapter(): BelongsTo
    {
        return $this->belongsTo(PoolChapter::class, 'last_chapter_id');
    }
}
