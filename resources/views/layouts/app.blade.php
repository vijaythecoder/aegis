<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('aegis.name', 'Aegis') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=dm-sans:400,500,600,700|syne:600,700,800|jetbrains-mono:400,500" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body
    class="bg-aegis-900 text-aegis-text font-sans antialiased overflow-hidden"
    x-data="{ sidebarOpen: true }"
>
    <div class="flex h-screen w-screen">

        {{-- Sidebar --}}
        <aside
            x-show="sidebarOpen"
            x-transition:enter="transition-transform duration-200 ease-out"
            x-transition:enter-start="-translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition-transform duration-150 ease-in"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="-translate-x-full"
            class="flex flex-col border-r border-aegis-border bg-aegis-850 shrink-0"
            style="width: var(--sidebar-width)"
        >
            {{-- Logo --}}
            <div class="drag-region flex items-center gap-3 px-5 py-4 border-b border-aegis-border">
                <div class="no-drag flex items-center gap-2.5">
                    <div class="relative w-8 h-8 rounded-lg bg-gradient-to-br from-aegis-accent to-aegis-accent-dim flex items-center justify-center shadow-lg shadow-aegis-accent/20">
                        <svg class="w-4.5 h-4.5 text-aegis-900" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 2L2 7l10 5 10-5-10-5z"/>
                            <path d="M2 17l10 5 10-5"/>
                            <path d="M2 12l10 5 10-5"/>
                        </svg>
                    </div>
                    <span class="font-display font-bold text-lg tracking-tight text-aegis-text">{{ config('aegis.name', 'Aegis') }}</span>
                </div>
            </div>

            {{-- Conversation Sidebar --}}
            @livewire('conversation-sidebar')

            {{-- Settings Link --}}
            <div class="px-3 py-3 border-t border-aegis-border">
                <a href="{{ route('settings') }}" class="no-drag flex items-center gap-2.5 px-3 py-2 rounded-lg text-aegis-text-dim hover:text-aegis-text hover:bg-aegis-surface-hover transition-all duration-150 text-sm">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/>
                    </svg>
                    Settings
                </a>
            </div>
        </aside>

        {{-- Main Content --}}
        <main class="flex-1 flex flex-col min-w-0 bg-aegis-900">
            {{-- Top Bar --}}
            <header class="drag-region flex items-center gap-3 px-4 py-3 border-b border-aegis-border bg-aegis-900/80 backdrop-blur-sm shrink-0">
                <button
                    x-show="!sidebarOpen"
                    @click="sidebarOpen = true"
                    class="no-drag p-1.5 rounded-md text-aegis-text-dim hover:text-aegis-text hover:bg-aegis-surface-hover transition-colors"
                >
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                        <line x1="9" y1="3" x2="9" y2="21"/>
                    </svg>
                </button>
                <button
                    x-show="sidebarOpen"
                    @click="sidebarOpen = false"
                    class="no-drag p-1.5 rounded-md text-aegis-text-dim hover:text-aegis-text hover:bg-aegis-surface-hover transition-colors"
                >
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                        <line x1="9" y1="3" x2="9" y2="21"/>
                    </svg>
                </button>
                <h1 class="no-drag text-sm font-medium text-aegis-text truncate">
                    @yield('title', config('aegis.name', 'Aegis'))
                </h1>
                <div class="ml-auto no-drag">
                    @livewire('agent-status')
                </div>
            </header>

            {{-- Content Area --}}
            <div class="flex-1 flex flex-col overflow-hidden">
                @yield('content')
            </div>

        </main>

    </div>

    @livewire('permission-dialog')
</body>
</html>
