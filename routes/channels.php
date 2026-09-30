<?php

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('users.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('conversation.{id}', function ($user, $id) {
    $conversation = Conversation::find($id);
    if (! $conversation) {
        return false;
    }

    return (int) $user->id === (int) $conversation->user_one_id || (int) $user->id === (int) $conversation->user_two_id;
});

/**
 * Presence channel for a lounge community. Returns the member payload Discord
 * shows in the member list (display name, avatar, presence and role colour).
 */
Broadcast::channel('community.{communityId}', function ($user, int $communityId): array|bool {
    $community = Community::find($communityId);
    if (! $community) {
        return false;
    }

    $isMember = $community->isMember($user);
    if (! $isMember && $community->owner_id !== $user->id && ! $user->isAdmin()) {
        return false;
    }

    $member = $community->memberFor($user);

    return [
        'id' => $user->id,
        'name' => $member?->displayName() ?? $user->name,
        'username' => $user->username,
        'avatar_url' => $user->avatar_url,
        'presence' => $member?->presenceState() ?? CommunityMember::PRESENCE_ONLINE,
        'role_color' => $community->displayColorFor($user),
    ];
});
