<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Service\CartService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CheckoutController extends AbstractController
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly EntityManagerInterface $entityManager,
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

        if ($request->isMethod('GET')) {
            return $this->render('checkout/index.html.twig', [
                'items' => $items,
                'total' => $this->cartService->getTotal(),
            ]);
        }

        $street = trim((string) $request->request->get('street'));
        $city = trim((string) $request->request->get('city'));
        $postalCode = trim((string) $request->request->get('postalCode'));
        $country = trim((string) $request->request->get('country'));

        if ($street === '' || $city === '' || $postalCode === '' || $country === '') {
            $this->addFlash('error', 'Please fill in all shipping address fields.');

            return $this->render('checkout/index.html.twig', [
                'items' => $items,
                'total' => $this->cartService->getTotal(),
                'street' => $street,
                'city' => $city,
                'postalCode' => $postalCode,
                'country' => $country,
            ]);
        }

        $order = new Order();

        $this->entityManager->beginTransaction();

        try {
            $order
                ->setUser($user)
                ->setStatus(OrderStatus::Placed)
                ->setCreatedAt(new \DateTimeImmutable())
                ->setTotal(number_format($this->cartService->getTotal(), 2, '.', ''))
                ->setStreet($street)
                ->setCity($city)
                ->setPostalCode($postalCode)
                ->setCountry($country);

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

            $this->cartService->clear();

            $this->addFlash('success', sprintf('Order #%d was placed successfully.', $order->getId()));

            return $this->redirectToRoute('app_cart');
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_cart');
        }
    }
}