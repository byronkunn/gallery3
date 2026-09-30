{{--
    Overlays: search, pinned messages, member profile popout, create channel/category and content report.
--}}

{{-- ============================================================ search --}}
<div x-show="$wire.searchOpen" x-cloak class="fixed inset-0 z-[75] bg-black/70 p-4 backdrop-blur-sm" @click.self="$wire.set('searchOpen', false)">
    <div class="mx-auto mt-10 w-full max-w-2xl overflow-hidden rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] shadow-2xl">
        <div class="flex items-center gap-2 border-b border-[var(--border-subtle)] px-4 py-3">
            <svg class="h-4 w-4 text-[var(--text-dim)]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 10a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search {{ $community->name }}" class="min-w-0 flex-1 bg-transparent text-sm text-[var(--text-main)] outline-none placeholder:text-[var(--text-dim)]" x-init="$el.focus()">
            <button wire:click="$set('searchOpen', false)" class="text-[var(--text-dim)] hover:text-[var(--text-main)]">✕</button>
        </div>
        <div class="max-h-[60vh] min-h-[120px] overflow-y-auto p-2">
            @if(mb_strlen(trim($search)) < 2)
                <p class="p-8 text-center text-sm text-[var(--text-dim)]">Type at least two characters to search messages and members.</p>
            @elseif($searchResults->isEmpty())
                <p class="p-8 text-center text-sm text-[var(--text-dim)]">No results for “{{ $search }}”.</p>
            @else
                <div class="mb-1 px-2 text-[11px] font-bold uppercase tracking-wide text-[var(--text-dim)]">{{ $searchResults->count() }} results</div>
                @foreach($searchResults as $result)
                    <a wire:key="search-{{ $result->id }}" href="{{ route('lounge.community', ['slug' => $community->slug, 'channel' => $result->channel_slug]) }}"
                       class="flex items-start gap-3 rounded-xl p-2.5 transition hover:bg-[var(--bg-surface-elevated)]">
                        <img src="{{ $result->avatar_url }}" alt="" class="h-8 w-8 shrink-0 rounded-full object-cover">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 text-xs">
                                <span class="font-bold text-[var(--text-main)]">{{ '@'.$result->username }}</span>
                                <span class="text-[var(--text-dim)]">#{{ $result->channel_name }}</span>
                                <time class="ml-auto text-[var(--text-dim)]">{{ \Illuminate\Support\Carbon::parse($result->created_at)->diffForHumans() }}</time>
                            </div>
                            <p class="mt-0.5 line-clamp-2 break-words text-sm text-[var(--text-muted)]">{{ \Illuminate\Support\Str::limit($result->body, 200) }}</p>
                        </div>
                    </a>
                @endforeach
            @endif
        </div>
    </div>
</div>

{{-- ============================================================== pins --}}
<div x-show="$wire.pinsOpen" x-cloak class="fixed inset-0 z-[75] bg-black/70 p-4 backdrop-blur-sm" @click.self="$wire.set('pinsOpen', false)">
    <div class="mx-auto mt-10 flex max-h-[75vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] shadow-2xl">
        <div class="flex items-center justify-between border-b border-[var(--border-subtle)] px-4 py-3">
            <h2 class="text-sm font-bold text-[var(--text-main)]">📌 Pinned in #{{ $activeChannel->name }}</h2>
            <button wire:click="$set('pinsOpen', false)" class="text-[var(--text-dim)] hover:text-[var(--text-main)]">✕</button>
        </div>
        <div class="min-h-0 flex-1 space-y-2 overflow-y-auto p-3">
            @forelse($pins as $pin)
                <div wire:key="pin-{{ $pin->id }}" class="rounded-xl bg-[var(--bg-page)] p-3">
                    <div class="flex items-center gap-2 text-xs">
                        <img src="{{ $pin->author_avatar }}" alt="" class="h-6 w-6 rounded-full object-cover">
                        <span class="font-bold text-[var(--text-main)]">{{ $pin->author_display }}</span>
                        <time class="text-[var(--text-dim)]">{{ $pin->created_at->diffForHumans() }}</time>
                        @if($permissions['delete_messages'])
                            <button wire:click="togglePin({{ $pin->id }})" class="ml-auto text-[var(--text-dim)] hover:text-rose-400">Unpin</button>
                        @endif
                    </div>
                    <div class="mt-1.5 break-words text-sm text-[var(--text-muted)]">{!! $pin->body_html !!}</div>
                </div>
            @empty
                <p class="py-10 text-center text-sm text-[var(--text-dim)]">No pinned messages in this channel yet.</p>
            @endforelse
        </div>
    </div>
