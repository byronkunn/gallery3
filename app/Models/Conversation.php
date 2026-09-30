<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_one_id',
        'user_two_id',
        'last_message_at',
        'user_one_hidden_at',
        'user_two_hidden_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'user_one_hidden_at' => 'datetime',
        'user_two_hidden_at' => 'datetime',
    ];

    /**
     * Only conversations this user has not removed from their inbox. A new
     * message from the other participant clears the flag again.
     */
    public function scopeVisibleFor(Builder $query, User $user): void
    {
        $query->where(function (Builder $belongs) use ($user): void {
            $belongs->where(function (Builder $asUserOne) use ($user): void {
                $asUserOne->where('user_one_id', $user->id)->whereNull('user_one_hidden_at');
            })->orWhere(function (Builder $asUserTwo) use ($user): void {
                $asUserTwo->where('user_two_id', $user->id)->whereNull('user_two_hidden_at');
            });
        });
    }

    public function userOne(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_one_id');
    }

    public function userTwo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_two_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->oldest();
    }

    public function latestMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    /**
     * Most recent message that a moderator has not hidden. Used for previews so
     * hidden content never leaks back into the conversation list.
     */
    public function visibleLatestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->where('is_hidden', false)->latestOfMany();
    }

    public function getOtherUser(User $currentUser): User
    {
        return $this->user_one_id === $currentUser->id ? $this->userTwo : $this->userOne;
    }

    public function unreadCountFor(User $user): int
    {
        return $this->messages()
            ->where('sender_id', '!=', $user->id)
            ->where('is_read', false)
            ->count();
    }

    public function hiddenAtFor(User $user): ?Carbon
    {
        return match ($user->id) {
            $this->user_one_id => $this->user_one_hidden_at,
            $this->user_two_id => $this->user_two_hidden_at,
            default => null,
        };
    }

    public function isHiddenFor(User $user): bool
    {
        return ! is_null($this->hiddenAtFor($user));
    }

    /**
     * Remove the conversation from this user's inbox without touching the
     * other participant's copy.
     */
    public function hideFor(User $user): void
    {
        $this->setHiddenFor($user, now());
    }

    /**
     * Bring a hidden conversation back, used when the other participant sends
     * a new message or the recipient starts a fresh chat.
     */
    public function unhideFor(User $user): void
    {
        $this->setHiddenFor($user, null);
    }

    private function setHiddenFor(User $user, ?Carbon $hiddenAt): void
    {
        $column = match ($user->id) {
            $this->user_one_id => 'user_one_hidden_at',
            $this->user_two_id => 'user_two_hidden_at',
            default => null,
        };

        if ($column === null) {
            return;
        }

        if ($hiddenAt === null && $this->{$column} === null) {
            return;
        }

        $this->forceFill([$column => $hiddenAt])->save();
    }
}
