<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Event\OrderPlacedEvent;
use App\Repository\OrderRepository;
use App\Service\CartService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CheckoutController extends AbstractController
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    #[Route('/checkout', name: 'app_checkout', methods: ['GET', 'POST'])]
    public function checkout(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('A valid user is required to checkout.');
        }

        $items = $this->cartService->getItems();

        if (count($items) === 0) {
            $this->addFlash('error', 'Your cart is empty.');

            return $this->redirectToRoute('app_cart');
        }

        $formData = [
            'street' => '',
            'city' => '',
            'postalCode' => '',
            'country' => '',
        ];

        $errors = [];

        if ($request->isMethod('POST')) {
            $formData = [
                'street' => trim((string) $request->request->get('street')),
                'city' => trim((string) $request->request->get('city')),
                'postalCode' => trim((string) $request->request->get('postalCode')),
                'country' => trim((string) $request->request->get('country')),
            ];

            if ($formData['street'] === '') {
                $errors['street'] = 'Street is required.';
            }

            if ($formData['city'] === '') {
                $errors['city'] = 'City is required.';
            }

            if ($formData['postalCode'] === '') {
                $errors['postalCode'] = 'Postal code is required.';
            }

            if ($formData['country'] === '') {
                $errors['country'] = 'Country is required.';
            }

            if (count($errors) === 0) {
                $order = new Order();

                $this->entityManager->beginTransaction();

                try {
                    $order
                        ->setUser($user)
                        ->setStatus(OrderStatus::Placed)
                        ->setCreatedAt(new \DateTimeImmutable())
                        ->setTotal(number_format($this->cartService->getTotal(), 2, '.', ''))
                        ->setStreet($formData['street'])
                        ->setCity($formData['city'])
                        ->setPostalCode($formData['postalCode'])
                        ->setCountry($formData['country']);

                    foreach ($items as $item) {
                        $product = $item['product'];
                        $quantity = $item['quantity'];

                        if ($quantity > $product->getStock()) {
                            throw new \InvalidArgumentException(sprintf(
                                'Not enough stock for product "%s".',
                                $product->getName()
                            ));
                        }

                        $orderItem = new OrderItem();
                        $orderItem
                            ->setProduct($product)
                            ->setQuantity($quantity)
                            ->setUnitPrice((string) $product->getPrice());

                        $order->addOrderItem($orderItem);

                        $product->setStock($product->getStock() - $quantity);

                        $this->entityManager->persist($orderItem);
                        $this->entityManager->persist($product);
                    }

                    $this->entityManager->persist($order);
                    $this->entityManager->flush();
                    $this->entityManager->commit();

                    $this->eventDispatcher->dispatch(new OrderPlacedEvent($order));

                    $this->cartService->clear();

                    return $this->redirectToRoute('app_checkout_confirmation', [
                        'id' => $order->getId(),
                    ]);
                } catch (\Throwable $exception) {
                    $this->entityManager->rollback();

                    $this->addFlash('error', $exception->getMessage());

                    return $this->redirectToRoute('app_cart');
                }
            }
        }

        return $this->render('checkout/index.html.twig', [
            'items' => $items,
            'total' => $this->cartService->getTotal(),
            'formData' => $formData,
            'errors' => $errors,
        ]);
    }

    #[Route('/checkout/confirmation/{id}', name: 'app_checkout_confirmation', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function confirmation(int $id, OrderRepository $orderRepository): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('A valid user is required to view this order.');
        }

        $order = $orderRepository->find($id);

        if (!$order) {
            throw $this->createNotFoundException('Order not found.');
        }

        if ($order->getUser() !== $user) {
            throw $this->createAccessDeniedException('You are not allowed to view this order.');
        }

        return $this->render('checkout/confirmation.html.twig', [
            'order' => $order,
        ]);
    }
}