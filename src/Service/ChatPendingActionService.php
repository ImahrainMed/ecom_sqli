<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Persists, at most, one cart-mutating chatbot action (add/update/remove)
 * that has been proposed by the model but not yet confirmed by the user.
 *
 * This is what makes the "confirm before mutating the cart" rule a backend
 * guarantee instead of a system-prompt request: ChatbotService never lets a
 * write function execute the moment the model calls it — it stores the call
 * here and only runs it once the *next* user message is deterministically
 * recognized as a confirmation, regardless of what the model's own reply
 * text claimed happened.
 */
class ChatPendingActionService
{
    private const SESSION_KEY = 'chatbot_pending_action';

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @param array<string, mixed> $args
     */
    public function set(string $function, array $args): void
    {
        $this->requestStack->getSession()->set(self::SESSION_KEY, [
            'function' => $function,
            'args' => $args,
        ]);
    }

    /**
     * @return array{function: string, args: array<string, mixed>}|null
     */
    public function get(): ?array
    {
        return $this->requestStack->getSession()->get(self::SESSION_KEY);
    }

    public function clear(): void
    {
        $this->requestStack->getSession()->remove(self::SESSION_KEY);
    }
}
