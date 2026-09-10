<?php

namespace App\Http\Controllers;

use App\Http\Requests\AskChatbotRequest;
use App\Services\AiProviderException;
use App\Services\ChatbotService;
use Illuminate\Http\JsonResponse;
use Throwable;

class CustomerChatbotController extends Controller
{
    public function __construct(private readonly ChatbotService $chatbot) {}

    public function store(AskChatbotRequest $request): JsonResponse
    {
        try {
            return response()->json(['data' => $this->chatbot->ask($request->string('question')->toString()), 'success' => true]);
        } catch (AiProviderException) {
            return response()->json(['message' => 'The menu assistant is temporarily unavailable. You can still browse the menu and place your order normally.'], 503);
        } catch (Throwable) {
            return response()->json(['message' => 'The menu assistant is temporarily unavailable. You can still browse the menu and place your order normally.'], 503);
        }
    }
}
