<?php

namespace App\Tests\Service;

use App\Entity\Product;
use App\Repository\ProductRepository;
use App\Service\CartService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class CartServiceTest extends TestCase
{
    private function createProduct(int $id, string $price = '10.00', int $stock = 10): Product
    {
        $product = new Product();

        $reflection = new \ReflectionClass($product);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($product, $id);

        $product->setName('Test Product');
        $product->setDescription('Test description');
        $product->setPrice($price);
        $product->setStock($stock);
        $product->setImageUrl('https://example.com/image.jpg');

        return $product;
    }

private function createCartService(array $products = []): CartService
{
    $session = new Session(new MockArraySessionStorage());

    $request = new Request();
    $request->setSession($session);

    $requestStack = new RequestStack();
    $requestStack->push($request);

    $productRepository = $this->createStub(ProductRepository::class);

    $productRepository
        ->method('find')
        ->willReturnCallback(function ($id) use ($products) {
            return $products[$id] ?? null;
        });

    return new CartService($requestStack, $productRepository);
}

    public function testAddItem(): void
    {
        $product = $this->createProduct(1, '20.00', 10);
        $cartService = $this->createCartService([1 => $product]);

        $cartService->add($product, 2);

        $cart = $cartService->getCart();

        $this->assertSame(2, $cart[1]);
    }

    public function testAddBeyondStockThrowsException(): void
    {
        $product = $this->createProduct(1, '20.00', 3);
        $cartService = $this->createCartService([1 => $product]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Requested quantity exceeds available stock.');

        $cartService->add($product, 4);
    }

    public function testRemoveItem(): void
    {
        $product = $this->createProduct(1, '20.00', 10);
        $cartService = $this->createCartService([1 => $product]);

        $cartService->add($product, 2);
        $cartService->remove($product);

        $this->assertArrayNotHasKey(1, $cartService->getCart());
    }

    public function testUpdateQuantity(): void
    {
        $product = $this->createProduct(1, '20.00', 10);
        $cartService = $this->createCartService([1 => $product]);

        $cartService->add($product, 2);
        $cartService->update($product, 5);

        $cart = $cartService->getCart();

        $this->assertSame(5, $cart[1]);
    }

    public function testEmptyCartTotalIsZero(): void
    {
        $cartService = $this->createCartService();

        $this->assertSame(0.0, $cartService->getTotal());
    }

    public function testGetTotal(): void
    {
        $product1 = $this->createProduct(1, '20.00', 10);
        $product2 = $this->createProduct(2, '15.50', 10);

        $cartService = $this->createCartService([
            1 => $product1,
            2 => $product2,
        ]);

        $cartService->add($product1, 2);
        $cartService->add($product2, 1);

        $this->assertSame(55.5, $cartService->getTotal());
    }

    public function testAddNegativeQuantityThrowsException(): void
    {
        $product = $this->createProduct(1, '20.00', 10);
        $cartService = $this->createCartService([1 => $product]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Quantity must be greater than zero.');

        $cartService->add($product, -1);
    }

    public function testAddZeroQuantityThrowsException(): void
    {
        $product = $this->createProduct(1, '20.00', 10);
        $cartService = $this->createCartService([1 => $product]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Quantity must be greater than zero.');

        $cartService->add($product, 0);
    }

    public function testAddCumulativeQuantityExceedsStockThrowsException(): void
    {
        $product = $this->createProduct(1, '20.00', 5);
        $cartService = $this->createCartService([1 => $product]);

        $cartService->add($product, 3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Requested quantity exceeds available stock.');

        $cartService->add($product, 3);
    }

    public function testUpdateNegativeQuantityThrowsException(): void
    {
        $product = $this->createProduct(1, '20.00', 10);
        $cartService = $this->createCartService([1 => $product]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Quantity cannot be negative.');

        $cartService->update($product, -1);
    }

    public function testRemoveNonExistentItemDoesNotThrow(): void
    {
        $product = $this->createProduct(1, '20.00', 10);
        $cartService = $this->createCartService([1 => $product]);

        $cartService->remove($product);

        $this->assertSame([], $cartService->getCart());
    }

    /**
     * Si le produit a été supprimé entre le moment où il a été ajouté au panier
     * et l'appel à getItems() (ProductRepository::find() retourne alors null),
     * l'entrée est ignorée silencieusement dans le résultat retourné.
     * Limitation connue (non corrigée par ce test) : le panier en session
     * conserve malgré tout l'entrée "fantôme" du produit supprimé.
     */
    public function testGetItemsSkipsDeletedProduct(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('cart', [1 => 2]);

        $request = new Request();
        $request->setSession($session);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $productRepository = $this->createStub(ProductRepository::class);
        $productRepository->method('find')->willReturn(null);

        $cartService = new CartService($requestStack, $productRepository);

        $this->assertSame([], $cartService->getItems());
        $this->assertSame([1 => 2], $cartService->getCart());
    }
}