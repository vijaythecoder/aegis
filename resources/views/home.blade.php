@extends('layouts.app')

@section('title', 'Welcome')

@section('content')
<div class="flex-1 flex items-center justify-center p-8">
    <div class="max-w-lg text-center space-y-8" x-data="{ show: false }" x-init="setTimeout(() => show = true, 100)">

        {{-- Shield Icon --}}
        <div
            x-show="show"
            x-transition:enter="transition-all duration-500 ease-out"
            x-transition:enter-start="opacity-0 scale-90"
            x-transition:enter-end="opacity-100 scale-100"
            class="mx-auto w-20 h-20 rounded-2xl bg-gradient-to-br from-aegis-accent/20 to-aegis-accent-dim/10 border border-aegis-accent/20 flex items-center justify-center shadow-2xl shadow-aegis-accent/10"
        >
            <svg class="w-10 h-10 text-aegis-accent" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
        </div>

        {{-- Title --}}
        <div
            x-show="show"
            x-transition:enter="transition-all duration-500 ease-out delay-150"
            x-transition:enter-start="opacity-0 translate-y-4"
            x-transition:enter-end="opacity-100 translate-y-0"
        >
            <h2 class="font-display font-bold text-3xl tracking-tight text-aegis-text">
                Welcome to <span class="text-aegis-accent">{{ config('aegis.name', 'Aegis') }}</span>
            </h2>
            <p class="mt-2 text-aegis-text-dim text-base leading-relaxed">
                {{ config('aegis.tagline', 'AI under your Aegis') }}
            </p>
        </div>

        {{-- Capabilities --}}
        <div
            x-show="show"
            x-transition:enter="transition-all duration-500 ease-out delay-300"
            x-transition:enter-start="opacity-0 translate-y-4"
            x-transition:enter-end="opacity-100 translate-y-0"
            class="grid grid-cols-1 sm:grid-cols-3 gap-3"
        >
            <div class="p-4 rounded-xl bg-aegis-surface border border-aegis-border hover:border-aegis-accent/20 transition-colors">
                <div class="w-8 h-8 rounded-lg bg-aegis-800 flex items-center justify-center mb-3">
                    <svg class="w-4 h-4 text-aegis-accent" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
                    </svg>
                </div>
                <p class="text-xs font-medium text-aegis-text">AI Chat</p>
                <p class="text-xs text-aegis-text-dim mt-0.5">Multi-provider conversations</p>
            </div>
            <div class="p-4 rounded-xl bg-aegis-surface border border-aegis-border hover:border-aegis-accent/20 transition-colors">
                <div class="w-8 h-8 rounded-lg bg-aegis-800 flex items-center justify-center mb-3">
                    <svg class="w-4 h-4 text-aegis-accent" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                        <line x1="8" y1="21" x2="16" y2="21"/>
                        <line x1="12" y1="17" x2="12" y2="21"/>
                    </svg>
                </div>
                <p class="text-xs font-medium text-aegis-text">Tools</p>
                <p class="text-xs text-aegis-text-dim mt-0.5">File, shell & code execution</p>
            </div>
            <div class="p-4 rounded-xl bg-aegis-surface border border-aegis-border hover:border-aegis-accent/20 transition-colors">
                <div class="w-8 h-8 rounded-lg bg-aegis-800 flex items-center justify-center mb-3">
                    <svg class="w-4 h-4 text-aegis-accent" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    </svg>
                </div>
                <p class="text-xs font-medium text-aegis-text">Privacy</p>
                <p class="text-xs text-aegis-text-dim mt-0.5">Local-first, your data stays yours</p>
            </div>
        </div>

        {{-- CTA --}}
        <div
            x-show="show"
            x-transition:enter="transition-all duration-500 ease-out delay-[450ms]"
            x-transition:enter-start="opacity-0 translate-y-4"
            x-transition:enter-end="opacity-100 translate-y-0"
        >
            <p class="text-xs text-aegis-text-dim">
                Press <kbd class="px-1.5 py-0.5 rounded bg-aegis-800 border border-aegis-border font-mono text-aegis-text text-[10px]">New Chat</kbd> to get started
            </p>
        </div>

    </div>
</div>
@endsection