</div>

{{-- =================================================== profile popout --}}
@if($profileMember)
    <div x-data x-show="$wire.profileUserId" x-cloak class="fixed inset-0 z-[76] flex items-start justify-center bg-black/60 p-4 backdrop-blur-sm" @click.self="$wire.closeProfile()">
        <div class="mt-24 w-full max-w-sm overflow-hidden rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] shadow-2xl">
            <div class="h-20 accent-bg"></div>
            <div class="px-4 pb-4">
                <div class="-mt-10 mb-2 flex items-end justify-between">
                    <div class="rounded-full border-4 border-[var(--bg-surface)]">
                        <x-lounge.avatar :user="$profileMember->user" :presence="$profileMember->presenceState()" :size="16" />
                    </div>
                    <button wire:click="closeProfile" class="pb-1 text-[var(--text-dim)] hover:text-[var(--text-main)]">✕</button>
                </div>
                <div class="rounded-xl bg-[var(--bg-page)] p-3">
                    <div class="font-black text-[var(--text-main)]">{{ $profileMember->displayName() }}</div>
                    <div class="text-xs text-[var(--text-dim)]">{{ '@'.$profileMember->user?->username }}</div>
                    @if($profileMember->status_text)
                        <div class="mt-2 text-sm text-[var(--text-muted)]">{{ $profileMember->status_emoji }} {{ $profileMember->status_text }}</div>
                    @endif
                    <div class="mt-3 space-y-1 text-xs text-[var(--text-dim)]">
                        @if($profileMember->role)<div>Role: <span class="font-semibold" style="color: {{ $profileMember->role->color ?: 'var(--text-main)' }}">{{ $profileMember->role->name }}</span></div>@endif
                        <div>Joined {{ $profileMember->joined_at?->format('M j, Y') ?? 'unknown' }}</div>
                        <div>Presence: {{ $profileMember->presenceState() }}</div>
                        <div>{{ $profileMember->message_count }} messages</div>
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap gap-2">
                    <a href="{{ route('profile', $profileMember->user?->username) }}" class="rounded-xl accent-bg px-3 py-1.5 text-xs font-bold text-white">View profile</a>
                    <a href="{{ route('messages') }}" class="rounded-xl border border-[var(--border-subtle)] px-3 py-1.5 text-xs font-bold text-[var(--text-main)]">Message</a>

                    @if($permissions['manage_members'] && $profileMember->user_id !== $community->owner_id)
                        <button wire:click="$set('timeoutUserId', {{ $profileMember->user_id }})" class="rounded-xl bg-amber-500/15 px-3 py-1.5 text-xs font-bold text-amber-400">Timeout</button>
                        <button wire:click="reviewMember({{ $profileMember->user_id }}, 'remove')" wire:confirm="Kick this member?" class="rounded-xl bg-[var(--bg-page)] px-3 py-1.5 text-xs font-bold text-[var(--text-muted)]">Kick</button>
                        @if($profileMember->status !== 'banned')
                            <button wire:click="reviewMember({{ $profileMember->user_id }}, 'ban')" wire:confirm="Ban this member?" class="rounded-xl bg-rose-500/15 px-3 py-1.5 text-xs font-bold text-rose-400">Ban</button>
                        @else
                            <button wire:click="reviewMember({{ $profileMember->user_id }}, 'unban')" class="rounded-xl bg-[var(--bg-page)] px-3 py-1.5 text-xs font-bold text-[var(--text-muted)]">Unban</button>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>
@endif

