<?php

namespace App\Http\Controllers\Api;

use App\Mobile\MobilePairingService;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileController
{
    public function status(): JsonResponse
    {
        return response()->json([
            'name' => config('aegis.name', 'Aegis'),
            'version' => config('aegis.version', '0.1.0'),
            'mobile_api' => 'v1',
        ]);
    }

    public function pair(Request $request, MobilePairingService $service): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'device_name' => 'required|string|max:100',
        ]);

        $token = (string) $request->input('token');
        $deviceName = (string) $request->input('device_name');

        $pairingData = $service->consumePairing($token);
        if ($pairingData === null) {
            return response()->json(['error' => 'Invalid or expired pairing token'], 401);
        }

        $session = $service->createSession($deviceName);

        return response()->json($session);
    }

    public function chat(Request $request, MobilePairingService $service): JsonResponse
    {
        $session = $this->authenticateSession($request, $service);
        if ($session === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $request->validate([
            'message' => 'required|string|max:10000',
            'conversation_id' => 'nullable|integer',
        ]);

        $userMessage = (string) $request->input('message');
        $conversationId = $request->input('conversation_id');

        $conversation = $conversationId
            ? Conversation::find($conversationId)
            : Conversation::create([
                'title' => mb_substr($userMessage, 0, 50),
                'model' => config('aegis.agent.default_model'),
                'provider' => config('aegis.agent.default_provider'),
                'status' => 'active',
            ]);

        if (! $conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userMessage,
            'token_count' => (int) ceil(mb_strlen($userMessage) / 4),
        ]);

        $responseText = 'Message received from mobile companion. Agent processing is available in desktop mode.';

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $responseText,
            'token_count' => (int) ceil(mb_strlen($responseText) / 4),
        ]);

        return response()->json([
            'conversation_id' => $conversation->id,
            'response' => $responseText,
        ]);
    }

    public function conversations(Request $request, MobilePairingService $service): JsonResponse
    {
        $session = $this->authenticateSession($request, $service);
        if ($session === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $conversations = Conversation::query()
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get(['id', 'title', 'model', 'provider', 'status', 'created_at', 'updated_at']);

        return response()->json(['conversations' => $conversations]);
    }

    public function messages(Request $request, MobilePairingService $service, int $conversationId): JsonResponse
    {
        $session = $this->authenticateSession($request, $service);
        if ($session === null) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $conversation = Conversation::find($conversationId);
        if (! $conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        $messages = Message::query()
            ->where('conversation_id', $conversationId)
            ->orderBy('created_at')
            ->limit(100)
            ->get(['id', 'role', 'content', 'token_count', 'created_at']);

        return response()->json(['messages' => $messages]);
    }

    private function authenticateSession(Request $request, MobilePairingService $service): ?array
    {
        $authHeader = (string) $request->header('Authorization', '');
        if (! str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $token = substr($authHeader, 7);

        return $service->validateSession($token);
    }
}
