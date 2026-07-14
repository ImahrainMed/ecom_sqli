<?php

namespace App\Twig;

use App\Service\CartService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class CartExtension extends AbstractExtension
{
    public function __construct(private readonly CartService $cartService)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cart_summary', $this->getCartSummary(...)),
        ];
    }

    /**
     * @return array{items: list<array{product: \App\Entity\Product, quantity: int, subtotal: float}>, count: int, total: float}
     */
    public function getCartSummary(): array
    {
        $items = $this->cartService->getItems();

        return [
            'items' => $items,
            'count' => array_sum(array_column($items, 'quantity')),
            'total' => $this->cartService->getTotal(),
        ];
    }
}
