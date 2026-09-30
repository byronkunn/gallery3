{{--
    Discord-style forum channel: post cards with tags, replies and moderation.
--}}
<header class="flex h-12 shrink-0 items-center gap-2 border-b border-black/20 px-4 shadow-sm">
    <button @click="sidebarOpen = ! sidebarOpen" class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-[var(--text-muted)] transition hover:bg-[var(--bg-surface)] hover:text-[var(--text-main)] md:hidden" aria-label="Toggle channels">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    <span class="text-xl leading-none text-[var(--text-dim)]">▤</span>
    <h1 class="shrink-0 text-[15px] font-bold text-[var(--text-main)]">{{ $activeChannel->name }}</h1>
    @if($activeChannel->description)
        <span class="mx-1 hidden h-5 w-px shrink-0 bg-[var(--border-medium)] sm:block"></span>
        <p class="hidden min-w-0 truncate text-[13px] text-[var(--text-muted)] sm:block">{{ $activeChannel->description }}</p>
    @endif

    <div class="ml-auto flex shrink-0 items-center gap-2">
        <select wire:model.live="forumSort" class="rounded-md bg-[var(--bg-page)] px-2 py-1 text-[13px] text-[var(--text-muted)] outline-none">
            <option value="latest">Latest activity</option>
            <option value="top">Most active</option>
        </select>
        <button wire:click="toggleMemberList" class="flex h-7 w-7 items-center justify-center rounded-md text-[var(--text-muted)] transition hover:bg-[var(--bg-surface)] hover:text-[var(--text-main)]" title="Toggle member list">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6-4a3 3 0 11-3-3 3 3 0 013 3z"/></svg>
        </button>
        @if($permissions['manage_channels'])
            <button wire:click="openChannelSettings({{ $activeChannel->id }})" class="flex h-7 w-7 items-center justify-center rounded-md text-[var(--text-muted)] transition hover:bg-[var(--bg-surface)] hover:text-[var(--text-main)]" title="Edit channel">⚙</button>
        @endif
    </div>
</header>

