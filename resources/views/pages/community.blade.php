<x-layouts.app :title="$slug . ' — Lounge'" :lounge="true" :flush="true">
    <livewire:community-space :slug="$slug" :channel="$channel" />
</x-layouts.app>
