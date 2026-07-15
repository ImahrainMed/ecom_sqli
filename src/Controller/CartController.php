<?php

namespace App\Controller;

use App\Repository\ProductRepository;
use App\Service\CartService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CartController extends AbstractController
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly ProductRepository $productRepository,
    ) {
    }

    #[Route('/cart', name: 'app_cart', methods: ['GET'])]
    public function index(): Response
    {
        $items = $this->cartService->getItems();

        return $this->render('cart/index.html.twig', [
            'cartIsEmpty' => count($items) === 0,
            'items' => $items,
            'total' => $this->cartService->getTotal(),
        ]);
    }

    #[Route('/cart/add/{productId}', name: 'app_cart_add', requirements: ['productId' => '\d+'], methods: ['POST'])]
    #[Route('/cart/add/{productId}', name: 'app_cart_add', requirements: ['productId' => '\d+'], methods: ['POST'])]
public function add(int $productId, Request $request): RedirectResponse
{
    $product = $this->productRepository->find($productId);

    if (!$product) {
        throw $this->createNotFoundException('Product not found.');
    }

    $quantity = $request->request->getInt('quantity', 1);

    try {
        $this->cartService->add($product, $quantity);
        $this->addFlash('success', sprintf('"%s" was added to your cart.', $product->getName()));
    } catch (\InvalidArgumentException) {
        $this->addFlash('error', 'Not enough stock available for this quantity.');
    }

    return $this->redirectToRoute('app_home', array_filter([
        'category' => $request->request->get('category'),
        'q' => $request->request->get('q'),
    ]));
}
    #[Route('/cart/update/{productId}', name: 'app_cart_update', requirements: ['productId' => '\d+'], methods: ['POST'])]
    public function update(int $productId, Request $request): RedirectResponse
    {
        $product = $this->productRepository->find($productId);

        if (!$product) {
            throw $this->createNotFoundException('Product not found.');
        }

        $quantity = (int) $request->request->get('quantity', 0);

        try {
            $this->cartService->update($product, $quantity);
        } catch (\InvalidArgumentException) {
            $this->addFlash('error', 'Requested quantity exceeds available stock.');
        }

        return $this->redirectToRoute('app_cart');
    }

    #[Route('/cart/remove/{productId}', name: 'app_cart_remove', requirements: ['productId' => '\d+'], methods: ['POST'])]
    public function remove(int $productId): RedirectResponse
    {
        $product = $this->productRepository->find($productId);

        if ($product) {
            $this->cartService->remove($product);
            $this->addFlash('success', sprintf('"%s" was removed from your cart.', $product->getName()));
        }

        return $this->redirectToRoute('app_cart');
    }
}
