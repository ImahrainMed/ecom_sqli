<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Persists the product(s) returned by the most recent search_products
 * call(s), so a same-session follow-up cart action that refers back to it
 * implicitly ("ajoute-le", "achète cet article") can be resolved
 * structurally instead of depending on the model re-deriving a precise
 * product_name on its own — see ChatbotService::handleAddToCart()'s Phase 2
 * test verdict for the failure mode this fixes.
 *
 * Same session-backed, single-slot pattern as ChatPendingActionService: only
 * the latest search result is kept, and a new search (or one that finds
 * nothing) simply overwrites/clears it.
 */
class ChatLastSearchService
{
    private const SESSION_KEY = 'chatbot_last_search';

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @param list<int>    $productIds
     * @param list<string> $productNames
     * @param list<string> $keywords     raw keyword args from the search_products call(s) that produced this result
     */
    public function set(array $productIds, array $productNames, array $keywords): void
    {
        $this->requestStack->getSession()->set(self::SESSION_KEY, [
            'product_ids' => $productIds,
            'product_names' => $productNames,
            'keywords' => $keywords,
        ]);
    }

    /**
     * @return array{product_ids: list<int>, product_names: list<string>, keywords: list<string>}|null
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
