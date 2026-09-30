{{--
    Site-wide announcement banner.

    Reads the "Site Broadcast Announcement" setting the admin console writes.
    Dismissal is remembered per announcement text, so a new broadcast shows up
    again to everyone.
--}}
@php
    $announcementText = trim((string) (\App\Support\SiteSettings::get('site_announcement') ?: \Illuminate\Support\Facades\Cache::get('site_announcement', '')));
@endphp

@if($announcementText !== '')
    @php
        $announcementStorageKey = 'site_announcement_dismissed_'.substr(sha1($announcementText), 0, 12);
    @endphp

    <div x-data="{
            visible: localStorage.getItem('{{ $announcementStorageKey }}') !== '1',
            dismiss() {
                this.visible = false;
                localStorage.setItem('{{ $announcementStorageKey }}', '1');
            }
         }"
         x-show="visible"
         x-cloak
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         role="status"
         aria-label="Site announcement"
         class="mx-4 md:mx-6 mt-4 flex items-start gap-3 rounded-2xl border border-[var(--accent-primary)]/40 bg-[var(--bg-surface)] px-4 py-3 shadow-sm">
        <span class="mt-0.5 shrink-0 text-base" aria-hidden="true">📢</span>
        <p class="flex-1 min-w-0 text-sm font-semibold text-[var(--text-main)] leading-relaxed">{{ $announcementText }}</p>
        <button type="button"
                x-on:click="dismiss()"
                title="Dismiss announcement"
                class="shrink-0 rounded-xl px-2 py-1 text-xs font-bold text-[var(--text-dim)] hover:text-[var(--text-main)] hover:bg-[var(--bg-surface-elevated)] transition">
            ✕
        </button>
    </div>
@endif
