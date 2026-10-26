<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramService $telegram)
    {
        $payload = $request->all();

        if (! empty($payload)) {
            $telegram->handleIncomingUpdate($payload);
        }

        return response()->json(['ok' => true]);
    }
}
