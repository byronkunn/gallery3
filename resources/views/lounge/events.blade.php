{{--
    Discord-style events channel: scheduled community events with RSVP.
--}}
<header class="flex h-12 shrink-0 items-center gap-2 border-b border-black/20 px-4 shadow-sm">
    <button @click="sidebarOpen = ! sidebarOpen" class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-[var(--text-muted)] transition hover:bg-[var(--bg-surface)] hover:text-[var(--text-main)] md:hidden" aria-label="Toggle channels">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    <span class="text-xl leading-none text-[var(--text-dim)]">◷</span>
    <h1 class="shrink-0 text-[15px] font-bold text-[var(--text-main)]">{{ $activeChannel->name }}</h1>
    @if($activeChannel->description)
        <span class="mx-1 hidden h-5 w-px shrink-0 bg-[var(--border-medium)] sm:block"></span>
        <p class="hidden min-w-0 truncate text-[13px] text-[var(--text-muted)] sm:block">{{ $activeChannel->description }}</p>
    @endif
    <div class="ml-auto flex shrink-0 items-center gap-1">
        <button wire:click="toggleMemberList" class="flex h-7 w-7 items-center justify-center rounded-md text-[var(--text-muted)] transition hover:bg-[var(--bg-surface)] hover:text-[var(--text-main)]" title="Toggle member list">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6-4a3 3 0 11-3-3 3 3 0 013 3z"/></svg>
        </button>
        @if($permissions['manage_channels'])
            <button wire:click="openChannelSettings({{ $activeChannel->id }})" class="flex h-7 w-7 items-center justify-center rounded-md text-[var(--text-muted)] transition hover:bg-[var(--bg-surface)] hover:text-[var(--text-main)]" title="Edit channel">⚙</button>
        @endif
    </div>
</header>

<div class="min-h-0 flex-1 overflow-y-auto p-4" x-data="{ compose: false }">
    @if(! $isMember)
        <div class="flex h-full flex-col items-center justify-center text-center">
            <div class="text-4xl">◷</div>
            <h2 class="mt-3 text-2xl font-black text-[var(--text-main)]">Join to see events</h2>
            <p class="mt-1 max-w-md text-sm text-[var(--text-muted)]">Scheduled art sessions and community events are for members.</p>
            @if(Auth::check() && $membership?->status !== 'pending' && $community->visibility !== 'invite')
                <button wire:click="joinCommunity" class="mt-4 rounded-xl accent-bg px-5 py-2.5 text-sm font-bold text-white">Join community</button>
            @endif
        </div>
    @else
        @if($activeChannel->isPostable())
            <button @click="compose = ! compose" class="mb-4 rounded-lg accent-bg px-3.5 py-2 text-sm font-bold text-white shadow">＋ Schedule event</button>

            <form x-show="compose" x-cloak wire:submit="createEvent" class="mb-4 space-y-2 rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-4">
                <h3 class="font-bold text-[var(--text-main)]">Schedule an event</h3>
                <input wire:model="eventTitle" maxlength="160" placeholder="Event title" class="w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm text-[var(--text-main)] outline-none">
                @error('eventTitle') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                <textarea wire:model="eventDescription" rows="2" placeholder="What will you do?" class="w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] p-3 text-sm text-[var(--text-main)] outline-none"></textarea>
                <input wire:model="eventStartsAt" type="datetime-local" class="max-w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm text-[var(--text-main)] outline-none">
                @error('eventStartsAt') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                <div class="flex justify-end gap-2">
                    <button type="button" @click="compose = false" class="rounded-xl px-4 py-2 text-sm text-[var(--text-muted)]">Cancel</button>
                    <button class="rounded-xl accent-bg px-4 py-2 text-sm font-bold text-white">Schedule</button>
                </div>
            </form>
        @endif

        <div class="space-y-3">
            @forelse($events as $event)
                @php($rsvps = $eventRsvps->get($event->id, collect()))
                @php($isGoing = $rsvps->contains('user_id', Auth::id()))
                @php($starts = \Illuminate\Support\Carbon::parse($event->starts_at))
                <article wire:key="event-{{ $event->id }}" class="overflow-hidden rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-surface)]">
                    <div class="h-1.5 accent-bg"></div>
                    <div class="flex flex-wrap items-start gap-4 p-4">
                        <div class="flex h-16 w-16 shrink-0 flex-col items-center justify-center rounded-xl bg-[var(--bg-page)]">
                            <span class="text-[11px] font-bold uppercase tracking-wide text-[var(--text-dim)]">{{ $starts->format('M') }}</span>
                            <span class="text-2xl font-black leading-none text-[var(--text-main)]">{{ $starts->format('j') }}</span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <h3 class="break-words text-[15px] font-bold text-[var(--text-main)]">{{ $event->title }}</h3>
                            <p class="mt-0.5 text-[13px] text-[var(--text-muted)]">{{ $starts->format('l, M j · g:i A') }}{{ $starts->isFuture() ? '' : ' · started' }}</p>
                            @if($event->description)
                                <p class="mt-1 break-words text-[13px] text-[var(--text-muted)]">{{ $event->description }}</p>
                            @endif
                            <p class="mt-1 text-[11px] text-[var(--text-dim)]">Hosted by {{ '@'.$event->username }} · {{ $rsvps->count() }} interested</p>
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-2">
                            <button wire:click="toggleRsvp({{ $event->id }})" class="rounded-xl px-4 py-2 text-sm font-bold transition {{ $isGoing ? 'bg-[var(--bg-page)] text-[var(--text-main)]' : 'accent-bg text-white' }}">
                                {{ $isGoing ? 'Interested ✓' : 'Interested' }}
                            </button>
                            @if($permissions['manage_events'])
                                <button wire:click="cancelEvent({{ $event->id }})" wire:confirm="Cancel this event?" class="text-xs text-rose-400">Cancel event</button>
                            @endif
                        </div>
                    </div>
                </article>
            @empty
                <div class="rounded-2xl border border-dashed border-[var(--border-subtle)] p-12 text-center text-sm text-[var(--text-muted)]">No upcoming events. Schedule the first one.</div>
            @endforelse
        </div>
    @endif
</div>
