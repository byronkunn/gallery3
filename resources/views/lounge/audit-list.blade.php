{{--
    Shared audit log list. Expects $auditLogs.
--}}
@forelse($auditLogs as $log)
    <div wire:key="audit-{{ $log->id }}" class="flex items-start gap-3 rounded-xl bg-[var(--bg-page)] p-3">
        <img src="{{ $log->actor_avatar }}" alt="" class="h-7 w-7 shrink-0 rounded-full object-cover">
        <div class="min-w-0 flex-1 text-xs">
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="font-bold text-[var(--text-main)]">{{ $log->actor_name ?? 'System' }}</span>
                <span class="rounded bg-[var(--bg-surface)] px-1.5 py-0.5 font-mono text-[10px] text-[var(--accent-light)]">{{ $log->action }}</span>
                @if($log->target_name)
                    <span class="font-semibold text-[var(--text-muted)]">{{ $log->target_name }}</span>
                @endif
                <time class="ml-auto text-[var(--text-dim)]">{{ \Illuminate\Support\Carbon::parse($log->created_at)->diffForHumans() }}</time>
            </div>
            @if($log->details && $log->details !== '[]')
                <div class="mt-1 break-words font-mono text-[11px] text-[var(--text-dim)]">{{ $log->details }}</div>
            @endif
            @if($log->community_channel_id)
                <div class="mt-0.5 text-[10px] text-[var(--text-dim)]">channel #{{ $log->community_channel_id }}</div>
            @endif
        </div>
    </div>
@empty
    <p class="py-8 text-center text-sm text-[var(--text-dim)]">Nothing has been logged yet.</p>
@endforelse
