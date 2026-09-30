<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      x-data="{
          themeMode: '{{ auth()->user()->theme_mode ?? 'dark' }}',
          themePalette: '{{ auth()->user()->theme_palette ?? 'violet' }}',
          fontSize: '{{ auth()->user()->font_size ?? 'md' }}',
          reducedMotion: {{ (auth()->user()->reduced_motion ?? false) ? 'true' : 'false' }}
      }"
      :data-theme-mode="themeMode"
      :data-palette="themePalette"
      :data-font-size="fontSize"
      :data-reduced-motion="reducedMotion"
      class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
    <title>{{ $area === 'moderation' ? 'Moderation' : 'Admin' }} Dashboard — Booru.art Management</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="min-h-screen bg-[var(--bg-page)] text-[var(--text-main)] antialiased"
      @theme-changed.window="themeMode = $event.detail.mode || themeMode; themePalette = $event.detail.palette || themePalette;">
    <livewire:admin-dashboard :area="$area" />
    @livewireScripts
</body>
</html>
