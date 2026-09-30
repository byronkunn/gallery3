{{--
    Discord-style channel sidebar: server header menu, categories, channels, threads and the user panel.
--}}
@php
    $uncategorised = $channels->whereNull('category_id');
    $groups = collect();
    if ($uncategorised->isNotEmpty()) {
        $groups->push(['id' => null, 'name' => null, 'channels' => $uncategorised]);
    }
    foreach ($categories as $category) {
        $groups->push(['id' => $category->id, 'name' => $category->name, 'channels' => $channels->where('category_id', $category->id)]);
    }
    $myMember = Auth::check() ? $membership : null;
@endphp

<aside class="fixed inset-y-0 left-0 z-50 flex w-60 shrink-0 flex-col bg-[var(--bg-surface)] shadow-2xl transition-transform duration-200 md:static md:z-auto md:translate-x-0 md:shadow-none"
       :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0'"
       aria-label="Channels">
    {{-- Server header --}}
    <div x-data="{ open: false }" class="relative shrink-0">
        <button wire:key="server-header" @click="open = !open"
                class="flex h-12 w-full items-center justify-between gap-2 border-b border-black/20 px-4 text-[15px] font-bold text-[var(--text-main)] shadow-sm transition hover:bg-[var(--bg-surface-elevated)]">
            <span class="min-w-0 truncate">{{ $community->name }}</span>
            <svg class="h-4 w-4 shrink-0 transition" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>
        </button>

        <div x-show="open" x-cloak @click.outside="open = false"
             x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             class="absolute inset-x-2 top-[52px] z-50 space-y-0.5 rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface-elevated)] p-2 text-sm shadow-2xl">
            @if($isMember && $permissions['manage_members'])
                <button wire:click="openInvites(); open = false" class="flex w-full items-center justify-between rounded-lg px-2.5 py-2 font-semibold text-[var(--accent-light)] transition hover:bg-[var(--accent-primary)] hover:text-white">
                    <span>Invite people</span><span>＋</span>
                </button>
            @endif
            @if($isMember)
                <button wire:click="openUserSettings(); open = false" class="w-full rounded-lg px-2.5 py-2 text-left font-semibold text-[var(--text-main)] transition hover:bg-[var(--accent-primary)] hover:text-white">Profile &amp; status</button>
            @endif
            @if($permissions['manage_channels'])
                <button wire:click="$set('createChannelOpen', true); open = false" class="w-full rounded-lg px-2.5 py-2 text-left font-semibold text-[var(--text-main)] transition hover:bg-[var(--accent-primary)] hover:text-white">Create channel</button>
                <button wire:click="$set('createCategoryOpen', true); open = false" class="w-full rounded-lg px-2.5 py-2 text-left font-semibold text-[var(--text-main)] transition hover:bg-[var(--accent-primary)] hover:text-white">Create category</button>
            @endif
            <button wire:click="toggleMemberList(); open = false" class="w-full rounded-lg px-2.5 py-2 text-left font-semibold text-[var(--text-main)] transition hover:bg-[var(--accent-primary)] hover:text-white">Toggle member list</button>
            @if($permissions['review_reports'] || $permissions['resolve_reports'])
                <button wire:click="openServerSettings(); $set('serverTab', 'moderation'); open = false" class="w-full rounded-lg px-2.5 py-2 text-left font-semibold text-[var(--text-main)] transition hover:bg-[var(--accent-primary)] hover:text-white">Moderation</button>
            @endif
            @if($canManageServer)
                <div class="my-1 h-px bg-[var(--border-subtle)]"></div>
                <button wire:click="openServerSettings(); $set('serverTab', 'overview'); open = false" class="w-full rounded-lg px-2.5 py-2 text-left font-semibold text-[var(--text-main)] transition hover:bg-[var(--accent-primary)] hover:text-white">Server settings</button>
                <button wire:click="$set('auditOpen', true); open = false" class="w-full rounded-lg px-2.5 py-2 text-left font-semibold text-[var(--text-main)] transition hover:bg-[var(--accent-primary)] hover:text-white">Audit log</button>
            @endif
            <div class="my-1 h-px bg-[var(--border-subtle)]"></div>
            @auth
                <button wire:click="leaveCommunity" class="w-full rounded-lg px-2.5 py-2 text-left font-semibold text-rose-400 transition hover:bg-rose-500 hover:text-white">Leave server</button>
            @endauth
        </div>
    </div>

    {{-- Channels --}}
    <div class="min-h-0 flex-1 space-y-3 overflow-y-auto px-2 py-3">
        @foreach($groups as $group)
            <div x-data="{ open: true }">
                @if($group['name'])
                    <div class="flex items-center justify-between">
                        <button @click="open = !open" class="flex min-w-0 flex-1 items-center gap-1 px-1 py-0.5 text-[11px] font-bold uppercase tracking-wider text-[var(--text-dim)] transition hover:text-[var(--text-main)]">
                            <svg class="h-3 w-3 shrink-0 transition" :class="open ? 'rotate-90' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M9 5l7 7-7 7"/></svg>
                            <span class="truncate">{{ $group['name'] }}</span>
                        </button>
                        @if($permissions['manage_channels'])
                            <button wire:click="deleteCategory({{ $group['id'] }})" wire:confirm="Delete this category? Channels will be kept." class="rounded px-1 text-[var(--text-dim)] transition hover:text-rose-400" title="Delete category">✕</button>
                        @endif
                    </div>
                @endif

                <div x-show="open" class="mt-0.5 space-y-0.5">
                    @foreach($group['channels'] as $item)
                        @php
                            $isActive = (int) $item->id === (int) $activeChannel->id;
                            $channelMentions = $unread['mentions'][$item->id] ?? 0;
                            $channelUnread = $unread['unread'][$item->id] ?? 0;
                            $childThreads = $threadsByParent->get($item->id, collect());
                        @endphp
                        <div x-data="{ threads: {{ $childThreads->contains('id', $activeChannel->id) ? 'true' : 'false' }} }">
                            <a href="{{ route('lounge.community', ['slug' => $community->slug, 'channel' => $item->slug]) }}"
                               class="group flex items-center gap-1.5 rounded-md px-2 py-1.5 text-[15px] transition {{ $isActive ? 'bg-[var(--bg-surface-elevated)] text-[var(--text-main)]' : 'text-[var(--text-muted)] hover:bg-[var(--bg-surface-elevated)] hover:text-[var(--text-main)]' }}">
                                <span class="w-4 shrink-0 text-center text-base leading-none opacity-70">{{ $item->icon() }}</span>
                                <span class="min-w-0 flex-1 truncate {{ $channelUnread > 0 && ! $isActive ? 'font-semibold text-[var(--text-main)]' : '' }}">{{ $item->name }}</span>
                                @if($item->is_read_only)
                                    <span class="shrink-0 text-[10px] opacity-60" title="Read only">🔒</span>
                                @endif
                                @if($channelMentions > 0 && ! $isActive)
                                    <span class="ml-auto shrink-0 rounded-full bg-rose-500 px-1.5 text-[11px] font-bold leading-5 text-white">{{ $channelMentions > 99 ? '99+' : $channelMentions }}</span>
                                @elseif($channelUnread > 0 && ! $isActive)
                                    <span class="ml-auto h-2 w-2 shrink-0 rounded-full bg-[var(--text-main)]"></span>
                                @endif
                                @if($childThreads->isNotEmpty())
                                    <button type="button" @click.prevent="threads = ! threads" class="ml-auto shrink-0 rounded px-1 text-[11px] text-[var(--text-dim)] transition hover:text-[var(--text-main)]" title="Toggle threads">
                                        <span x-text="threads ? '▾' : '▸'"></span>
                                    </button>
                                @endif
                            </a>

                            @if($childThreads->isNotEmpty())
                                <div x-show="threads" x-cloak class="ml-4 mt-0.5 space-y-0.5 border-l border-[var(--border-subtle)] pl-2">
                                    @foreach($childThreads as $thread)
                                        @php($threadActive = (int) $thread->id === (int) $activeChannel->id)
                                        <a href="{{ route('lounge.community', ['slug' => $community->slug, 'channel' => $thread->slug]) }}"
                                           class="group flex items-center gap-1.5 rounded-md px-2 py-1 text-[13px] {{ $threadActive ? 'bg-[var(--bg-surface-elevated)] text-[var(--text-main)]' : 'text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">
                                            <span class="opacity-60">❞</span>
                                            <span class="min-w-0 flex-1 truncate">{{ \Illuminate\Support\Str::limit($thread->name, 26) }}</span>
                                            <span class="shrink-0 text-[10px] text-[var(--text-dim)]">{{ $thread->message_count }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        @if($isMember && $permissions['manage_channels'])
            <div class="space-y-1 pt-1">
                <button wire:click="$set('createChannelOpen', true)" class="w-full rounded-md px-2 py-1.5 text-left text-[13px] font-semibold text-[var(--text-dim)] transition hover:bg-[var(--bg-surface-elevated)] hover:text-[var(--accent-light)]">＋ Create channel</button>
                <button wire:click="$set('createCategoryOpen', true)" class="w-full rounded-md px-2 py-1.5 text-left text-[13px] font-semibold text-[var(--text-dim)] transition hover:bg-[var(--bg-surface-elevated)] hover:text-[var(--accent-light)]">＋ Create category</button>
            </div>
        @endif
    </div>

    {{-- User panel --}}
    <div class="shrink-0 border-t border-black/20 bg-[var(--bg-page)]/70 px-2 py-2">
        @auth
            @php($displayName = $myMember?->displayName() ?? Auth::user()->name)
            <div class="flex items-center gap-2">
                <x-lounge.avatar :user="Auth::user()" :presence="$myMember?->presenceState() ?? 'online'" :size="8" />
                <div class="min-w-0 flex-1 leading-tight">
                    <div class="truncate text-[13px] font-bold text-[var(--text-main)]">{{ $displayName }}</div>
                    @if($myMember?->status_text)
                        <div class="truncate text-[11px] text-[var(--text-dim)]">{{ $myMember->status_emoji }} {{ $myMember->status_text }}</div>
                    @else
                        <div class="truncate text-[11px] text-[var(--text-dim)]">{{ '@'.Auth::user()->username }}</div>
                    @endif
                </div>

                <div x-data="{ presenceOpen: false }" class="relative">
                    <button @click="presenceOpen = ! presenceOpen" class="flex h-7 w-7 items-center justify-center rounded-md text-[var(--text-muted)] transition hover:bg-[var(--bg-surface-elevated)] hover:text-[var(--text-main)]" title="Set status">
                        <span class="inline-block h-2.5 w-2.5 rounded-full {{ $myMember?->presenceState() === 'online' ? 'bg-emerald-500' : ($myMember?->presenceState() === 'idle' ? 'bg-amber-400' : ($myMember?->presenceState() === 'dnd' ? 'bg-rose-500' : 'bg-[var(--text-dim)]')) }}"></span>
                    </button>
                    <div x-show="presenceOpen" x-cloak @click.outside="presenceOpen = false" class="absolute bottom-9 right-0 z-50 w-48 space-y-0.5 rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface-elevated)] p-2 text-sm shadow-2xl">
                        @foreach(['online' => 'Online', 'idle' => 'Idle', 'dnd' => 'Do Not Disturb', 'invisible' => 'Invisible'] as $value => $label)
                            <button wire:click="setPresence('{{ $value }}'); presenceOpen = false" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left transition hover:bg-[var(--bg-surface)] {{ $presence === $value ? 'text-[var(--accent-light)]' : 'text-[var(--text-main)]' }}">
                                <span class="inline-block h-2.5 w-2.5 rounded-full {{ $value === 'online' ? 'bg-emerald-500' : ($value === 'idle' ? 'bg-amber-400' : ($value === 'dnd' ? 'bg-rose-500' : 'bg-[var(--text-dim)]')) }}"></span>
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <button wire:click="toggleMute" class="flex h-7 w-7 items-center justify-center rounded-md transition hover:bg-[var(--bg-surface-elevated)] {{ $myMember?->is_muted ? 'text-rose-400' : 'text-[var(--text-muted)] hover:text-[var(--text-main)]' }}" title="{{ $myMember?->is_muted ? 'Unmute server' : 'Mute server' }}">
                    @if($myMember?->is_muted)
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707A1 1 0 0112 5v14a1 1 0 01-1.707.707L5.586 15zM17 9l4 6m0-6l-4 6"/></svg>
                    @else
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.536 8.464a5 5 0 010 7.072M12 6v12m-6.414-3H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707A1 1 0 0112 5v14a1 1 0 01-1.707.707L5.586 15z"/></svg>
                    @endif
                </button>

                <button wire:click="openUserSettings" class="flex h-7 w-7 items-center justify-center rounded-md text-[var(--text-muted)] transition hover:bg-[var(--bg-surface-elevated)] hover:text-[var(--text-main)]" title="Profile settings">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                </button>
            </div>
        @else
            <a href="{{ route('login') }}" class="block rounded-lg accent-bg px-3 py-2 text-center text-sm font-bold text-white">Log in</a>
        @endauth
    </div>
</aside>
