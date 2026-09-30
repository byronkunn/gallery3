<!DOCTYPE html>
<html lang="en"
      x-data="{ themeMode: 'dark', themePalette: 'violet' }"
      :data-theme-mode="themeMode"
      :data-palette="themePalette"
      class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard — Booru.art Management</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-[var(--bg-page)] text-[var(--text-main)] antialiased">
    <livewire:admin-dashboard />
    @livewireScripts
</body>
</html>
