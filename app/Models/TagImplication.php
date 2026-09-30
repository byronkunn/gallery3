<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TagImplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'tag_id',
        'implied_tag_id',
        'status',
        'user_id',
    ];

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'tag_id');
    }

    public function impliedTag(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'implied_tag_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