{{-- ================================================== create channel --}}
<div x-show="$wire.createChannelOpen" x-cloak class="fixed inset-0 z-[75] flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm" @click.self="$wire.set('createChannelOpen', false)">
    <form wire:submit="createChannel" class="w-full max-w-md space-y-3 rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5 shadow-2xl">
        <div class="flex items-center justify-between">
            <h2 class="text-[15px] font-bold text-[var(--text-main)]">Create channel</h2>
            <button type="button" wire:click="$set('createChannelOpen', false)" class="text-[var(--text-dim)] hover:text-[var(--text-main)]">✕</button>
        </div>
        <label class="block text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">Channel type
            <select wire:model="channelType" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm normal-case tracking-normal text-[var(--text-main)] outline-none">
                <option value="text"># Text channel</option>
                <option value="forum">▤ Forum channel</option>
                <option value="events">◷ Events channel</option>
            </select>
        </label>
        <label class="block text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">Channel name
            <input wire:model="channelName" maxlength="80" placeholder="new-channel" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm normal-case tracking-normal text-[var(--text-main)] outline-none">
        </label>
        @error('channelName') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
        <label class="block text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">Topic (optional)
            <input wire:model="channelTopic" maxlength="300" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm normal-case tracking-normal text-[var(--text-main)] outline-none">
        </label>
        <label class="block text-xs font-bold uppercase tracking-wide text-[var(--text-dim)]">Category
            <select wire:model="channelCategoryId" class="mt-1 w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm normal-case tracking-normal text-[var(--text-main)] outline-none">
                <option value="">No category</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
        </label>
        <div class="flex justify-end gap-2 pt-1">
            <button type="button" wire:click="$set('createChannelOpen', false)" class="rounded-xl px-4 py-2 text-sm text-[var(--text-muted)]">Cancel</button>
            <button class="rounded-xl accent-bg px-5 py-2 text-sm font-bold text-white">Create channel</button>
        </div>
    </form>
</div>

{{-- ================================================= create category --}}
<div x-show="$wire.createCategoryOpen" x-cloak class="fixed inset-0 z-[75] flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm" @click.self="$wire.set('createCategoryOpen', false)">
    <form wire:submit="createCategory" class="w-full max-w-sm space-y-3 rounded-2xl border border-[var(--border-subtle)] bg-[var(--bg-surface)] p-5 shadow-2xl">
        <h2 class="text-[15px] font-bold text-[var(--text-main)]">Create category</h2>
        <input wire:model="categoryName" maxlength="60" placeholder="Category name" class="w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm text-[var(--text-main)] outline-none">
        @error('categoryName') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
        <div class="flex justify-end gap-2">
            <button type="button" wire:click="$set('createCategoryOpen', false)" class="rounded-xl px-4 py-2 text-sm text-[var(--text-muted)]">Cancel</button>
            <button class="rounded-xl accent-bg px-5 py-2 text-sm font-bold text-white">Create</button>
        </div>
    </form>
</div>

{{-- ==================================================== report content --}}
@if($reportTargetId && $isMember)
    <div x-data x-show="$wire.reportTargetId" x-cloak class="fixed inset-0 z-[75] flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm" @click.self="$wire.set('reportTargetId', null)">
        <form wire:submit="reportContent('{{ $reportTargetType }}', {{ $reportTargetId }})" class="w-full max-w-md space-y-3 rounded-2xl border border-amber-500/30 bg-[var(--bg-surface)] p-5 shadow-2xl">
            <h2 class="text-[15px] font-bold text-[var(--text-main)]">Report {{ str_replace('_', ' ', $reportTargetType) }}</h2>
            <input wire:model="reportReason" maxlength="80" placeholder="Reason" class="w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] px-3 py-2 text-sm text-[var(--text-main)] outline-none">
            @error('reportReason') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
            <textarea wire:model="reportDetails" maxlength="1500" rows="3" placeholder="More details (optional)" class="w-full rounded-xl border border-[var(--border-subtle)] bg-[var(--bg-page)] p-3 text-sm text-[var(--text-main)] outline-none"></textarea>
            <div class="flex justify-end gap-2">
                <button type="button" wire:click="$set('reportTargetId', null)" class="rounded-xl px-4 py-2 text-sm text-[var(--text-muted)]">Cancel</button>
                <button class="rounded-xl bg-amber-600 px-5 py-2 text-sm font-bold text-white">Send report</button>
            </div>
        </form>
    </div>
@endif
