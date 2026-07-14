<?php

namespace App\Service;

use App\Entity\Product;
use App\Repository\ProductRepository;
use Symfony\Component\HttpFoundation\RequestStack;

class CartService
{
    private const CART_SESSION_KEY = 'cart';

    public function __construct(
        private RequestStack $requestStack,
        private ProductRepository $productRepository
    ) {
    }

    public function add(Product $product, int $quantity = 1): void
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be greater than zero.');
        }

        $cart = $this->getCart();

        $productId = $product->getId();
        $currentQuantity = $cart[$productId] ?? 0;
        $newQuantity = $currentQuantity + $quantity;

        if ($newQuantity > $product->getStock()) {
            throw new \InvalidArgumentException('Requested quantity exceeds available stock.');
        }

        $cart[$productId] = $newQuantity;

        $this->saveCart($cart);
    }

    public function update(Product $product, int $quantity): void
    {
        if ($quantity < 0) {
            throw new \InvalidArgumentException('Quantity cannot be negative.');
        }

        $cart = $this->getCart();
        $productId = $product->getId();

        if ($quantity === 0) {
            unset($cart[$productId]);
            $this->saveCart($cart);
            return;
        }

        if ($quantity > $product->getStock()) {
            throw new \InvalidArgumentException('Requested quantity exceeds available stock.');
        }

        $cart[$productId] = $quantity;

        $this->saveCart($cart);
    }

    public function remove(Product $product): void
    {
        $cart = $this->getCart();

        unset($cart[$product->getId()]);

        $this->saveCart($cart);
    }

    public function clear(): void
    {
        $this->saveCart([]);
    }

    public function getCart(): array
    {
        return $this->getSession()->get(self::CART_SESSION_KEY, []);
    }

    public function getItems(): array
    {
        $cart = $this->getCart();
        $items = [];

        foreach ($cart as $productId => $quantity) {
            $product = $this->productRepository->find($productId);

            if (!$product) {
                continue;
            }

            $items[] = [
                'product' => $product,
                'quantity' => $quantity,
                'subtotal' => (float) $product->getPrice() * $quantity,
            ];
        }

        return $items;
    }

    public function getTotal(): float
    {
        $total = 0;

        foreach ($this->getItems() as $item) {
            $total += $item['subtotal'];
        }

        return $total;
    }

    private function saveCart(array $cart): void
    {
        $this->getSession()->set(self::CART_SESSION_KEY, $cart);
    }

    private function getSession()
    {
        return $this->requestStack->getSession();
    }
}