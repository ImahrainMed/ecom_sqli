<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Persists the chatbot widget's conversation history in the user's session,
 * the same way CartService persists the cart, so it survives full page
 * navigations (e.g. clicking a product link in a bot reply).
 */
class ChatHistoryService
{
    private const SESSION_KEY = 'chatbot_history';
    private const MAX_MESSAGES = 50;

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getMessages(): array
    {
        return $this->requestStack->getSession()->get(self::SESSION_KEY, []);
    }

    public function addUserMessage(string $message): void
    {
        $this->append(['role' => 'user', 'message' => $message]);
    }

    public function addBotMessage(array $response): void
    {
        $this->append(['role' => 'bot'] + $response);
    }

    public function clear(): void
    {
        $this->requestStack->getSession()->remove(self::SESSION_KEY);
    }

    private function append(array $entry): void
    {
        $session = $this->requestStack->getSession();
        $messages = $session->get(self::SESSION_KEY, []);
        $messages[] = $entry;

        if (count($messages) > self::MAX_MESSAGES) {
            $messages = array_slice($messages, -self::MAX_MESSAGES);
        }

        $session->set(self::SESSION_KEY, $messages);
    }
}
