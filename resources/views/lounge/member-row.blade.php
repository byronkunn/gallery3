{{--
    One member row inside the member list. Expects $member and $isPresent.
--}}
@php
    $roleColor = $member->user_id === $community->owner_id
        ? 'var(--accent-primary)'
        : ($member->role?->color ?: 'var(--text-main)');
@endphp

<button wire:click="openProfile({{ $member->user_id }})" wire:key="member-{{ $member->id }}"
        class="flex w-full items-center gap-2 rounded-md px-2 py-1 text-left transition hover:bg-[var(--bg-surface-elevated)] {{ $isPresent ? '' : 'opacity-55' }}">
    <x-lounge.avatar :user="$member->user" :presence="$member->presenceState()" :size="8" />
    <div class="min-w-0 flex-1 leading-tight">
        <div class="flex items-center gap-1">
            <span class="truncate text-[14px] font-semibold" style="color: {{ $roleColor }}">{{ $member->displayName() }}</span>
            @if($member->user_id === $community->owner_id)
                <span class="shrink-0 text-[10px]" title="Server owner">👑</span>
            @endif
            @if($member->isTimedOut())
                <span class="shrink-0 text-[10px] text-rose-400" title="Timed out until {{ $member->timeout_until->diffForHumans() }}">⏳</span>
            @endif
            @if($member->is_muted)
                <span class="shrink-0 text-[10px] text-[var(--text-dim)]" title="Server muted">🔇</span>
            @endif
        </div>
        @if($member->status_text)
            <div class="truncate text-[11px] text-[var(--text-dim)]">{{ $member->status_emoji }} {{ $member->status_text }}</div>
        @elseif($member->role)
            <div class="truncate text-[11px] text-[var(--text-dim)]">{{ $member->role->name }}</div>
        @endif
    </div>
</button>
