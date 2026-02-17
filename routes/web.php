<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    try {
        $onboarded = Setting::query()
            ->where('group', 'app')
            ->where('key', 'onboarding_completed')
            ->value('value');
    } catch (\Throwable) {
        $onboarded = null;
    }

    if (! $onboarded) {
        return redirect()->route('onboarding');
    }

    return redirect()->route('chat');
})->name('home');

Route::get('/onboarding', function () {
    try {
        $onboarded = Setting::query()
            ->where('group', 'app')
            ->where('key', 'onboarding_completed')
            ->value('value');
    } catch (\Throwable) {
        $onboarded = null;
    }

    if ($onboarded) {
        return redirect('/');
    }

    return view('onboarding');
})->name('onboarding');

Route::get('/chat', fn () => view('chat'))->name('chat');
Route::get('/chat/{conversation}', fn (int $conversation) => view('chat', ['conversationId' => $conversation]))->name('chat.conversation');

Route::get('/usage', fn () => view('usage'))->name('usage');

Route::get('/settings', fn () => view('settings'))->name('settings');

Route::get('/knowledge', fn () => view('knowledge'))->name('knowledge');

Route::get('/security', fn () => view('security'))->name('security');

Route::get('/mobile/chat', fn () => view('mobile.chat'))->name('mobile.chat');
