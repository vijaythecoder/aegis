<?php

use App\Messaging\Adapters\SlackAdapter;
use App\Messaging\Adapters\TelegramAdapter;
use App\Messaging\MessageRouter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/webhook/whatsapp', function (Request $request) {
    $mode = (string) ($request->query('hub_mode') ?? $request->query('hub.mode', ''));
    $token = (string) ($request->query('hub_verify_token') ?? $request->query('hub.verify_token', ''));
    $challenge = (string) ($request->query('hub_challenge') ?? $request->query('hub.challenge', ''));
    $expectedToken = (string) config('aegis.messaging.whatsapp.verify_token', '');

    if ($mode === 'subscribe' && $token !== '' && hash_equals($expectedToken, $token)) {
        return response($challenge, 200);
    }

    return response()->json(['error' => 'Invalid verify token'], 403);
});

Route::post('/webhook/{platform}', function (Request $request, string $platform) {
    $router = app(MessageRouter::class);
    $adapter = $router->getAdapter($platform);

    if (! $adapter) {
        return response()->json(['error' => 'Unknown platform'], 404);
    }

    if ($adapter instanceof SlackAdapter && $adapter->isUrlVerification($request)) {
        if (! $adapter->verifyRequestSignature($request)) {
            return response()->json(['error' => 'Invalid request signature'], 401);
        }

        return response()->json([
            'challenge' => $adapter->challenge($request),
        ]);
    }

    $incoming = $adapter->handleIncomingMessage($request);

    if ($adapter instanceof SlackAdapter) {
        app()->terminating(function () use ($router, $adapter, $incoming): void {
            $response = $router->route($incoming);
            $adapter->sendMessage($incoming->channelId, $response->text);
        });

        return response()->json(['ok' => true]);
    }

    if ($adapter instanceof TelegramAdapter) {
        $commandResponse = $adapter->handleCommand($incoming);

        if ($commandResponse !== null) {
            $adapter->sendMessage($incoming->channelId, $commandResponse);

            return response()->json(['ok' => true, 'handled' => 'command']);
        }
    }

    $response = $router->route($incoming);
    $adapter->sendMessage($incoming->channelId, $response->text);

    return response()->json(['ok' => true]);
});
