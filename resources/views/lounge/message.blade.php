{{--
    A single chat message row (Discord density: grouped by author, hover toolbar, reactions, threads).
--}}
@php
    $thread = $threadsByMessage[$message->id] ?? null;
    $quickEmoji = ['👍', '🔥', '❤️', '😂', '🎉'];
    $canRemove = $message->is_own || $permissions['delete_messages'];
@endphp

@if($message->day_divider)
    <div class="relative mx-4 my-4 flex items-center gap-3">
        <div class="h-px flex-1 bg-[var(--border-subtle)]"></div>
        <span class="shrink-0 text-[11px] font-bold text-[var(--text-dim)]">
            {{ $message->created_at->isToday() ? 'Today' : ($message->created_at->isYesterday() ? 'Yesterday' : $message->created_at->format('F j, Y')) }}
        </span>
        <div class="h-px flex-1 bg-[var(--border-subtle)]"></div>
    </div>
@endif

<article x-data="{ menu: false, picker: false }"
         class="group relative px-4 py-[1px] transition-colors hover:bg-[var(--bg-page)]/40 {{ $message->mention_me ? 'border-l-2 border-amber-400 bg-amber-500/[0.06]' : '' }}">

    <div class="flex gap-3 py-0.5">
        <div class="w-10 shrink-0">
            @if($message->group_start)
                <x-lounge.avatar :user="$message->author" :dot="false" :size="10" class="mt-0.5" />
            @else
                <span class="hidden pt-1 text-right font-mono text-[10px] leading-5 text-[var(--text-dim)] group-hover:block">{{ $message->created_at->format('g:i A') }}</span>
            @endif
        </div>

        <div class="min-w-0 flex-1">
            @if($message->group_start)
                <div class="flex flex-wrap items-center gap-2">
                    <button wire:click="openProfile({{ $message->user_id }})" class="text-[15px] font-semibold hover:underline" style="color: {{ $message->author_color }}">
                        {{ $message->author_display }}
                    </button>
                    @if($message->author?->is_artist)
                        <span class="rounded bg-[var(--accent-primary)]/20 px-1 text-[10px] font-bold uppercase tracking-wide text-[var(--accent-light)]">Artist</span>
                    @endif
                    <time class="text-[11px] text-[var(--text-dim)]">{{ $message->created_at->format('M j, Y g:i A') }}</time>
                </div>
            @endif

            @if($message->reply_preview)
                <div class="mb-1 flex items-center gap-1.5 text-xs text-[var(--text-muted)]">
                    <span class="text-[var(--text-dim)]">↪</span>
                    <span class="font-semibold">{{ $message->reply_preview['author'] }}</span>
                    <span class="min-w-0 truncate text-[var(--text-dim)]">{{ $message->reply_preview['body'] }}</span>
                </div>
            @endif

            @if($message->body_html !== '')
                <div class="break-words text-[15px] leading-[1.45] text-[var(--text-main)]">{!! $message->body_html !!}</div>
            @endif

            @if($message->attachment_url)
                <a href="{{ $message->attachment_url }}" target="_blank" rel="noopener noreferrer" class="mt-1.5 inline-block max-w-md">
                    <img src="{{ $message->attachment_url }}" alt="Shared {{ $message->attachment_type }}" loading="lazy" class="max-h-80 rounded-lg border border-[var(--border-subtle)] object-contain">
                </a>
            @endif

            @if($message->is_pinned)
                <div class="mt-1 text-[11px] font-semibold text-amber-400">📌 Pinned message</div>
            @endif

            {{-- reactions --}}
            @if($message->reaction_groups->isNotEmpty())
                <div class="mt-1.5 flex flex-wrap items-center gap-1">
                    @foreach($message->reaction_groups as $reaction)
                        <button wire:click="toggleReaction({{ $message->id }}, '{{ $reaction['emoji'] }}')"
                                class="flex items-center gap-1 rounded-lg border px-1.5 py-0.5 text-xs transition {{ $reaction['mine'] ? 'border-[var(--accent-primary)] bg-[var(--accent-primary)]/20 text-[var(--accent-light)]' : 'border-[var(--border-subtle)] bg-[var(--bg-page)] text-[var(--text-muted)] hover:border-[var(--border-medium)]' }}">
                            <span>{{ $reaction['emoji'] }}</span>
                            <span class="font-bold">{{ $reaction['count'] }}</span>
                        </button>
                    @endforeach
                    <div class="relative">
                        <button @click="picker = ! picker" class="flex items-center rounded-lg border border-[var(--border-subtle)] bg-[var(--bg-page)] px-1.5 py-0.5 text-xs text-[var(--text-muted)] opacity-0 transition hover:border-[var(--border-medium)] group-hover:opacity-100" title="Add reaction">＋</button>
                        <div x-show="picker" x-cloak @click.outside="picker = false" class="absolute bottom-8 left-0 z-30 flex items-center gap-1 rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface-elevated)] p-1.5 shadow-2xl">
                            @foreach($quickEmoji as $glyph)
                                <button wire:click="toggleReaction({{ $message->id }}, '{{ $glyph }}')" @click="picker = false" class="rounded p-1 text-lg transition hover:bg-[var(--bg-surface)]">{{ $glyph }}</button>
                            @endforeach
                            @foreach($emoji->take(8) as $custom)
                                <button wire:click="toggleReaction({{ $message->id }}, ':{{ $custom->name }}:')" @click="picker = false" class="rounded p-1 transition hover:bg-[var(--bg-surface)]"><img src="{{ $custom->image_url }}" alt=":{{ $custom->name }}:" class="h-6 w-6"></button>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            {{-- thread indicator --}}
            @if($thread)
                <button wire:click="createThread({{ $message->id }})" class="mt-1.5 flex items-center gap-1.5 rounded-lg border border-[var(--border-subtle)] bg-[var(--bg-page)] px-2 py-1 text-xs font-semibold text-[var(--accent-light)] transition hover:border-[var(--accent-primary)]">
                    ❞ {{ $thread->message_count }} {{ \Illuminate\Support\Str::plural('reply', $thread->message_count) }}
                    <span class="text-[var(--text-dim)]">· {{ $thread->name }}</span>
                </button>
            @endif

            @if($message->edited_at)
                <span class="ml-1 text-[10px] text-[var(--text-dim)]">(edited)</span>
            @endif
        </div>
    </div>

    {{-- hover toolbar --}}
    <div class="absolute -top-3 right-4 z-20 hidden items-center gap-0.5 rounded-lg border border-[var(--border-subtle)] bg-[var(--bg-surface-elevated)] p-0.5 shadow-xl group-hover:flex">
        <div class="relative">
            <button @click="picker = ! picker" class="flex h-7 w-7 items-center justify-center rounded text-sm text-[var(--text-muted)] transition hover:bg-[var(--bg-surface)] hover:text-[var(--text-main)]" title="Add reaction">🙂</button>
            <div x-show="picker" x-cloak @click.outside="picker = false" class="absolute right-0 top-8 z-30 flex items-center gap-1 rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-surface-elevated)] p-1.5 shadow-2xl">
                @foreach($quickEmoji as $glyph)
                    <button wire:click="toggleReaction({{ $message->id }}, '{{ $glyph }}')" @click="picker = false" class="rounded p-1 text-lg transition hover:bg-[var(--bg-surface)]">{{ $glyph }}</button>
                @endforeach
                @foreach($emoji->take(6) as $custom)
                    <button wire:click="toggleReaction({{ $message->id }}, ':{{ $custom->name }}:')" @click="picker = false" class="rounded p-1 transition hover:bg-[var(--bg-surface)]"><img src="{{ $custom->image_url }}" alt=":{{ $custom->name }}:" class="h-6 w-6"></button>
                @endforeach
            </div>
        </div>

        @if($isMember)
            <button wire:click="setReply({{ $message->id }})" class="flex h-7 w-7 items-center justify-center rounded text-[var(--text-muted)] transition hover:bg-[var(--bg-surface)] hover:text-[var(--text-main)]" title="Reply">↪</button>
        @endif

        @if($activeChannel->isText() && ! $activeChannel->isThread() && ! $thread && $isMember)
            <button wire:click="createThread({{ $message->id }})" class="flex h-7 w-7 items-center justify-center rounded text-[var(--text-muted)] transition hover:bg-[var(--bg-surface)] hover:text-[var(--accent-light)]" title="Create thread">❞</button>
        @endif

        @if($permissions['delete_messages'])
            <button wire:click="togglePin({{ $message->id }})" class="flex h-7 w-7 items-center justify-center rounded text-[var(--text-muted)] transition hover:bg-[var(--bg-surface)] hover:text-amber-400" title="{{ $message->is_pinned ? 'Unpin' : 'Pin' }}">📌</button>
        @endif

        @if($message->is_own)
            <button wire:click="startEditing({{ $message->id }})" class="flex h-7 w-7 items-center justify-center rounded text-[var(--text-muted)] transition hover:bg-[var(--bg-surface)] hover:text-[var(--accent-light)]" title="Edit">✎</button>
        @endif

        @if($canRemove)
            <button wire:click="deleteMessage({{ $message->id }})" wire:confirm="Delete this message?" class="flex h-7 w-7 items-center justify-center rounded text-[var(--text-muted)] transition hover:bg-[var(--bg-surface)] hover:text-rose-400" title="Delete">🗑</button>
        @endif

        @if($isMember)
            <button wire:click="$set('reportTargetId', {{ $message->id }}); $set('reportTargetType', 'message')" class="flex h-7 w-7 items-center justify-center rounded text-[var(--text-muted)] transition hover:bg-[var(--bg-surface)] hover:text-amber-400" title="Report">⚑</button>
        @endif
    </div>
</article>
