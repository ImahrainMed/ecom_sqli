<?php

namespace App\Controller;

use App\Service\ChatbotService;
use App\Service\ChatHistoryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ChatbotController extends AbstractController
{
    #[Route('/chatbot/message', name: 'app_chatbot_message', methods: ['POST'])]
    public function message(Request $request, ChatbotService $chatbotService): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $message = trim((string) ($data['message'] ?? ''));

        if ($message === '') {
            return new JsonResponse([
                'type' => 'text',
                'message' => 'Veuillez saisir un message.',
            ], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($chatbotService->ask($message));
    }

    #[Route('/chatbot/history', name: 'app_chatbot_history', methods: ['GET'])]
    public function history(ChatHistoryService $chatHistoryService): JsonResponse
    {
        return new JsonResponse(['messages' => $chatHistoryService->getMessages()]);
    }

    #[Route('/chatbot/clear', name: 'app_chatbot_clear', methods: ['POST'])]
    public function clear(ChatHistoryService $chatHistoryService): JsonResponse
    {
        $chatHistoryService->clear();

        return new JsonResponse(['status' => 'ok']);
    }
}
