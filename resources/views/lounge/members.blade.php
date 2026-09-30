{{--
    Discord-style member list: hoisted role groups, online and offline sections.
--}}
@php
    $query = mb_strtolower(trim($memberSearch));
    $visible = $activeMembers->filter(function ($member) use ($query) {
        if ($query === '') {
            return true;
        }
        return str_contains(mb_strtolower((string) $member->displayName()), $query)
            || str_contains(mb_strtolower((string) $member->user?->username), $query);
    });

    $isPresent = fn ($member) => $member->presenceState() !== 'offline'
        && $member->last_seen_at !== null
        && $member->last_seen_at->gt(now()->subMinutes(10));

    $hoistedRoles = $roles->where('hoist', true);
    $hoistedIds = $hoistedRoles->pluck('id')->all();
    $ungrouped = $visible->whereNotIn('community_role_id', $hoistedIds);
    $onlineMembers = $ungrouped->filter($isPresent)->values();
    $offlineMembers = $ungrouped->reject($isPresent)->values();
@endphp

<aside class="hidden w-60 shrink-0 flex-col bg-[var(--bg-surface)] lg:flex" aria-label="Members">
    <div class="flex h-12 shrink-0 items-center gap-2 border-b border-black/20 px-3">
        <span class="text-[13px] font-bold uppercase tracking-wide text-[var(--text-dim)]">Members</span>
        <span class="rounded-full bg-[var(--bg-page)] px-2 text-[11px] font-bold text-[var(--text-muted)]">{{ $activeMembers->count() }}</span>
        <input wire:model.live.debounce.300ms="memberSearch" type="search" placeholder="Search members" class="ml-auto w-24 rounded-md bg-[var(--bg-page)] px-2 py-1 text-xs text-[var(--text-main)] outline-none placeholder:text-[var(--text-dim)] focus:w-32 transition-all">
        <button wire:click="toggleMemberList" class="text-[var(--text-dim)] transition hover:text-[var(--text-main)]" title="Hide member list">✕</button>
    </div>

    <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-2 py-3">
        @foreach($hoistedRoles as $role)
            @php($roleMembers = $visible->where('community_role_id', $role->id))
            @if($roleMembers->isNotEmpty())
                <div>
                    <div class="px-2 pb-1 text-[11px] font-bold uppercase tracking-wider" style="color: {{ $role->color ?: 'var(--text-dim)' }}">
                        {{ $role->name }} — {{ $roleMembers->count() }}
                    </div>
                    @foreach($roleMembers as $member)
                        @include('lounge.member-row', ['member' => $member, 'isPresent' => $isPresent($member)])
                    @endforeach
                </div>
            @endif
        @endforeach

        @if($onlineMembers->isNotEmpty())
            <div>
                <div class="px-2 pb-1 text-[11px] font-bold uppercase tracking-wider text-[var(--text-dim)]">Online — {{ $onlineMembers->count() }}</div>
                @foreach($onlineMembers as $member)
                    @include('lounge.member-row', ['member' => $member, 'isPresent' => true])
                @endforeach
            </div>
        @endif

        @if($offlineMembers->isNotEmpty())
            <div>
                <div class="px-2 pb-1 text-[11px] font-bold uppercase tracking-wider text-[var(--text-dim)]">Offline — {{ $offlineMembers->count() }}</div>
                @foreach($offlineMembers as $member)
                    @include('lounge.member-row', ['member' => $member, 'isPresent' => false])
                @endforeach
            </div>
        @endif

        @if($visible->isEmpty())
            <p class="px-2 py-4 text-xs text-[var(--text-dim)]">No members match that search.</p>
        @endif
    </div>
</aside>
