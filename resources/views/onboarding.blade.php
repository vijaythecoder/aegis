<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>Setup — {{ config('aegis.name', 'Aegis') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=dm-sans:400,500,600,700|syne:600,700,800|jetbrains-mono:400,500" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-aegis-900 text-aegis-text font-sans antialiased overflow-hidden">
    <div class="flex h-screen w-screen items-center justify-center p-6">
        <div class="w-full max-w-xl">
            @livewire('onboarding-wizard')
        </div>
    </div>
</body>
</html>