<div class="min-h-0 flex-1 overflow-y-auto p-4" x-data="{ compose: {{ $isMember && $activeChannel->isPostable() ? 'false' : 'false' }} }">
    @if(! $isMember)
        <div class="flex h-full flex-col items-center justify-center text-center">
            <div class="text-4xl">▤</div>
            <h2 class="mt-3 text-2xl font-black text-[var(--text-main)]">Join to read {{ $community->name }}</h2>
            <p class="mt-1 max-w-md text-sm text-[var(--text-muted)]">Forum posts, replies and tags are available to members.</p>
            @if(Auth::check() && $membership?->status !== 'pending' && $community->visibility !== 'invite')
                <button wire:click="joinCommunity" class="mt-4 rounded-xl accent-bg px-5 py-2.5 text-sm font-bold text-white">Join community</button>
            @endif
        </div>
    @else
        {{-- toolbar --}}
        <div class="mb-4 flex flex-wrap items-center gap-2">
            @if($activeChannel->isPostable())
                <button @click="compose = ! compose" class="rounded-lg accent-bg px-3.5 py-2 text-sm font-bold text-white shadow">＋ New post</button>
            @endif
            <button wire:click="$set('forumTagFilter', null)" class="rounded-full px-3 py-1 text-xs font-semibold transition {{ $forumTagFilter === null ? 'bg-[var(--accent-primary)]/20 text-[var(--accent-light)]' : 'bg-[var(--bg-surface)] text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">All</button>
            @foreach($availableForumTags as $tag)
                <button wire:click="$set('forumTagFilter', '{{ $tag->slug }}')" class="rounded-full px-3 py-1 text-xs font-semibold transition {{ $forumTagFilter === $tag->slug ? 'bg-[var(--accent-primary)]/20 text-[var(--accent-light)]' : 'bg-[var(--bg-surface)] text-[var(--text-muted)] hover:text-[var(--text-main)]' }}">{{ $tag->name }}</button>
            @endforeach
            @if($permissions['manage_tags'])
                <form wire:submit="createForumTag" class="flex items-center gap-1">
                    <input wire:model="forumTagName" placeholder="New tag" class="w-24 rounded-lg bg-[var(--bg-surface)] px-2 py-1 text-xs text-[var(--text-main)] outline-none">
                    <button class="rounded-lg border border-[var(--border-subtle)] px-2 py-1 text-xs font-semibold text-[var(--text-muted)] hover:text-[var(--text-main)]">Add forum tag</button>
                </form>
            @endif
        </div>

        {{-- new post --}}
        <form x-show="compose" x-cloak wire:submit="createForumPost" class="mb-4 space-y-2 rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-4">
            <h3 class="font-bold text-[var(--text-main)]">Start a discussion</h3>
            <input wire:model="forumTitle" maxlength="160" placeholder="Post title" class="w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm text-[var(--text-main)] outline-none">
            @error('forumTitle') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
            <textarea wire:model="forumBody" rows="3" placeholder="Share details, references or a question" class="w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] p-3 text-sm text-[var(--text-main)] outline-none"></textarea>
            <input wire:model="forumTags" placeholder="Tags (comma separated): {{ $availableForumTags->pluck('name')->join(', ') }}" class="w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm text-[var(--text-main)] outline-none">
            <div class="flex justify-end gap-2">
                <button type="button" @click="compose = false" class="rounded-xl px-4 py-2 text-sm text-[var(--text-muted)]">Cancel</button>
                <button class="rounded-xl accent-bg px-4 py-2 text-sm font-bold text-white">Post thread</button>
            </div>
        </form>

        {{-- posts --}}
        <div class="space-y-3">
            @forelse($forumPosts as $post)
                @php($postTags = $forumPostTagMap->get($post->id, collect()))
                @php($postReplies = $forumReplies->get($post->id, collect()))
                <article x-data="{ open: false }" wire:key="forum-post-{{ $post->id }}" class="overflow-hidden rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-surface)]">
                    <button type="button" @click="open = ! open" class="w-full p-4 text-left transition hover:bg-[var(--bg-surface-elevated)]/50">
                        <div class="flex items-start gap-3">
                            <x-lounge.avatar :user="$post->user_id ? (object) ['avatar_url' => $post->avatar_url] : null" :dot="false" :size="10" />
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="min-w-0 break-words text-[15px] font-bold text-[var(--text-main)]">{{ $post->title }}</h3>
                                    @if($post->status === 'locked') <span class="rounded bg-[var(--bg-page)] px-1.5 py-0.5 text-[10px] font-bold uppercase text-[var(--text-dim)]">Locked</span> @endif
                                </div>
                                @if($post->body)
                                    <p class="mt-0.5 line-clamp-2 break-words text-[13px] text-[var(--text-muted)]">{{ $post->body }}</p>
                                @endif
                                <div class="mt-2 flex flex-wrap items-center gap-2 text-[11px] text-[var(--text-dim)]">
                                    <span class="font-semibold text-[var(--text-muted)]">{{ '@'.$post->username }}</span>
                                    <span>·</span>
                                    <span>{{ \Illuminate\Support\Carbon::parse($post->updated_at)->diffForHumans() }}</span>
                                    <span>·</span>
                                    <span>💬 {{ $postReplies->count() }}</span>
                                    @foreach($postTags as $tag)
                                        <span class="rounded-full bg-[var(--accent-primary)]/15 px-2 py-0.5 font-semibold text-[var(--accent-light)]">{{ $tag->name }}</span>
                                    @endforeach
                                </div>
                            </div>
                            <span class="shrink-0 text-[var(--text-dim)] transition" :class="open ? 'rotate-180' : ''">▾</span>
                        </div>
                    </button>

                    <div x-show="open" x-cloak class="border-t border-[var(--border-subtle)] p-4">
                        @if($post->body)
                            <p class="whitespace-pre-line break-words text-sm text-[var(--text-main)]">{{ $post->body }}</p>
                        @endif

                        <div class="mt-3 space-y-2">
                            @foreach($postReplies as $reply)
                                <div wire:key="forum-reply-{{ $reply->id }}" class="flex gap-3 rounded-xl bg-[var(--bg-page)] p-3">
                                    <x-lounge.avatar :user="(object) ['avatar_url' => $reply->avatar_url]" :dot="false" :size="8" />
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2 text-xs">
                                            <span class="font-semibold text-[var(--text-main)]">{{ '@'.$reply->username }}</span>
                                            <time class="text-[var(--text-dim)]">{{ \Illuminate\Support\Carbon::parse($reply->created_at)->diffForHumans() }}</time>
                                        </div>
                                        <p class="mt-0.5 whitespace-pre-line break-words text-sm text-[var(--text-muted)]">{{ $reply->body }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        @if($post->status === 'open' && ! $activeChannel->is_read_only)
                            <form wire:submit="replyToForumPost({{ $post->id }})" class="mt-3 flex gap-2">
                                <input wire:model="replyBody" placeholder="Reply to this thread" class="min-w-0 flex-1 rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm text-[var(--text-main)] outline-none">
                                <button class="rounded-xl border border-[var(--border-subtle)] px-3 py-2 text-sm font-semibold text-[var(--text-main)]">Reply</button>
                            </form>
                            @error('replyBody') <p class="mt-1 text-xs text-rose-400">{{ $message }}</p> @enderror
                        @endif

                        <div class="mt-3 flex flex-wrap items-center gap-3 text-xs">
                            <button wire:click="$set('reportTargetId', {{ $post->id }}); $set('reportTargetType', 'forum_post')" class="text-[var(--text-dim)] transition hover:text-amber-400">Report thread</button>
                            @if($permissions['manage_forums'])
                                <button wire:click="moderateForumPost({{ $post->id }}, 'hide')" class="text-rose-400">Hide thread</button>
                                <button wire:click="moderateForumPost({{ $post->id }}, '{{ $post->status === 'locked' ? 'unlock' : 'lock' }}')" class="text-[var(--text-dim)] hover:text-[var(--text-main)]">{{ $post->status === 'locked' ? 'Unlock' : 'Lock' }}</button>
                            @endif
                        </div>
                    </div>
                </article>
            @empty
                <div class="rounded-2xl border border-dashed border-[var(--border-subtle)] p-12 text-center text-sm text-[var(--text-muted)]">No posts yet. Start the first discussion.</div>
            @endforelse
        </div>
    @endif
</div>
