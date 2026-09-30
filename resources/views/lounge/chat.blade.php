{{--
    Discord-style chat surface: channel header, grouped message list, polls, typing indicator and composer.
--}}
@php
    $channelThreads = $threadsByParent->get($activeChannel->id, collect());
@endphp

<header class="flex h-12 shrink-0 items-center gap-2 border-b border-black/20 px-4 shadow-sm">
    <button @click="sidebarOpen = ! sidebarOpen" class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-[var(--text-muted)] transition hover:bg-[var(--bg-surface)] hover:text-[var(--text-main)] md:hidden" aria-label="Toggle channels">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    @if($thread)
        <a href="{{ route('lounge.community', ['slug' => $community->slug, 'channel' => $threadParent?->slug ?? 'general']) }}" class="flex h-7 w-7 items-center justify-center rounded-md text-[var(--text-muted)] transition hover:bg-[var(--bg-surface)] hover:text-[var(--text-main)]" title="Back to channel">←</a>
        <span class="text-lg opacity-70">❞</span>
        <h1 class="min-w-0 truncate text-[15px] font-bold text-[var(--text-main)]">{{ $thread->name }}</h1>
        <span class="shrink-0 rounded bg-[var(--bg-page)] px-1.5 py-0.5 text-[11px] font-semibold text-[var(--text-dim)]">Thread</span>
        @if($threadParent)
            <span class="hidden shrink-0 text-[11px] text-[var(--text-dim)] sm:inline">in #{{ $threadParent->name }}</span>
        @endif
    @else
        <span class="text-xl leading-none text-[var(--text-dim)]">{{ $activeChannel->icon() }}</span>
        <h1 class="shrink-0 text-[15px] font-bold text-[var(--text-main)]">{{ $activeChannel->name }}</h1>
        @if($activeChannel->description)
            <span class="mx-1 hidden h-5 w-px shrink-0 bg-[var(--border-medium)] sm:block"></span>
            <p class="hidden min-w-0 truncate text-[13px] text-[var(--text-muted)] sm:block">{{ $activeChannel->description }}</p>
        @endif
        @if($activeChannel->is_nsfw)
            <span class="shrink-0 rounded bg-rose-500/20 px-1.5 py-0.5 text-[10px] font-bold uppercase text-rose-400">NSFW</span>
        @endif
        @if($activeChannel->is_read_only)
            <span class="shrink-0 rounded bg-[var(--bg-page)] px-1.5 py-0.5 text-[11px] font-semibold text-[var(--text-dim)]">Read only</span>
        @endif
        @if($activeChannel->slowmode_seconds > 0)
            <span class="hidden shrink-0 rounded bg-[var(--bg-page)] px-1.5 py-0.5 text-[11px] font-semibold text-[var(--text-dim)] sm:inline">Slowmode {{ $activeChannel->slowmode_seconds }}s</span>
        @endif
    @endif

    <div class="ml-auto flex shrink-0 items-center gap-1">
        @if($isMember && $channelThreads->isNotEmpty())
            <div x-data="{ open: false }" class="relative">
                <button @click="open = ! open" class="flex items-center gap-1.5 rounded-md px-2 py-1 text-[13px] font-semibold text-[var(--text-muted)] transition hover:bg-[var(--bg-surface)] hover:text-[var(--text-main)]" title="Threads">
                    ❞ <span class="hidden sm:inline">Threads</span>
                    <span class="rounded-full bg-[var(--bg-page)] px-1.5 text-[11px]">{{ $channelThreads->count() }}</span>
                </button>
                <div x-show="open" x-cloak @click.outside="open = false" class="absolute right-0 top-9 z-40 w-72 space-y-0.5 rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface-elevated)] p-2 shadow-2xl">
                    @foreach($channelThreads as $threadItem)
                        <a href="{{ route('lounge.community', ['slug' => $community->slug, 'channel' => $threadItem->slug]) }}" class="flex items-center gap-2 rounded-lg px-2 py-1.5 transition hover:bg-[var(--bg-surface)]">
                            <span class="opacity-60">❞</span>
                            <span class="min-w-0 flex-1 truncate text-sm text-[var(--text-main)]">{{ $threadItem->name }}</span>
                            <span class="shrink-0 text-[11px] text-[var(--text-dim)]">{{ $threadItem->message_count }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        @if($thread && Auth::check() && (Auth::id() === $community->owner_id || Auth::user()?->isAdmin() || $community->hasPermission(Auth::user(), 'manage_channels')))
            <button wire:click="lockThread({{ $thread->id }})"
                    class="flex h-7 items-center gap-1.5 rounded-md px-2 text-[13px] font-semibold transition {{ $thread->thread_locked ? 'text-amber-400' : 'text-[var(--text-muted)] hover:bg-[var(--bg-surface)] hover:text-[var(--text-main)]' }}"
                    title="{{ $thread->thread_locked ? 'Unlock this thread' : 'Lock this thread' }}">
                {{ $thread->thread_locked ? '🔒' : '🔓' }} <span class="hidden sm:inline">{{ $thread->thread_locked ? 'Locked' : 'Lock' }}</span>
            </button>
            <button wire:click="archiveThread({{ $thread->id }})"
                    class="flex h-7 items-center gap-1.5 rounded-md px-2 text-[13px] font-semibold transition {{ $thread->thread_archived ? 'text-amber-400' : 'text-[var(--text-muted)] hover:bg-[var(--bg-surface)] hover:text-[var(--text-main)]' }}"
                    title="{{ $thread->thread_archived ? 'Unarchive this thread' : 'Archive this thread' }}">
                🗄️ <span class="hidden sm:inline">{{ $thread->thread_archived ? 'Archived' : 'Archive' }}</span>
            </button>
        @endif

        @if($activeChannel->isText() || $activeChannel->isThread())
            <button wire:click="openPins" class="flex h-7 items-center gap-1.5 rounded-md px-2 text-[13px] font-semibold text-[var(--text-muted)] transition hover:bg-[var(--bg-surface)] hover:text-[var(--text-main)]" title="Pinned messages">📌 <span class="hidden sm:inline">Pins</span></button>
            <div class="hidden items-center rounded-md bg-[var(--bg-page)] px-2 sm:flex">
                <svg class="h-3.5 w-3.5 text-[var(--text-dim)]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 10a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input wire:model.live.debounce.300ms="search" @focus="$wire.openSearch()" type="search" placeholder="Search" class="w-28 bg-transparent px-2 py-1 text-[13px] text-[var(--text-main)] outline-none placeholder:text-[var(--text-dim)] lg:w-40">
            </div>
        @endif

        <button wire:click="toggleMemberList" class="flex h-7 w-7 items-center justify-center rounded-md text-[var(--text-muted)] transition hover:bg-[var(--bg-surface)] hover:text-[var(--text-main)]" title="Toggle member list">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6-4a3 3 0 11-3-3 3 3 0 013 3z"/></svg>
        </button>

        @if($isMember)
            <button wire:click="openUserSettings" class="flex h-7 w-7 items-center justify-center rounded-md text-[var(--text-muted)] transition hover:bg-[var(--bg-surface)] hover:text-[var(--text-main)]" title="Notification settings">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
            </button>
        @endif

        @if($permissions['manage_channels'])
            <button wire:click="openChannelSettings({{ $activeChannel->id }})" class="flex h-7 w-7 items-center justify-center rounded-md text-[var(--text-muted)] transition hover:bg-[var(--bg-surface)] hover:text-[var(--text-main)]" title="Edit channel">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </button>
        @endif
    </div>
</header>

{{-- message list --}}
<div x-ref="messageScroll" wire:key="lounge-messages-{{ $activeChannel->id }}" class="min-h-0 flex-1 overflow-y-auto pb-6">
    @if(! $isMember)
        <div class="flex h-full flex-col items-center justify-center px-6 text-center">
            <div class="flex h-16 w-16 items-center justify-center rounded-3xl bg-[var(--bg-surface)] text-3xl">{{ $activeChannel->icon() }}</div>
            <h2 class="mt-4 text-2xl font-black text-[var(--text-main)]">
                {{ $membership?->status === 'pending' ? 'Your request is pending' : 'You are previewing #'.$activeChannel->name }}
            </h2>
            <p class="mt-2 max-w-md text-sm text-[var(--text-muted)]">
                {{ $membership?->status === 'pending'
                    ? 'A moderator will review your request to join ' . $community->name . '.'
                    : 'Join ' . $community->name . ' to chat, react, start threads and see the member list.' }}
            </p>
            @if(Auth::check() && $membership?->status !== 'pending' && $community->visibility !== 'invite')
                <form wire:submit="joinCommunity" class="mt-5 w-full max-w-md space-y-2">
                    @if(count($community->onboarding_questions ?? []))
                        <textarea wire:model="joinAnswer" rows="2" placeholder="{{ $community->onboarding_questions[0] }}" class="w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] p-3 text-sm text-[var(--text-main)]"></textarea>
                    @endif
                    <button class="rounded-xl accent-bg px-5 py-2.5 text-sm font-bold text-white">{{ $community->visibility === 'approval' ? 'Request to join' : 'Join community' }}</button>
                </form>
            @elseif($membership?->status === 'banned')
                <p class="mt-4 rounded-xl bg-rose-500/10 px-4 py-2 text-sm font-bold text-rose-400">You are banned from this community.</p>
            @endif
        </div>
    @else
        {{-- channel intro --}}
        <div class="px-4 pb-2 pt-8">
            <div class="flex h-16 w-16 items-center justify-center rounded-full bg-[var(--bg-surface)] text-3xl">{{ $activeChannel->icon() }}</div>
            <h2 class="mt-3 text-3xl font-black text-[var(--text-main)]">Welcome to #{{ $activeChannel->name }}</h2>
            <p class="mt-1 text-sm text-[var(--text-muted)]">
                {{ $activeChannel->description ?: 'This is the start of the #'.$activeChannel->name.' channel.' }}
            </p>
            @if($activeChannel->isThread() && $threadParent)
                <a href="{{ route('lounge.community', ['slug' => $community->slug, 'channel' => $threadParent->slug]) }}" class="mt-2 inline-flex text-sm font-semibold text-[var(--accent-light)] hover:underline">← Back to #{{ $threadParent->name }}</a>
            @endif
        </div>

        @forelse($messages as $message)
            <div wire:key="message-{{ $message->id }}">
                @include('lounge.message')
            </div>
        @empty
            <div class="px-4 py-6 text-sm text-[var(--text-dim)]">No messages yet — say hello. 👋</div>
        @endforelse

        {{-- polls --}}
        @if($polls->isNotEmpty())
            <div class="space-y-3 px-4 pt-4">
                @foreach($polls as $poll)
                    <section wire:key="poll-{{ $poll->id }}" class="max-w-lg rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-4">
                        <div class="mb-2 flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">
                            <span>📊 Poll</span>
                        </div>
                        <h3 class="font-bold text-[var(--text-main)]">{{ $poll->question }}</h3>
                        <div class="mt-2 space-y-1.5">
                            @foreach($pollOptionGroups->get($poll->id, collect()) as $option)
                                <button wire:click="vote({{ $poll->id }}, {{ $option->id }})" wire:key="poll-option-{{ $option->id }}"
                                        class="flex w-full items-center gap-2 rounded-lg bg-[var(--bg-page)] p-2 text-left text-sm transition hover:ring-1 hover:ring-[var(--accent-primary)]">
                                    <span class="min-w-0 flex-1 break-words text-[var(--text-main)]">{{ $option->label }}</span>
                                    <span class="shrink-0 text-xs font-bold text-[var(--text-muted)]">{{ $pollVotes->get($option->id, 0) }}</span>
                                </button>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        @endif
    @endif
</div>

{{-- composer --}}
@if($isMember)
    <div class="shrink-0 px-4 pb-6"
         x-data="{
             attach: false,
             pollPanel: false,
             emojiPanel: false,
             commands: [
                 { name: '/shrug', desc: 'Append ¯\\_(ツ)_/¯' },
                 { name: '/tableflip', desc: 'Flip a table' },
                 { name: '/unflip', desc: 'Unflip a table' },
                 { name: '/me', desc: 'Write an italic action' },
                 { name: '/spoiler', desc: 'Hide text until clicked' },
                 { name: '/nick', desc: 'Change your nickname' },
                 { name: '/status', desc: 'Set your custom status' },
                 { name: '/invite', desc: 'Create an invite link' },
                 { name: '/help', desc: 'List all commands' }
             ],
             members: @js($activeMembers->map(fn ($member) => ['id' => $member->user_id, 'name' => $member->displayName(), 'username' => $member->user?->username, 'avatar' => $member->user?->avatar_url])->values()),
             mentionQuery() {
                 const match = ($wire.body || '').match(/(?:^|\s)@([A-Za-z0-9_.\-]*)$/);
                 return match ? match[1].toLowerCase() : null;
             },
             mentionMatches() {
                 const query = this.mentionQuery();
                 if (query === null) return [];
                 const options = [{ id: 'everyone', name: '@everyone', username: 'everyone', avatar: null }];
                 return options.concat(this.members.filter((m) => (m.username || '').toLowerCase().startsWith(query) || (m.name || '').toLowerCase().startsWith(query))).slice(0, 8);
             },
             insertMention(member) {
                 $wire.set('body', ($wire.body || '').replace(/(?:^|\s)@([A-Za-z0-9_.\-]*)$/, (full) => full.replace(/@[A-Za-z0-9_.\-]*$/, '@' + member.username + ' ')));
                 this.$refs.composerInput?.focus();
             }
         }">
        @if($editingMessageId)
            <div class="flex items-center justify-between rounded-t-xl border-x border-t border-[var(--border-subtle)] bg-[var(--bg-surface)] px-4 py-2 text-xs">
                <span class="text-[var(--text-muted)]">Editing message</span>
                <button wire:click="cancelEditing" class="font-semibold text-[var(--text-muted)] hover:text-[var(--text-main)]">Cancel</button>
            </div>
        @elseif($replyingTo)
            <div class="flex items-center justify-between rounded-t-xl border-x border-t border-[var(--border-subtle)] bg-[var(--bg-surface)] px-4 py-2 text-xs">
                <span class="text-[var(--text-muted)]">Replying to a message</span>
                <button wire:click="cancelReply" class="font-semibold text-[var(--text-muted)] hover:text-[var(--text-main)]">Cancel</button>
            </div>
        @endif

        <form wire:submit="sendMessage" class="rounded-b-xl rounded-t-xl bg-[var(--bg-page)] px-4 pt-2.5 {{ $editingMessageId || $replyingTo ? 'rounded-t-none' : '' }} {{ $activeChannel->is_read_only ? 'opacity-60' : '' }}">
            @error('body') <p class="mb-1 text-xs text-rose-400">{{ $message }}</p> @enderror

            @if($attachmentUrl)
                <div class="mb-2 flex items-center gap-2 rounded-lg border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-2 text-xs">
                    <img src="{{ $attachmentUrl }}" alt="" class="h-10 w-10 rounded object-cover">
                    <span class="min-w-0 flex-1 truncate text-[var(--text-muted)]">{{ $attachmentUrl }}</span>
                    <button type="button" wire:click="$set('attachmentUrl', '')" class="text-rose-400">Remove</button>
                </div>
            @endif

            @if($editingMessageId)
                <div class="flex items-end gap-2">
                    <textarea wire:model="editingBody" rows="1" class="max-h-48 min-h-[24px] flex-1 resize-none bg-transparent py-2 text-[15px] text-[var(--text-main)] outline-none" @keydown.enter.prevent="if (! $event.shiftKey) { $wire.saveEdit(); }"></textarea>
                    <button type="submit" class="mb-1 rounded-lg accent-bg px-3 py-1.5 text-xs font-bold text-white">Save</button>
                </div>
            @else
                {{-- member mention autocomplete --}}
                <div x-show="mentionMatches().length" x-cloak class="mb-2 max-h-56 overflow-y-auto rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface-elevated)] p-1.5 shadow-2xl">
                    <template x-for="member in mentionMatches()" :key="member.id">
                        <button type="button" @click="insertMention(member)" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left transition hover:bg-[var(--bg-surface)]">
                            <template x-if="member.avatar">
                                <img :src="member.avatar" alt="" class="h-6 w-6 rounded-full object-cover">
                            </template>
                            <template x-if="! member.avatar">
                                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-[var(--bg-page)] text-[11px] font-bold text-[var(--text-muted)]">@</span>
                            </template>
                            <span class="text-sm font-semibold text-[var(--text-main)]" x-text="member.name"></span>
                            <span class="text-xs text-[var(--text-dim)]" x-text="'@' + member.username"></span>
                        </button>
                    </template>
                </div>

                {{-- slash command autocomplete --}}
                <div x-show="($wire.body || '').startsWith('/')" x-cloak class="mb-2 max-h-56 overflow-y-auto rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface-elevated)] p-1.5 shadow-2xl">
                    <template x-for="command in commands.filter(c => c.name.startsWith(($wire.body || '').split(/\s+/)[0]))" :key="command.name">
                        <button type="button" @click="$wire.set('body', command.name + ' ')" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left transition hover:bg-[var(--bg-surface)]">
                            <span class="font-mono text-sm font-bold text-[var(--accent-light)]" x-text="command.name"></span>
                            <span class="text-xs text-[var(--text-muted)]" x-text="command.desc"></span>
                        </button>
                    </template>
                </div>

                @if($attachmentUrl === '')
                    <div x-show="attach" x-cloak class="mb-2 flex flex-wrap gap-2">
                        <input wire:model="attachmentUrl" type="url" placeholder="Image or GIF URL (https://…)" class="min-w-0 flex-1 rounded-lg border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-3 py-2 text-sm text-[var(--text-main)]">
                        <select wire:model="attachmentType" class="rounded-lg border border-[var(--border-subtle)] bg-[var(--bg-surface)] px-3 py-2 text-sm text-[var(--text-main)]">
                            <option value="image">Image</option>
                            <option value="gif">GIF</option>
                        </select>
                    </div>
                @endif

                @if($activeChannel->isText() && ! $activeChannel->isThread() && $activeChannel->isPostable())
                    <div x-show="pollPanel" x-cloak class="mb-2 space-y-2 rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-3">
                        <div class="text-[11px] font-bold uppercase tracking-wide text-[var(--text-dim)]">New poll</div>
                        <input wire:model="pollQuestion" type="text" maxlength="200" placeholder="Poll question"
                               class="w-full rounded-lg border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm text-[var(--text-main)]">
                        @error('pollQuestion') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                        <textarea wire:model="pollOptions" rows="4" maxlength="1200" placeholder="One option per line (at least two)"
                                  class="w-full rounded-lg border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm text-[var(--text-main)]"></textarea>
                        @error('pollOptions') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                        <div class="flex items-center gap-2">
                            <button type="button" wire:click="createPoll" class="rounded-lg accent-bg px-3 py-1.5 text-xs font-bold text-white">Create poll</button>
                            <button type="button" @click="pollPanel = false" class="text-xs font-semibold text-[var(--text-muted)] hover:text-[var(--text-main)]">Cancel</button>
                        </div>
                    </div>
                @endif

                <div class="flex items-end gap-3">
                    <button type="button" @click="attach = ! attach" class="mb-2 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[var(--bg-surface)] text-lg font-bold text-[var(--text-muted)] transition hover:text-[var(--text-main)]" title="Attach image URL">＋</button>

                    @if($activeChannel->isText() && ! $activeChannel->isThread() && $activeChannel->isPostable())
                        <button type="button" @click="pollPanel = ! pollPanel" class="mb-2 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[var(--bg-surface)] text-sm font-bold text-[var(--text-muted)] transition hover:text-[var(--text-main)]" title="Create a poll">📊</button>
                    @endif

                    <textarea wire:model="body" rows="1" x-ref="composerInput" @input="notifyTyping()"
                              @keydown.enter.prevent="if (! $event.shiftKey) { $wire.sendMessage(); }"
                              placeholder="{{ $activeChannel->is_read_only ? 'This channel is read only' : 'Message #'.$activeChannel->name }}"
                              @if($activeChannel->is_read_only) disabled @endif
                              class="max-h-48 min-h-[24px] flex-1 resize-none bg-transparent py-2 text-[15px] leading-[1.4] text-[var(--text-main)] outline-none placeholder:text-[var(--text-dim)]"></textarea>

                    <div class="relative mb-1">
                        <button type="button" @click="emojiPanel = ! emojiPanel" class="flex h-7 w-7 items-center justify-center rounded text-lg text-[var(--text-muted)] transition hover:text-[var(--text-main)]" title="Emoji">🙂</button>
                        <div x-show="emojiPanel" x-cloak @click.outside="emojiPanel = false" class="absolute bottom-9 right-0 z-40 w-64 rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface-elevated)] p-2 shadow-2xl">
                            @if($emoji->isNotEmpty())
                                <div class="mb-2 text-[11px] font-bold uppercase tracking-wide text-[var(--text-dim)]">Server emoji</div>
                                <div class="mb-2 flex flex-wrap gap-1">
                                    @foreach($emoji as $custom)
                                        <button type="button" @click="$wire.set('body', ($wire.body || '') + ' :{{ $custom->name }}: '); emojiPanel = false" class="rounded p-1 transition hover:bg-[var(--bg-surface)]" title=":{{ $custom->name }}:">
                                            <img src="{{ $custom->image_url }}" alt=":{{ $custom->name }}:" class="h-6 w-6">
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                            <div class="mb-1 text-[11px] font-bold uppercase tracking-wide text-[var(--text-dim)]">Emoji</div>
                            <div class="grid grid-cols-8 gap-0.5">
                                @foreach(['😀','😂','🥹','😍','😎','🤔','🙃','😴','🔥','✨','❤️','💜','👍','👎','👏','🙌','🎉','😭','😅','🤯','👀','💀','🤝','🫡','🌸','🎨','🖌️','📌','⭐','🍀','☕','🌙'] as $glyph)
                                    <button type="button" @click="$wire.set('body', ($wire.body || '') + '{{ $glyph }}')" class="rounded p-1 text-lg transition hover:bg-[var(--bg-surface)]">{{ $glyph }}</button>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <button type="submit" @if($activeChannel->is_read_only) disabled @endif class="mb-1 flex h-7 w-7 items-center justify-center rounded text-[var(--accent-light)] transition hover:text-[var(--accent-primary)] disabled:opacity-40" title="Send">
                        <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24"><path d="M3.4 20.4l17.45-7.48a1 1 0 000-1.84L3.4 3.6a.993.993 0 00-1.39.91L2 9.12c0 .5.37.93.87.99L17 12 2.87 13.88c-.5.07-.87.5-.87 1l.01 4.61c0 .71.73 1.2 1.39.91z"/></svg>
                    </button>
                </div>
            @endif
        </form>

        <div class="h-5 px-1 pt-1 text-xs text-[var(--text-muted)]">
            <span x-show="typingLabel()" x-cloak x-text="typingLabel()"></span>
            @if($activeChannel->slowmode_seconds > 0)
                <span class="text-[var(--text-dim)]">· Slowmode {{ $activeChannel->slowmode_seconds }}s</span>
            @endif
        </div>
    </div>
@endif
