@props([
    'user' => null,
    'presence' => 'offline',
    'size' => 8,
    'dot' => true,
])

@php
    $sizeClass = match ((int) $size) {
        6 => 'h-6 w-6',
        7 => 'h-7 w-7',
        8 => 'h-8 w-8',
        10 => 'h-10 w-10',
        12 => 'h-12 w-12',
        16 => 'h-16 w-16',
        default => 'h-10 w-10',
    };

    $dotColor = match ($presence) {
        'online' => 'bg-emerald-500',
        'idle' => 'bg-amber-400',
        'dnd' => 'bg-rose-500',
        'invisible', 'offline', null, '' => 'bg-[var(--text-dim)]',
        default => 'bg-[var(--text-dim)]',
    };
@endphp

<span {{ $attributes->merge(['class' => 'relative inline-flex shrink-0 '.$sizeClass]) }}>
    @if($user?->avatar_url)
        <img src="{{ $user->avatar_url }}" alt="" class="h-full w-full rounded-full object-cover">
    @else
        <span class="flex h-full w-full items-center justify-center rounded-full bg-[var(--bg-page)] text-xs font-black uppercase text-[var(--text-dim)]" role="img" aria-label="No avatar image">
            {{ mb_strtoupper(mb_substr($user?->name ?: $user?->username ?: '?', 0, 1)) }}
        </span>
    @endif
    @if($dot)
        <span class="absolute -bottom-0.5 -right-0.5 h-3.5 w-3.5 rounded-full border-[2.5px] border-[var(--bg-surface)] {{ $dotColor }}"></span>
    @endif
</span>
