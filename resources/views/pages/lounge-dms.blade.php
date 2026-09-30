<x-layouts.app title="Direct Messages — Lounge" :lounge="true" :flush="true">
    <livewire:lounge-dms :conversationId="$conversationId ?? null" />
</x-layouts.app>
