<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TagHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'tag_id',
        'user_id',
        'action',
        'old_wiki',
        'new_wiki',
        'edit_summary',
    ];

    protected $casts = [
        'old_wiki' => 'array',
        'new_wiki' => 'array',
    ];

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
