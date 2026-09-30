<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'text',
        'reply_to_id',
        'shared_post_id',
        'reactions',
        'is_read',
        'read_at',
        'is_hidden',
        'hidden_reason',
    ];

    protected $casts = [
        'reactions' => 'array',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'is_hidden' => 'boolean',
    ];

    /**
     * A new message always brings the thread back to both inboxes, even if one
     * of the participants had removed it from their DM list.
     */
    protected static function booted(): void
    {
        static::created(function (Message $message): void {
            $conversation = $message->conversation;
            $sender = $message->sender;

            if (! $conversation || ! $sender) {
                return;
            }

            $conversation->unhideFor($sender);
            $conversation->unhideFor($conversation->getOtherUser($sender));
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_id');
    }

    public function sharedPost(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'shared_post_id');
    }
}
