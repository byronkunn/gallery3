{{--
    Discord-style server rail: DM home, joined communities, discovery and add-server.
--}}
<nav class="flex w-[72px] shrink-0 flex-col items-center gap-2 overflow-y-auto overflow-x-hidden border-r border-black/20 bg-[var(--bg-page)] py-3" aria-label="Servers">
    {{-- Direct messages / home --}}
    @php($isDmActive = request()->routeIs('lounge.dms*'))
    <a href="{{ route('lounge.dms') }}" class="group relative flex h-12 w-12 items-center justify-center rounded-2xl {{ $isDmActive ? 'accent-bg text-white shadow-md' : 'bg-[var(--bg-surface)] text-[var(--text-muted)] hover:rounded-xl hover:bg-[var(--accent-primary)] hover:text-white' }} transition-all" aria-label="Direct messages">
        <span class="absolute -left-3 h-2 w-1 rounded-r-full bg-white transition-all {{ $isDmActive ? 'h-8' : 'group-hover:h-6' }}"></span>
        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
        <span class="pointer-events-none absolute left-16 z-50 hidden whitespace-nowrap rounded-lg bg-black px-2.5 py-1.5 text-xs font-semibold text-white shadow-xl group-hover:block">Direct Messages</span>
    </a>

    @if($recentConversations->isNotEmpty())
        <div class="flex flex-col items-center gap-2">
            @foreach($recentConversations as $conversation)
                @php($partner = $conversation->getOtherUser(Auth::user()))
                <a href="{{ route('lounge.dms', $conversation->id) }}" class="group relative" aria-label="Chat with {{ $partner->name }}">
                    <img src="{{ $partner->avatar_url }}" alt="" class="h-10 w-10 rounded-full object-cover ring-2 ring-transparent transition group-hover:ring-[var(--accent-primary)]">
                    <span class="pointer-events-none absolute left-14 top-1/2 z-50 hidden -translate-y-1/2 whitespace-nowrap rounded-lg bg-black px-2.5 py-1.5 text-xs font-semibold text-white shadow-xl group-hover:block">{{ $partner->name }}</span>
                </a>
            @endforeach
        </div>
    @endif

    <div class="h-px w-8 shrink-0 rounded bg-[var(--border-medium)]"></div>

    {{-- Joined communities --}}
    @foreach($myCommunities as $item)
        @php($isActive = isset($community) && $community && (int) $item->id === (int) $community->id)
        <a href="{{ route('lounge.community', ['slug' => $item->slug]) }}"
           class="group relative flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-[var(--bg-surface)] text-lg font-black text-[var(--text-main)] transition-all hover:rounded-xl"
           aria-label="{{ $item->name }}" @if($isActive) aria-current="page" @endif>
            <span class="absolute -left-3 w-1 rounded-r-full bg-white transition-all {{ $isActive ? 'h-10' : 'h-2 opacity-0 group-hover:h-6 group-hover:opacity-100' }}"></span>
            @if($item->icon_url)
                <img src="{{ $item->icon_url }}" alt="" class="h-full w-full object-cover {{ $isActive ? 'rounded-xl' : 'group-hover:rounded-xl' }} transition">
            @else
                {{ mb_strtoupper(mb_substr($item->name, 0, 1)) }}
            @endif
            @if($item->membership_status === 'pending')
                <span class="absolute bottom-0 right-0 h-4 w-4 rounded-full border-2 border-[var(--bg-page)] bg-amber-400" title="Membership pending"></span>
            @endif
            <span class="pointer-events-none absolute left-16 z-50 hidden whitespace-nowrap rounded-lg bg-black px-2.5 py-1.5 text-xs font-semibold text-white shadow-xl group-hover:block">{{ $item->name }}</span>
        </a>
    @endforeach

    {{-- Discovery --}}
    <a href="{{ route('lounge.explore') }}" class="group relative flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-[var(--bg-surface)] text-emerald-400 transition-all hover:rounded-xl hover:bg-emerald-500 hover:text-white" aria-label="Explore communities">
        <span class="absolute -left-3 h-2 w-1 rounded-full bg-white opacity-0 transition-all group-hover:h-6 group-hover:opacity-100"></span>
        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 10a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        <span class="pointer-events-none absolute left-16 z-50 hidden whitespace-nowrap rounded-lg bg-black px-2.5 py-1.5 text-xs font-semibold text-white shadow-xl group-hover:block">Explore communities</span>
    </a>

    <a href="{{ route('lounge.explore') }}" class="group relative flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-[var(--bg-surface)] text-emerald-400 transition-all hover:rounded-xl hover:bg-emerald-500 hover:text-white" aria-label="Add a community">
        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
        <span class="pointer-events-none absolute left-16 z-50 hidden whitespace-nowrap rounded-lg bg-black px-2.5 py-1.5 text-xs font-semibold text-white shadow-xl group-hover:block">Add a community</span>
    </a>
</nav>
